<?php

namespace justinholtweb\passer\models\plan;

use craft\base\Model;

/**
 * How one WordPress taxonomy becomes Craft.
 */
class TaxonomyMapping extends Model
{
    public string $taxonomy = '';
    public bool $enabled = true;

    /**
     * @var string categories|tags|entries|skip
     *
     * `entries` exists because Craft's tag groups are flat and its category groups are a legacy
     * concept: a taxonomy with meta of its own, or one that will grow, belongs in a structure
     * section like anything else.
     */
    public string $destination = 'categories';

    public string $handle = '';
    public string $name = '';
    public bool $createGroup = false;

    /** @var string|null For `entries`: the entry type handle. */
    public ?string $entryType = null;

    /** @var array<string, string> Term meta key => Craft field handle. */
    public array $fieldMap = [];

    /** @var array<string, string> */
    public array $createFields = [];

    /** @var int[] */
    public array $siteIds = [];

    public function rules(): array
    {
        return [
            [['taxonomy'], 'required'],
            [['destination'], 'in', 'range' => ['categories', 'tags', 'entries', 'skip']],
        ];
    }
}
