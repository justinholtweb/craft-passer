<?php

namespace justinholtweb\passer\importers;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use justinholtweb\passer\models\wp\WpMenu;
use justinholtweb\passer\models\wp\WpMenuItem;
use justinholtweb\passer\services\IdMap;

/**
 * WordPress navigation menus become Craft menus.
 *
 * The point is not the menu structure — that is easy — it is the links. A WordPress menu item
 * that points at a page stores that page's ID, and the imported menu should point at the Craft
 * entry, not at a URL on a domain that is about to stop existing. Every item that can be
 * resolved through the ID map becomes a relation; only genuine custom links stay as URLs.
 *
 * Three destinations: FreeNav, Verbb Navigation, and a structure section of link entries that
 * needs nothing installed.
 */
class MenuImporter extends BaseImporter
{
    public static function phase(): string
    {
        return 'menus';
    }

    public function import(RunContext $context, array $resumeFrom = []): array
    {
        $domain = $context->plan->domain('menus');

        if ($domain === null || !$domain->enabled) {
            return [];
        }

        try {
            $menus = $context->source->menus();
        } catch (\Throwable $e) {
            $this->warn($context, 'Menus could not be read from this source: ' . $e->getMessage());

            return [];
        }

        if ($menus === []) {
            $this->notice($context, 'No navigation menus were found in the source.');

            return [];
        }

        $context->progress->startPhase(self::phase(), count($menus));
        $context->map->warm();

        $processed = 0;

        foreach ($menus as $menu) {
            $processed++;

            $this->attempt($context, IdMap::KEY_MENU, $menu->id, $menu->name, function () use ($menu, $domain, $context) {
                $existed = $context->map->has(IdMap::KEY_MENU, $menu->id);

                match ($domain->destination) {
                    'freenav' => $this->writeFreeNav($menu, $context),
                    'verbb-navigation' => $this->writeVerbbNavigation($menu, $context),
                    default => $this->writeStructureSection($menu, $domain->options, $context),
                };

                if ($existed) {
                    // The writers count a creation; correct it once the outcome is known.
                    $context->count(self::phase(), 'created', -1);
                    $context->count(self::phase(), 'updated');
                }
            });

            $context->progress->advance($processed, $menu->name);
        }

        $context->progress->endPhase(self::phase());

        $this->reportLocations($menus, $context);

        return [];
    }

    // -----------------------------------------------------------------------------------------
    // FreeNav
    // -----------------------------------------------------------------------------------------

    private function writeFreeNav(WpMenu $menu, RunContext $context): void
    {
        if ($context->dryRun) {
            $context->count(self::phase(), 'created');

            return;
        }

        $freenav = Craft::$app->getPlugins()->getPlugin('free-nav');

        if ($freenav === null) {
            throw new \RuntimeException('FreeNav is not installed.');
        }

        $menuClass = 'justinholt\\freenav\\models\\Menu';
        $nodeClass = 'justinholt\\freenav\\elements\\Node';

        if (!class_exists($menuClass) || !class_exists($nodeClass)) {
            throw new \RuntimeException('FreeNav is installed but its Menu or Node class could not be loaded.');
        }

        $handle = $this->handleFor($menu);
        $service = $freenav->getMenus();
        $existing = $service->getMenuByHandle($handle);

        $model = $existing ?? new $menuClass();
        $model->name = $menu->name !== '' ? $menu->name : 'Menu';
        $model->handle = $handle;

        if (!$service->saveMenu($model)) {
            throw new \RuntimeException('Could not save the FreeNav menu: ' . implode('; ', $model->getFirstErrors()));
        }

        $context->map->record(
            IdMap::KEY_MENU,
            $menu->id,
            $menuClass,
            $model->id,
            $model->uid ?? null,
            null,
            null,
            null,
            $context->runId
        );

        $this->writeFreeNavNodes($menu->tree(), $model, null, $nodeClass, $context);

        $context->count(self::phase(), 'created');
    }

