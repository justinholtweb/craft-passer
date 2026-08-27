<?php

namespace justinholtweb\passer\models;

use craft\base\Model;

/**
 * What a WordPress site actually contains, as measured rather than assumed.
 *
 * The wizard shows this before anything is imported, and the planner uses it to propose a
 * mapping. It is stored on the plan so a later run can be compared against what was there when
 * the plan was made.
 */
class Inventory extends Model
{
    public ?string $siteName = null;
    public ?string $siteUrl = null;
    public ?string $wpVersion = null;
    public string $sourceType = '';

    /** @var array<string, int> Post type => count. */
    public array $postTypes = [];

    /** @var array<string, int> Taxonomy => term count. */
    public array $taxonomies = [];

    /** @var array<string, string[]> Post type => taxonomies attached to it. */
    public array $postTypeTaxonomies = [];

    public int $users = 0;
    public int $comments = 0;
    public int $attachments = 0;

    /** @var int|null Total bytes of attachments, when the source can say. */
    public ?int $attachmentBytes = null;

    /** @var array<string, int> Meta key => row count, most-used first. */
    public array $metaKeys = [];

    /** @var array<string, string[]> Post type => meta keys seen on it. */
    public array $metaKeysByType = [];

    /** @var DetectedPlugin[] */
    public array $plugins = [];

    public int $menus = 0;
    public int $widgets = 0;
    public int $products = 0;
    public int $orders = 0;
    public int $coupons = 0;
    public int $forms = 0;
    public int $formSubmissions = 0;
    public int $redirects = 0;

    /** @var string[] Locales/languages found, when the site is multilingual. */
    public array $languages = [];

    /** @var string[] Notes worth showing the user before they commit to a plan. */
    public array $warnings = [];

    /** @var string[] Things the chosen source could not look at. */
    public array $notScanned = [];

    /**
     * Post types WordPress owns and Passer maps specially, rather than offering as content.
     *
     * @var string[]
     */
    public const INTERNAL_POST_TYPES = [
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_global_styles',
        'wp_navigation',
        'wp_template',
        'wp_template_part',
        'wp_font_family',
        'wp_font_face',
        'acf-field',
        'acf-field-group',
        'acf-post-type',
        'acf-taxonomy',
        'shop_order_refund',
        'scheduled-action',
        'product_variation',
    ];

    /**
     * Post types the user would recognise as content, i.e. everything WordPress and its plugins
     * do not use for their own bookkeeping.
     *
     * @return array<string, int>
     */
    public function contentPostTypes(): array
    {
        $out = [];

        foreach ($this->postTypes as $type => $count) {
            if (in_array($type, self::INTERNAL_POST_TYPES, true)) {
                continue;
            }

            if ($type === 'attachment') {
                continue;
            }

            $out[$type] = $count;
        }

        return $out;
    }

    /**
     * Taxonomies worth offering as a mapping target.
     *
     * @return array<string, int>
     */
    public function contentTaxonomies(): array
    {
        $internal = ['nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'product_visibility'];

        return array_diff_key($this->taxonomies, array_flip($internal));
    }

    public function plugin(string $slug): ?DetectedPlugin
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->slug === $slug) {
                return $plugin;
            }
        }

        return null;
    }

    public function hasPlugin(string $slug): bool
    {
        return $this->plugin($slug) !== null;
    }

    /**
     * @return DetectedPlugin[]
     */
    public function pluginsInCategory(string $category): array
    {
        return array_values(array_filter(
            $this->plugins,
            static fn(DetectedPlugin $p) => $p->category === $category
        ));
    }

    public function totalItems(): int
    {
        return array_sum($this->contentPostTypes())
            + array_sum($this->taxonomies)
            + $this->users
            + $this->comments
            + $this->attachments
            + $this->products
            + $this->orders;
    }
}
