<?php

namespace justinholtweb\passer\importers;

use Craft;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use justinholtweb\passer\models\wp\WpWidget;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\services\IdMap;

/**
 * WordPress widgets become Craft globals or entries.
 *
 * Widgets are the most awkward of the six domains, because half of them are not content at all.
 * A "Recent Posts" widget is a query, a "Search" widget is a form, a "Navigation Menu" widget is
 * a pointer at a menu — none of those have anything to import, and reproducing them is the
 * template's job.
 *
 * So widgets are sorted into three groups. The ones with real content (text, HTML, image,
 * block) are imported. The ones that are configuration (nav menu, recent posts, categories) are
 * described, so a developer knows what the sidebar was supposed to do. The rest are counted.
 */
class WidgetImporter extends BaseImporter
{
    /**
     * Widget types whose content is theirs, rather than generated at render time.
     */
    private const CONTENT_TYPES = ['text', 'custom_html', 'block', 'media_image', 'media_video', 'media_audio', 'rss'];

    /**
     * Widget types that are configuration: worth describing, not importing.
     */
    private const CONFIG_TYPES = [
        'nav_menu' => 'a navigation menu',
        'recent-posts' => 'a list of recent posts',
        'recent-comments' => 'a list of recent comments',
        'categories' => 'a list of categories',
        'archives' => 'a date archive',
        'tag_cloud' => 'a tag cloud',
        'search' => 'a search form',
        'calendar' => 'a calendar',
        'meta' => 'WordPress meta links',
        'pages' => 'a list of pages',
    ];

    public static function phase(): string
    {
        return 'widgets';
    }

    public function import(RunContext $context, array $resumeFrom = []): array
    {
        $domain = $context->plan->domain('widgets');

        if ($domain === null || !$domain->enabled) {
            return [];
        }

        try {
            $widgets = $context->source->widgets();
        } catch (\Throwable $e) {
            $this->warn($context, 'Widgets could not be read from this source: ' . $e->getMessage());

            return [];
        }

        if ($widgets === []) {
            $this->notice($context, 'No widgets were found in the source.');

            return [];
        }

        $context->progress->startPhase(self::phase(), count($widgets));
        $context->map->warm();

        // Grouped by sidebar, because the sidebar — not the individual widget — is the unit a
        // Craft site will want to render.
        $bySidebar = [];

        foreach ($widgets as $widget) {
            $bySidebar[$widget->sidebar][] = $widget;
        }

        // `wp_inactive_widgets` is WordPress's holding pen for widgets removed from a sidebar.
        // They are, by definition, not on the site.
        $inactive = count($bySidebar['wp_inactive_widgets'] ?? []);
        unset($bySidebar['wp_inactive_widgets']);

        $processed = 0;
        $described = 0;

        foreach ($bySidebar as $sidebar => $sidebarWidgets) {
            $this->attempt($context, 'sidebar', $sidebar, $sidebar, function () use ($sidebar, $sidebarWidgets, $domain, $context, &$described) {
                $described += match ($domain->destination) {
                    'global-sets' => $this->writeGlobalSet($sidebar, $sidebarWidgets, $context),
                    'entries' => $this->writeEntries($sidebar, $sidebarWidgets, $domain->options, $context),
                    default => $this->describe($sidebar, $sidebarWidgets, $context),
                };
            });

            $processed += count($sidebarWidgets);
            $context->progress->advance($processed, $sidebar);
        }

        $context->progress->endPhase(self::phase());

        if ($inactive > 0) {
            $this->notice($context, sprintf(
                '%d widget%s were in WordPress\'s inactive list and were not imported — they were '
                . 'not on the site.',
                $inactive,
                $inactive === 1 ? '' : 's'
            ));
        }

        $this->describeConfigWidgets($widgets, $context);

        return [];
    }

    // -----------------------------------------------------------------------------------------
    // Global sets
    // -----------------------------------------------------------------------------------------

