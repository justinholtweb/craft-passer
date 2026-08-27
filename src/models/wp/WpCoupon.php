<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;

/**
 * A WooCommerce coupon (`shop_coupon` post).
 */
class WpCoupon extends Model
{
    public int $id = 0;
    public string $code = '';
    public string $description = '';

    /** @var string fixed_cart|percent|fixed_product */
    public string $discountType = 'fixed_cart';

    public ?string $amount = null;
    public ?string $expiryDate = null;

    public bool $freeShipping = false;
    public bool $individualUse = false;
    public bool $excludeSaleItems = false;

    public ?string $minimumAmount = null;
    public ?string $maximumAmount = null;

    public ?int $usageLimit = null;
    public ?int $usageLimitPerUser = null;
    public int $usageCount = 0;

    /** @var int[] */
    public array $productIds = [];

    /** @var int[] */
    public array $excludedProductIds = [];

    /** @var int[] */
    public array $productCategories = [];

    /** @var int[] */
    public array $excludedProductCategories = [];

    /** @var string[] */
    public array $emailRestrictions = [];
}
