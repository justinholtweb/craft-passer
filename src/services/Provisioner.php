<?php

namespace justinholtweb\passer\services;

use Craft;
use craft\base\Component;
use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\PlainText;
use craft\fields\Tags;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\TagGroup;
use craft\models\UserGroup;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use justinholtweb\passer\models\MigrationPlan;
use justinholtweb\passer\models\plan\PostTypeMapping;
use justinholtweb\passer\models\plan\TaxonomyMapping;
use justinholtweb\passer\models\ProvisionResult;

/**
 * Creates the Craft content model a plan needs.
 *
 * This is the step wp-import leaves to the developer, and it is where most of the tedium of a
 * WordPress migration actually lives: eleven custom post types, forty ACF fields and six
 * taxonomies is a day of clicking before a single post moves.
 *
 * Everything is idempotent — running it twice creates nothing the second time — because the
 * whole plan is designed to be re-run.
 */
class Provisioner extends Component
{
    public function dryRun(MigrationPlan $plan): ProvisionResult
    {
        return $this->run($plan, false);
    }

    public function apply(MigrationPlan $plan): ProvisionResult
    {
        return $this->run($plan, true);
    }

    private function run(MigrationPlan $plan, bool $apply): ProvisionResult
    {
        $result = new ProvisionResult(['applied' => $apply]);

        // Project config writes are queued and flushed together, so a half-provisioned model is
        // not left behind if one step fails.
        $projectConfig = Craft::$app->getProjectConfig();
        $wasMuted = $projectConfig->muteEvents;

        // Each step is isolated: a category group Craft refuses must not stop the sections
        // being created, because the run that follows needs them and the user can fix one
        // group far more easily than they can work out why nothing at all appeared.
        $steps = [
            'fields' => fn() => $this->provisionFields($plan, $result, $apply),
            'user groups' => fn() => $this->provisionUserGroups($plan, $result, $apply),
            'taxonomies' => fn() => $this->provisionTaxonomies($plan, $result, $apply),
            'sections' => fn() => $this->provisionSections($plan, $result, $apply),
        ];

        try {
            foreach ($steps as $label => $step) {
                try {
                    $step();
                } catch (\Throwable $e) {
                    $result->errors[] = sprintf('Could not provision %s: %s', $label, $e->getMessage());
                }
            }
        } finally {
            $projectConfig->muteEvents = $wasMuted;
        }

        return $result;
    }

    // -----------------------------------------------------------------------------------------
    // Fields
    // -----------------------------------------------------------------------------------------

    /**
     * @return array<string, string> Handle => field type, gathered from every mapping.
     */
    private function requiredFields(MigrationPlan $plan): array
    {
        $required = [];

        foreach ($plan->postTypes as $mapping) {
            if (!$mapping->enabled) {
                continue;
            }

            foreach ($mapping->createFields as $handle => $type) {
                $required[$handle] = $type;
            }

            if ($mapping->createContentField && $mapping->contentField !== null) {
                // A CKEditor field is only createable when CKEditor is installed; the plan's
                // content format already reflects that, so this reads it rather than re-deciding.
                $required[$mapping->contentField] = $this->contentFieldClass($mapping);
            }
        }

        foreach ($plan->taxonomies as $mapping) {
            if (!$mapping->enabled) {
                continue;
            }

            foreach ($mapping->createFields as $handle => $type) {
                $required[$handle] = $type;
            }
        }

        foreach ($this->seoFallbackFields($plan) as $handle => $type) {
            $required[$handle] = $type;
        }

        return $required;
    }

    /**
     * The plain fields the SEO phase writes to when it has no SEO plugin to write to.
     *
     * Without these the fallback destination has nowhere to put anything, which is the one way
     * a "needs no plugin" option could still fail.
     *
     * @return array<string, class-string>
     */
    private function seoFallbackFields(MigrationPlan $plan): array
    {
        $domain = $plan->domain('seo');

        if ($domain === null || !$domain->enabled) {
            return [];
        }

        // SEOmatic and ether/seo hold their own metadata; only the fallback needs fields, and a
        // site with SEOmatic installed but no SEO Settings field on an entry type falls back to
        // these too, so they are created whenever SEOmatic is not writable per entry.
        if (!in_array($domain->destination, ['native-fields', 'sprout-seo', 'seomatic'], true)) {
            return [];
        }

        return [
            'metaTitle' => PlainText::class,
            'metaDescription' => PlainText::class,
            'metaCanonical' => PlainText::class,
            'metaRobots' => PlainText::class,
            'ogTitle' => PlainText::class,
            'ogDescription' => PlainText::class,
            'ogImage' => Assets::class,
        ];
    }

