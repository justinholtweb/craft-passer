<?php

namespace justinholtweb\passer\services;

use craft\base\Component;
use justinholtweb\passer\helpers\WpSerialize;
use justinholtweb\passer\models\wp\WpCoupon;
use justinholtweb\passer\models\wp\WpOrder;
use justinholtweb\passer\models\wp\WpPost;
use justinholtweb\passer\models\wp\WpProduct;
use justinholtweb\passer\models\wp\WpVariation;
use justinholtweb\passer\sources\SourceInterface;

/**
 * Assembles WooCommerce's data out of the places it keeps it.
 *
 * Woo is not one data model but three overlapping ones, accumulated over a decade:
 *
 * - **Products** are `product` posts whose price, stock and dimensions are individual
 *   `wp_postmeta` rows, whose categories are taxonomy terms, and whose attributes are a
 *   serialized blob in `_product_attributes` that references *other* taxonomies for global
 *   attributes and holds inline strings for local ones.
 * - **Variations** are separate `product_variation` posts, one per combination, whose defining
 *   attribute values are meta rows named `attribute_pa_colour` — where the suffix is the
 *   taxonomy name, lowercased, and the value is a term *slug*, not a name.
 * - **Orders** are either `shop_order` posts with meta (the legacy store) or rows in
 *   `wc_orders` + `wc_order_addresses` + `wc_order_operational_data` (High-Performance Order
 *   Storage, the default since Woo 8.2). A store mid-migration has both, and the two disagree.
 *
 * This class hides all of that. Everything downstream sees WpProduct, WpVariation, WpOrder.
 */
class WooReader extends Component
{
    private ?bool $hpos = null;

    /** @var array<string, array<string, string>>|null Attribute taxonomy => slug => name. */
    private ?array $attributeTerms = null;

