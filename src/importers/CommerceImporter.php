<?php

namespace justinholtweb\passer\importers;

use Craft;
use justinholtweb\passer\models\wp\WpCoupon;
use justinholtweb\passer\models\wp\WpOrder;
use justinholtweb\passer\models\wp\WpProduct;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\services\IdMap;

/**
 * WooCommerce becomes Craft Commerce.
 *
 * This is the largest of the domains wp-import refuses, and the one clients care about most,
 * because a store that loses its order history loses its accounts.
 *
 * Four sub-phases, in dependency order: product categories, then products with their variants,
 * then coupons, then orders — an order line references a product and an order references a
 * customer, so both must already exist.
 *
 * Orders are imported as **completed history**, not as live carts. Commerce recalculates an
 * order's totals from its line items and its adjusters whenever it is saved in a normal flow;
 * that would silently rewrite historical totals to whatever today's tax rates produce, which is
 * both wrong and, for anyone who has filed accounts on those numbers, worse than wrong. So
 * imported orders are written with their stored totals and marked complete, and any discrepancy
 * between the Woo total and the sum of the lines is reported rather than reconciled.
 */
class CommerceImporter extends BaseImporter
{
    public static function phase(): string
    {
        return 'commerce';
    }

    public function estimate(RunContext $context): ?int
    {
        try {
            $types = $context->source->postTypes();
        } catch (\Throwable) {
            return null;
        }

        return ($types['product'] ?? 0) + ($types['shop_order'] ?? 0) + ($types['shop_coupon'] ?? 0);
    }

    public function import(RunContext $context, array $resumeFrom = []): array
    {
        $domain = $context->plan->domain('commerce');

        if ($domain === null || !$domain->enabled) {
            return [];
        }

        if ($domain->destination === 'entries-only') {
            return $this->importAsEntries($context, $resumeFrom);
        }

        if (!Craft::$app->getPlugins()->isPluginEnabled('commerce')) {
            $this->warn($context, 'Craft Commerce is not installed, so WooCommerce data was not imported.');

            return [];
        }

        $reader = Plugin::getInstance()->woo;

        if (!$reader->isPresent($context->source)) {
            $this->notice($context, 'No WooCommerce data was found in the source.');

            return [];
        }

        $completed = $resumeFrom['completed'] ?? [];
        $context->progress->startPhase(self::phase(), $this->estimate($context));

        $context->map->warm();

        if (!in_array('products', $completed, true)) {
            $this->importProducts($context, $domain->options);
            $completed[] = 'products';
        }

        if (!in_array('coupons', $completed, true)) {
            $this->importCoupons($context);
            $completed[] = 'coupons';
        }

        if (!in_array('orders', $completed, true)) {
            $this->importOrders($context, $domain->options);
            $completed[] = 'orders';
        }

        $context->progress->endPhase(self::phase());

        return ['completed' => $completed];
    }

    // -----------------------------------------------------------------------------------------
    // Products
    // -----------------------------------------------------------------------------------------

    private function importProducts(RunContext $context, array $options): void
    {
        $productClass = 'craft\\commerce\\elements\\Product';
        $variantClass = 'craft\\commerce\\elements\\Variant';

        if (!class_exists($productClass)) {
            throw new \RuntimeException('Craft Commerce is enabled but its Product element could not be loaded.');
        }

        $typeHandle = (string)($options['productType'] ?? 'wooProducts');
        $productType = $this->productType($typeHandle);

        if ($productType === null) {
            $this->warn($context, sprintf(
                'No Commerce product type named "%s" exists, so products were not imported. '
                . 'Create one and re-run this phase.',
                $typeHandle
            ));

            return;
        }

        $reader = Plugin::getInstance()->woo;
        $processed = 0;
        $unsupported = [];

        foreach ($reader->products($context->source) as $product) {
            $processed++;

            if (in_array($product->productType, ['grouped', 'external', 'subscription', 'bundle'], true)) {
                // A grouped product is a list of other products and an external one is a link
                // offsite. Neither is a purchasable in Commerce's sense, and inventing one would
                // create a store that sells things it cannot fulfil.
                $unsupported[$product->productType] = ($unsupported[$product->productType] ?? 0) + 1;
                $context->count(self::phase(), 'skipped');
                continue;
            }

            $this->attempt($context, IdMap::KEY_PRODUCT, $product->id, $product->title, function () use ($product, $context, $productType, $productClass, $variantClass) {
                $this->importProduct($product, $context, $productType, $productClass, $variantClass);
            });

            $context->progress->advance($processed, $product->title);
        }

        foreach ($unsupported as $type => $count) {
            $this->notice($context, sprintf(
                '%d %s product%s were not imported: Craft Commerce has no equivalent. They are '
                . 'listed in this report so they can be rebuilt deliberately.',
                $count,
                $type,
                $count === 1 ? '' : 's'
            ));
        }
    }

