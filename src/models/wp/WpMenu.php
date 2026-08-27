<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;

/**
 * A WordPress navigation menu.
 *
 * Menus are a taxonomy (`nav_menu`) whose terms are the menus and whose posts (`nav_menu_item`)
 * are the items — which is why no source that cannot read post meta can read menus, and why the
 * REST source can only read them on sites running WordPress 5.9+ with the menus endpoint enabled.
 */
class WpMenu extends Model
{
    public int $id = 0;
    public string $name = '';
    public string $slug = '';

    /**
     * Theme locations this menu is assigned to (`primary`, `footer`, …), read from the
     * `nav_menu_locations` theme mod. Useful because the location, not the menu name, is what a
     * Craft template will want to ask for.
     *
     * @var string[]
     */
    public array $locations = [];

    /** @var WpMenuItem[] Flat list, in menu_order. Hierarchy lives in each item's parentItemId. */
    public array $items = [];

    /**
     * Items reorganised into a tree, each item gaining a `children` array.
     *
     * @return WpMenuItem[]
     */
    public function tree(): array
    {
        /** @var array<int, WpMenuItem> $byId */
        $byId = [];
        foreach ($this->items as $item) {
            $item->children = [];
            $byId[$item->id] = $item;
        }

        $roots = [];

        foreach ($this->items as $item) {
            if ($item->parentItemId && isset($byId[$item->parentItemId])) {
                $byId[$item->parentItemId]->children[] = $item;
            } else {
                $roots[] = $item;
            }
        }

        return $roots;
    }
}
