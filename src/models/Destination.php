<?php

namespace justinholtweb\passer\models;

use craft\base\Model;

/**
 * One place a domain's data can land.
 */
class Destination extends Model
{
    public string $handle = '';
    public string $name = '';
    public string $domain = '';

    /** @var string|null Craft plugin handle this destination needs. Null means Passer alone. */
    public ?string $requiresPlugin = null;

    public bool $available = false;

    /** @var string Shown in the wizard: what choosing this actually does. */
    public string $description = '';

    /** @var bool The dependency-free option Passer falls back to. Exactly one per domain. */
    public bool $isFallback = false;

    /** @var int Higher sorts first; the highest available destination is preselected. */
    public int $priority = 0;

    /** @var string|null Why it is unavailable, when it is. */
    public ?string $unavailableReason = null;
}