    private function importProduct(
        WpProduct $product,
        RunContext $context,
        object $productType,
        string $productClass,
        string $variantClass,
    ): void {
        $hash = $context->map->hashPayload([
            $product->title, $product->description, $product->price, $product->sku,
            $product->stockQuantity, $product->status, count($product->variations),
        ]);

        if ($this->unchanged($context, IdMap::KEY_PRODUCT, $product->id, $hash)) {
            $context->count(self::phase(), 'skipped');

            return;
        }

        $existingId = $context->map->lookup(IdMap::KEY_PRODUCT, $product->id);
        $element = $existingId !== null ? $productClass::find()->id($existingId)->status(null)->one() : null;
        $isNew = $element === null;

        if ($element === null) {
            $element = new $productClass();
            $element->typeId = $productType->id;
        }

        $element->title = $this->cleanText($product->title) ?: 'Untitled product';
        $element->slug = $this->cleanSlug($product->slug, $product->title);
        $element->enabled = $product->status === 'publish';

        $date = $this->toDateTime($product->date);

        if ($date !== null) {
            $element->postDate = $date;
        }

        $this->applyProductFields($element, $product, $context);

        if ($context->dryRun) {
            $context->count(self::phase(), $isNew ? 'created' : 'updated');

            return;
        }

        $element->setVariants($this->buildVariants($product, $context, $variantClass, $element));

        $this->save($element);

        $context->map->record(
            IdMap::KEY_PRODUCT,
            $product->id,
            $productClass,
            $element->id,
            $element->uid,
            null,
            $hash,
            null,
            $context->runId
        );

        // Variants are mapped individually so order line items can point at the exact one.
        $this->recordVariantMap($product, $element, $context);
        $this->applyStockLevels($product, $element, $context);

        $context->count(self::phase(), $isNew ? 'created' : 'updated');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildVariants(WpProduct $product, RunContext $context, string $variantClass, object $element): array
    {
        $variants = [];

        if (!$product->isVariable()) {
            $variants['new1'] = $this->variantData(
                sku: $product->sku ?: 'WOO-' . $product->id,
                price: $product->price ?? $product->regularPrice ?? '0',
                stock: $product->stockQuantity,
                manageStock: $product->manageStock,
                inStock: $product->stockStatus !== 'outofstock',
                weight: $product->weight,
                length: $product->length,
                width: $product->width,
                height: $product->height,
                title: $product->title,
                isDefault: true,
            );

            return $variants;
        }

        $index = 0;
        $anyAttribute = 0;

        foreach ($product->variations as $variation) {
            if ($variation->hasAnyAttribute()) {
                // Woo lets a variation match "any" value for an attribute — one row standing in
                // for every colour. Commerce variants are concrete, so expanding it would invent
                // prices and SKUs that the shop never had.
                $anyAttribute++;
            }

            $index++;

            // Woo's variation post title is generated and often empty; the attribute values are
            // what a customer actually chose between.
            $label = $variation->attributes !== []
                ? implode(' / ', array_filter($variation->attributes))
                : ($variation->title ?: 'Variant ' . $index);

            $variants['new' . $index] = $this->variantData(
                sku: $variation->sku ?: ($product->sku ?: 'WOO-' . $product->id) . '-' . $index,
                price: $variation->price ?? $variation->regularPrice ?? $product->price ?? '0',
                stock: $variation->stockQuantity,
                manageStock: $variation->manageStock,
                inStock: $variation->stockStatus !== 'outofstock',
                weight: $variation->weight ?? $product->weight,
                length: $variation->length ?? $product->length,
                width: $variation->width ?? $product->width,
                height: $variation->height ?? $product->height,
                title: $label,
                isDefault: $index === 1,
            );
        }

        if ($anyAttribute > 0) {
            $this->notice($context, sprintf(
                '"%s" has %d variation%s set to "any" for at least one attribute. Craft Commerce '
                . 'variants are concrete, so each was imported as a single variant with the '
                . 'attributes it does specify.',
                $product->title,
                $anyAttribute,
                $anyAttribute === 1 ? '' : 's'
            ));
        }

        return $variants !== [] ? $variants : ['new1' => $this->variantData(
            sku: $product->sku ?: 'WOO-' . $product->id,
            price: $product->price ?? '0',
            stock: null,
            manageStock: false,
            inStock: true,
            weight: $product->weight,
            length: $product->length,
            width: $product->width,
            height: $product->height,
            title: $product->title,
            isDefault: true,
        )];
    }

    /**
     * @return array<string, mixed>
     */
    private function variantData(
        string $sku,
        string $price,
        ?int $stock,
        bool $manageStock,
        bool $inStock,
        ?float $weight,
        ?float $length,
        ?float $width,
        ?float $height,
        string $title,
        bool $isDefault,
    ): array {
        // `stock` is read-only in Commerce 5 — it is derived from inventory levels across
        // locations, not stored on the variant — so the quantity is applied after the save, by
        // applyStockLevels(), and only the tracking flag is set here.
        return [
            'title' => mb_substr($title, 0, 255),
            'sku' => mb_substr($sku, 0, 255),
            'price' => (float)$price,
            'inventoryTracked' => $manageStock,
            'availableForPurchase' => $inStock || $manageStock,
            'weight' => $weight,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'isDefault' => $isDefault,
            'enabled' => true,
        ];
    }

    private function recordVariantMap(WpProduct $product, object $element, RunContext $context): void
    {
        $variants = $element->getVariants();
        $index = 0;

        foreach ($product->variations as $variation) {
            $variant = $variants[$index] ?? null;
            $index++;

            if ($variant === null) {
                continue;
            }

            $context->map->record(
                IdMap::KEY_VARIATION,
                $variation->id,
                $variant::class,
                $variant->id,
                $variant->uid,
                null,
                null,
                null,
                $context->runId
            );
        }

        if ($product->variations === [] && isset($variants[0])) {
            // A simple product's single variant is what its order lines reference.
            $context->map->record(
                IdMap::KEY_VARIATION,
                $product->id,
                $variants[0]::class,
                $variants[0]->id,
                $variants[0]->uid,
                null,
                null,
                null,
                $context->runId
            );
        }
    }

    /**
     * Write Woo's stock quantities into Commerce's inventory.
     *
     * Commerce 5 keeps stock in inventory levels per location rather than on the variant, so it
     * cannot be set while saving. The quantity goes to the store's primary location, which is
     * the only sensible destination for a shop that had no notion of locations at all.
     */
    private function applyStockLevels(WpProduct $product, object $element, RunContext $context): void
    {
        $commerce = Craft::$app->getPlugins()->getPlugin('commerce');

        if ($commerce === null || !method_exists($commerce, 'getInventory')) {
            return;
        }

        $quantities = [];
        $variants = $element->getVariants();
        $index = 0;

        foreach ($product->variations as $variation) {
            $variant = $variants[$index] ?? null;
            $index++;

            if ($variant !== null && $variation->manageStock) {
                $quantities[] = [$variant, $variation->stockQuantity ?? 0];
            }
        }

        if ($product->variations === [] && isset($variants[0]) && $product->manageStock) {
            $quantities[] = [$variants[0], $product->stockQuantity ?? 0];
        }

        foreach ($quantities as [$variant, $quantity]) {
            try {
                $commerce->getInventory()->updatePurchasableInventoryLevel($variant, (int)$quantity);
            } catch (\Throwable $e) {
                $this->warn($context, sprintf(
                    'Imported "%s" but could not set its stock to %d: %s',
                    $variant->sku ?: $product->title,
                    (int)$quantity,
                    $e->getMessage()
                ));
            }
        }
    }

    private function applyProductFields(object $element, WpProduct $product, RunContext $context): void
    {
        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return;
        }

        $transformer = Plugin::getInstance()->contentTransformer;

        foreach (['description', 'body'] as $handle) {
            if ($layout->getFieldByHandle($handle) !== null && $product->description !== '') {
                $element->setFieldValue($handle, $transformer->toHtml($this->cleanText($product->description), $context));
                break;
            }
        }

        foreach (['shortDescription', 'excerpt', 'summary'] as $handle) {
            if ($layout->getFieldByHandle($handle) !== null && $product->shortDescription !== '') {
                $element->setFieldValue($handle, $transformer->toHtml($this->cleanText($product->shortDescription), $context));
                break;
            }
        }

        $imageIds = [];

        foreach (array_merge($product->imageId !== null ? [$product->imageId] : [], $product->galleryIds) as $wpId) {
            $assetId = $context->map->lookup(IdMap::KEY_ATTACHMENT, $wpId);

            if ($assetId !== null) {
                $imageIds[] = $assetId;
            }
        }

        foreach (['images', 'productImages', 'gallery', 'featuredImage'] as $handle) {
            if ($imageIds !== [] && $layout->getFieldByHandle($handle) !== null) {
                $element->setFieldValue($handle, $imageIds);
                break;
            }
        }

        // Product categories, resolved through the map like any other taxonomy.
        $categoryIds = [];

        foreach ($product->terms['product_cat'] ?? [] as $slug) {
            $mapping = $context->plan->taxonomies['product_cat'] ?? null;

            if ($mapping === null) {
                continue;
            }

            $id = \craft\elements\Category::find()->group($mapping->handle)->slug($slug)->ids()[0] ?? null;

            if ($id !== null) {
                $categoryIds[] = $id;
            }
        }

        foreach (['productCategories', 'categories'] as $handle) {
            if ($categoryIds !== [] && $layout->getFieldByHandle($handle) !== null) {
                $element->setFieldValue($handle, $categoryIds);
                break;
            }
        }
    }