    /**
     * @param WpMenuItem[] $items
     */
    private function writeFreeNavNodes(array $items, object $menu, ?int $parentId, string $nodeClass, RunContext $context): void
    {
        foreach ($items as $item) {
            // A menu item already imported is updated in place. Without this a second run —
            // which is the normal way a migration is used — duplicates every node in the menu.
            $existingId = $context->map->lookup(IdMap::KEY_MENU_ITEM, $item->id);
            $node = $existingId !== null ? $nodeClass::find()->id($existingId)->status(null)->one() : null;
            $node ??= new $nodeClass();

            $node->menuId = $menu->id;
            $node->title = $this->titleFor($item, $context);
            $node->siteId = $context->plan->defaultSiteId ?? Craft::$app->getSites()->getPrimarySite()->id;

            $target = $this->resolve($item, $context);

            if ($target !== null) {
                // An element node keeps working when the entry's URI changes, which is exactly
                // what a migration does to every URI on the site.
                $node->nodeType = $this->freeNavNodeType($target['type']);
                $node->linkedElementId = $target['id'];
            } else {
                $node->nodeType = 'custom';
                $node->customUrl = $this->urlFor($item, $context);
            }

            $node->newWindow = $item->target === '_blank';

            if ($item->classes !== []) {
                $node->classes = implode(' ', $item->classes);
            }

            if ($parentId !== null) {
                $node->setParentId($parentId);
            }

            $this->save($node, false);

            $context->map->record(
                IdMap::KEY_MENU_ITEM,
                $item->id,
                $nodeClass,
                $node->id,
                $node->uid,
                null,
                null,
                null,
                $context->runId
            );

            if ($item->children !== []) {
                $this->writeFreeNavNodes($item->children, $menu, $node->id, $nodeClass, $context);
            }
        }
    }

    // -----------------------------------------------------------------------------------------
    // Verbb Navigation
    // -----------------------------------------------------------------------------------------

    private function writeVerbbNavigation(WpMenu $menu, RunContext $context): void
    {
        if ($context->dryRun) {
            $context->count(self::phase(), 'created');

            return;
        }

        $plugin = Craft::$app->getPlugins()->getPlugin('navigation');

        if ($plugin === null) {
            throw new \RuntimeException('Verbb Navigation is not installed.');
        }

        $navClass = 'verbb\\navigation\\models\\Nav';
        $nodeClass = 'verbb\\navigation\\elements\\Node';

        if (!class_exists($navClass) || !class_exists($nodeClass)) {
            throw new \RuntimeException('Verbb Navigation is installed but its classes could not be loaded.');
        }

        $handle = $this->handleFor($menu);
        $navs = $plugin->getNavs();
        $nav = $navs->getNavByHandle($handle);

        if ($nav === null) {
            $nav = new $navClass();
            $nav->name = $menu->name !== '' ? $menu->name : 'Menu';
            $nav->handle = $handle;
            $nav->propagateNodes = false;

            $siteSettings = [];

            foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
                $siteSettings[$siteId] = ['enabled' => true];
            }

            $nav->setSiteSettings($siteSettings);

            if (!$navs->saveNav($nav)) {
                throw new \RuntimeException('Could not save the navigation: ' . implode('; ', $nav->getFirstErrors()));
            }
        }

        $context->map->record(IdMap::KEY_MENU, $menu->id, $navClass, $nav->id, $nav->uid ?? null, null, null, null, $context->runId);

        $this->writeVerbbNodes($menu->tree(), $nav, null, $nodeClass, $context);

