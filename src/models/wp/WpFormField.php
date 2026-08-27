<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;

/**
 * A single field on an imported form, in a vocabulary all five source plugins map onto.
 */
class WpFormField extends Model
{
    public string $handle = '';
    public string $label = '';

    /**
     * @var string One of: singleLine, multiLine, email, phone, number, dropdown, radio,
     * checkboxes, date, file, hidden, html, heading, section, name, address, url, password,
     * agree, recaptcha, payment, rating.
     */
    public string $type = 'singleLine';

    public bool $required = false;
    public ?string $placeholder = null;
    public ?string $defaultValue = null;
    public ?string $instructions = null;

    /** @var array<int, array{label: string, value: string, default?: bool}> */
    public array $options = [];

    /** @var array<string, mixed> Type-specific extras: min/max, allowed extensions, mask… */
    public array $settings = [];

    /** @var string|null The raw type name from the source plugin, kept for the report. */
    public ?string $sourceType = null;
}
