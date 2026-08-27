<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;

/**
 * A row from `wp_terms` joined to `wp_term_taxonomy`.
 */
class WpTerm extends Model
{
    public int $id = 0;

    /** @var int The term_taxonomy_id, which is what post assignments actually reference. */
    public int $taxonomyId = 0;

    public string $name = '';
    public string $slug = '';
    public string $taxonomy = 'category';
    public string $description = '';
    public int $parentId = 0;
    public int $count = 0;

    /** @var array<string, mixed> */
    public array $meta = [];

    public function metaValue(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }
}
