<?php

namespace justinholtweb\passer\services;

use Craft;
use craft\base\Component;
use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\Entries;
use craft\fields\Lightswitch;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\Tags;
use craft\helpers\StringHelper;
use justinholtweb\passer\models\Inventory;
use justinholtweb\passer\models\MigrationPlan;
use justinholtweb\passer\models\plan\DomainMapping;
use justinholtweb\passer\models\plan\PostTypeMapping;
use justinholtweb\passer\models\plan\TaxonomyMapping;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\sources\SourceCapabilities;

/**
 * Proposes a migration plan from an inventory.
 *
 * The proposal matters because the alternative — an empty mapping screen and a hundred rows to
 * fill in — is where WordPress migrations stall. Every suggestion here is overridable in the
 * wizard; the job is to make the common case correct by default, and the uncommon case visible.
 */
class Planner extends Component
{
    /**
     * Meta keys that exist to serve WordPress or a plugin, not the content. Suggesting these as
     * fields would bury the two or three keys that actually matter.
     */
    private const NOISE_PREFIXES = [
        '_edit_lock', '_edit_last', '_wp_', '_oembed_', '_menu_item_', '_thumbnail_id',
        '_yoast_', 'rank_math_', '_aioseo_', '_seopress_', '_genesis_',
        '_elementor_', '_et_pb_', '_vc_', '_wpb_',
        '_price', '_regular_price', '_sale_price', '_sku', '_stock', '_manage_stock',
        '_virtual', '_downloadable', '_weight', '_length', '_width', '_height',
        '_tax_status', '_tax_class', '_product_', '_children', '_visibility',
        '_backorders', '_sold_individually', '_purchase_note', '_default_attributes',
        '_wc_', '_billing_', '_shipping_', '_order_', '_customer_', '_payment_',
        '_transaction_id', '_cart_', '_recorded_',
        '_pingme', '_encloseme', '_pending', '_transient', '_wpas_',
        '_acf-', '_dp_original', '_wpml_', '_icl_',
        'classic-editor-remember', 'footnotes',
    ];

    public function propose(Inventory $inventory, SourceCapabilities $capabilities): MigrationPlan
    {
        $plan = new MigrationPlan();
        $plan->name = ($inventory->siteName ?: 'WordPress') . ' migration';
        $plan->sourceType = $inventory->sourceType;
        $plan->defaultSiteId = Craft::$app->getSites()->getPrimarySite()->id;
        $plan->fallbackAuthorId = $this->firstAdminId();

        $this->proposePostTypes($plan, $inventory);
        $this->proposeTaxonomies($plan, $inventory);
        $this->proposeUsers($plan, $inventory);
        $this->proposeMedia($plan, $inventory);
        $this->proposeDomains($plan, $inventory, $capabilities);
        $this->proposeLanguages($plan, $inventory);

        return $plan;
    }

    // -----------------------------------------------------------------------------------------
    // Post types
    // -----------------------------------------------------------------------------------------

    private function proposePostTypes(MigrationPlan $plan, Inventory $inventory): void
    {
        $entries = Craft::$app->getEntries();

        foreach ($inventory->contentPostTypes() as $postType => $count) {
            // Woo's own post types are handled by the commerce phase, not as generic content.
            if (in_array($postType, ['product', 'shop_order', 'shop_coupon', 'shop_subscription'], true)) {
                continue;
            }

            // Form plugins' post types are handled by the forms phase.
            if (in_array($postType, ['wpcf7_contact_form', 'wpforms', 'nf_sub', 'redirect_rule'], true) ) {
                continue;
            }

            $mapping = new PostTypeMapping();
            $mapping->postType = $postType;
            $mapping->enabled = $count > 0;

            $handle = $this->handleFor($postType);
            $mapping->sectionName = $this->titleFor($postType);
            $mapping->entryTypeName = $mapping->sectionName;

            // A WordPress `page` is hierarchical and a `post` is not, which maps exactly onto
            // Craft's structure/channel distinction.
            $mapping->sectionType = $postType === 'page' ? 'structure' : 'channel';

            $existing = $entries->getSectionByHandle($handle);

            if ($existing !== null) {
                $mapping->section = $existing->handle;
                $mapping->sectionName = $existing->name;
                $mapping->sectionType = $existing->type;
                $mapping->createSection = false;

                $entryType = $existing->getEntryTypes()[0] ?? null;
                $mapping->entryType = $entryType?->handle ?? $handle;
                $mapping->entryTypeName = $entryType?->name ?? $mapping->sectionName;
                $mapping->createEntryType = $entryType === null;
            } else {
                $mapping->section = $handle;
                $mapping->entryType = $handle;
                $mapping->createSection = true;
                $mapping->createEntryType = true;
            }

            $mapping->contentField = 'body';
            $mapping->createContentField = Craft::$app->getFields()->getFieldByHandle('body') === null;
            $mapping->contentFormat = $this->isInstalled('ckeditor') ? 'ckeditor' : 'html';
            $mapping->fallbackAuthorId = $plan->fallbackAuthorId;
            $mapping->siteIds = $plan->defaultSiteId !== null ? [$plan->defaultSiteId] : [];

            $this->proposeFieldsFor($mapping, $inventory, $postType);
            $this->proposeTaxonomyFieldsFor($mapping, $inventory, $postType);

            $plan->postTypes[$postType] = $mapping;
        }
    }

