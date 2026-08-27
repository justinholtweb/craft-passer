<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;

/**
 * A WooCommerce product.
 *
 * Woo products are `product` posts whose real data is scattered across `wp_postmeta`, the
 * `wc_product_meta_lookup` table, product taxonomies, and — for variable products — a second
 * generation of `product_variation` posts. This model is the assembled result.
 */
class WpProduct extends Model
{
    public int $id = 0;
    public string $title = '';
    public string $slug = '';
    public string $description = '';
    public string $shortDescription = '';
    public string $status = 'publish';
    public ?string $date = null;
    public ?string $modified = null;

    /** @var string simple|variable|grouped|external|subscription|bundle */
    public string $productType = 'simple';

    public ?string $sku = null;
    public ?string $price = null;
    public ?string $regularPrice = null;
    public ?string $salePrice = null;
    public ?string $saleStart = null;
    public ?string $saleEnd = null;

    public bool $manageStock = false;
    public ?int $stockQuantity = null;

    /** @var string instock|outofstock|onbackorder */
    public string $stockStatus = 'instock';

    public ?string $backorders = 'no';

    public ?float $weight = null;
    public ?float $length = null;
    public ?float $width = null;
    public ?float $height = null;

    public bool $virtual = false;
    public bool $downloadable = false;

    public ?string $taxStatus = 'taxable';
    public ?string $taxClass = null;

    public bool $featured = false;
    public string $catalogVisibility = 'visible';

    /** @var int|null Attachment ID of the main product image. */
    public ?int $imageId = null;

    /** @var int[] Attachment IDs of the gallery. */
    public array $galleryIds = [];

    /** @var array<string, string[]> Product categories and tags, taxonomy => term slugs. */
    public array $terms = [];

    /**
     * Product attributes, keyed by attribute name. Global attributes (`pa_colour`) are
     * taxonomy-backed; local ones are stored inline. `variation` marks the ones that variations
     * are actually built from, which is the distinction Craft Commerce needs.
     *
     * @var array<string, array{name: string, options: string[], visible: bool, variation: bool, taxonomy: bool}>
     */
    public array $attributes = [];

    /** @var WpVariation[] */
    public array $variations = [];

    /** @var int[] */
    public array $upsellIds = [];

    /** @var int[] */
    public array $crossSellIds = [];

    /** @var int[] Children of a grouped product. */
    public array $groupedIds = [];

    public ?string $externalUrl = null;
    public ?string $buttonText = null;

    /** @var array<string, mixed> */
    public array $meta = [];

    public function isVariable(): bool
    {
        return $this->productType === 'variable' && $this->variations !== [];
    }

    /**
     * Attributes that variations vary on, in the order Woo listed them.
     *
     * @return array<string, array{name: string, options: string[], visible: bool, variation: bool, taxonomy: bool}>
     */
    public function variationAttributes(): array
    {
        return array_filter($this->attributes, static fn(array $a) => $a['variation']);
    }
}