        $context->count(self::phase(), 'created');
    }

    /**
     * @param WpMenuItem[] $items
     */
    private function writeVerbbNodes(array $items, object $nav, ?int $parentId, string $nodeClass, RunContext $context): void
    {
        foreach ($items as $item) {
            $existingId = $context->map->lookup(IdMap::KEY_MENU_ITEM, $item->id);
            $node = $existingId !== null ? $nodeClass::find()->id($existingId)->status(null)->one() : null;
            $node ??= new $nodeClass();

            $node->navId = $nav->id;
            $node->title = $this->titleFor($item, $context);
            $node->siteId = $context->plan->defaultSiteId ?? Craft::$app->getSites()->getPrimarySite()->id;
            $node->enabled = true;

            $target = $this->resolve($item, $context);

            if ($target !== null) {
                $node->elementId = $target['id'];
                $node->type = $target['type'];
            } else {
                $node->type = 'verbb\\navigation\\nodetypes\\CustomType';
                $node->url = $this->urlFor($item, $context);
            }

            $node->newWindow = $item->target === '_blank';
            $node->classes = $item->classes !== [] ? implode(' ', $item->classes) : null;

            if ($parentId !== null) {
                $node->newParentId = $parentId;
            }

            $this->save($node, false);

            $context->map->record(IdMap::KEY_MENU_ITEM, $item->id, $nodeClass, $node->id, $node->uid, null, null, null, $context->runId);

            if ($item->children !== []) {
                $this->writeVerbbNodes($item->children, $nav, $node->id, $nodeClass, $context);
            }
        }
    }

    // -----------------------------------------------------------------------------------------
    // The plugin-free fallback
    // -----------------------------------------------------------------------------------------

    /**
     * Each menu becomes a branch of a structure section, one entry per item.
     *
     * @param array<string, mixed> $options
     */
    private function writeStructureSection(WpMenu $menu, array $options, RunContext $context): void
    {
        $sectionHandle = (string)($options['section'] ?? 'navigation');
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);

        if ($section === null) {
            throw new \RuntimeException(
                "No section named '$sectionHandle' exists. Create a structure section for "
                . 'navigation, or choose a different destination for menus.'
            );
        }

        if ($section->type !== 'structure') {
            throw new \RuntimeException("The section '$sectionHandle' is not a structure section, so a menu hierarchy cannot be stored in it.");
        }

        $entryType = $section->getEntryTypes()[0] ?? null;

        if ($entryType === null) {
            throw new \RuntimeException("The section '$sectionHandle' has no entry type.");
        }

        if ($context->dryRun) {
            $context->count(self::phase(), 'created');

            return;
        }

        // The menu itself becomes a root entry, so several menus can share one section without
        // their items running together.
        $rootId = $context->map->lookup(IdMap::KEY_MENU, $menu->id);
        $root = $rootId !== null ? Entry::find()->id($rootId)->status(null)->one() : null;

        if ($root === null) {
            $root = new Entry();
            $root->sectionId = $section->id;
            $root->typeId = $entryType->id;
        }

        $root->title = $menu->name !== '' ? $menu->name : 'Menu';
        $root->slug = $this->handleFor($menu);
        $root->authorIds = [$context->plan->fallbackAuthorId ?? $this->anyAdminId()];

        $this->save($root, false);

        $context->map->record(IdMap::KEY_MENU, $menu->id, Entry::class, $root->id, $root->uid, null, null, null, $context->runId);

        $this->writeStructureNodes($menu->tree(), $section, $entryType, $root, $context);

        $context->count(self::phase(), 'created');
    }

    /**
     * @param WpMenuItem[] $items
     */
    private function writeStructureNodes(
        array $items,
        \craft\models\Section $section,
        \craft\models\EntryType $entryType,
        Entry $parent,
        RunContext $context,
    ): void {
        foreach ($items as $item) {
            $existingId = $context->map->lookup(IdMap::KEY_MENU_ITEM, $item->id);
            $entry = $existingId !== null ? Entry::find()->id($existingId)->status(null)->one() : null;

            if ($entry === null) {
                $entry = new Entry();
                $entry->sectionId = $section->id;
                $entry->typeId = $entryType->id;
            }

            $entry->title = $this->titleFor($item, $context);
            $entry->authorIds = $parent->getAuthorIds();
            $entry->setParentId($parent->id);

            $layout = $entryType->getFieldLayout();
            $target = $this->resolve($item, $context);

            // Whatever link field the section happens to have is used; a section with none
            // still gets the structure, and the report says the URLs had nowhere to go.
            foreach (['link', 'url', 'linkUrl', 'nodeLink'] as $handle) {
                if ($layout->getFieldByHandle($handle) === null) {
                    continue;
                }

                $entry->setFieldValue($handle, $target !== null
                    ? ['type' => 'entry', 'value' => $target['id']]
                    : $this->urlFor($item, $context));

                break;
            }

            foreach (['entry', 'linkedEntry', 'target'] as $handle) {
                if ($target !== null && $layout->getFieldByHandle($handle) !== null) {
                    $entry->setFieldValue($handle, [$target['id']]);
                    break;
                }
            }

            $this->save($entry, false);

            $context->map->record(IdMap::KEY_MENU_ITEM, $item->id, Entry::class, $entry->id, $entry->uid, null, null, null, $context->runId);

            if ($item->children !== []) {
                $this->writeStructureNodes($item->children, $section, $entryType, $entry, $context);
            }
        }
    }

    // -----------------------------------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------------------------------

    /**
     * FreeNav names its node types after the element they point at.
     */
    private function freeNavNodeType(string $elementType): string
    {
        return match ($elementType) {
            \craft\elements\Entry::class => 'entry',
            \craft\elements\Category::class => 'category',
            \craft\elements\Asset::class => 'asset',
            'craft\\commerce\\elements\\Product' => 'product',
            // A Craft tag has no FreeNav node type, so it becomes a custom link to the tag's
            // own URL rather than a broken relation.
            default => 'custom',
        };
    }

    /**
     * @return array{id: int, type: class-string}|null
     */
    private function resolve(WpMenuItem $item, RunContext $context): ?array
    {
        if (!$item->isElementLink()) {
            return null;
        }

        $key = $item->mapKey();

        if ($key === null) {
            return null;
        }

        $entry = $context->map->entry($key, $item->objectId);

        if ($entry === null || $entry['destId'] === null) {
            return null;
        }

        return ['id' => $entry['destId'], 'type' => $entry['destType']];
    }

    /**
     * A menu item's title, falling back to the linked element's when WordPress left it blank —
     * which it does whenever the editor never overrode it.
     */
    private function titleFor(WpMenuItem $item, RunContext $context): string
    {
        if ($item->title !== '') {
            return $this->cleanText($item->title);
        }

        $target = $this->resolve($item, $context);

        if ($target !== null) {
            $element = $target['type']::find()->id($target['id'])->status(null)->one();

            if ($element !== null && (string)$element->title !== '') {
                return (string)$element->title;
            }
        }

        return $item->url !== null ? $item->url : 'Link';
    }

    private function urlFor(WpMenuItem $item, RunContext $context): string
    {
        $url = $item->url ?? '';

        if ($url === '') {
            return '#';
        }

        $siteUrl = $context->source->siteUrl();

        // A "custom" link that actually points at the old site is still an internal link and
        // should not survive the migration as an absolute URL.
        if ($siteUrl !== null && str_starts_with($url, rtrim($siteUrl, '/'))) {
            $entry = $context->map->lookupByUrl($url);

            if ($entry !== null && $entry['destId'] !== null) {
                $element = $entry['destType']::find()->id($entry['destId'])->status(null)->one();
                $resolved = $element?->getUrl();

                if ($resolved !== null) {
                    return $resolved;
                }
            }

            $path = parse_url($url, PHP_URL_PATH);

            return is_string($path) ? $path : '/';
        }

        return $url;
    }

    private function handleFor(WpMenu $menu): string
    {
        $base = $menu->slug !== '' ? $menu->slug : $menu->name;
        $handle = \craft\helpers\StringHelper::toCamelCase(preg_replace('/[^A-Za-z0-9]+/', ' ', $base) ?? '');

        if ($handle === '' || !preg_match('/^[a-zA-Z]/', $handle)) {
            $handle = 'menu' . $menu->id;
        }

        return $handle;
    }

    /**
     * @param WpMenu[] $menus
     */
    private function reportLocations(array $menus, RunContext $context): void
    {
        foreach ($menus as $menu) {
            if ($menu->locations === []) {
                continue;
            }

            $this->info($context, sprintf(
                'The menu "%s" was assigned to the theme location%s %s in WordPress. Ask for it '
                . 'by its handle "%s" in your templates.',
                $menu->name,
                count($menu->locations) === 1 ? '' : 's',
                implode(', ', $menu->locations),
                $this->handleFor($menu)
            ));
        }
    }

    private function anyAdminId(): int
    {
        $admin = \craft\elements\User::find()->admin()->status(null)->orderBy(['id' => SORT_ASC])->one();

        if ($admin === null) {
            throw new \RuntimeException('This Craft install has no admin user to own imported menu entries.');
        }

        return $admin->id;
    }
}