    private function productType(string $handle): ?object
    {
        $commerce = Craft::$app->getPlugins()->getPlugin('commerce');

        if ($commerce === null) {
            return null;
        }

        try {
            return $commerce->getProductTypes()->getProductTypeByHandle($handle)
                ?? ($commerce->getProductTypes()->getAllProductTypes()[0] ?? null);
        } catch (\Throwable) {
            return null;
        }
    }

    // -----------------------------------------------------------------------------------------
    // Coupons
    // -----------------------------------------------------------------------------------------

    private function importCoupons(RunContext $context): void
    {
        $discountClass = 'craft\\commerce\\models\\Discount';

        if (!class_exists($discountClass)) {
            return;
        }

        $commerce = Craft::$app->getPlugins()->getPlugin('commerce');

        if ($commerce === null) {
            return;
        }

        $reader = Plugin::getInstance()->woo;

        foreach ($reader->coupons($context->source) as $coupon) {
            $this->attempt($context, IdMap::KEY_COUPON, $coupon->id, $coupon->code, function () use ($coupon, $context, $commerce, $discountClass) {
                $this->importCoupon($coupon, $context, $commerce, $discountClass);
            });
        }
    }

    private function importCoupon(WpCoupon $coupon, RunContext $context, object $commerce, string $discountClass): void
    {
        if ($coupon->code === '') {
            return;
        }

        $hash = $context->map->hashPayload([$coupon->code, $coupon->discountType, $coupon->amount, $coupon->expiryDate]);

        if ($this->unchanged($context, IdMap::KEY_COUPON, $coupon->id, $hash)) {
            $context->count(self::phase(), 'skipped');

            return;
        }

        if ($context->dryRun) {
            $context->count(self::phase(), 'created');

            return;
        }

        $discount = new $discountClass();

        // Commerce 5 is multi-store and a discount belongs to exactly one store.
        $storeId = $this->primaryStoreId();

        if ($storeId !== null) {
            $discount->storeId = $storeId;
        }

        $discount->name = $coupon->code;
        $discount->description = $this->cleanText($coupon->description);
        $discount->enabled = true;

        // Woo's `fixed_cart` discounts the order; `percent` and `fixed_product` discount the
        // items. Commerce splits the same idea across two sets of fields.
        match ($coupon->discountType) {
            'percent' => $discount->percentDiscount = -1 * ((float)$coupon->amount / 100),
            'fixed_product' => $discount->perItemDiscount = -1 * (float)$coupon->amount,
            default => $discount->baseDiscount = -1 * (float)$coupon->amount,
        };

        $discount->hasFreeShippingForOrder = $coupon->freeShipping;
        $discount->purchaseTotal = (float)($coupon->minimumAmount ?? 0);
        $discount->totalDiscountUseLimit = $coupon->usageLimit ?? 0;
        $discount->perUserLimit = $coupon->usageLimitPerUser ?? 0;
        $discount->totalDiscountUses = $coupon->usageCount;
        $discount->excludeOnSale = $coupon->excludeSaleItems;
        $discount->stopProcessing = $coupon->individualUse;

        if ($coupon->expiryDate !== null) {
            $expiry = $this->toDateTime($coupon->expiryDate);

            if ($expiry !== null) {
                $discount->dateTo = $expiry;
            }
        }

        try {
            if (!$commerce->getDiscounts()->saveDiscount($discount)) {
                throw new \RuntimeException(implode('; ', $discount->getFirstErrors()));
            }

            // Commerce 4+ keeps coupon codes on a related record rather than on the discount.
            if (method_exists($commerce, 'getCoupons')) {
                $couponClass = 'craft\\commerce\\models\\Coupon';

                if (class_exists($couponClass)) {
                    $record = new $couponClass([
                        'code' => $coupon->code,
                        'discountId' => $discount->id,
                        'uses' => $coupon->usageCount,
                        'maxUses' => $coupon->usageLimit,
                    ]);

                    $commerce->getCoupons()->saveCoupon($record);
                }
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("Could not save the discount for coupon {$coupon->code}: " . $e->getMessage(), 0, $e);
        }

        $context->map->record(
            IdMap::KEY_COUPON,
            $coupon->id,
            $discountClass,
            $discount->id,
            null,
            null,
            $hash,
            null,
            $context->runId
        );

        $context->count(self::phase(), 'created');
    }

    // -----------------------------------------------------------------------------------------
    // Orders
    // -----------------------------------------------------------------------------------------

    private function importOrders(RunContext $context, array $options): void
    {
        $orderClass = 'craft\\commerce\\elements\\Order';

        if (!class_exists($orderClass)) {
            return;
        }

        $reader = Plugin::getInstance()->woo;
        $currency = $reader->currency($context->source);
        $processed = 0;
        $mismatches = 0;

        foreach ($reader->orders($context->source) as $order) {
            $processed++;

            $imported = $this->attempt($context, IdMap::KEY_ORDER, $order->id, $order->number ?? (string)$order->id, function () use ($order, $context, $orderClass, $currency, &$mismatches) {
                if ($this->importOrder($order, $context, $orderClass, $currency)) {
                    $mismatches++;
                }
            });

            $context->progress->advance($processed, 'Order ' . ($order->number ?? $order->id));
        }

        if ($mismatches > 0) {
            $this->notice($context, sprintf(
                '%d order%s had a stored total that does not equal the sum of their line items. '
                . 'The stored total was kept, because it is what the customer was charged. They '
                . 'are worth checking before the accounts are relied on.',
                $mismatches,
                $mismatches === 1 ? '' : 's'
            ));
        }
    }

    /**
     * @return bool True when the order's totals did not reconcile.
     */
    private function importOrder(WpOrder $order, RunContext $context, string $orderClass, ?string $currency): bool
    {
        $hash = $context->map->hashPayload([$order->status, $order->total, count($order->lineItems), $order->email]);

        if ($this->unchanged($context, IdMap::KEY_ORDER, $order->id, $hash)) {
            $context->count(self::phase(), 'skipped');

            return false;
        }

        if ($context->dryRun) {
            $context->count(self::phase(), 'created');

            return false;
        }

        $existingId = $context->map->lookup(IdMap::KEY_ORDER, $order->id);
        $element = $existingId !== null ? $orderClass::find()->id($existingId)->status(null)->one() : null;
        $isNew = $element === null;

        /** @var \craft\base\ElementInterface $element */
        $element ??= new $orderClass();

        // Commerce requires a unique order number, and Woo's is only unique within Woo — a
        // Craft store that already has orders, or a second WordPress site being merged in, will
        // collide. The Woo number stays the human-facing reference; the number is namespaced.
        $element->number = $this->uniqueOrderNumber($order, $orderClass);
        $element->reference = (string)($order->number ?: $order->id);

        $storeId = $this->primaryStoreId();

        if ($storeId !== null && property_exists($element, 'storeId')) {
            $element->storeId = $storeId;
        }
        $element->email = $order->email;
        $element->currency = $order->currency ?? $currency ?? 'USD';
        $element->paymentCurrency = $element->currency;
        $element->orderLanguage = Craft::$app->language;
        $element->isCompleted = true;
        $element->message = $order->customerNote;
        $element->lastIp = $order->customerIp;

        $date = $this->toDateTime($order->date);

        if ($date !== null) {
            $element->dateOrdered = $date;
            $element->dateCreated = $date;
        }

        $paid = $this->toDateTime($order->datePaid);

        if ($paid !== null) {
            $element->datePaid = $paid;
        }

        $customerId = $order->customerId > 0
            ? $context->map->lookup(IdMap::KEY_USER, $order->customerId)
            : null;

        if ($customerId !== null) {
            $element->setCustomerId($customerId);
        }

        $element->orderStatusId = $this->orderStatusId($order, $context);

        if ($context->dryRun) {
            return false;
        }

        $this->save($element, false);

        $this->applyOrderLines($element, $order, $context);
        $this->applyOrderAddresses($element, $order);
        $this->applyStoredTotals($element, $order);

        $this->save($element, false);

        $context->map->record(
            IdMap::KEY_ORDER,
            $order->id,
            $orderClass,
            $element->id,
            $element->uid,
            null,
            $hash,
            null,
            $context->runId
        );

        $context->count(self::phase(), $isNew ? 'created' : 'updated');

        return !$this->totalsReconcile($order);
    }

    private function applyOrderLines(object $element, WpOrder $order, RunContext $context): void
    {
        $lineItemClass = 'craft\\commerce\\models\\LineItem';

        if (!class_exists($lineItemClass)) {
            return;
        }

        $lineItems = [];

        foreach ($order->lineItems as $line) {
            $variantId = $line['variationId'] > 0
                ? $context->map->lookup(IdMap::KEY_VARIATION, $line['variationId'])
                : $context->map->lookup(IdMap::KEY_VARIATION, $line['productId']);

            $qty = max(1, $line['quantity']);

            $item = new $lineItemClass();
            $item->orderId = $element->id;
            $item->qty = $qty;
            $item->sku = $line['sku'] ?? '';
            $item->description = $line['name'];

            // `salePrice` is read-only in Commerce 5 — it is derived from the price and the
            // promotional price. Woo's subtotal is the pre-discount line and its total is what
            // was charged, which maps exactly onto those two.
            $item->setPrice($qty > 0 ? (float)$line['subtotal'] / $qty : (float)$line['subtotal']);

            $charged = $qty > 0 ? (float)$line['total'] / $qty : (float)$line['total'];

            if (abs($charged - (float)$item->price) >= 0.0001 && method_exists($item, 'setPromotionalPrice')) {
                $item->setPromotionalPrice($charged);
            }

            if ($variantId !== null) {
                $item->purchasableId = $variantId;
            } else {
                // The product has been deleted from the shop, or was a type Commerce cannot
                // hold. The line is still real history and is kept with its description, price
                // and quantity — an order missing its lines is worse than one missing a link.
                $item->purchasableId = null;
            }

            $lineItems[] = $item;
        }

        if ($lineItems !== []) {
            $element->setLineItems($lineItems);
        }
    }

    private function applyOrderAddresses(object $element, WpOrder $order): void
    {
        foreach (['billing' => 'setBillingAddress', 'shipping' => 'setShippingAddress'] as $kind => $setter) {
            $data = $order->$kind;

            if (!is_array($data) || ($data['address_1'] ?? '') === '' || !method_exists($element, $setter)) {
                continue;
            }

            $country = strtoupper(trim((string)($data['country'] ?? '')));
            $name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

            // Commerce is handed an array rather than an Address element on purpose: an address
            // may only belong to the order that owns it, and Commerce sets that ownership itself
            // when it builds the element. A pre-built element is rejected outright.
            $element->$setter([
                'title' => $name !== '' ? $name : 'Address',
                'fullName' => $name !== '' ? $name : null,
                'organization' => ($data['company'] ?? '') ?: null,
                'addressLine1' => $data['address_1'] ?? null,
                'addressLine2' => ($data['address_2'] ?? '') ?: null,
                'locality' => ($data['city'] ?? '') ?: null,
                'administrativeArea' => ($data['state'] ?? '') ?: null,
                'postalCode' => ($data['postcode'] ?? '') ?: null,
                // Craft addresses require a two-letter country code, and Woo stores exactly that.
                'countryCode' => strlen($country) === 2 ? $country : 'US',
            ]);
        }
    }

    /**
     * Write the totals WooCommerce recorded, rather than the ones Commerce would compute.
     */
    private function applyStoredTotals(object $element, WpOrder $order): void
    {
        // Commerce derives `total` from adjustments, so the historical figures are carried in an
        // adjustment of their own. This keeps the order's total exactly what the customer paid
        // while remaining a legitimate Commerce order rather than a hand-written row.
        $adjustmentClass = 'craft\\commerce\\models\\OrderAdjustment';

        if (!class_exists($adjustmentClass)) {
            return;
        }

        $adjustments = [];
        $itemSubtotal = 0.0;

        foreach ($element->getLineItems() as $item) {
            $itemSubtotal += (float)$item->salePrice * (int)$item->qty;
        }

        foreach ($order->shippingLines as $line) {
            $amount = (float)$line['total'];

            if ($amount === 0.0) {
                continue;
            }

            $adjustment = new $adjustmentClass();
            $adjustment->type = 'shipping';
            $adjustment->name = $line['name'] !== '' ? $line['name'] : 'Shipping';
            $adjustment->description = $line['methodId'] ?? '';
            $adjustment->amount = $amount;
            $adjustment->orderId = $element->id;
            $adjustments[] = $adjustment;
        }

        foreach ($order->taxLines as $line) {
            $amount = (float)$line['total'];

            if ($amount === 0.0) {
                continue;
            }

            $adjustment = new $adjustmentClass();
            $adjustment->type = 'tax';
            $adjustment->name = $line['label'] !== '' ? $line['label'] : 'Tax';
            $adjustment->amount = $amount;
            $adjustment->included = false;
            $adjustment->orderId = $element->id;
            $adjustments[] = $adjustment;
        }

        foreach ($order->couponLines as $line) {
            $amount = -1 * (float)$line['discount'];

            if ($amount === 0.0) {
                continue;
            }

            $adjustment = new $adjustmentClass();
            $adjustment->type = 'discount';
            $adjustment->name = $line['code'];
            $adjustment->amount = $amount;
            $adjustment->orderId = $element->id;
            $adjustments[] = $adjustment;
        }

        foreach ($order->feeLines as $line) {
            $amount = (float)$line['total'];

            if ($amount === 0.0) {
                continue;
            }

            $adjustment = new $adjustmentClass();
            $adjustment->type = 'fee';
            $adjustment->name = $line['name'] !== '' ? $line['name'] : 'Fee';
            $adjustment->amount = $amount;
            $adjustment->orderId = $element->id;
            $adjustments[] = $adjustment;
        }

        // Anything left over between the lines-plus-adjustments and the recorded total is a
        // rounding difference, a plugin's own adjustment, or a manual edit made in Woo. It is
        // recorded explicitly so the order still totals what it totalled.
        $storedTotal = (float)($order->total ?? 0);
        $accounted = $itemSubtotal;

        foreach ($adjustments as $adjustment) {
            $accounted += (float)$adjustment->amount;
        }

        $difference = round($storedTotal - $accounted, 4);

        if (abs($difference) >= 0.0001) {
            $adjustment = new $adjustmentClass();
            $adjustment->type = 'discount';
            $adjustment->name = 'WooCommerce adjustment';
            $adjustment->description = 'Difference between the WooCommerce order total and the sum of its lines, preserved so the order totals what the customer paid.';
            $adjustment->amount = $difference;
            $adjustment->orderId = $element->id;
            $adjustments[] = $adjustment;
        }

        if ($adjustments !== []) {
            $element->setAdjustments($adjustments);
        }
    }

    private function totalsReconcile(WpOrder $order): bool
    {
        $lines = 0.0;

        foreach ($order->lineItems as $line) {
            $lines += (float)$line['total'];
        }

        foreach ($order->shippingLines as $line) {
            $lines += (float)$line['total'];
        }

        foreach ($order->taxLines as $line) {
            $lines += (float)$line['total'];
        }

        foreach ($order->couponLines as $line) {
            $lines -= (float)$line['discount'];
        }

        return abs(round($lines - (float)($order->total ?? 0), 2)) < 0.01;
    }

    /**
     * A Commerce order number that is unique in this store.
     */
    private function uniqueOrderNumber(WpOrder $order, string $orderClass): string
    {
        $base = 'wc-' . ($order->number ?: $order->id);

        if (!$orderClass::find()->number($base)->status(null)->exists()) {
            return $base;
        }

        // Already taken by something this run did not create — most often a previous, partly
        // failed import. Fall back to something that cannot collide.
        return $base . '-' . substr(sha1($base . microtime(true)), 0, 8);
    }

    private function primaryStoreId(): ?int
    {
        $commerce = Craft::$app->getPlugins()->getPlugin('commerce');

        if ($commerce === null || !method_exists($commerce, 'getStores')) {
            return null;
        }

        try {
            return $commerce->getStores()->getPrimaryStore()?->id;
        } catch (\Throwable) {
            return null;
        }
    }

    private function orderStatusId(WpOrder $order, RunContext $context): ?int
    {
        $commerce = Craft::$app->getPlugins()->getPlugin('commerce');

        if ($commerce === null) {
            return null;
        }

        try {
            $statuses = $commerce->getOrderStatuses()->getAllOrderStatuses();
        } catch (\Throwable) {
            return null;
        }

        $wanted = match ($order->plainStatus()) {
            'completed' => ['completed', 'shipped'],
            'processing' => ['processing', 'new'],
            'pending' => ['pending', 'new'],
            'on-hold' => ['on-hold', 'hold', 'pending'],
            'cancelled' => ['cancelled', 'canceled'],
            'refunded' => ['refunded'],
            'failed' => ['failed'],
            default => ['new'],
        };

        foreach ($wanted as $handle) {
            foreach ($statuses as $status) {
                if ($status->handle === $handle) {
                    return $status->id;
                }
            }
        }

        return $statuses[0]->id ?? null;
    }

    // -----------------------------------------------------------------------------------------
    // The Commerce-free fallback
    // -----------------------------------------------------------------------------------------

    /**
     * Import products as plain entries, for a site with no Commerce licence.
     */
    private function importAsEntries(RunContext $context, array $resumeFrom): array
    {
        $mapping = $context->plan->postTypes['product'] ?? null;

        if ($mapping === null) {
            $this->warn($context, 'Products cannot be imported as entries without a mapping for the "product" post type.');

            return [];
        }

        $this->notice($context, 'Products were imported as entries. Orders, customers and coupons need Craft Commerce and were not imported.');

        // The generic content importer already knows how to do this; there is no reason to have
        // a second implementation that will drift from it.
        return (new ContentImporter())->import($context, $resumeFrom);
    }
}