    /**
     * @param WpWidget[] $widgets
     */
    private function writeGlobalSet(string $sidebar, array $widgets, RunContext $context): int
    {
        $handle = $this->handleFor($sidebar);
        $set = Craft::$app->getGlobals()->getSetByHandle($handle);

        if ($set === null) {
            $this->warn($context, sprintf(
                'No global set named "%s" exists, so the "%s" sidebar was described in this '
                . 'report rather than imported. Create the global set and re-run this phase.',
                $handle,
                $sidebar
            ));

            return $this->describe($sidebar, $widgets, $context);
        }

        if ($context->dryRun) {
            $context->count(self::phase(), 'created');

            return 0;
        }

        $layout = $set->getFieldLayout();
        $html = $this->renderSidebar($widgets, $context);

        $wrote = false;

        foreach (['content', 'body', 'widgets', 'html'] as $fieldHandle) {
            if ($layout?->getFieldByHandle($fieldHandle) !== null) {
                $set->setFieldValue($fieldHandle, $html);
                $wrote = true;
                break;
            }
        }

        if (!$wrote) {
            $this->warn($context, sprintf(
                'The global set "%s" has no content, body, widgets or html field, so the "%s" '
                . 'sidebar had nowhere to go. Its contents are in this report.',
                $handle,
                $sidebar
            ));

            return $this->describe($sidebar, $widgets, $context);
        }

        $this->save($set, false);

        $context->map->record('sidebar', $sidebar, GlobalSet::class, $set->id, $set->uid, null, null, null, $context->runId);
        $context->count(self::phase(), 'created', count($widgets));

        return 0;
    }

    // -----------------------------------------------------------------------------------------
    // Entries
    // -----------------------------------------------------------------------------------------

    /**
     * @param WpWidget[] $widgets
     * @param array<string, mixed> $options
     */
    private function writeEntries(string $sidebar, array $widgets, array $options, RunContext $context): int
    {
        $sectionHandle = (string)($options['section'] ?? 'widgets');
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);

        if ($section === null) {
            $this->warn($context, sprintf(
                'No section named "%s" exists, so widgets were described in this report rather '
                . 'than imported.',
                $sectionHandle
            ));

            return $this->describe($sidebar, $widgets, $context);
        }

        $entryType = $section->getEntryTypes()[0] ?? null;

        if ($entryType === null) {
            return $this->describe($sidebar, $widgets, $context);
        }

        if ($context->dryRun) {
            $context->count(self::phase(), 'created', count($widgets));

            return 0;
        }

        $transformer = Plugin::getInstance()->contentTransformer;
        $authorId = $context->plan->fallbackAuthorId ?? $this->anyAdminId();

        foreach ($widgets as $widget) {
            if (!in_array($widget->type, self::CONTENT_TYPES, true)) {
                continue;
            }

            $existingId = $context->map->lookup(IdMap::KEY_WIDGET, $sidebar . ':' . $widget->instanceId());
            $entry = $existingId !== null ? Entry::find()->id($existingId)->status(null)->one() : null;

            if ($entry === null) {
                $entry = new Entry();
                $entry->sectionId = $section->id;
                $entry->typeId = $entryType->id;
            }

            $entry->title = $widget->title() !== '' ? $widget->title() : ucfirst($widget->type) . ' widget';
            $entry->authorIds = [$authorId];

            $layout = $entryType->getFieldLayout();
            $body = $this->widgetHtml($widget, $context);

            foreach (['body', 'content', 'html'] as $handle) {
                if ($layout->getFieldByHandle($handle) !== null) {
                    $entry->setFieldValue($handle, $body);
                    break;
                }
            }

            foreach (['sidebar', 'region', 'area'] as $handle) {
                if ($layout->getFieldByHandle($handle) !== null) {
                    $entry->setFieldValue($handle, $widget->sidebarName ?: $sidebar);
                    break;
                }
            }

            $this->save($entry, false);

            $context->map->record(
                IdMap::KEY_WIDGET,
                $sidebar . ':' . $widget->instanceId(),
                Entry::class,
                $entry->id,
                $entry->uid,
                null,
                null,
                null,
                $context->runId
            );

            $context->count(self::phase(), 'created');
        }

