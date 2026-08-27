<?php

namespace justinholtweb\passer\services;

use craft\base\Component;
use justinholtweb\passer\models\DetectedPlugin;
use justinholtweb\passer\sources\SourceInterface;
use justinholtweb\passer\sources\UnsupportedOperationException;

/**
 * Works out which WordPress plugins a site is running, from the traces they leave.
 *
 * Asking WordPress for its active plugin list would be simpler, but the answer is wrong for a
 * migration: a plugin that was deactivated last year still has all its data in the database, and
 * that data is exactly what needs migrating. So detection is by evidence — a table, an option, a
 * meta key, a post type — which finds the dead ones too.
 */
class PluginDetector extends Component
{
    /**
     * Each signature is a plugin and the traces that prove it.
     *
     * @var array<int, array{
     *     slug: string, name: string, category: string, supported: bool,
     *     tables?: string[], options?: string[], metaKeys?: string[], postTypes?: string[],
     *     handling?: string
     * }>
     */
    private const SIGNATURES = [
        // --- SEO ---------------------------------------------------------------------------
        [
            'slug' => 'wordpress-seo',
            'name' => 'Yoast SEO',
            'category' => 'seo',
            'supported' => true,
            'options' => ['wpseo', 'wpseo_titles'],
            'metaKeys' => ['_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw'],
            'tables' => ['yoast_indexable'],
            'handling' => 'Titles, descriptions, canonicals, social metadata, robots directives and breadcrumb titles are imported.',
        ],
        [
            'slug' => 'seo-by-rank-math',
            'name' => 'Rank Math SEO',
            'category' => 'seo',
            'supported' => true,
            'options' => ['rank-math-options-titles', 'rank_math_version'],
            'metaKeys' => ['rank_math_title', 'rank_math_description', 'rank_math_focus_keyword'],
            'handling' => 'Titles, descriptions, canonicals, social metadata and robots directives are imported. Rank Math redirections are imported separately.',
        ],
        [
            'slug' => 'all-in-one-seo-pack',
            'name' => 'All in One SEO',
            'category' => 'seo',
            'supported' => true,
            'tables' => ['aioseo_posts', 'aioseo_redirects'],
            'options' => ['aioseo_options'],
            'metaKeys' => ['_aioseo_title', '_aioseo_description'],
            'handling' => 'Titles, descriptions, canonicals and social metadata are imported from the aioseo_posts table.',
        ],
        [
            'slug' => 'wp-seopress',
            'name' => 'SEOPress',
            'category' => 'seo',
            'supported' => true,
            'options' => ['seopress_titles_option_name'],
            'metaKeys' => ['_seopress_titles_title', '_seopress_titles_desc'],
            'handling' => 'Titles, descriptions, canonicals and social metadata are imported.',
        ],
        [
            'slug' => 'autodescription',
            'name' => 'The SEO Framework',
            'category' => 'seo',
            'supported' => true,
            'options' => ['autodescription-site-settings'],
            'metaKeys' => ['_genesis_title', '_genesis_description'],
            'handling' => 'Titles and descriptions are imported.',
        ],

        // --- Commerce ----------------------------------------------------------------------
        [
            'slug' => 'woocommerce',
            'name' => 'WooCommerce',
            'category' => 'commerce',
            'supported' => true,
            'tables' => ['woocommerce_order_items', 'wc_product_meta_lookup'],
            'options' => ['woocommerce_version', 'woocommerce_currency'],
            'postTypes' => ['product', 'shop_order', 'shop_coupon'],
            'handling' => 'Products, variations, categories, attributes, orders, customers and coupons become Craft Commerce records.',
        ],
        [
            'slug' => 'woocommerce-subscriptions',
            'name' => 'WooCommerce Subscriptions',
            'category' => 'commerce',
            'supported' => false,
            'postTypes' => ['shop_subscription'],
            'handling' => 'Subscriptions have no equivalent in Craft Commerce and are reported rather than imported.',
        ],
        [
            'slug' => 'easy-digital-downloads',
            'name' => 'Easy Digital Downloads',
            'category' => 'commerce',
            'supported' => false,
            'postTypes' => ['download', 'edd_payment'],
            'options' => ['edd_settings'],
            'handling' => 'Downloads are imported as entries; payments are not migrated.',
        ],

        // --- Fields ------------------------------------------------------------------------
        [
            'slug' => 'advanced-custom-fields',
            'name' => 'Advanced Custom Fields',
            'category' => 'fields',
            'supported' => true,
            'postTypes' => ['acf-field-group', 'acf-field'],
            'options' => ['acf_version'],
            'handling' => 'Field groups become Craft fields; values are mapped by field type.',
        ],
        [
            'slug' => 'meta-box',
            'name' => 'Meta Box',
            'category' => 'fields',
            'supported' => false,
            'options' => ['meta_box_version'],
            'postTypes' => ['meta-box'],
            'handling' => 'Meta Box values are available for manual mapping as raw meta keys.',
        ],
        [
            'slug' => 'pods',
            'name' => 'Pods',
            'category' => 'fields',
            'supported' => false,
            'tables' => ['podsrel'],
            'options' => ['pods_framework_version'],
            'handling' => 'Pods values are available for manual mapping as raw meta keys.',
        ],
        [
            'slug' => 'carbon-fields',
            'name' => 'Carbon Fields',
            'category' => 'fields',
            'supported' => false,
            'metaKeys' => ['_crb_'],
            'handling' => 'Carbon Fields values are available for manual mapping as raw meta keys.',
        ],

        // --- Forms -------------------------------------------------------------------------
        [
            'slug' => 'contact-form-7',
            'name' => 'Contact Form 7',
            'category' => 'forms',
            'supported' => true,
            'postTypes' => ['wpcf7_contact_form'],
            'options' => ['wpcf7'],
            'handling' => 'Form definitions are parsed out of the CF7 tag syntax and rebuilt. CF7 stores no submissions unless Flamingo is installed.',
        ],
        [
            'slug' => 'gravityforms',
            'name' => 'Gravity Forms',
            'category' => 'forms',
            'supported' => true,
            'tables' => ['gf_form', 'gf_entry', 'rg_form'],
            'options' => ['rg_form_version', 'gform_version'],
            'handling' => 'Form definitions, notifications and stored entries are imported.',
        ],
        [
            'slug' => 'wpforms-lite',
            'name' => 'WPForms',
            'category' => 'forms',
            'supported' => true,
            'postTypes' => ['wpforms'],
            'tables' => ['wpforms_entries'],
            'handling' => 'Form definitions and stored entries are imported.',
        ],
        [
            'slug' => 'ninja-forms',
            'name' => 'Ninja Forms',
            'category' => 'forms',
            'supported' => true,
            'tables' => ['nf3_forms', 'nf3_fields'],
            'postTypes' => ['nf_sub'],
            'handling' => 'Form definitions and stored submissions are imported.',
        ],
        [
            'slug' => 'formidable',
            'name' => 'Formidable Forms',
            'category' => 'forms',
            'supported' => true,
            'tables' => ['frm_forms', 'frm_fields', 'frm_items'],
            'handling' => 'Form definitions and stored entries are imported.',
        ],

        // --- Redirects ---------------------------------------------------------------------
        [
            'slug' => 'redirection',
            'name' => 'Redirection',
            'category' => 'redirects',
            'supported' => true,
            'tables' => ['redirection_items', 'redirection_groups'],
            'handling' => 'Redirects, including regular-expression ones, are imported with their status codes.',
        ],
        [
            'slug' => 'safe-redirect-manager',
            'name' => 'Safe Redirect Manager',
            'category' => 'redirects',
            'supported' => true,
            'postTypes' => ['redirect_rule'],
            'handling' => 'Redirect rules are imported.',
        ],
        [
            'slug' => 'simple-301-redirects',
            'name' => 'Simple 301 Redirects',
            'category' => 'redirects',
            'supported' => true,
            'options' => ['301_redirects'],
            'handling' => 'Redirects are imported as permanent.',
        ],
        [
            'slug' => 'eps-301-redirects',
            'name' => '301 Redirects (EPS)',
            'category' => 'redirects',
            'supported' => true,
            'tables' => ['eps_redirects'],
            'handling' => 'Redirects are imported.',
        ],

        // --- Multilingual ------------------------------------------------------------------
        [
            'slug' => 'sitepress-multilingual-cms',
            'name' => 'WPML',
            'category' => 'multilingual',
            'supported' => true,
            'tables' => ['icl_translations', 'icl_languages'],
            'handling' => 'Translation groups are used to attach imported content to the matching Craft site.',
        ],
        [
            'slug' => 'polylang',
            'name' => 'Polylang',
            'category' => 'multilingual',
            'supported' => true,
            'options' => ['polylang'],
            'handling' => 'The language taxonomy is used to attach imported content to the matching Craft site.',
        ],

        // --- Comments and other ------------------------------------------------------------
        [
            'slug' => 'akismet',
            'name' => 'Akismet',
            'category' => 'comments',
            'supported' => true,
            'options' => ['akismet_strictness', 'wordpress_api_key'],
            'handling' => 'Akismet spam verdicts are used to skip comments WordPress already judged spam.',
        ],
        [
            'slug' => 'wp-super-cache',
            'name' => 'WP Super Cache',
            'category' => 'other',
            'supported' => false,
            'options' => ['wpsupercache_start'],
            'handling' => 'Nothing to migrate.',
        ],
        [
            'slug' => 'elementor',
            'name' => 'Elementor',
            'category' => 'other',
            'supported' => false,
            'metaKeys' => ['_elementor_data', '_elementor_edit_mode'],
            'handling' => 'Elementor stores its layout as JSON that only Elementor can render. Passer imports the rendered HTML where it can and reports every page that needs rebuilding.',
        ],
        [
            'slug' => 'wpbakery',
            'name' => 'WPBakery Page Builder',
            'category' => 'other',
            'supported' => false,
            'metaKeys' => ['_wpb_vc_js_status'],
            'handling' => 'WPBakery shortcodes are expanded where a handler exists and left as text otherwise; affected pages are reported.',
        ],
        [
            'slug' => 'divi',
            'name' => 'Divi Builder',
            'category' => 'other',
            'supported' => false,
            'metaKeys' => ['_et_pb_use_builder'],
            'handling' => 'Divi shortcodes are expanded where a handler exists; affected pages are reported.',
        ],
    ];