    /**
     * Suggest field mappings for the meta keys that look like real content.
     */
    private function proposeFieldsFor(PostTypeMapping $mapping, Inventory $inventory, string $postType): void
    {
        $keys = $inventory->metaKeysByType[$postType] ?? array_keys($inventory->metaKeys);
        $fields = Craft::$app->getFields();

        foreach ($keys as $key) {
            if ($this->isNoise($key)) {
                continue;
            }

            $handle = $this->handleFor($key);

            if ($handle === '') {
                continue;
            }

            $mapping->fieldMap[$key] = $handle;

            if ($fields->getFieldByHandle($handle) === null) {
                $mapping->createFields[$handle] = PlainText::class;
            }
        }

        // Two special cases every WordPress site has, and neither is a plain text field.
        if ($fields->getFieldByHandle('featuredImage') !== null || $mapping->enabled) {
            $mapping->featuredImageField = 'featuredImage';

            if ($fields->getFieldByHandle('featuredImage') === null) {
                $mapping->createFields['featuredImage'] = Assets::class;
            }
        }
    }

    private function proposeTaxonomyFieldsFor(PostTypeMapping $mapping, Inventory $inventory, string $postType): void
    {
        $attached = $inventory->postTypeTaxonomies[$postType] ?? [];

        // Without an explicit post-type-to-taxonomy map, fall back to WordPress's own defaults,
        // which cover the overwhelming majority of sites.
        if ($attached === [] && $postType === 'post') {
            $attached = array_values(array_intersect(['category', 'post_tag'], array_keys($inventory->taxonomies)));
        }

        $fields = Craft::$app->getFields();

        foreach ($attached as $taxonomy) {
            $handle = $this->handleFor($taxonomy === 'post_tag' ? 'tags' : $taxonomy);
            $mapping->taxonomyFields[$taxonomy] = $handle;

            if ($fields->getFieldByHandle($handle) === null) {
                $mapping->createFields[$handle] = $taxonomy === 'post_tag' ? Tags::class : Categories::class;
            }
        }
    }

    // -----------------------------------------------------------------------------------------
    // Taxonomies
    // -----------------------------------------------------------------------------------------

    private function proposeTaxonomies(MigrationPlan $plan, Inventory $inventory): void
    {
        $categories = Craft::$app->getCategories();
        $tags = Craft::$app->getTags();

        foreach ($inventory->contentTaxonomies() as $taxonomy => $count) {
            // Woo's product taxonomies belong to the commerce phase.
            if (str_starts_with($taxonomy, 'pa_') || in_array($taxonomy, ['product_cat', 'product_tag', 'product_type', 'product_shipping_class'], true)) {
                continue;
            }

            $mapping = new TaxonomyMapping();
            $mapping->taxonomy = $taxonomy;
            $mapping->enabled = $count > 0;
            $mapping->name = $this->titleFor($taxonomy === 'post_tag' ? 'tags' : $taxonomy);
            $mapping->handle = $this->handleFor($taxonomy === 'post_tag' ? 'tags' : $taxonomy);
            $mapping->siteIds = $plan->defaultSiteId !== null ? [$plan->defaultSiteId] : [];

            // WordPress tags are flat and categories are hierarchical, which is precisely the
            // difference between a Craft tag group and a category group.
            $mapping->destination = $taxonomy === 'post_tag' ? 'tags' : 'categories';

            $existing = $mapping->destination === 'tags'
                ? $tags->getTagGroupByHandle($mapping->handle)
                : $categories->getGroupByHandle($mapping->handle);

            $mapping->createGroup = $existing === null;

            if ($existing !== null) {
                $mapping->name = $existing->name;
            }

            $plan->taxonomies[$taxonomy] = $mapping;
        }
    }

    // -----------------------------------------------------------------------------------------
    // Users, media
    // -----------------------------------------------------------------------------------------

