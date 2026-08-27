<?php

namespace justinholtweb\passer\models;

use craft\base\Model;
use justinholtweb\passer\models\plan\DomainMapping;
use justinholtweb\passer\models\plan\PostTypeMapping;
use justinholtweb\passer\models\plan\TaxonomyMapping;

/**
 * The complete description of a migration: what comes across, and what it becomes.
 *
 * A plan is saved, re-runnable and reviewable. That matters more than it sounds: a real
 * migration is never run once. It is run against a copy, examined, corrected, and run again —
 * often several times over weeks while the WordPress site is still being edited. A plan that
 * cannot be replayed makes that impossible.
 */
class MigrationPlan extends Model
{
    public ?int $id = null;
    public string $name = 'WordPress migration';

    public string $sourceType = '';

    /** @var array<string, mixed> */
    public array $sourceConfig = [];

    /** @var PostTypeMapping[] Keyed by WordPress post type. */
    public array $postTypes = [];

    /** @var TaxonomyMapping[] Keyed by WordPress taxonomy. */
    public array $taxonomies = [];

    /** @var DomainMapping[] Keyed by domain name. */
    public array $domains = [];

    // --- Users ---------------------------------------------------------------------------------

    public bool $importUsers = true;

    /** @var array<string, string> WordPress role slug => Craft user group handle. */
    public array $roleMap = [];

    /** @var array<string, string> Craft user group handles Passer should create, handle => name. */
    public array $createUserGroups = [];

    /** @var bool Create imported users as active. They cannot log in either way — WordPress
     * password hashes are not verifiable by Craft — so this only governs whether they must
     * request a reset or be activated by an admin. */
    public bool $activateUsers = false;

    /** @var bool Import users who have never authored anything. On a site with spam
     * registrations this is usually thousands of records nobody wants. */
    public bool $importNonAuthors = false;

    // --- Media ---------------------------------------------------------------------------------

    public bool $importMedia = true;
    public string $volume = '';
    public string $volumeName = '';
    public bool $createVolume = false;

    /** @var string preserve|yearMonth|flat How the uploads tree is reproduced in the volume. */
    public string $folderStrategy = 'preserve';

    /** @var bool Import attachments nothing references. */
    public bool $importOrphanedMedia = false;

    // --- Multi-site ----------------------------------------------------------------------------

    /** @var array<string, int> WordPress language code => Craft site ID. */
    public array $languageMap = [];

    /** @var int|null Craft site everything lands in when the source is not multilingual. */
    public ?int $defaultSiteId = null;

    // --- Behaviour -----------------------------------------------------------------------------

    /** @var int|null Author for content whose WordPress author cannot be resolved. */
    public ?int $fallbackAuthorId = null;

    /** @var bool Re-run behaviour: update elements already in the ID map rather than skipping. */
    public bool $updateExisting = true;

    /** @var bool Rewrite absolute WordPress URLs in imported content to Craft URLs. */
    public bool $rewriteUrls = true;

    /** @var bool Expand shortcodes found in content. */
    public bool $expandShortcodes = true;

    /**
     * @return PostTypeMapping[] Only the enabled ones.
     */
    public function enabledPostTypes(): array
    {
        return array_filter($this->postTypes, static fn(PostTypeMapping $m) => $m->enabled);
    }

    /**
     * @return TaxonomyMapping[]
     */
    public function enabledTaxonomies(): array
    {
        return array_filter(
            $this->taxonomies,
            static fn(TaxonomyMapping $m) => $m->enabled && $m->destination !== 'skip'
        );
    }

    public function domain(string $name): ?DomainMapping
    {
        return $this->domains[$name] ?? null;
    }

    public function domainEnabled(string $name): bool
    {
        return ($this->domains[$name] ?? null)?->enabled === true;
    }

    /**
     * The phases this plan will actually run, in dependency order.
     *
     * Order is not cosmetic. Media must exist before posts, because a post's featured image is a
     * relation; posts must exist before menus, because a menu item points at one; and everything
     * must exist before redirects, because a redirect's destination is resolved through the ID map.
     *
     * @return string[]
     */
    public function phases(): array
    {
        $phases = [];

        if ($this->importUsers) {
            $phases[] = 'users';
        }

        if ($this->importMedia) {
            $phases[] = 'media';
        }

        if ($this->enabledTaxonomies() !== []) {
            $phases[] = 'taxonomies';
        }

        if ($this->enabledPostTypes() !== []) {
            $phases[] = 'content';
        }

        foreach (['comments', 'commerce', 'menus', 'widgets', 'forms', 'seo', 'redirects'] as $domain) {
            if ($this->domainEnabled($domain)) {
                $phases[] = $domain;
            }
        }

        return $phases;
    }

    /**
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        return [
            'name' => $this->name,
            'postTypes' => array_map(static fn(PostTypeMapping $m) => $m->toArray(), $this->postTypes),
            'taxonomies' => array_map(static fn(TaxonomyMapping $m) => $m->toArray(), $this->taxonomies),
            'domains' => array_map(static fn(DomainMapping $m) => $m->toArray(), $this->domains),
            'importUsers' => $this->importUsers,
            'roleMap' => $this->roleMap,
            'createUserGroups' => $this->createUserGroups,
            'activateUsers' => $this->activateUsers,
            'importNonAuthors' => $this->importNonAuthors,
            'importMedia' => $this->importMedia,
            'volume' => $this->volume,
            'volumeName' => $this->volumeName,
            'createVolume' => $this->createVolume,
            'folderStrategy' => $this->folderStrategy,
            'importOrphanedMedia' => $this->importOrphanedMedia,
            'languageMap' => $this->languageMap,
            'defaultSiteId' => $this->defaultSiteId,
            'fallbackAuthorId' => $this->fallbackAuthorId,
            'updateExisting' => $this->updateExisting,
            'rewriteUrls' => $this->rewriteUrls,
            'expandShortcodes' => $this->expandShortcodes,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $plan = new self();

        foreach ($config as $key => $value) {
            if (in_array($key, ['postTypes', 'taxonomies', 'domains'], true)) {
                continue;
            }

            if ($plan->canSetProperty($key)) {
                $plan->$key = $value;
            }
        }

        foreach ($config['postTypes'] ?? [] as $key => $row) {
            $plan->postTypes[(string)$key] = new PostTypeMapping(is_array($row) ? $row : []);
        }

        foreach ($config['taxonomies'] ?? [] as $key => $row) {
            $plan->taxonomies[(string)$key] = new TaxonomyMapping(is_array($row) ? $row : []);
        }

        foreach ($config['domains'] ?? [] as $key => $row) {
            $plan->domains[(string)$key] = new DomainMapping(is_array($row) ? $row : []);
        }

        return $plan;
    }
}