    private function contentFieldClass(PostTypeMapping $mapping): string
    {
        if ($mapping->contentFormat === 'ckeditor' && class_exists('craft\ckeditor\Field')) {
            return 'craft\ckeditor\Field';
        }

        return PlainText::class;
    }

    private function provisionFields(MigrationPlan $plan, ProvisionResult $result, bool $apply): void
    {
        $fields = Craft::$app->getFields();

        foreach ($this->requiredFields($plan) as $handle => $type) {
            if ($fields->getFieldByHandle($handle) !== null) {
                $result->addExisting('field', $handle, $handle);
                continue;
            }

            if (!class_exists($type)) {
                $result->errors[] = "Cannot create the field '$handle': the field type $type is not installed.";
                continue;
            }

            $result->add('field', $handle, $this->labelFor($handle), $this->fieldTypeLabel($type));

            if (!$apply) {
                continue;
            }

            $field = $fields->createField([
                'type' => $type,
                'name' => $this->labelFor($handle),
                'handle' => $handle,
            ]);

            $this->configureField($field, $plan);

            if (!$fields->saveField($field)) {
                $result->errors[] = sprintf(
                    "Could not create the field '%s': %s",
                    $handle,
                    implode('; ', $field->getFirstErrors())
                );
            }
        }
    }

    /**
     * Give a newly created field the settings that make it usable, since Craft's defaults for a
     * relation field point at nothing at all.
     */
    private function configureField(\craft\base\FieldInterface $field, MigrationPlan $plan): void
    {
        if ($field instanceof PlainText) {
            // WordPress meta is untyped and frequently long; a single-line default would
            // truncate it on save.
            $field->multiline = true;
            $field->initialRows = 4;

            return;
        }

        if ($field instanceof Assets) {
            $volume = $plan->volume !== '' ? Craft::$app->getVolumes()->getVolumeByHandle($plan->volume) : null;

            if ($volume !== null) {
                $field->sources = ['volume:' . $volume->uid];
            }

            $field->maxRelations = 1;

            return;
        }

        if ($field instanceof Categories) {
            $group = $this->groupForField($field->handle, $plan);

            if ($group !== null) {
                $field->source = 'group:' . $group->uid;
            }

            return;
        }

        if ($field instanceof Tags) {
            $handle = $field->handle;
            $group = Craft::$app->getTags()->getTagGroupByHandle($handle);

            if ($group !== null) {
                $field->source = 'taggroup:' . $group->uid;
            }
        }
    }

    private function groupForField(string $handle, MigrationPlan $plan): ?CategoryGroup
    {
        foreach ($plan->taxonomies as $mapping) {
            if ($mapping->handle === $handle && $mapping->destination === 'categories') {
                return Craft::$app->getCategories()->getGroupByHandle($mapping->handle);
            }
        }

        return Craft::$app->getCategories()->getGroupByHandle($handle);
    }

    // -----------------------------------------------------------------------------------------
    // User groups
    // -----------------------------------------------------------------------------------------

    private function provisionUserGroups(MigrationPlan $plan, ProvisionResult $result, bool $apply): void
    {
        if (!$plan->importUsers) {
            return;
        }

        $service = Craft::$app->getUserGroups();

        foreach ($plan->createUserGroups as $handle => $name) {
            if ($service->getGroupByHandle($handle) !== null) {
                $result->addExisting('userGroup', $handle, $name);
                continue;
            }

            $result->add('userGroup', $handle, $name);

            if (!$apply) {
                continue;
            }

            $group = new UserGroup(['name' => $name, 'handle' => $handle]);

            if (!$service->saveGroup($group)) {
                $result->errors[] = sprintf(
                    "Could not create the user group '%s': %s",
                    $handle,
                    implode('; ', $group->getFirstErrors())
                );
            }
        }
    }

