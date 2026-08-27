<?php

namespace justinholtweb\passer\importers;

use Craft;
use justinholtweb\passer\models\wp\WpForm;
use justinholtweb\passer\models\wp\WpFormField;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\services\IdMap;

/**
 * Contact Form 7, Gravity Forms, WPForms, Ninja Forms and Formidable become Formie forms.
 *
 * Forms are the domain where an automated import is most likely to get something subtly wrong,
 * because a form's behaviour is not just its fields — it is conditional logic, calculations,
 * payment integrations and third-party hooks that no importer can carry across. So the emphasis
 * here is on rebuilding the *structure* faithfully and on reporting, in detail, everything that
 * did not come with it. A form that is 90% built and honestly labelled is worth far more than
 * one that looks complete and silently drops a required field.
 */
class FormImporter extends BaseImporter
{
    /**
     * Field types with no Formie equivalent, and what happens to them.
     */
    private const UNSUPPORTED = [
        'payment' => 'payment fields need a Formie payment integration configured against your own gateway',
        'rating' => 'rating fields have no Formie equivalent and were imported as a dropdown of 1–5',
    ];

    public static function phase(): string
    {
        return 'forms';
    }

    public function import(RunContext $context, array $resumeFrom = []): array
    {
        $domain = $context->plan->domain('forms');

        if ($domain === null || !$domain->enabled) {
            return [];
        }

        $plugins = [];

        foreach (Plugin::getInstance()->pluginDetector->detect($context->source) as $plugin) {
            if ($plugin->category === 'forms') {
                $plugins[] = $plugin->slug;
            }
        }

        if ($plugins === []) {
            $this->notice($context, 'No form plugin data was found in the source.');

            return [];
        }

        $forms = Plugin::getInstance()->forms->read($context->source, $plugins);

        if ($forms === []) {
            $this->notice($context, 'Form plugins were detected but no forms were found.');

            return [];
        }

        $context->progress->startPhase(self::phase(), count($forms));
        $context->map->warm();

        $processed = 0;

        foreach ($forms as $form) {
            $processed++;

            $this->attempt($context, IdMap::KEY_FORM, $form->sourceId, $form->title, function () use ($form, $domain, $context) {
                match ($domain->destination) {
                    'formie' => $this->writeFormie($form, $context),
                    'bandage' => $this->writeBandage($form, $context),
                    default => $this->describe($form, $context),
                };
            });

            $context->progress->advance($processed, $form->title);
        }

        $context->progress->endPhase(self::phase());

        return [];
    }

    // -----------------------------------------------------------------------------------------
    // Formie
    // -----------------------------------------------------------------------------------------

    private function writeFormie(WpForm $form, RunContext $context): void
    {
        $formClass = 'verbb\\formie\\elements\\Form';

        if (!class_exists($formClass)) {
            $this->warn($context, 'Formie is not installed, so forms were described rather than imported.');
            $this->describe($form, $context);

            return;
        }

        if ($context->dryRun) {
            $context->count(self::phase(), 'created');

            return;
        }

        $existingId = $context->map->lookup(IdMap::KEY_FORM, $form->sourceId);
        $element = $existingId !== null ? $formClass::find()->id($existingId)->status(null)->one() : null;
        $isNew = $element === null;

        /** @var \craft\base\ElementInterface $element */
        $element ??= new $formClass();

        $element->title = $this->cleanText($form->title) ?: 'Imported form';
        $element->handle = $this->uniqueHandle($form, $formClass, $element->id);

        $settings = $element->getSettings();

        if ($form->submitLabel !== null && $form->submitLabel !== '') {
            $settings->submitActionMessage = $form->successMessage ?? $settings->submitActionMessage;
        }

        if ($form->successMessage !== null && $form->successMessage !== '') {
            $settings->submitActionMessage = strip_tags($form->successMessage);
        }

        $element->setSettings($settings);

        // Formie's page/row/field structure is what the form builder produces; building it by
        // hand is the only way to create a form programmatically that the builder will then open.
        $element->setPages([[
            'label' => 'Page 1',
            'settings' => ['submitButtonLabel' => $form->submitLabel ?: 'Submit'],
            'rows' => $this->formieRows($form, $context),
        ]]);

        $this->save($element, false);

        $context->map->record(
            IdMap::KEY_FORM,
            $form->sourceId,
            $formClass,
            $element->id,
            $element->uid,
            null,
            null,
            null,
            $context->runId
        );

        $this->writeFormieNotifications($form, $element, $context);
        $this->writeFormieSubmissions($form, $element, $context);

        $context->count(self::phase(), $isNew ? 'created' : 'updated');

        $this->reportGaps($form, $context);
    }