    public function isPresent(SourceInterface $source): bool
    {
        try {
            return $source->hasTable('woocommerce_order_items')
                || $source->hasTable('wc_product_meta_lookup')
                || isset($source->postTypes()['product']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether this store keeps orders in the HPOS tables rather than as posts.
     */
    public function usesHpos(SourceInterface $source): bool
    {
        if ($this->hpos !== null) {
            return $this->hpos;
        }

        try {
            if (!$source->hasTable('wc_orders')) {
                return $this->hpos = false;
            }

            // The table can exist and be empty on a store that has the feature available but
            // not enabled, in which case the posts are still authoritative.
            foreach ($source->table('wc_orders', [], 0, 1) as $ignored) {
                return $this->hpos = true;
            }

            return $this->hpos = false;
        } catch (\Throwable) {
            return $this->hpos = false;
        }
    }

    public function currency(SourceInterface $source): ?string
    {
        try {
            $currency = $source->option('woocommerce_currency');

            return is_string($currency) && $currency !== '' ? $currency : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // -----------------------------------------------------------------------------------------
    // Products
    // -----------------------------------------------------------------------------------------

    /**
     * @return \Generator<WpProduct>
     */
    public function products(SourceInterface $source, int $offset = 0, ?int $limit = null): \Generator
    {
        foreach ($source->posts('product', $offset, $limit) as $post) {
            yield $this->productFromPost($post, $source);
        }
    }

    public function productFromPost(WpPost $post, SourceInterface $source): WpProduct
    {
        $product = new WpProduct();
        $product->id = $post->id;
        $product->title = $post->title;
        $product->slug = $post->name;
        $product->description = $post->content;
        $product->shortDescription = $post->excerpt;
        $product->status = $post->status;
        $product->date = $post->date;
        $product->modified = $post->modified;
        $product->meta = $post->meta;

        $product->sku = $this->string($post, '_sku');
        $product->price = $this->string($post, '_price');
        $product->regularPrice = $this->string($post, '_regular_price');
        $product->salePrice = $this->string($post, '_sale_price');
        $product->saleStart = $this->timestamp($post, '_sale_price_dates_from');
        $product->saleEnd = $this->timestamp($post, '_sale_price_dates_to');

        $product->manageStock = $this->yes($post, '_manage_stock');
        $stock = $post->metaValue('_stock');
        $product->stockQuantity = is_numeric($stock) ? (int)$stock : null;
        $product->stockStatus = $this->string($post, '_stock_status') ?? 'instock';
        $product->backorders = $this->string($post, '_backorders');

        $product->weight = $this->float($post, '_weight');
        $product->length = $this->float($post, '_length');
        $product->width = $this->float($post, '_width');
        $product->height = $this->float($post, '_height');

        $product->virtual = $this->yes($post, '_virtual');
        $product->downloadable = $this->yes($post, '_downloadable');
        $product->taxStatus = $this->string($post, '_tax_status') ?? 'taxable';
        $product->taxClass = $this->string($post, '_tax_class');

        $product->catalogVisibility = $this->string($post, '_visibility') ?? 'visible';
        $product->imageId = $post->featuredImageId;

        $gallery = $post->metaValue('_product_image_gallery');
        $product->galleryIds = is_string($gallery) && $gallery !== ''
            ? array_values(array_filter(array_map('intval', explode(',', $gallery))))
            : [];

        $product->upsellIds = $this->intList($post, '_upsell_ids');
        $product->crossSellIds = $this->intList($post, '_crosssell_ids');
        $product->groupedIds = $this->intList($post, '_children');

        $product->externalUrl = $this->string($post, '_product_url');
        $product->buttonText = $this->string($post, '_button_text');

        // The product type is a taxonomy term, not a meta value — one of Woo's odder decisions.
        foreach ($post->terms['product_type'] ?? [] as $term) {
            $product->productType = $term->slug;
            break;
        }

        foreach ($post->terms as $taxonomy => $terms) {
            if ($taxonomy === 'product_type' || $taxonomy === 'product_visibility') {
                continue;
            }

            $product->terms[$taxonomy] = array_map(static fn($t) => $t->slug, $terms);
        }

        // The "featured" flag lives in the product_visibility taxonomy.
        foreach ($post->terms['product_visibility'] ?? [] as $term) {
            if ($term->slug === 'featured') {
                $product->featured = true;
            }
        }

        $product->attributes = $this->attributesFor($post, $source);

        if ($product->productType === 'variable') {
            $product->variations = iterator_to_array($this->variations($product->id, $source), false);
        }

        return $product;
    }

    /**
     * Read `_product_attributes` and resolve the global ones to their term names.
     *
     * @return array<string, array{name: string, options: string[], visible: bool, variation: bool, taxonomy: bool}>
     */
    private function attributesFor(WpPost $post, SourceInterface $source): array
    {
        $raw = $post->metaValue('_product_attributes');

        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $key => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $name = (string)($definition['name'] ?? $key);
            $isTaxonomy = !empty($definition['is_taxonomy']);

            if ($isTaxonomy) {
                // A global attribute's options are the terms assigned to this product in that
                // taxonomy — the `value` field is empty for them.
                $options = [];

                foreach ($post->terms[$name] ?? [] as $term) {
                    $options[] = $term->name;
                }
            } else {
                // Local attributes store their options as a pipe-delimited string.
                $value = (string)($definition['value'] ?? '');
                $options = array_values(array_filter(array_map('trim', explode('|', $value))));
            }

            $out[(string)$key] = [
                'name' => $this->attributeLabel($name),
                'options' => $options,
                'visible' => !empty($definition['is_visible']),
                'variation' => !empty($definition['is_variation']),
                'taxonomy' => $isTaxonomy,
            ];
        }

        return $out;
    }

    private function attributeLabel(string $name): string
    {
        // Global attribute taxonomies are named `pa_colour`; the label is what the shop calls it.
        if (str_starts_with($name, 'pa_')) {
            $name = substr($name, 3);
        }

        return ucwords(str_replace(['-', '_'], ' ', $name));
    }

    /**
     * @return \Generator<WpVariation>
     */
    public function variations(int $productId, SourceInterface $source): \Generator
    {
        foreach ($source->posts('product_variation') as $post) {
            if ($post->parentId !== $productId) {
                continue;
            }

            yield $this->variationFromPost($post, $source);
        }
    }

    public function variationFromPost(WpPost $post, SourceInterface $source): WpVariation
    {
        $variation = new WpVariation();
        $variation->id = $post->id;
        $variation->parentId = $post->parentId;
        $variation->title = $post->title;
        $variation->description = $post->excerpt ?: null;
        $variation->menuOrder = $post->menuOrder;
        $variation->status = $post->status;
        $variation->meta = $post->meta;

        $variation->sku = $this->string($post, '_sku');
        $variation->price = $this->string($post, '_price');
        $variation->regularPrice = $this->string($post, '_regular_price');
        $variation->salePrice = $this->string($post, '_sale_price');

        $variation->manageStock = $this->yes($post, '_manage_stock');
        $stock = $post->metaValue('_stock');
        $variation->stockQuantity = is_numeric($stock) ? (int)$stock : null;
        $variation->stockStatus = $this->string($post, '_stock_status') ?? 'instock';

        $variation->weight = $this->float($post, '_weight');
        $variation->length = $this->float($post, '_length');
        $variation->width = $this->float($post, '_width');
        $variation->height = $this->float($post, '_height');

        $variation->virtual = $this->yes($post, '_virtual');
        $variation->downloadable = $this->yes($post, '_downloadable');
        $variation->imageId = $post->featuredImageId;

        // Defining values are meta rows named `attribute_<taxonomy-or-name>`.
        foreach ($post->meta as $key => $value) {
            if (!str_starts_with($key, 'attribute_')) {
                continue;
            }

            $attribute = substr($key, 10);
            $raw = is_scalar($value) ? (string)$value : '';

            // Global attributes store the term *slug*; the shop displays the term *name*, and
            // matching a variation to a Commerce variant needs the name.
            $variation->attributes[$this->attributeLabel($attribute)] =
                $raw !== '' ? $this->resolveAttributeTerm($attribute, $raw, $source) : '';
        }

        return $variation;
    }

    private function resolveAttributeTerm(string $taxonomy, string $slug, SourceInterface $source): string
    {
        if (!str_starts_with($taxonomy, 'pa_')) {
            return $slug;
        }

        $this->attributeTerms ??= [];

        if (!isset($this->attributeTerms[$taxonomy])) {
            $map = [];

            try {
                foreach ($source->terms($taxonomy) as $term) {
                    $map[$term->slug] = $term->name;
                }
            } catch (\Throwable) {
                // Fall back to the slug, which is at least a stable identifier.
            }

            $this->attributeTerms[$taxonomy] = $map;
        }

        return $this->attributeTerms[$taxonomy][$slug] ?? $slug;
    }

    // -----------------------------------------------------------------------------------------
    // Coupons
    // -----------------------------------------------------------------------------------------

    /**
     * @return \Generator<WpCoupon>
     */
    public function coupons(SourceInterface $source): \Generator
    {
        foreach ($source->posts('shop_coupon') as $post) {
            $coupon = new WpCoupon();
            $coupon->id = $post->id;
            // Woo stores the code as the post title, lowercased on save.
            $coupon->code = $post->title !== '' ? $post->title : $post->name;
            $coupon->description = $post->excerpt;
            $coupon->discountType = $this->string($post, 'discount_type') ?? 'fixed_cart';
            $coupon->amount = $this->string($post, 'coupon_amount');
            $coupon->expiryDate = $this->string($post, 'date_expires') !== null
                ? $this->timestamp($post, 'date_expires')
                : $this->string($post, 'expiry_date');

            $coupon->freeShipping = $this->yes($post, 'free_shipping');
            $coupon->individualUse = $this->yes($post, 'individual_use');
            $coupon->excludeSaleItems = $this->yes($post, 'exclude_sale_items');

            $coupon->minimumAmount = $this->string($post, 'minimum_amount');
            $coupon->maximumAmount = $this->string($post, 'maximum_amount');

            $limit = $post->metaValue('usage_limit');
            $coupon->usageLimit = is_numeric($limit) ? (int)$limit : null;

            $perUser = $post->metaValue('usage_limit_per_user');
            $coupon->usageLimitPerUser = is_numeric($perUser) ? (int)$perUser : null;

            $count = $post->metaValue('usage_count');
            $coupon->usageCount = is_numeric($count) ? (int)$count : 0;

            $coupon->productIds = $this->intList($post, 'product_ids');
            $coupon->excludedProductIds = $this->intList($post, 'exclude_product_ids');
            $coupon->productCategories = array_map('intval', (array)$post->metaValue('product_categories', []));
            $coupon->excludedProductCategories = array_map('intval', (array)$post->metaValue('exclude_product_categories', []));

            $emails = $post->metaValue('customer_email');
            $coupon->emailRestrictions = WpSerialize::flattenScalars($emails);

            yield $coupon;
        }
    }

    // -----------------------------------------------------------------------------------------
    // Orders
    // -----------------------------------------------------------------------------------------

    /**
     * @return \Generator<WpOrder>
     */
    public function orders(SourceInterface $source, int $offset = 0, ?int $limit = null): \Generator
    {
        if ($this->usesHpos($source)) {
            yield from $this->hposOrders($source, $offset, $limit);

            return;
        }

        foreach ($source->posts('shop_order', $offset, $limit) as $post) {
            yield $this->orderFromPost($post, $source);
        }
    }

    private function orderFromPost(WpPost $post, SourceInterface $source): WpOrder
    {
        $order = new WpOrder();
        $order->id = $post->id;
        $order->status = $post->status;
        $order->date = $post->date;
        $order->meta = $post->meta;

        $order->number = $this->string($post, '_order_number') ?? (string)$post->id;
        $order->currency = $this->string($post, '_order_currency');
        $order->customerId = (int)($post->metaValue('_customer_user') ?? 0);
        $order->email = $this->string($post, '_billing_email');
        $order->phone = $this->string($post, '_billing_phone');

        $order->total = $this->string($post, '_order_total');
        $order->totalTax = $this->string($post, '_order_tax');
        $order->shippingTotal = $this->string($post, '_order_shipping');
        $order->shippingTax = $this->string($post, '_order_shipping_tax');
        $order->discountTotal = $this->string($post, '_cart_discount');
        $order->discountTax = $this->string($post, '_cart_discount_tax');

        $order->paymentMethod = $this->string($post, '_payment_method');
        $order->paymentMethodTitle = $this->string($post, '_payment_method_title');
        $order->transactionId = $this->string($post, '_transaction_id');
        $order->datePaid = $this->timestamp($post, '_date_paid');
        $order->dateCompleted = $this->timestamp($post, '_date_completed');

        $order->customerNote = $post->excerpt !== '' ? $post->excerpt : null;
        $order->customerIp = $this->string($post, '_customer_ip_address');
        $order->customerUserAgent = $this->string($post, '_customer_user_agent');

        foreach (['billing', 'shipping'] as $kind) {
            $address = [];

            foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'] as $field) {
                $address[$field] = $this->string($post, "_{$kind}_{$field}");
            }

            $order->$kind = $address;
        }

        $this->attachOrderItems($order, $source);

        return $order;
    }

    /**
     * @return \Generator<WpOrder>
     */
    private function hposOrders(SourceInterface $source, int $offset, ?int $limit): \Generator
    {
        $count = 0;

        foreach ($source->table('wc_orders', [], $offset, $limit) as $row) {
            $order = new WpOrder();
            $order->id = (int)($row['id'] ?? 0);
            $order->status = (string)($row['status'] ?? 'wc-completed');
            $order->currency = (string)($row['currency'] ?? '') ?: null;
            $order->date = $this->nullDate($row['date_created_gmt'] ?? null);
            $order->datePaid = $this->nullDate($row['date_paid_gmt'] ?? null);
            $order->dateCompleted = $this->nullDate($row['date_completed_gmt'] ?? null);
            $order->customerId = (int)($row['customer_id'] ?? 0);
            $order->email = (string)($row['billing_email'] ?? '') ?: null;
            $order->total = $row['total_amount'] !== null ? (string)$row['total_amount'] : null;
            $order->totalTax = $row['tax_amount'] !== null ? (string)$row['tax_amount'] : null;
            $order->paymentMethod = (string)($row['payment_method'] ?? '') ?: null;
            $order->paymentMethodTitle = (string)($row['payment_method_title'] ?? '') ?: null;
            $order->transactionId = (string)($row['transaction_id'] ?? '') ?: null;
            $order->customerIp = (string)($row['ip_address'] ?? '') ?: null;
            $order->customerUserAgent = (string)($row['user_agent'] ?? '') ?: null;
            $order->customerNote = (string)($row['customer_note'] ?? '') ?: null;

            $this->attachHposAddresses($order, $source);
            $this->attachHposOperationalData($order, $source);
            $this->attachOrderItems($order, $source);

            yield $order;

            if ($limit !== null && ++$count >= $limit) {
                return;
            }
        }
    }

    private function attachHposAddresses(WpOrder $order, SourceInterface $source): void
    {
        try {
            foreach ($source->table('wc_order_addresses', ['order_id' => $order->id]) as $row) {
                $kind = (string)($row['address_type'] ?? 'billing');

                if (!in_array($kind, ['billing', 'shipping'], true)) {
                    continue;
                }

                $order->$kind = [
                    'first_name' => (string)($row['first_name'] ?? ''),
                    'last_name' => (string)($row['last_name'] ?? ''),
                    'company' => (string)($row['company'] ?? ''),
                    'address_1' => (string)($row['address_1'] ?? ''),
                    'address_2' => (string)($row['address_2'] ?? ''),
                    'city' => (string)($row['city'] ?? ''),
                    'state' => (string)($row['state'] ?? ''),
                    'postcode' => (string)($row['postcode'] ?? ''),
                    'country' => (string)($row['country'] ?? ''),
                    'email' => (string)($row['email'] ?? ''),
                    'phone' => (string)($row['phone'] ?? ''),
                ];

                if ($kind === 'billing') {
                    $order->email ??= (string)($row['email'] ?? '') ?: null;
                    $order->phone ??= (string)($row['phone'] ?? '') ?: null;
                }
            }
        } catch (\Throwable) {
            // Addresses are optional; an order without them still imports.
        }
    }

    private function attachHposOperationalData(WpOrder $order, SourceInterface $source): void
    {
        try {
            foreach ($source->table('wc_order_operational_data', ['order_id' => $order->id]) as $row) {
                $order->number = (string)($row['order_key'] ?? '') ?: (string)$order->id;
                $order->shippingTax = $row['shipping_tax_amount'] !== null ? (string)$row['shipping_tax_amount'] : null;
                $order->shippingTotal = $row['shipping_total_amount'] !== null ? (string)$row['shipping_total_amount'] : null;
                $order->discountTax = $row['discount_tax_amount'] !== null ? (string)$row['discount_tax_amount'] : null;
                $order->discountTotal = $row['discount_total_amount'] !== null ? (string)$row['discount_total_amount'] : null;
                break;
            }
        } catch (\Throwable) {
            // As above.
        }
    }

    /**
     * Line items live in `woocommerce_order_items` regardless of where the order does, with
     * their detail in `woocommerce_order_itemmeta`.
     */
    private function attachOrderItems(WpOrder $order, SourceInterface $source): void
    {
        try {
            $items = iterator_to_array($source->table('woocommerce_order_items', ['order_id' => $order->id]), false);
        } catch (\Throwable) {
            return;
        }

        if ($items === []) {
            return;
        }

        $itemIds = array_map(static fn(array $r) => (int)($r['order_item_id'] ?? 0), $items);
        $meta = $this->itemMeta($itemIds, $source);

        foreach ($items as $item) {
            $id = (int)($item['order_item_id'] ?? 0);
            $type = (string)($item['order_item_type'] ?? '');
            $name = (string)($item['order_item_name'] ?? '');
            $values = $meta[$id] ?? [];

            match ($type) {
                'line_item' => $order->lineItems[] = [
                    'name' => $name,
                    'productId' => (int)($values['_product_id'] ?? 0),
                    'variationId' => (int)($values['_variation_id'] ?? 0),
                    'quantity' => (int)($values['_qty'] ?? 1),
                    'subtotal' => (string)($values['_line_subtotal'] ?? '0'),
                    'total' => (string)($values['_line_total'] ?? '0'),
                    'tax' => (string)($values['_line_tax'] ?? '0'),
                    'sku' => isset($values['_sku']) ? (string)$values['_sku'] : null,
                    'meta' => $values,
                ],

                'shipping' => $order->shippingLines[] = [
                    'name' => $name,
                    'total' => (string)($values['cost'] ?? '0'),
                    'tax' => (string)($values['total_tax'] ?? '0'),
                    'methodId' => isset($values['method_id']) ? (string)$values['method_id'] : null,
                ],

                'coupon' => $order->couponLines[] = [
                    'code' => $name,
                    'discount' => (string)($values['discount_amount'] ?? '0'),
                    'discountTax' => (string)($values['discount_amount_tax'] ?? '0'),
                ],

                'tax' => $order->taxLines[] = [
                    'label' => (string)($values['label'] ?? $name),
                    'total' => (string)($values['tax_amount'] ?? '0'),
                    'rateId' => isset($values['rate_id']) ? (int)$values['rate_id'] : null,
                    'compound' => !empty($values['compound']),
                ],

                'fee' => $order->feeLines[] = [
                    'name' => $name,
                    'total' => (string)($values['_line_total'] ?? '0'),
                ],

                default => null,
            };
        }
    }

    /**
     * @param int[] $itemIds
     * @return array<int, array<string, mixed>>
     */
    private function itemMeta(array $itemIds, SourceInterface $source): array
    {
        if ($itemIds === []) {
            return [];
        }

        $out = [];

        try {
            foreach ($source->table('woocommerce_order_itemmeta', ['order_item_id' => $itemIds]) as $row) {
                $id = (int)($row['order_item_id'] ?? 0);
                $key = (string)($row['meta_key'] ?? '');

                if ($key !== '') {
                    $out[$id][$key] = WpSerialize::unserialize($row['meta_value'] ?? null);
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    // -----------------------------------------------------------------------------------------
    // Meta helpers
    // -----------------------------------------------------------------------------------------

    private function string(WpPost $post, string $key): ?string
    {
        $value = $post->metaValue($key);

        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        return (string)$value;
    }

    private function float(WpPost $post, string $key): ?float
    {
        $value = $post->metaValue($key);

        return is_numeric($value) ? (float)$value : null;
    }

    private function yes(WpPost $post, string $key): bool
    {
        return $post->metaValue($key) === 'yes';
    }

    /**
     * @return int[]
     */
    private function intList(WpPost $post, string $key): array
    {
        $value = $post->metaValue($key);

        if (is_string($value)) {
            $value = array_filter(array_map('trim', explode(',', $value)));
        }

        return array_values(array_filter(array_map('intval', (array)$value)));
    }

    /**
     * Some Woo date meta is a Unix timestamp and some is a formatted string, depending on
     * version and on whether the value was written by Woo or by an importer.
     */
    private function timestamp(WpPost $post, string $key): ?string
    {
        $value = $post->metaValue($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return gmdate('Y-m-d H:i:s', (int)$value);
        }

        return (string)$value;
    }

    private function nullDate(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return ($value === '' || str_starts_with($value, '0000-00-00')) ? null : $value;
    }
}