    // -----------------------------------------------------------------------------------------
    // Taxonomies
    // -----------------------------------------------------------------------------------------

    private function provisionTaxonomies(MigrationPlan $plan, ProvisionResult $result, bool $apply): void
    {
        foreach ($plan->enabledTaxonomies() as $mapping) {
            match ($mapping->destination) {
                'categories' => $this->provisionCategoryGroup($mapping, $plan, $result, $apply),
                'tags' => $this->provisionTagGroup($mapping, $plan, $result, $apply),
                default => null,
            };
        }
    }

    private function provisionCategoryGroup(
        TaxonomyMapping $mapping,
        MigrationPlan $plan,
        ProvisionResult $result,
        bool $apply,
    ): void {
        $service = Craft::$app->getCategories();

        if ($service->getGroupByHandle($mapping->handle) !== null) {
            $result->addExisting('categoryGroup', $mapping->handle, $mapping->name);
            return;
        }

        $result->add('categoryGroup', $mapping->handle, $mapping->name, 'from ' . $mapping->taxonomy);

        if (!$apply) {
            return;
        }

        $group = new CategoryGroup([
            'name' => $mapping->name,
            'handle' => $mapping->handle,
        ]);

        // Craft refuses to save a category group that lacks settings for *every* site in the
        // install — not just the ones being imported into. Sections are not like this, which is
        // why the plan's own site list is right for them and wrong here. Sites outside the plan
        // get the group with URLs switched off, so it exists without inventing routes.
        $wanted = $this->siteIdsFor($mapping->siteIds, $plan);
        $siteSettings = [];

        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            $inPlan = in_array($siteId, $wanted, true);

            $siteSettings[$siteId] = new CategoryGroup_SiteSettings([
                'siteId' => $siteId,
                'hasUrls' => $inPlan,
                'uriFormat' => $inPlan ? $mapping->handle . '/{slug}' : null,
                'template' => $inPlan ? $mapping->handle . '/_entry' : null,
            ]);
        }

        $group->setSiteSettings($siteSettings);
        $group->setFieldLayout($this->layoutFor($mapping->fieldMap, \craft\elements\Category::class));

