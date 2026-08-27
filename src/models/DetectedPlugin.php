<?php

namespace justinholtweb\passer\models;

use craft\base\Model;

/**
 * A WordPress plugin Passer found evidence of, and what it means for the migration.
 */
class DetectedPlugin extends Model
{
    public string $slug = '';
    public string $name = '';

    /** @var string seo|commerce|forms|redirects|fields|multilingual|comments|other */
    public string $category = 'other';

    public ?string $version = null;

    /** @var string How it was found: 'table', 'option', 'meta', 'postType'. */
    public string $evidence = '';

    /** @var string The specific table/option/meta key that gave it away. */
    public string $evidenceDetail = '';

    /** @var int Rows of data attributable to it, where countable. */
    public int $dataCount = 0;

    /** @var bool Whether Passer has an importer for it. */
    public bool $supported = false;

    /** @var string|null What Passer will do with it, shown in the wizard. */
    public ?string $handling = null;
}
