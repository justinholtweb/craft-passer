<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;

/**
 * A WooCommerce order.
 *
 * Woo stores orders two entirely different ways depending on version and settings: as
 * `shop_order` posts with meta (legacy), or in dedicated HPOS tables `wc_orders`,
 * `wc_order_addresses`, `wc_order_operational_data` (High-Performance Order Storage, default
 * since Woo 8.2). The database source reads whichever is present and produces this same model.
 */
class WpOrder extends Model
{
    public int $id = 0;
    public ?string $number = null;
    public string $status = 'wc-completed';
    public ?string $currency = null;
    public ?string $date = null;
    public ?string $datePaid = null;
    public ?string $dateCompleted = null;

    public int $customerId = 0;
    public ?string $email = null;
    public ?string $phone = null;

    /** @var array<string, string|null> Billing address fields, Woo's key names. */
    public array $billing = [];

    /** @var array<string, string|null> Shipping address fields, Woo's key names. */
    public array $shipping = [];

    public ?string $total = null;
    public ?string $subtotal = null;
    public ?string $totalTax = null;
    public ?string $shippingTotal = null;
    public ?string $shippingTax = null;
    public ?string $discountTotal = null;
    public ?string $discountTax = null;

    public ?string $paymentMethod = null;
    public ?string $paymentMethodTitle = null;
    public ?string $transactionId = null;

    public ?string $customerNote = null;
    public ?string $customerIp = null;
    public ?string $customerUserAgent = null;

    /**
     * @var array<int, array{
     *     name: string, productId: int, variationId: int, quantity: int,
     *     subtotal: string, total: string, tax: string, sku: ?string,
     *     meta: array<string, mixed>
     * }>
     */
    public array $lineItems = [];

    /** @var array<int, array{name: string, total: string, tax: string, methodId: ?string}> */
    public array $shippingLines = [];

    /** @var array<int, array{code: string, discount: string, discountTax: string}> */
    public array $couponLines = [];

    /** @var array<int, array{label: string, total: string, rateId: ?int, compound: bool}> */
    public array $taxLines = [];

    /** @var array<int, array{name: string, total: string}> */
    public array $feeLines = [];

    /** @var array<int, array{date: string, content: string, author: string, customerNote: bool}> */
    public array $notes = [];

    /** @var array<string, mixed> */
    public array $meta = [];

    /**
     * Woo's `wc-` prefixed status without the prefix.
     */
    public function plainStatus(): string
    {
        return str_starts_with($this->status, 'wc-') ? substr($this->status, 3) : $this->status;
    }

    public function isPaid(): bool
    {
        return in_array($this->plainStatus(), ['processing', 'completed'], true) || $this->datePaid !== null;
    }
}
