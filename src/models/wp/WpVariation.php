<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;

/**
 * A `product_variation` post belonging to a variable product.
 */
class WpVariation extends Model
{
    public int $id = 0;
    public int $parentId = 0;
    public string $title = '';
    public ?string $description = null;
    public int $menuOrder = 0;
    public string $status = 'publish';

    public ?string $sku = null;
    public ?string $price = null;
    public ?string $regularPrice = null;
    public ?string $salePrice = null;

    public bool $manageStock = false;
    public ?int $stockQuantity = null;
    public string $stockStatus = 'instock';

    public ?float $weight = null;
    public ?float $length = null;
    public ?float $width = null;
    public ?float $height = null;

    public bool $virtual = false;
    public bool $downloadable = false;

    public ?int $imageId = null;

    /**
     * The attribute values that define this variation, as attribute name => value.
     * An empty value means "any", which Woo allows and Craft Commerce has no equivalent for.
     *
     * @var array<string, string>
     */
    public array $attributes = [];

    /** @var array<string, mixed> */
    public array $meta = [];

    /**
     * True when this variation matches "any" for at least one attribute, which cannot be
     * represented as a distinct Commerce variant and must be reported rather than guessed at.
     */
    public function hasAnyAttribute(): bool
    {
        foreach ($this->attributes as $value) {
            if ($value === '') {
                return true;
            }
        }

        return false;
    }
}