    private function proposeUsers(MigrationPlan $plan, Inventory $inventory): void
    {
        $plan->importUsers = $inventory->users > 0;

        $groups = Craft::$app->getUserGroups();

        // WordPress roles map onto Craft user groups by name; the ones Craft has no notion of
        // (subscriber, customer) are still worth creating, because they are how a site
        // distinguishes its audience from its staff.
        $defaults = [
            'administrator' => 'Administrators',
            'editor' => 'Editors',
            'author' => 'Authors',
            'contributor' => 'Contributors',
            'subscriber' => 'Subscribers',
            'customer' => 'Customers',
            'shop_manager' => 'Shop Managers',
        ];

        foreach ($defaults as $role => $name) {
            $handle = $this->handleFor($role . 's');
            $existing = $groups->getGroupByHandle($handle);

            $plan->roleMap[$role] = $handle;

            if ($existing === null) {
                $plan->createUserGroups[$handle] = $name;
            }
        }

        // Administrators are not a Craft group; Craft has an admin flag instead. Mapping the
        // WordPress administrator role onto a group rather than the flag is deliberate — a
        // migration should not silently mint Craft admins.
        $plan->createUserGroups[$this->handleFor('administrators')] = 'Administrators (from WordPress)';
    }

    private function proposeMedia(MigrationPlan $plan, Inventory $inventory): void
    {
        $plan->importMedia = $inventory->attachments > 0;

        $volumes = Craft::$app->getVolumes();
        $existing = $volumes->getVolumeByHandle('uploads') ?? ($volumes->getAllVolumes()[0] ?? null);

        if ($existing !== null) {
            $plan->volume = $existing->handle;
            $plan->volumeName = $existing->name;
            $plan->createVolume = false;
        } else {
            $plan->volume = 'wordpress';
            $plan->volumeName = 'WordPress uploads';
            // Creating a volume needs a filesystem, which Passer cannot invent — the wizard
            // asks for one rather than guessing.
            $plan->createVolume = true;
        }
    }

    // -----------------------------------------------------------------------------------------
    // Domains
    // -----------------------------------------------------------------------------------------

    private function proposeDomains(MigrationPlan $plan, Inventory $inventory, SourceCapabilities $capabilities): void
    {
        $registry = Plugin::getInstance()->destinations;

        $present = [
            'comments' => $inventory->comments > 0,
            'commerce' => $inventory->products > 0 || $inventory->orders > 0,
            'menus' => $inventory->menus > 0,
            'widgets' => $inventory->widgets > 0,
            'forms' => $inventory->forms > 0,
            'seo' => $inventory->pluginsInCategory('seo') !== [],
            'redirects' => $inventory->redirects > 0,
        ];

        foreach ($present as $domain => $hasData) {
            $mapping = new DomainMapping();
            $mapping->domain = $domain;

            $preferred = $registry->preferredFor($domain);
            $mapping->destination = $preferred?->handle ?? '';

            // Enable only what there is data for, and only what this source can actually read.
            $mapping->enabled = $hasData
                && $capabilities->supports($domain)
                && $preferred !== null
                && $preferred->handle !== 'skip';

            $plan->domains[$domain] = $mapping;
        }
    }

    private function proposeLanguages(MigrationPlan $plan, Inventory $inventory): void
    {
        if ($inventory->languages === []) {
            return;
        }

        $sites = Craft::$app->getSites()->getAllSites();

        foreach ($inventory->languages as $code) {
            foreach ($sites as $site) {
                // Match `de` to `de`, and `de` to `de-DE`, which is how the two systems usually
                // differ on the same language.
                if (
                    strcasecmp($site->language, $code) === 0
                    || str_starts_with(strtolower($site->language), strtolower($code) . '-')
                ) {
                    $plan->languageMap[$code] = $site->id;
                    break;
                }
            }
        }
    }

    // -----------------------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------------------

    private function isNoise(string $key): bool
    {
        // A leading underscore is WordPress's own convention for "hidden from the editor", and
        // is right far more often than it is wrong.
        foreach (self::NOISE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function handleFor(string $name): string
    {
        $handle = StringHelper::toCamelCase(preg_replace('/[^A-Za-z0-9]+/', ' ', $name) ?? '');

        // A handle must start with a letter, and WordPress keys frequently start with a digit
        // or an underscore.
        if ($handle !== '' && !preg_match('/^[a-zA-Z]/', $handle)) {
            $handle = 'wp' . ucfirst($handle);
        }

        return $handle;
    }

    private function titleFor(string $name): string
    {
        return StringHelper::titleize(str_replace(['_', '-'], ' ', $name));
    }

    private function isInstalled(string $handle): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled($handle);
    }

    private function firstAdminId(): ?int
    {
        $admin = \craft\elements\User::find()->admin()->status(null)->orderBy(['id' => SORT_ASC])->one();

        return $admin?->id;
    }
}