    /**
     * @return DetectedPlugin[]
     */
    public function detect(SourceInterface $source): array
    {
        $capabilities = $source->capabilities();
        $found = [];

        // Cheap lookups first, so a slow source is not asked the same question repeatedly.
        $postTypes = [];

        try {
            $postTypes = $source->postTypes();
        } catch (\Throwable) {
            // A source that cannot enumerate post types simply contributes no evidence here.
        }

        $metaKeys = $this->metaKeySample($source);

        foreach (self::SIGNATURES as $signature) {
            $plugin = $this->match($source, $signature, $postTypes, $metaKeys, $capabilities->options);

            if ($plugin !== null) {
                $found[] = $plugin;
            }
        }

        return $found;
    }

    /**
     * @param array{slug: string, name: string, category: string, supported: bool, tables?: string[], options?: string[], metaKeys?: string[], postTypes?: string[], handling?: string} $signature
     * @param array<string, int> $postTypes
     * @param array<string, int> $metaKeys
     */
    private function match(
        SourceInterface $source,
        array $signature,
        array $postTypes,
        array $metaKeys,
        bool $canReadOptions,
    ): ?DetectedPlugin {
        $plugin = new DetectedPlugin([
            'slug' => $signature['slug'],
            'name' => $signature['name'],
            'category' => $signature['category'],
            'supported' => $signature['supported'],
            'handling' => $signature['handling'] ?? null,
        ]);

        foreach ($signature['tables'] ?? [] as $table) {
            try {
                if ($source->hasTable($table)) {
                    $plugin->evidence = 'table';
                    $plugin->evidenceDetail = $table;

                    return $plugin;
                }
            } catch (\Throwable) {
                // Sources that cannot list tables just skip this class of evidence.
            }
        }

        foreach ($signature['postTypes'] ?? [] as $postType) {
            if (isset($postTypes[$postType])) {
                $plugin->evidence = 'postType';
                $plugin->evidenceDetail = $postType;
                $plugin->dataCount = $postTypes[$postType];

                return $plugin;
            }
        }

        foreach ($signature['metaKeys'] ?? [] as $key) {
            foreach ($metaKeys as $seen => $count) {
                // Some signatures are prefixes (`_crb_`), so a prefix match counts.
                if ($seen === $key || str_starts_with($seen, $key)) {
                    $plugin->evidence = 'meta';
                    $plugin->evidenceDetail = $seen;
                    $plugin->dataCount = $count;

                    return $plugin;
                }
            }
        }

        if ($canReadOptions) {
            foreach ($signature['options'] ?? [] as $option) {
                try {
                    if ($source->option($option) !== null) {
                        $plugin->evidence = 'option';
                        $plugin->evidenceDetail = $option;

                        return $plugin;
                    }
                } catch (UnsupportedOperationException | \Throwable) {
                    break;
                }
            }
        }

        return null;
    }

    /**
     * A histogram of meta keys, when the source can produce one cheaply.
     *
     * @return array<string, int>
     */
    private function metaKeySample(SourceInterface $source): array
    {
        if (method_exists($source, 'metaKeyHistogram')) {
            try {
                return $source->metaKeyHistogram(null, 1000);
            } catch (\Throwable) {
                return [];
            }
        }

        return [];
    }
}
