<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;

/**
 * A stored form entry. Only Gravity Forms, WPForms, Ninja Forms and Formidable keep these;
 * Contact Form 7 stores nothing unless Flamingo is installed.
 */
class WpFormSubmission extends Model
{
    public string $sourceId = '';
    public string $formSourceId = '';
    public ?string $date = null;
    public ?string $ip = null;
    public ?string $userAgent = null;
    public int $userId = 0;
    public ?string $status = null;

    /** @var array<string, mixed> Values keyed by the field handle they belong to. */
    public array $values = [];
}
