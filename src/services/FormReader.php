<?php

namespace justinholtweb\passer\services;

use craft\base\Component;
use craft\helpers\Json;
use justinholtweb\passer\helpers\WpSerialize;
use justinholtweb\passer\models\wp\WpForm;
use justinholtweb\passer\models\wp\WpFormField;
use justinholtweb\passer\models\wp\WpFormSubmission;
use justinholtweb\passer\sources\SourceInterface;

/**
 * Reads forms out of the five WordPress form plugins that matter.
 *
 * They could hardly be less alike. Contact Form 7 stores a form as a *string of custom markup*
 * that has to be parsed. Gravity Forms stores it as JSON in its own table. WPForms stores JSON
 * in a post's content. Ninja Forms normalises across four tables. Formidable uses three.
 *
 * Everything arrives here as WpForm + WpFormField, in a vocabulary that maps onto Formie.
 */
class FormReader extends Component
{
    /**
     * @param string[] $plugins Detected plugin slugs.
     * @return WpForm[]
     */
    public function read(SourceInterface $source, array $plugins): array
    {
        $forms = [];

        foreach ($plugins as $slug) {
            try {
                $forms = array_merge($forms, match ($slug) {
                    'contact-form-7' => $this->contactForm7($source),
                    'gravityforms' => $this->gravityForms($source),
                    'wpforms-lite' => $this->wpForms($source),
                    'ninja-forms' => $this->ninjaForms($source),
                    'formidable' => $this->formidable($source),
                    default => [],
                });
            } catch (\Throwable) {
                // A plugin whose tables are missing contributes nothing.
                continue;
            }
        }

        return $forms;
    }

    // -----------------------------------------------------------------------------------------
    // Contact Form 7
    // -----------------------------------------------------------------------------------------

    /**
     * @return WpForm[]
     */
    private function contactForm7(SourceInterface $source): array
    {
        $forms = [];

        foreach ($source->posts('wpcf7_contact_form') as $post) {
            $form = new WpForm();
            $form->sourceId = (string)$post->id;
            $form->plugin = 'cf7';
            $form->title = $post->title;

            // CF7 keeps the form body in one meta key and everything else in others.
            $body = $post->metaValue('_form');
            $form->fields = is_string($body) ? $this->parseCf7Tags($body) : [];

            $mail = $post->metaValue('_mail');

            if (is_array($mail)) {
                $form->notifications[] = [
                    'to' => $mail['recipient'] ?? '',
                    'subject' => $mail['subject'] ?? '',
                    'from' => $mail['sender'] ?? '',
                    'body' => $mail['body'] ?? '',
                    'replyTo' => $mail['additional_headers'] ?? '',
                    'html' => !empty($mail['use_html']),
                ];
            }

            $messages = $post->metaValue('_messages');

            if (is_array($messages)) {
                $form->successMessage = (string)($messages['mail_sent_ok'] ?? '');
            }

            $forms[] = $form;
        }

        return $forms;
    }

    /**
     * Parse Contact Form 7's tag syntax.
     *
     * A CF7 form is markup with tags in it:
     *
     *     <label>Your name [text* your-name placeholder "Jane"]</label>
     *     [submit "Send"]
     *
     * The asterisk means required; the first token is the name; `id:` and `class:` are options;
     * a bare quoted string is a default or a label depending on the tag type; and for choice
     * fields every quoted string after the name is an option.
     *
     * @return WpFormField[]
     */
    public function parseCf7Tags(string $body): array
    {
        $fields = [];

        if (!preg_match_all('/\[([a-z_]+)(\*?)\s*([^\]]*)\]/i', $body, $matches, PREG_SET_ORDER)) {
            return $fields;
        }

        foreach ($matches as $match) {
            $tag = strtolower($match[1]);
            $required = $match[2] === '*';
            $rest = trim($match[3]);

            if (in_array($tag, ['submit', 'response'], true)) {
                continue;
            }

            // The name is the first bare token; everything after it is options.
            $parts = preg_split('/\s+/', $rest) ?: [];
            $name = array_shift($parts) ?? '';

            if ($name === '') {
                continue;
            }

            $field = new WpFormField();
            $field->handle = $this->handle($name);
            $field->label = $this->labelFromName($name);
            $field->required = $required;
            $field->sourceType = $tag;
            $field->type = $this->cf7Type($tag);

            $options = [];

            // Quoted strings carry defaults, placeholders and choice options.
            if (preg_match_all('/"([^"]*)"|\'([^\']*)\'/', $rest, $quoted)) {
                foreach ($quoted[0] as $index => $ignored) {
                    $value = $quoted[1][$index] !== '' ? $quoted[1][$index] : $quoted[2][$index];

                    if ($value !== '') {
                        $options[] = $value;
                    }
                }
            }

            if (in_array($field->type, ['dropdown', 'radio', 'checkboxes'], true)) {
                foreach ($options as $option) {
                    $field->options[] = ['label' => $option, 'value' => $option];
                }
            } elseif ($options !== []) {
                // `placeholder` as a bare option makes the first quoted string a placeholder
                // rather than a default value.
                if (str_contains($rest, 'placeholder')) {
                    $field->placeholder = $options[0];
                } else {
                    $field->defaultValue = $options[0];
                }
            }

            if (preg_match('/\bid:(\S+)/', $rest, $m)) {
                $field->settings['id'] = $m[1];
            }

            if (preg_match('/\bclass:(\S+)/', $rest, $m)) {
                $field->settings['class'] = $m[1];
            }

            if (preg_match('/\bfiletypes:(\S+)/', $rest, $m)) {
                $field->settings['allowedExtensions'] = explode('|', $m[1]);
            }

            $fields[] = $field;
        }

        return $fields;
    }

