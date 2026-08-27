<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;

/**
 * One `nav_menu_item` post, with its `_menu_item_*` meta already interpreted.
 */
class WpMenuItem extends Model
{
    public int $id = 0;
    public string $title = '';
    public int $order = 0;
    public int $parentItemId = 0;

    /** @var string post_type|taxonomy|custom|post_type_archive */
    public string $objectType = 'custom';

    /** @var string The specific post type or taxonomy name, e.g. `page`, `category`. */
    public string $object = '';

    /** @var int The ID of the linked post or term. Zero for custom links. */
    public int $objectId = 0;

    /** @var string|null For custom links, the raw URL. */
    public ?string $url = null;

    public string $target = '';
    public string $attrTitle = '';
    public string $description = '';

    /** @var string[] CSS classes WordPress stored on the item. */
    public array $classes = [];

    public ?string $xfn = null;

    /** @var WpMenuItem[] */
    public array $children = [];

    /**
     * True when this item points at content Passer will have imported, and so should become a
     * relation rather than a bare URL.
     */
    public function isElementLink(): bool
    {
        return in_array($this->objectType, ['post_type', 'taxonomy'], true) && $this->objectId > 0;
    }

    /**
     * The ID map key this item's target would have been recorded under.
     */
    public function mapKey(): ?string
    {
        return match ($this->objectType) {
            'post_type' => 'post',
            'taxonomy' => 'term',
            default => null,
        };
    }
}
