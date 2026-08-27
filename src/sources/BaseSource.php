<?php

namespace justinholtweb\passer\sources;

use craft\base\Component;
use justinholtweb\passer\models\wp\WpMenu;
use justinholtweb\passer\models\wp\WpPost;
use justinholtweb\passer\models\wp\WpWidget;

/**
 * Shared behaviour for the four source modes.
 *
 * Everything here is either a default that says "this mode cannot do that" or a piece of
 * WordPress interpretation that is identical regardless of where the bytes came from — widget
 * assembly, menu-item meta reading, the source fingerprint.
 */
abstract class BaseSource extends Component implements SourceInterface
{
    protected bool $connected = false;
    protected ?SourceCapabilities $capabilities = null;

    public static function displayName(): string
    {
        return static::type();
    }

    public function capabilities(): SourceCapabilities
    {
        return $this->capabilities ??= $this->defineCapabilities();
    }

    abstract protected function defineCapabilities(): SourceCapabilities;

    public function close(): void
    {
        $this->connected = false;
    }

    public function sourceHash(): string
    {
        return sha1($this->fingerprint());
    }

    /**
     * The string the source fingerprint is derived from. Subclasses that know the site URL
     * should return it, so the same site reached two different ways maps to one namespace.
     */
    abstract protected function fingerprint(): string;

    public function post(int $id): ?WpPost
    {
        throw new UnsupportedOperationException(
            static::displayName() . ' cannot fetch a single post by ID.'
        );
    }

    public function option(string $name): mixed
    {
        throw new UnsupportedOperationException(
            static::displayName() . ' cannot read WordPress options.'
        );
    }

    public function optionsLike(string $prefix): array
    {
        throw new UnsupportedOperationException(
            static::displayName() . ' cannot read WordPress options.'
        );
    }

    public function menus(): array
    {
        throw new UnsupportedOperationException(
            static::displayName() . ' cannot read navigation menus.'
        );
    }

    public function widgets(): array
    {
        throw new UnsupportedOperationException(
            static::displayName() . ' cannot read widgets.'
        );
    }

    public function table(string $table, array $where = [], int $offset = 0, ?int $limit = null): \Generator
    {
        throw new UnsupportedOperationException(
            static::displayName() . ' cannot read arbitrary tables.'
        );

        // Unreachable, but PHP needs a yield for this to be a Generator at all.
        yield from [];
    }

    public function hasTable(string $table): bool
    {
        return false;
    }

    protected function requireConnection(): void
    {
        if (!$this->connected) {
            $this->connect();
        }
    }

    /**
     * Assemble widget instances from the two halves WordPress stores them in.
     *
     * @param array<string, mixed> $sidebarsWidgets The `sidebars_widgets` option.
     * @param array<string, mixed> $widgetOptions Every `widget_*` option, keyed by option name.
     * @param array<string, string> $sidebarNames Registered sidebar names, when knowable.
     * @return WpWidget[]
     */
    protected function assembleWidgets(
        array $sidebarsWidgets,
        array $widgetOptions,
        array $sidebarNames = [],
    ): array {
        $widgets = [];

        foreach ($sidebarsWidgets as $sidebar => $instanceIds) {
            // WordPress keeps a bookkeeping key here that is not a sidebar.
            if ($sidebar === 'array_version' || !is_array($instanceIds)) {
                continue;
            }

            $order = 0;

            foreach ($instanceIds as $instanceId) {
                if (!is_string($instanceId)) {
                    continue;
                }

                // Instance IDs are `<type>-<number>`, and the type itself may contain hyphens.
                if (!preg_match('/^(.*)-(\d+)$/', $instanceId, $m)) {
                    continue;
                }

                $type = $m[1];
                $number = (int)$m[2];
                $settings = $widgetOptions['widget_' . $type][$number] ?? null;

                if (!is_array($settings)) {
                    // An instance listed in a sidebar with no settings row is orphaned — Woo,
                    // theme switches and plugin removals all leave these behind. Skip silently;
                    // there is nothing to import.
                    continue;
                }

                $widget = new WpWidget();
                $widget->type = $type;
                $widget->number = $number;
                $widget->sidebar = $sidebar;
                $widget->sidebarName = $sidebarNames[$sidebar] ?? $sidebar;
                $widget->order = $order++;
                $widget->settings = $settings;

                $widgets[] = $widget;
            }
        }

        return $widgets;
    }

    /**
     * Read a `nav_menu_item` post's `_menu_item_*` meta into a WpMenuItem.
     */
    protected function menuItemFromPost(WpPost $post): \justinholtweb\passer\models\wp\WpMenuItem
    {
        $item = new \justinholtweb\passer\models\wp\WpMenuItem();
        $item->id = $post->id;
        $item->order = $post->menuOrder;
        $item->parentItemId = (int)$post->metaValue('_menu_item_menu_item_parent', 0);
        $item->objectType = (string)$post->metaValue('_menu_item_type', 'custom');
        $item->object = (string)$post->metaValue('_menu_item_object', '');
        $item->objectId = (int)$post->metaValue('_menu_item_object_id', 0);
        $item->target = (string)$post->metaValue('_menu_item_target', '');
        $item->attrTitle = (string)$post->metaValue('_menu_item_attr_title', '');
        $item->xfn = $post->metaValue('_menu_item_xfn') ?: null;
        $item->description = $post->excerpt;

        $classes = $post->metaValue('_menu_item_classes');
        $item->classes = array_values(array_filter(
            is_array($classes) ? $classes : [],
            static fn($c) => is_string($c) && $c !== ''
        ));

        if ($item->objectType === 'custom') {
            $item->url = $post->metaValue('_menu_item_url') ?: null;
        }

        // WordPress leaves the item title empty when it should inherit the linked object's
        // title, which is why so many exported menus look like they have blank items.
        $item->title = $post->title !== '' ? $post->title : ($item->attrTitle ?: '');

        return $item;
    }

    /**
     * Attach items to menus and record each menu's theme locations.
     *
     * @param WpMenu[] $menus
     * @param array<string, int> $locations The `nav_menu_locations` theme mod: location => term ID.
     * @return WpMenu[]
     */
    protected function applyMenuLocations(array $menus, array $locations): array
    {
        foreach ($menus as $menu) {
            foreach ($locations as $location => $termId) {
                if ((int)$termId === $menu->id) {
                    $menu->locations[] = (string)$location;
                }
            }
        }

        return $menus;
    }
}