    private function cf7Type(string $tag): string
    {
        return match ($tag) {
            'text' => 'singleLine',
            'email' => 'email',
            'url' => 'url',
            'tel' => 'phone',
            'number', 'range' => 'number',
            'date' => 'date',
            'textarea' => 'multiLine',
            'select' => 'dropdown',
            'radio' => 'radio',
            'checkbox' => 'checkboxes',
            'acceptance' => 'agree',
            'file' => 'file',
            'hidden' => 'hidden',
            'quiz' => 'singleLine',
            'recaptcha' => 'recaptcha',
            default => 'singleLine',
        };
    }

    // -----------------------------------------------------------------------------------------
    // Gravity Forms
    // -----------------------------------------------------------------------------------------

    /**
     * @return WpForm[]
     */
    private function gravityForms(SourceInterface $source): array
    {
        // Gravity renamed its tables from `rg_` to `gf_` in version 2.3; long-lived sites can
        // still be on the old names.
        $formTable = $source->hasTable('gf_form') ? 'gf_form' : ($source->hasTable('rg_form') ? 'rg_form' : null);

        if ($formTable === null) {
            return [];
        }

        $metaTable = $formTable === 'gf_form' ? 'gf_form_meta' : 'rg_form_meta';
        $forms = [];

        foreach ($source->table($formTable) as $row) {
            $id = (int)($row['id'] ?? 0);

            $form = new WpForm();
            $form->sourceId = (string)$id;
            $form->plugin = 'gravityforms';
            $form->title = (string)($row['title'] ?? 'Form ' . $id);

            $meta = null;

            foreach ($source->table($metaTable, ['form_id' => $id]) as $metaRow) {
                $meta = Json::decodeIfJson((string)($metaRow['display_meta'] ?? ''));
                break;
            }

            if (!is_array($meta)) {
                $forms[] = $form;
                continue;
            }

            $form->description = (string)($meta['description'] ?? '') ?: null;
            $form->submitLabel = (string)($meta['button']['text'] ?? '') ?: null;
            $form->successMessage = (string)($meta['confirmation']['message'] ?? '') ?: null;

            foreach ($meta['fields'] ?? [] as $definition) {
                if (!is_array($definition)) {
                    continue;
                }

                $form->fields[] = $this->gravityField($definition);
            }

            foreach ($meta['notifications'] ?? [] as $notification) {
                if (!is_array($notification)) {
                    continue;
                }

                $form->notifications[] = [
                    'to' => $notification['to'] ?? '',
                    'subject' => $notification['subject'] ?? '',
                    'from' => $notification['from'] ?? '',
                    'body' => $notification['message'] ?? '',
                    'replyTo' => $notification['replyTo'] ?? '',
                    'name' => $notification['name'] ?? '',
                ];
            }

            $form->submissions = $this->gravityEntries($source, $id, $form);

            $forms[] = $form;
        }

        return $forms;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function gravityField(array $definition): WpFormField
    {
        $field = new WpFormField();
        $field->sourceType = (string)($definition['type'] ?? 'text');
        $field->label = (string)($definition['label'] ?? '');
        $field->handle = $this->handle((string)($definition['inputName'] ?? $definition['label'] ?? 'field'));
        $field->required = !empty($definition['isRequired']);
        $field->placeholder = (string)($definition['placeholder'] ?? '') ?: null;
        $field->defaultValue = (string)($definition['defaultValue'] ?? '') ?: null;
        $field->instructions = (string)($definition['description'] ?? '') ?: null;

        $field->type = match ($field->sourceType) {
            'text' => 'singleLine',
            'textarea' => 'multiLine',
            'email' => 'email',
            'phone' => 'phone',
            'number' => 'number',
            'website' => 'url',
            'date' => 'date',
            'time' => 'date',
            'select' => 'dropdown',
            'multiselect' => 'dropdown',
            'radio' => 'radio',
            'checkbox' => 'checkboxes',
            'fileupload' => 'file',
            'hidden' => 'hidden',
            'html' => 'html',
            'section' => 'heading',
            'page' => 'section',
            'name' => 'name',
            'address' => 'address',
            'consent' => 'agree',
            'captcha' => 'recaptcha',
            'product', 'total', 'creditcard' => 'payment',
            default => 'singleLine',
        };

        foreach ($definition['choices'] ?? [] as $choice) {
            if (!is_array($choice)) {
                continue;
            }

            $field->options[] = [
                'label' => (string)($choice['text'] ?? ''),
                'value' => (string)($choice['value'] ?? $choice['text'] ?? ''),
                'default' => !empty($choice['isSelected']),
            ];
        }

        if (!empty($definition['allowedExtensions'])) {
            $field->settings['allowedExtensions'] = explode(',', (string)$definition['allowedExtensions']);
        }

        return $field;
    }

    /**
     * @return WpFormSubmission[]
     */
    private function gravityEntries(SourceInterface $source, int $formId, WpForm $form): array
    {
        if (!$source->hasTable('gf_entry')) {
            return [];
        }

        $submissions = [];
        $entries = [];

        foreach ($source->table('gf_entry', ['form_id' => $formId]) as $row) {
            $id = (int)($row['id'] ?? 0);

            $submission = new WpFormSubmission();
            $submission->sourceId = (string)$id;
            $submission->formSourceId = (string)$formId;
            $submission->date = (string)($row['date_created'] ?? '') ?: null;
            $submission->ip = (string)($row['ip'] ?? '') ?: null;
            $submission->userAgent = (string)($row['user_agent'] ?? '') ?: null;
            $submission->userId = (int)($row['created_by'] ?? 0);
            $submission->status = (string)($row['status'] ?? 'active');

            $entries[$id] = $submission;
        }

        if ($entries === [] || !$source->hasTable('gf_entry_meta')) {
            return array_values($entries);
        }

        // Gravity stores values one row per field, keyed by the field's numeric ID — which is
        // its position in the definition, not its name.
        $byPosition = [];
        $position = 1;

        foreach ($form->fields as $field) {
            $byPosition[(string)$position++] = $field->handle;
        }

        foreach ($source->table('gf_entry_meta', ['form_id' => $formId]) as $row) {
            $entryId = (int)($row['entry_id'] ?? 0);
            $key = (string)($row['meta_key'] ?? '');

            if (!isset($entries[$entryId]) || $key === '') {
                continue;
            }

            // Composite fields use `3.1`, `3.2` for their parts; the whole-number part is the
            // field.
            $base = explode('.', $key)[0];
            $handle = $byPosition[$base] ?? 'field' . $base;

            $value = $row['meta_value'] ?? '';

            if (str_contains($key, '.')) {
                $entries[$entryId]->values[$handle] = array_merge(
                    (array)($entries[$entryId]->values[$handle] ?? []),
                    [$key => $value]
                );
            } else {
                $entries[$entryId]->values[$handle] = $value;
            }
        }

        return array_values($entries);
    }

    // -----------------------------------------------------------------------------------------
    // WPForms
    // -----------------------------------------------------------------------------------------

    /**
     * @return WpForm[]
     */
    private function wpForms(SourceInterface $source): array
    {
        $forms = [];

        foreach ($source->posts('wpforms') as $post) {
            $definition = Json::decodeIfJson($post->content);

            $form = new WpForm();
            $form->sourceId = (string)$post->id;
            $form->plugin = 'wpforms';
            $form->title = $post->title;

            if (!is_array($definition)) {
                $forms[] = $form;
                continue;
            }

            $form->description = (string)($definition['settings']['form_desc'] ?? '') ?: null;
            $form->submitLabel = (string)($definition['settings']['submit_text'] ?? '') ?: null;
            $form->successMessage = (string)($definition['settings']['confirmation_message'] ?? '') ?: null;

            foreach ($definition['fields'] ?? [] as $fieldDefinition) {
                if (!is_array($fieldDefinition)) {
                    continue;
                }

                $field = new WpFormField();
                $field->sourceType = (string)($fieldDefinition['type'] ?? 'text');
                $field->label = (string)($fieldDefinition['label'] ?? '');
                $field->handle = $this->handle($field->label ?: 'field' . ($fieldDefinition['id'] ?? ''));
                $field->required = !empty($fieldDefinition['required']);
                $field->placeholder = (string)($fieldDefinition['placeholder'] ?? '') ?: null;
                $field->instructions = (string)($fieldDefinition['description'] ?? '') ?: null;

                $field->type = match ($field->sourceType) {
                    'text' => 'singleLine',
                    'textarea' => 'multiLine',
                    'email' => 'email',
                    'phone' => 'phone',
                    'number', 'number-slider' => 'number',
                    'url' => 'url',
                    'date-time' => 'date',
                    'select' => 'dropdown',
                    'radio' => 'radio',
                    'checkbox' => 'checkboxes',
                    'file-upload' => 'file',
                    'hidden' => 'hidden',
                    'html' => 'html',
                    'divider' => 'heading',
                    'pagebreak' => 'section',
                    'name' => 'name',
                    'address' => 'address',
                    'gdpr-checkbox' => 'agree',
                    'captcha' => 'recaptcha',
                    'rating' => 'rating',
                    'payment-single', 'payment-total', 'credit-card' => 'payment',
                    default => 'singleLine',
                };

                foreach ($fieldDefinition['choices'] ?? [] as $choice) {
                    if (!is_array($choice)) {
                        continue;
                    }

                    $field->options[] = [
                        'label' => (string)($choice['label'] ?? ''),
                        'value' => (string)($choice['value'] ?? $choice['label'] ?? ''),
                        'default' => !empty($choice['default']),
                    ];
                }

                $form->fields[] = $field;
            }

            foreach ($definition['settings']['notifications'] ?? [] as $notification) {
                if (!is_array($notification)) {
                    continue;
                }

                $form->notifications[] = [
                    'to' => $notification['email'] ?? '',
                    'subject' => $notification['subject'] ?? '',
                    'from' => $notification['sender_address'] ?? '',
                    'body' => $notification['message'] ?? '',
                    'replyTo' => $notification['replyto'] ?? '',
                    'name' => $notification['sender_name'] ?? '',
                ];
            }

            $form->submissions = $this->wpFormsEntries($source, (int)$post->id, $form);

            $forms[] = $form;
        }

        return $forms;
    }

    /**
     * @return WpFormSubmission[]
     */
    private function wpFormsEntries(SourceInterface $source, int $formId, WpForm $form): array
    {
        if (!$source->hasTable('wpforms_entries')) {
            return [];
        }

        $submissions = [];

        foreach ($source->table('wpforms_entries', ['form_id' => $formId]) as $row) {
            $submission = new WpFormSubmission();
            $submission->sourceId = (string)($row['entry_id'] ?? '');
            $submission->formSourceId = (string)$formId;
            $submission->date = (string)($row['date'] ?? '') ?: null;
            $submission->ip = (string)($row['ip_address'] ?? '') ?: null;
            $submission->userAgent = (string)($row['user_agent'] ?? '') ?: null;
            $submission->userId = (int)($row['user_id'] ?? 0);
            $submission->status = (string)($row['status'] ?? '');

            $fields = Json::decodeIfJson((string)($row['fields'] ?? ''));

            if (is_array($fields)) {
                foreach ($fields as $field) {
                    if (!is_array($field)) {
                        continue;
                    }

                    $handle = $this->handle((string)($field['name'] ?? 'field'));
                    $submission->values[$handle] = $field['value'] ?? '';
                }
            }

            $submissions[] = $submission;
        }

        return $submissions;
    }

    // -----------------------------------------------------------------------------------------
    // Ninja Forms
    // -----------------------------------------------------------------------------------------

    /**
     * @return WpForm[]
     */
    private function ninjaForms(SourceInterface $source): array
    {
        if (!$source->hasTable('nf3_forms')) {
            return [];
        }

        $forms = [];

        foreach ($source->table('nf3_forms') as $row) {
            $id = (int)($row['id'] ?? 0);

            $form = new WpForm();
            $form->sourceId = (string)$id;
            $form->plugin = 'ninjaforms';
            $form->title = (string)($row['title'] ?? 'Form ' . $id);

            if ($source->hasTable('nf3_fields')) {
                foreach ($source->table('nf3_fields', ['parent_id' => $id]) as $fieldRow) {
                    $field = new WpFormField();
                    $field->sourceType = (string)($fieldRow['type'] ?? 'textbox');
                    $field->label = (string)($fieldRow['label'] ?? '');
                    $field->handle = $this->handle((string)($fieldRow['key'] ?? $field->label));
                    $field->required = !empty($fieldRow['required']);

                    $field->type = match ($field->sourceType) {
                        'textbox' => 'singleLine',
                        'textarea' => 'multiLine',
                        'email' => 'email',
                        'phone' => 'phone',
                        'number' => 'number',
                        'date' => 'date',
                        'listselect', 'listmultiselect' => 'dropdown',
                        'listradio' => 'radio',
                        'listcheckbox', 'checkbox' => 'checkboxes',
                        'file_upload' => 'file',
                        'hidden' => 'hidden',
                        'html' => 'html',
                        'submit' => 'hidden',
                        'recaptcha' => 'recaptcha',
                        'starrating' => 'rating',
                        default => 'singleLine',
                    };

                    if ($field->sourceType === 'submit') {
                        $form->submitLabel = $field->label;
                        continue;
                    }

                    $form->fields[] = $field;
                }
            }

            $forms[] = $form;
        }

        return $forms;
    }

    // -----------------------------------------------------------------------------------------
    // Formidable
    // -----------------------------------------------------------------------------------------

    /**
     * @return WpForm[]
     */
    private function formidable(SourceInterface $source): array
    {
        if (!$source->hasTable('frm_forms')) {
            return [];
        }

        $forms = [];

        foreach ($source->table('frm_forms') as $row) {
            $id = (int)($row['id'] ?? 0);

            // Formidable stores both forms and their per-form "styles" in the same table;
            // a row with a parent is not a form.
            if (!empty($row['parent_form_id'])) {
                continue;
            }

            $form = new WpForm();
            $form->sourceId = (string)$id;
            $form->plugin = 'formidable';
            $form->title = (string)($row['name'] ?? 'Form ' . $id);
            $form->description = (string)($row['description'] ?? '') ?: null;

            if ($source->hasTable('frm_fields')) {
                foreach ($source->table('frm_fields', ['form_id' => $id]) as $fieldRow) {
                    $field = new WpFormField();
                    $field->sourceType = (string)($fieldRow['type'] ?? 'text');
                    $field->label = (string)($fieldRow['name'] ?? '');
                    $field->handle = $this->handle((string)($fieldRow['field_key'] ?? $field->label));
                    $field->required = !empty($fieldRow['required']);
                    $field->instructions = (string)($fieldRow['description'] ?? '') ?: null;

                    $field->type = match ($field->sourceType) {
                        'text' => 'singleLine',
                        'textarea', 'rte' => 'multiLine',
                        'email' => 'email',
                        'phone' => 'phone',
                        'number' => 'number',
                        'url', 'website' => 'url',
                        'date' => 'date',
                        'select' => 'dropdown',
                        'radio' => 'radio',
                        'checkbox' => 'checkboxes',
                        'file' => 'file',
                        'hidden' => 'hidden',
                        'html' => 'html',
                        'divider' => 'heading',
                        'break' => 'section',
                        'captcha' => 'recaptcha',
                        'star' => 'rating',
                        default => 'singleLine',
                    };

                    $options = WpSerialize::unserialize($fieldRow['options'] ?? null);

                    if (is_array($options)) {
                        foreach ($options as $option) {
                            $label = is_array($option) ? (string)($option['label'] ?? '') : (string)$option;

                            if ($label !== '') {
                                $field->options[] = ['label' => $label, 'value' => $label];
                            }
                        }
                    }

                    $form->fields[] = $field;
                }
            }

            $forms[] = $form;
        }

        return $forms;
    }

    // -----------------------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------------------

    private function handle(string $name): string
    {
        $handle = \craft\helpers\StringHelper::toCamelCase(preg_replace('/[^A-Za-z0-9]+/', ' ', $name) ?? '');

        if ($handle === '') {
            return 'field';
        }

        // Craft field handles must start with a letter, and form field names routinely do not.
        return preg_match('/^[a-zA-Z]/', $handle) ? $handle : 'field' . ucfirst($handle);
    }

    private function labelFromName(string $name): string
    {
        return ucfirst(str_replace(['-', '_'], ' ', $name));
    }
}
