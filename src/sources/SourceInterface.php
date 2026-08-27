<?php

namespace justinholtweb\passer\sources;

use justinholtweb\passer\models\wp\WpComment;
use justinholtweb\passer\models\wp\WpMenu;
use justinholtweb\passer\models\wp\WpPost;
use justinholtweb\passer\models\wp\WpTerm;
use justinholtweb\passer\models\wp\WpUser;
use justinholtweb\passer\models\wp\WpWidget;

/**
 * A read-only view of a WordPress site.
 *
 * Every method that can return many rows returns a Generator, because the whole point of the
 * database and WXR sources is that they work on sites too large to hold in memory. Implementations
 * must never buffer a full result set.
 */
interface SourceInterface
{
    /**
     * Machine name of this source mode: `database`, `wxr`, `rest`, `wpcli`.
     */
    public static function type(): string;

    /**
     * Human label for the wizard.
     */
    public static function displayName(): string;

    /**
     * Open the connection / file handle. Throws SourceException on failure.
     */
    public function connect(): void;

    /**
     * Prove the source is reachable and readable, returning a short human summary
     * ("WordPress 6.5.2, 1,204 posts, prefix wp_").
     *
     * @throws \justinholtweb\passer\sources\SourceException
     */
    public function testConnection(): string;

    public function capabilities(): SourceCapabilities;

    /**
     * A stable fingerprint of this WordPress install, used to namespace the ID map so two
     * separate migrations into one Craft site cannot collide. Derived from the site URL where
     * one is knowable, and the connection target otherwise.
     */
    public function sourceHash(): string;

    /**
     * The site's home URL, used for rewriting absolute links found in content.
     */
    public function siteUrl(): ?string;

    /**
     * Post type names present in the source, mapped to their row counts.
     *
     * @return array<string, int>
     */
    public function postTypes(): array;

    /**
     * Taxonomy names present in the source, mapped to their term counts.
     *
     * @return array<string, int>
     */
    public function taxonomies(): array;

    /**
     * @return \Generator<WpPost>
     */
    public function posts(string $postType, int $offset = 0, ?int $limit = null): \Generator;

    public function post(int $id): ?WpPost;

    /**
     * @return \Generator<WpTerm>
     */
    public function terms(string $taxonomy, int $offset = 0, ?int $limit = null): \Generator;

    /**
     * @return \Generator<WpUser>
     */
    public function users(int $offset = 0, ?int $limit = null): \Generator;

    /**
     * @return \Generator<WpComment>
     */
    public function comments(int $offset = 0, ?int $limit = null): \Generator;

    /**
     * A single row from `wp_options`, already unserialized.
     */
    public function option(string $name): mixed;

    /**
     * Option names matching a SQL-style prefix, mapped to their unserialized values.
     *
     * @return array<string, mixed>
     */
    public function optionsLike(string $prefix): array;

    /**
     * @return WpMenu[]
     */
    public function menus(): array;

    /**
     * @return WpWidget[]
     */
    public function widgets(): array;

    /**
     * Rows from an arbitrary WordPress table. Only the database and wp-cli sources can answer
     * this; the others must throw UnsupportedOperationException. It is how the WooCommerce and
     * form importers reach `wc_*`, `gf_*` and plugin-specific tables without every one of them
     * needing a method on this interface.
     *
     * @return \Generator<array<string, mixed>>
     */
    public function table(string $table, array $where = [], int $offset = 0, ?int $limit = null): \Generator;

    /**
     * Whether a table exists in the source, used to detect installed WordPress plugins.
     */
    public function hasTable(string $table): bool;

    public function close(): void;
}