    /**
     * @return array<int, array{fields: array<int, array<string, mixed>>}>
     */
    private function formieRows(WpForm $form, RunContext $context): array
    {
        $rows = [];

        foreach ($form->fields as $field) {
            $type = $this->formieFieldClass($field);

            if ($type === null) {
                continue;
            }

            $settings = [
                'label' => $field->label !== '' ? $field->label : ucfirst($field->handle),
                'handle' => $field->handle,
                'required' => $field->required,
                'instructions' => $field->instructions ?? '',
                'placeholder' => $field->placeholder ?? '',
                'defaultValue' => $field->defaultValue ?? '',
            ];

            if ($field->options !== []) {
                $settings['options'] = array_map(static fn(array $o) => [
                    'label' => $o['label'],
                    'value' => $o['value'],
                    'isDefault' => !empty($o['default']),
                ], $field->options);
            }

            if ($field->type === 'rating') {
                // Formie has no rating field, so a five-option dropdown keeps the data shape and
                // the report says what happened.
                $settings['options'] = array_map(
                    static fn(int $n) => ['label' => (string)$n, 'value' => (string)$n, 'isDefault' => false],
                    range(1, 5)
                );
            }

            if ($field->type === 'number') {
                $settings['limit'] = false;
            }

            if ($field->type === 'file' && !empty($field->settings['allowedExtensions'])) {
                $settings['allowedKinds'] = $this->kindsFor($field->settings['allowedExtensions']);
            }

            if ($field->type === 'multiLine') {
                $settings['rows'] = 4;
            }

            // Each field gets its own row: a WordPress form has no column layout to preserve, so
            // inventing one would be guessing.
            $rows[] = ['fields' => [['type' => $type] + $settings]];
        }

        return $rows;
    }

    private function formieFieldClass(WpFormField $field): ?string
    {
        $map = [
            'singleLine' => 'verbb\\formie\\fields\\SingleLineText',
            'multiLine' => 'verbb\\formie\\fields\\MultiLineText',
            'email' => 'verbb\\formie\\fields\\Email',
            'phone' => 'verbb\\formie\\fields\\Phone',
            'number' => 'verbb\\formie\\fields\\Number',
            'url' => 'verbb\\formie\\fields\\SingleLineText',
            'dropdown' => 'verbb\\formie\\fields\\Dropdown',
            'radio' => 'verbb\\formie\\fields\\Radio',
            'checkboxes' => 'verbb\\formie\\fields\\Checkboxes',
            'date' => 'verbb\\formie\\fields\\Date',
            'file' => 'verbb\\formie\\fields\\FileUpload',
            'hidden' => 'verbb\\formie\\fields\\Hidden',
            'html' => 'verbb\\formie\\fields\\Html',
            'heading' => 'verbb\\formie\\fields\\Heading',
            'section' => 'verbb\\formie\\fields\\Section',
            'name' => 'verbb\\formie\\fields\\Name',
            'address' => 'verbb\\formie\\fields\\Address',
            'agree' => 'verbb\\formie\\fields\\Agree',
            'rating' => 'verbb\\formie\\fields\\Dropdown',
            // reCAPTCHA is a Formie *captcha integration*, configured site-wide with your own
            // keys, not a field. Creating a field for it would produce a form that never
            // validates.
            'recaptcha' => null,
            'payment' => null,
        ];

        $class = $map[$field->type] ?? 'verbb\\formie\\fields\\SingleLineText';

        return $class !== null && class_exists($class) ? $class : null;
    }

