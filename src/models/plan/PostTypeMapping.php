<?php

namespace justinholtweb\passer\models\plan;

use craft\base\Model;

/**
 * How one WordPress post type becomes Craft entries.
 */
class PostTypeMapping extends Model
{
    public string $postType = '';
    public bool $enabled = true;

    /** @var string Section handle. May not exist yet; see `createSection`. */
    public string $section = '';

    public string $sectionName = '';

    /** @var string channel|structure|single */
    public string $sectionType = 'channel';

    public string $entryType = '';
    public string $entryTypeName = '';

    public bool $createSection = false;
    public bool $createEntryType = false;

    /** @var string|null Field handle to write post_content into. Null discards the body. */
    public ?string $contentField = 'body';

    /** @var bool Create the content field if it is missing. */
    public bool $createContentField = false;

    /**
     * @var string html|ckeditor|matrix How post_content is stored. `matrix` splits Gutenberg
     * blocks into blocks of a Matrix field rather than flattening them to markup.
     */
    public string $contentFormat = 'html';

    /** @var string|null Field handle for the excerpt. */
    public ?string $excerptField = null;

    /** @var string|null Field handle for the featured image. */
    public ?string $featuredImageField = null;

    /**
     * WordPress meta key => Craft field handle. Only keys listed here are imported; everything
     * else in `wp_postmeta` is deliberately left behind, because most of it is plugin bookkeeping.
     *
     * @var array<string, string>
     */
    public array $fieldMap = [];

    /**
     * Field handles Passer should create for mapped meta keys that have no field yet, as
     * handle => field type class.
     *
     * @var array<string, string>
     */
    public array $createFields = [];

    /**
     * WordPress taxonomy => Craft field handle holding the relation.
     *
     * @var array<string, string>
     */
    public array $taxonomyFields = [];

    /** @var int[] Craft site IDs to import into. Empty means the primary site. */
    public array $siteIds = [];

    /**
     * WordPress post status => Craft entry status (`live`, `pending`, `expired`, `disabled`).
     *
     * @var array<string, string>
     */
    public array $statusMap = [
        'publish' => 'live',
        'future' => 'pending',
        'draft' => 'disabled',
        'pending' => 'disabled',
        'private' => 'disabled',
        'inherit' => 'live',
    ];

    /** @var bool Import posts WordPress had in the trash. */
    public bool $includeTrashed = false;

    /** @var int|null Craft user ID to author entries whose WordPress author cannot be resolved. */
    public ?int $fallbackAuthorId = null;

    /** @var bool Keep the WordPress permalink structure as the entry URI. */
    public bool $preserveUris = true;

    public function rules(): array
    {
        return [
            [['postType'], 'required'],
            [['sectionType'], 'in', 'range' => ['channel', 'structure', 'single']],
            [['contentFormat'], 'in', 'range' => ['html', 'ckeditor', 'matrix']],
        ];
    }
}