        return 0;
    }

    // -----------------------------------------------------------------------------------------
    // Rendering and describing
    // -----------------------------------------------------------------------------------------

    /**
     * @param WpWidget[] $widgets
     */
    private function renderSidebar(array $widgets, RunContext $context): string
    {
        $parts = [];

        foreach ($widgets as $widget) {
            $html = $this->widgetHtml($widget, $context);

            if (trim($html) === '') {
                continue;
            }

            $title = $widget->title();

            $parts[] = \craft\helpers\Html::tag(
                'section',
                ($title !== '' ? \craft\helpers\Html::tag('h2', \craft\helpers\Html::encode($title)) : '') . $html,
                ['class' => 'widget widget-' . $widget->type]
            );
        }

        return implode("\n\n", $parts);
    }

    private function widgetHtml(WpWidget $widget, RunContext $context): string
    {
        $transformer = Plugin::getInstance()->contentTransformer;

        if ($widget->isBlockWidget()) {
            // A block widget's whole content is Gutenberg markup, so it goes through the same
            // pipeline as post content — including URL rewriting and asset resolution.
            $content = (string)($widget->settings['content'] ?? '');

            return $transformer->toHtml($content, $context);
        }

        $content = $widget->content();

        if ($content === '') {
            return '';
        }

        // A text widget's `filter` setting is WordPress's own record of whether it needs
        // paragraph handling — honouring it is the difference between a readable sidebar and
        // one long run-on line.
        $needsAutop = $widget->type === 'text' && !empty($widget->settings['filter']);

        $html = $needsAutop ? $transformer->autop($content) : $content;

        return $transformer->toHtml($html, $context);
    }

    /**
     * @param WpWidget[] $widgets
     */
    private function describe(string $sidebar, array $widgets, RunContext $context): int
    {
        $lines = [];

        foreach ($widgets as $widget) {
            $title = $widget->title();
            $description = self::CONFIG_TYPES[$widget->type] ?? $widget->type;

            $lines[] = sprintf(
                '  %d. %s (%s)%s',
                $widget->order + 1,
                $title !== '' ? $title : 'Untitled',
                $description,
                in_array($widget->type, self::CONTENT_TYPES, true)
                    ? ' — ' . mb_substr(trim(strip_tags($widget->content())), 0, 120)
                    : ''
            );
        }

        $this->notice($context, sprintf(
            "The sidebar \"%s\" contained %d widget%s:\n%s",
            $widgets[0]->sidebarName ?: $sidebar,
            count($widgets),
            count($widgets) === 1 ? '' : 's',
            implode("\n", $lines)
        ));

        return count($widgets);
    }

    /**
     * @param WpWidget[] $widgets
     */
    private function describeConfigWidgets(array $widgets, RunContext $context): void
    {
        $counts = [];

        foreach ($widgets as $widget) {
            if (isset(self::CONFIG_TYPES[$widget->type])) {
                $counts[$widget->type] = ($counts[$widget->type] ?? 0) + 1;
            }
        }

        foreach ($counts as $type => $count) {
            $this->notice($context, sprintf(
                '%d "%s" widget%s were found. These generate their content at render time rather '
                . 'than storing it, so there is nothing to import — reproduce them in your '
                . 'templates.',
                $count,
                self::CONFIG_TYPES[$type],
                $count === 1 ? '' : 's'
            ));
        }
    }

    private function handleFor(string $sidebar): string
    {
        $handle = \craft\helpers\StringHelper::toCamelCase(preg_replace('/[^A-Za-z0-9]+/', ' ', $sidebar) ?? '');

        if ($handle === '' || !preg_match('/^[a-zA-Z]/', $handle)) {
            $handle = 'sidebar' . preg_replace('/\D+/', '', $sidebar);
        }

        return $handle;
    }

    private function anyAdminId(): int
    {
        $admin = \craft\elements\User::find()->admin()->status(null)->orderBy(['id' => SORT_ASC])->one();

        if ($admin === null) {
            throw new \RuntimeException('This Craft install has no admin user to own imported widget entries.');
        }

        return $admin->id;
    }
}