        try {
            if (!$service->saveGroup($group)) {
                $result->errors[] = sprintf(
                    "Could not create the category group '%s': %s",
                    $mapping->handle,
                    implode('; ', $group->getFirstErrors())
                );
            }
        } catch (\Throwable $e) {
            // Craft throws rather than failing validation for some of these.
            $result->errors[] = sprintf(
                "Could not create the category group '%s': %s",
                $mapping->handle,
                $e->getMessage()
            );
        }
    }

    private function provisionTagGroup(
        TaxonomyMapping $mapping,
        MigrationPlan $plan,
        ProvisionResult $result,
        bool $apply,
    ): void {
        $service = Craft::$app->getTags();

        if ($service->getTagGroupByHandle($mapping->handle) !== null) {
            $result->addExisting('tagGroup', $mapping->handle, $mapping->name);
            return;
        }

        $result->add('tagGroup', $mapping->handle, $mapping->name, 'from ' . $mapping->taxonomy);

        if (!$apply) {
            return;
        }

        $group = new TagGroup(['name' => $mapping->name, 'handle' => $mapping->handle]);
        $group->setFieldLayout($this->layoutFor($mapping->fieldMap, \craft\elements\Tag::class));

        try {
            if (!$service->saveTagGroup($group)) {
                $result->errors[] = sprintf(
                    "Could not create the tag group '%s': %s",
                    $mapping->handle,
                    implode('; ', $group->getFirstErrors())
                );
            }
        } catch (\Throwable $e) {
            $result->errors[] = sprintf("Could not create the tag group '%s': %s", $mapping->handle, $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------------------------
    // Sections and entry types
    // -----------------------------------------------------------------------------------------

    private function provisionSections(MigrationPlan $plan, ProvisionResult $result, bool $apply): void
    {
        $entries = Craft::$app->getEntries();

        foreach ($plan->enabledPostTypes() as $mapping) {
            $entryType = $this->provisionEntryType($mapping, $plan, $result, $apply);

            if ($entries->getSectionByHandle($mapping->section) !== null) {
                $result->addExisting('section', $mapping->section, $mapping->sectionName);
                continue;
            }

            $result->add('section', $mapping->section, $mapping->sectionName, $mapping->sectionType . ', from ' . $mapping->postType);

            if (!$apply) {
                continue;
            }

            if ($entryType === null) {
                $result->errors[] = "Could not create the section '{$mapping->section}' without its entry type.";
                continue;
            }

            $section = new Section([
                'name' => $mapping->sectionName,
                'handle' => $mapping->section,
                'type' => $mapping->sectionType,
            ]);

            $siteSettings = [];

            foreach ($this->siteIdsFor($mapping->siteIds, $plan) as $siteId) {
                $siteSettings[$siteId] = new Section_SiteSettings([
                    'siteId' => $siteId,
                    'enabledByDefault' => true,
                    'hasUrls' => true,
                    // WordPress permalinks are almost always `/slug` for pages and
                    // `/<type>/slug` for everything else. The importer overrides the URI per
                    // entry when `preserveUris` is on; this is the shape for anything new.
                    'uriFormat' => $mapping->postType === 'page' ? '{slug}' : $mapping->section . '/{slug}',
                    'template' => $mapping->section . '/_entry',
                ]);
            }

            $section->setSiteSettings($siteSettings);
            $section->setEntryTypes([$entryType]);

            try {
                if (!$entries->saveSection($section)) {
                    $result->errors[] = sprintf(
                        "Could not create the section '%s': %s",
                        $mapping->section,
                        implode('; ', $section->getFirstErrors())
                    );
                }
            } catch (\Throwable $e) {
                $result->errors[] = sprintf("Could not create the section '%s': %s", $mapping->section, $e->getMessage());
            }
        }
    }

    private function provisionEntryType(PostTypeMapping $mapping, MigrationPlan $plan, ProvisionResult $result, bool $apply): ?EntryType
    {
        $entries = Craft::$app->getEntries();
        $existing = $entries->getEntryTypeByHandle($mapping->entryType);

        if ($existing !== null) {
            $result->addExisting('entryType', $mapping->entryType, $existing->name);

            // An entry type that already exists still needs any field the plan has since gained
            // — a new meta mapping, or the SEO fallback fields. Skipping it outright means
            // adding a mapping and re-provisioning silently does nothing, and the import phase
            // then finds no field to write to.
            $this->addMissingFields($existing, $mapping, $plan, $result, $apply);

            return $existing;
        }

        $result->add('entryType', $mapping->entryType, $mapping->entryTypeName, 'from ' . $mapping->postType);

        if (!$apply) {
            return null;
        }

        $entryType = new EntryType([
            'name' => $mapping->entryTypeName,
            'handle' => $mapping->entryType,
            'hasTitleField' => true,
        ]);

        $entryType->setFieldLayout($this->layoutFor($this->handlesFor($mapping, $plan), \craft\elements\Entry::class, true));

        try {
            if (!$entries->saveEntryType($entryType)) {
                $result->errors[] = sprintf(
                    "Could not create the entry type '%s': %s",
                    $mapping->entryType,
                    implode('; ', $entryType->getFirstErrors())
                );

                return null;
            }
        } catch (\Throwable $e) {
            $result->errors[] = sprintf("Could not create the entry type '%s': %s", $mapping->entryType, $e->getMessage());

            return null;
        }

        return $entryType;
    }

    /**
     * Append fields the plan wants to an entry type that already has a layout, leaving
     * everything the site has arranged by hand exactly where it is.
     */
    private function addMissingFields(
        EntryType $entryType,
        PostTypeMapping $mapping,
        MigrationPlan $plan,
        ProvisionResult $result,
        bool $apply,
    ): void {
        $layout = $entryType->getFieldLayout();
        $present = [];

        foreach ($layout->getCustomFields() as $field) {
            $present[$field->handle] = true;
        }

        $fields = Craft::$app->getFields();
        $missing = [];

        foreach ($this->handlesFor($mapping, $plan) as $handle) {
            if ($handle === '' || isset($present[$handle])) {
                continue;
            }

            $field = $fields->getFieldByHandle($handle);

            if ($field !== null) {
                $missing[] = $field;
                $present[$handle] = true;
            }
        }

        if ($missing === []) {
            return;
        }

        $result->add('entryTypeFields', $entryType->handle, $entryType->name, sprintf(
            'adding %s',
            implode(', ', array_map(static fn($f) => $f->handle, $missing))
        ));

        if (!$apply) {
            return;
        }

        $tabs = $layout->getTabs();
        $lastTab = end($tabs);

        if ($lastTab === false) {
            $layout->setTabs([['name' => 'Content', 'elements' => array_map(static fn($f) => new CustomField($f), $missing)]]);
        } else {
            $elements = $lastTab->getElements();

            foreach ($missing as $field) {
                $elements[] = new CustomField($field);
            }

            $lastTab->setElements($elements);
            $layout->setTabs($tabs);
        }

        $entryType->setFieldLayout($layout);

        try {
            if (!$entries = Craft::$app->getEntries()->saveEntryType($entryType)) {
                $result->errors[] = sprintf(
                    "Could not add fields to the entry type '%s': %s",
                    $entryType->handle,
                    implode('; ', $entryType->getFirstErrors())
                );
            }
        } catch (\Throwable $e) {
            $result->errors[] = sprintf("Could not add fields to the entry type '%s': %s", $entryType->handle, $e->getMessage());
        }
    }

    /**
     * Every field handle a post-type mapping needs on its entry type.
     *
     * @return string[]
     */
    private function handlesFor(PostTypeMapping $mapping, MigrationPlan $plan): array
    {
        $handles = array_values($mapping->fieldMap);

        foreach ([$mapping->contentField, $mapping->excerptField, $mapping->featuredImageField] as $handle) {
            if ($handle !== null && $handle !== '') {
                $handles[] = $handle;
            }
        }

        foreach ($mapping->taxonomyFields as $handle) {
            $handles[] = $handle;
        }

        foreach (array_keys($this->seoFallbackFields($plan)) as $handle) {
            $handles[] = $handle;
        }

        return array_values(array_unique($handles));
    }

    /**
     * Build a field layout from a list of handles, skipping any field that does not exist.
     *
     * @param string[] $handles
     * @param class-string $elementType
     */
    private function layoutFor(array $handles, string $elementType, bool $withTitle = false): FieldLayout
    {
        $fields = Craft::$app->getFields();
        $elements = [];

        if ($withTitle) {
            $elements[] = new EntryTitleField();
        }

        foreach (array_unique(array_values($handles)) as $handle) {
            if ($handle === '' ) {
                continue;
            }

            $field = $fields->getFieldByHandle((string)$handle);

            if ($field === null) {
                continue;
            }

            $elements[] = new CustomField($field);
        }

        $layout = new FieldLayout(['type' => $elementType]);

        // The tab is handed over as an array, not a FieldLayoutTab. Craft's setTabs() puts the
        // layout into an array tab's config before constructing it; a pre-built tab has its
        // elements set during construction, before setLayout() ever runs, and any element that
        // asks for its layout then throws "Field layout tab is missing its field layout".
        $layout->setTabs([['name' => 'Content', 'elements' => $elements]]);

        return $layout;
    }

    /**
     * @param int[] $siteIds
     * @return int[]
     */
    private function siteIdsFor(array $siteIds, MigrationPlan $plan): array
    {
        if ($siteIds !== []) {
            return $siteIds;
        }

        if ($plan->languageMap !== []) {
            return array_values(array_unique(array_map('intval', $plan->languageMap)));
        }

        return [$plan->defaultSiteId ?? Craft::$app->getSites()->getPrimarySite()->id];
    }

    private function labelFor(string $handle): string
    {
        return ucfirst(trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $handle) ?? $handle));
    }

    private function fieldTypeLabel(string $class): string
    {
        $parts = explode('\\', $class);

        return (string)end($parts);
    }
}