    /**
     * @param string[] $extensions
     * @return string[]
     */
    private function kindsFor(array $extensions): array
    {
        $kinds = [];

        foreach ($extensions as $extension) {
            $extension = strtolower(ltrim(trim($extension), '.'));

            $kind = match (true) {
                in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'], true) => 'image',
                in_array($extension, ['pdf'], true) => 'pdf',
                in_array($extension, ['doc', 'docx', 'odt', 'rtf', 'txt'], true) => 'text',
                in_array($extension, ['xls', 'xlsx', 'csv', 'ods'], true) => 'excel',
                in_array($extension, ['mp4', 'mov', 'avi', 'webm'], true) => 'video',
                in_array($extension, ['mp3', 'wav', 'ogg', 'm4a'], true) => 'audio',
                in_array($extension, ['zip', 'gz', 'rar', '7z'], true) => 'compressed',
                default => null,
            };

            if ($kind !== null && !in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    private function writeFormieNotifications(WpForm $form, object $element, RunContext $context): void
    {
        $notificationClass = 'verbb\\formie\\models\\Notification';
        $formie = Craft::$app->getPlugins()->getPlugin('formie');

        if (!class_exists($notificationClass) || $formie === null || $form->notifications === []) {
            return;
        }

        foreach ($form->notifications as $index => $notification) {
            $to = trim((string)($notification['to'] ?? ''));

            if ($to === '') {
                continue;
            }

            $model = new $notificationClass();
            $model->formId = $element->id;
            $model->name = 'Imported notification ' . ($index + 1);
            $model->enabled = true;
            $model->to = $to;
            $model->subject = (string)($notification['subject'] ?? 'Form submission');
            $model->from = (string)($notification['from'] ?? '') ?: null;
            $model->fromName = (string)($notification['name'] ?? '') ?: null;
            $model->replyTo = (string)($notification['replyTo'] ?? '') ?: null;

            // WordPress notification bodies are full of the source plugin's own placeholder
            // syntax — `[your-name]`, `{Field:3}`, `{field_id="5"}` — which Formie will not
            // interpret. The body is imported verbatim so nothing is lost, and the gap report
            // says the placeholders need rewriting.
            $model->content = $this->notificationContent((string)($notification['body'] ?? ''));

            try {
                $formie->getNotifications()->saveNotification($model);
            } catch (\Throwable $e) {
                $this->warn($context, sprintf(
                    'Could not import a notification for "%s": %s',
                    $form->title,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Formie stores notification content as a rich-text document rather than a string.
     */
    private function notificationContent(string $body): string
    {
        if ($body === '') {
            return '';
        }

        $paragraphs = [];

        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim(strip_tags($line));

            if ($line === '') {
                continue;
            }

            $paragraphs[] = [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $line]],
            ];
        }

        return \craft\helpers\Json::encode($paragraphs);
    }

    private function writeFormieSubmissions(WpForm $form, object $element, RunContext $context): void
    {
        if ($form->submissions === []) {
            return;
        }

        $submissionClass = 'verbb\\formie\\elements\\Submission';

        if (!class_exists($submissionClass)) {
            return;
        }

        $imported = 0;
        $layout = $element->getFormFieldLayout();
        $handles = [];

        foreach ($element->getFields() as $field) {
            $handles[$field->handle] = true;
        }

        foreach ($form->submissions as $submission) {
            $existingId = $context->map->lookup(IdMap::KEY_SUBMISSION, $form->sourceId . ':' . $submission->sourceId);

            if ($existingId !== null) {
                continue;
            }

            $entry = new $submissionClass();
            $entry->setForm($element);
            $entry->isIncomplete = false;
            $entry->isSpam = false;
            $entry->ipAddress = $submission->ip;

            $date = $this->toDateTime($submission->date);

            if ($date !== null) {
                $entry->dateCreated = $date;
            }

            if ($submission->userId > 0) {
                $userId = $context->map->lookup(IdMap::KEY_USER, $submission->userId);

                if ($userId !== null) {
                    $entry->userId = $userId;
                }
            }

            foreach ($submission->values as $handle => $value) {
                if (!isset($handles[$handle])) {
                    continue;
                }

                $entry->setFieldValue($handle, is_array($value) ? implode(', ', array_map('strval', $value)) : $value);
            }

            try {
                $this->save($entry, false);

                $context->map->record(
                    IdMap::KEY_SUBMISSION,
                    $form->sourceId . ':' . $submission->sourceId,
                    $submissionClass,
                    $entry->id,
                    $entry->uid,
                    null,
                    null,
                    null,
                    $context->runId
                );

                $imported++;
            } catch (\Throwable $e) {
                $this->warn($context, sprintf(
                    'Could not import submission %s for "%s": %s',
                    $submission->sourceId,
                    $form->title,
                    $e->getMessage()
                ));
            }
        }

        if ($imported > 0) {
            $this->info($context, sprintf(
                '%s submission%s imported for "%s".',
                number_format($imported),
                $imported === 1 ? '' : 's',
                $form->title
            ));
        }
    }

    // -----------------------------------------------------------------------------------------
    // Bandage
    // -----------------------------------------------------------------------------------------

    private function writeBandage(WpForm $form, RunContext $context): void
    {
        // Bandage extends Craft's Contact Form rather than building forms, so there is no form
        // to create — only submissions to store, and a specification for the developer to build
        // the form itself from.
        $this->describe($form, $context);

        $submissionClass = 'justinholtweb\\bandage\\elements\\Submission';

        if (!class_exists($submissionClass) || $form->submissions === [] || $context->dryRun) {
            return;
        }

        $imported = 0;

        foreach ($form->submissions as $submission) {
            $key = $form->sourceId . ':' . $submission->sourceId;

            if ($context->map->has(IdMap::KEY_SUBMISSION, $key)) {
                continue;
            }

            $element = new $submissionClass();
            $element->formHandle = $this->handleFor($form);
            $element->formName = $form->title;
            $element->fields = $submission->values;
            $element->ipAddress = $submission->ip;
            $element->userAgent = $submission->userAgent;

            $date = $this->toDateTime($submission->date);

            if ($date !== null) {
                $element->dateCreated = $date;
            }

            try {
                $this->save($element, false);

                $context->map->record(
                    IdMap::KEY_SUBMISSION,
                    $key,
                    $submissionClass,
                    $element->id,
                    $element->uid,
                    null,
                    null,
                    null,
                    $context->runId
                );

                $imported++;
            } catch (\Throwable) {
                continue;
            }
        }

        if ($imported > 0) {
            $this->info($context, sprintf('%s submissions imported into Bandage for "%s".', number_format($imported), $form->title));
        }
    }

    // -----------------------------------------------------------------------------------------
    // The specification fallback
    // -----------------------------------------------------------------------------------------

    /**
     * Write a complete description of the form to the run report.
     *
     * This is the fallback destination, and it is genuinely useful rather than a consolation
     * prize: a developer rebuilding a form by hand needs exactly this list, and getting it out
     * of five different WordPress plugins by hand is a morning's work.
     */
    private function describe(WpForm $form, RunContext $context): void
    {
        $lines = [sprintf('Form "%s" (%s, %d fields):', $form->title, $form->plugin, count($form->fields))];

        foreach ($form->fields as $field) {
            $line = sprintf(
                '  - %s (%s%s), handle %s',
                $field->label !== '' ? $field->label : '(no label)',
                $field->type,
                $field->required ? ', required' : '',
                $field->handle
            );

            if ($field->options !== []) {
                $line .= ' — options: ' . implode(', ', array_map(static fn(array $o) => $o['label'], $field->options));
            }

            if ($field->placeholder !== null && $field->placeholder !== '') {
                $line .= ' — placeholder: ' . $field->placeholder;
            }

            $lines[] = $line;
        }

        foreach ($form->notifications as $notification) {
            $lines[] = sprintf(
                '  Notification to %s, subject "%s"',
                $notification['to'] ?? '(nobody)',
                $notification['subject'] ?? ''
            );
        }

        if ($form->submissions !== []) {
            $lines[] = sprintf('  %d stored submission%s.', count($form->submissions), count($form->submissions) === 1 ? '' : 's');
        }

        $this->notice($context, implode("\n", $lines));
        $context->count(self::phase(), 'skipped');
    }

    // -----------------------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------------------

    private function reportGaps(WpForm $form, RunContext $context): void
    {
        $seen = [];

        foreach ($form->fields as $field) {
            if (isset(self::UNSUPPORTED[$field->type]) && !isset($seen[$field->type])) {
                $seen[$field->type] = true;

                $this->notice($context, sprintf(
                    '"%s" has a %s field: %s.',
                    $form->title,
                    $field->type,
                    self::UNSUPPORTED[$field->type]
                ));
            }

            if ($field->type === 'recaptcha') {
                $this->notice($context, sprintf(
                    '"%s" used a captcha. Formie handles captchas as a site-wide integration '
                    . 'rather than a field, so switch one on in Formie\'s settings with your own keys.',
                    $form->title
                ));
            }
        }

        if ($form->notifications !== []) {
            $this->notice($context, sprintf(
                'The notification bodies for "%s" were imported verbatim and still contain %s\'s '
                . 'placeholder syntax. Rewrite them with Formie\'s {field.handle} variables.',
                $form->title,
                $form->plugin
            ));
        }
    }

    private function handleFor(WpForm $form): string
    {
        $handle = \craft\helpers\StringHelper::toCamelCase(preg_replace('/[^A-Za-z0-9]+/', ' ', $form->title) ?? '');

        if ($handle === '' || !preg_match('/^[a-zA-Z]/', $handle)) {
            $handle = 'form' . preg_replace('/\D+/', '', $form->sourceId);
        }

        return $handle;
    }

    private function uniqueHandle(WpForm $form, string $formClass, ?int $ignoreId): string
    {
        $base = $this->handleFor($form);
        $handle = $base;
        $suffix = 1;

        while (true) {
            $query = $formClass::find()->handle($handle)->status(null);

            if ($ignoreId !== null) {
                $query->id('not ' . $ignoreId);
            }

            if (!$query->exists()) {
                return $handle;
            }

            $handle = $base . (++$suffix);

            if ($suffix > 100) {
                return $base . $form->sourceId;
            }
        }
    }
}
