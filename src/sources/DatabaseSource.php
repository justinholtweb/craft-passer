<?php

namespace justinholtweb\passer\sources;

use justinholtweb\passer\helpers\WpSerialize;
use justinholtweb\passer\models\wp\WpComment;
use justinholtweb\passer\models\wp\WpMenu;
use justinholtweb\passer\models\wp\WpPost;
use justinholtweb\passer\models\wp\WpTerm;
use justinholtweb\passer\models\wp\WpUser;
use PDO;
use PDOException;

/**
 * Reads WordPress straight out of MySQL.
 *
 * This is the only source that can see everything, because most of what a migration needs is
 * not exposed anywhere else: menus and widgets live in `wp_options`, SEO metadata lives in
 * `wp_postmeta`, and WooCommerce lives in tables no REST route touches. It also works on a site
 * that is switched off — a restored `.sql` dump on a local MySQL is a perfectly good source, and
 * is frequently the only source a client can actually provide.
 *
 * The connection is held read-only: `assertReadOnly()` refuses credentials that can write, so a
 * misconfigured migration cannot damage the site it is reading.
 */
class DatabaseSource extends BaseSource
{
    public string $host = '127.0.0.1';
    public int $port = 3306;
    public string $database = '';
    public string $username = '';
    public string $password = '';
    public string $charset = 'utf8mb4';

    /** @var string|null Table prefix. Null asks Passer to detect it. */
    public ?string $prefix = null;

    /**
     * @var int For multisite, the blog to read. Blog 1 uses the bare prefix; blog N uses
     * `<prefix><N>_`, which is why this cannot simply be folded into the prefix.
     */
    public int $blogId = 1;

    /** @var bool Refuse credentials that can write. On by default and only turn-off-able in code. */
    public bool $enforceReadOnly = true;

    /** @var string|null Path to a CA cert for a TLS connection. */
    public ?string $sslCa = null;

    private ?PDO $pdo = null;
    private ?string $resolvedPrefix = null;
    private ?array $tableCache = null;
    private ?string $siteUrlCache = null;

    public static function type(): string
    {
        return 'database';
    }

    public static function displayName(): string
    {
        return 'MySQL database';
    }

    protected function defineCapabilities(): SourceCapabilities
    {
        return new SourceCapabilities([
            'posts' => true,
            'postMeta' => true,
            'terms' => true,
            'termMeta' => true,
            'users' => true,
            'userMeta' => true,
            'comments' => true,
            'media' => true,
            'options' => true,
            'menus' => true,
            'widgets' => true,
            'commerce' => true,
            'forms' => true,
            'redirects' => true,
            'exactCounts' => true,
            'discoversPostTypes' => true,
            'limitations' => [
                'Media files are fetched over HTTP from the site URL recorded in the database; '
                . 'if that host is gone, supply a local uploads directory instead.',
            ],
        ]);
    }

    protected function fingerprint(): string
    {
        return $this->siteUrl() ?? sprintf('mysql://%s:%d/%s', $this->host, $this->port, $this->database);
    }

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->database,
            $this->charset
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // Unbuffered queries are what make streaming a million-row wp_postmeta possible
            // without exhausting memory. The cost is that no second query can run while a
            // result set is open, which every generator in this class is careful to respect.
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ];

        if ($this->sslCa !== null && $this->sslCa !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $this->sslCa;
        }

        try {
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            throw new SourceException('Could not connect to the WordPress database: ' . $e->getMessage(), 0, $e);
        }

        if ($this->enforceReadOnly) {
            $this->assertReadOnly();
        }

        $this->connected = true;
    }

    /**
     * Refuse a connection that could modify WordPress.
     *
     * A migration has no business holding write credentials to the site it is reading, and the
     * failure mode if it does — a half-written source during a long run — is unrecoverable. The
     * check is a real attempted write against a scratch table, because `SHOW GRANTS` lies often
     * enough (grants via roles, wildcard hosts, proxied users) to be worthless.
     */
    private function assertReadOnly(): void
    {
        $table = '_passer_write_probe_' . bin2hex(random_bytes(4));

        try {
            $this->pdo->exec("CREATE TABLE `$table` (id INT)");
        } catch (PDOException) {
            // Denied, which is exactly what we want.
            return;
        }

        // It worked, so these credentials can write. Clean up and refuse.
        try {
            $this->pdo->exec("DROP TABLE `$table`");
        } catch (PDOException) {
            // Nothing useful to do; the refusal below is the important part.
        }

        throw new SourceException(
            'These database credentials can write to WordPress. Passer only ever reads, and '
            . 'refuses write access so a migration cannot damage the site it is reading. Create '
            . 'a user with SELECT only, and connect as that.'
        );
    }

    public function testConnection(): string
    {
        $this->connect();

        $prefix = $this->tablePrefix();
        $version = $this->option('db_version');
        $posts = (int)$this->scalar("SELECT COUNT(*) FROM {$this->table_('posts')} WHERE post_status != 'auto-draft'");
        $name = (string)($this->option('blogname') ?: 'WordPress');

        return sprintf(
            '%s at %s — %s rows in %sposts, DB schema %s.',
            $name,
            $this->siteUrl() ?? $this->host,
            number_format($posts),
            $prefix,
            $version !== null ? (string)$version : 'unknown'
        );
    }

    public function siteUrl(): ?string
    {
        if ($this->siteUrlCache !== null) {
            return $this->siteUrlCache ?: null;
        }

        $url = $this->option('home') ?: $this->option('siteurl');
        $this->siteUrlCache = is_string($url) ? rtrim($url, '/') : '';

        return $this->siteUrlCache ?: null;
    }

    // -----------------------------------------------------------------------------------------
    // Prefix and table naming
    // -----------------------------------------------------------------------------------------

    /**
     * The prefix in force for the selected blog.
     */
    public function tablePrefix(): string
    {
        if ($this->resolvedPrefix !== null) {
            return $this->resolvedPrefix;
        }

        $base = $this->prefix ?? $this->detectPrefix();

        // Multisite: blog 1 keeps the bare prefix, every other blog gets its ID interposed.
        $this->resolvedPrefix = $this->blogId > 1
            ? $base . $this->blogId . '_'
            : $base;

        return $this->resolvedPrefix;
    }

    /**
     * Work out the table prefix by looking for a `*posts` table with WordPress's columns.
     *
     * Sites are routinely installed with a randomised prefix as a (useless) security measure, so
     * this cannot be assumed to be `wp_`.
     */
    private function detectPrefix(): string
    {
        foreach ($this->listTables() as $table) {
            if (!str_ends_with($table, 'posts')) {
                continue;
            }

            $candidate = substr($table, 0, -strlen('posts'));

            // A multisite blog table (`wp_2_posts`) is not the base prefix.
            if (preg_match('/\d+_$/', $candidate)) {
                continue;
            }

            if ($this->tableHasColumn($table, 'post_content') && $this->tableHasColumn($table, 'post_type')) {
                return $candidate;
            }
        }

        throw new SourceException(
            'No WordPress tables found in this database. Check the database name, and the table '
            . 'prefix if the site uses a non-standard one.'
        );
    }

    /**
     * Fully-qualified, back-quoted name of a prefixed WordPress table.
     *
     * Named with a trailing underscore because `table()` is the interface's arbitrary-table
     * reader and the two would otherwise collide.
     */
    private function table_(string $name): string
    {
        return '`' . $this->tablePrefix() . $name . '`';
    }

    /**
     * Some tables are global in multisite and never carry the blog ID: users and usermeta.
     */
    private function globalTable(string $name): string
    {
        $base = $this->prefix ?? $this->detectPrefix();

        return '`' . $base . $name . '`';
    }

    /**
     * @return string[]
     */
    private function listTables(): array
    {
        if ($this->tableCache !== null) {
            return $this->tableCache;
        }

        $this->requireConnection();
        $stmt = $this->pdo->query('SHOW TABLES');
        $tables = [];

        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $tables[] = (string)$row[0];
        }

        $stmt->closeCursor();

        return $this->tableCache = $tables;
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        $this->requireConnection();

        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $this->pdo->quote($column));
            $found = $stmt->fetch() !== false;
            $stmt->closeCursor();

            return $found;
        } catch (PDOException) {
            return false;
        }
    }

    public function hasTable(string $table): bool
    {
        $unprefixed = $this->tablePrefix() . $table;

        return in_array($unprefixed, $this->listTables(), true)
            || in_array($table, $this->listTables(), true);
    }

    // -----------------------------------------------------------------------------------------
    // Low-level query helpers
    // -----------------------------------------------------------------------------------------

    private function scalar(string $sql, array $params = []): mixed
    {
        $this->requireConnection();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        $stmt->closeCursor();

        return $value === false ? null : $value;
    }

    /**
     * Run a query and buffer every row. Only for result sets known to be small — anything that
     * scales with site size must use `stream()`.
     */
    private function all(string $sql, array $params = []): array
    {
        $this->requireConnection();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();

        return $rows;
    }

    /**
     * Run a query and yield rows one at a time.
     *
     * The connection is unbuffered, so the cursor must be drained or closed before any other
     * query runs. Callers that need a second query per row must therefore collect IDs first —
     * which is what the batched loops below do.
     *
     * @return \Generator<array<string, mixed>>
     */
    private function stream(string $sql, array $params = []): \Generator
    {
        $this->requireConnection();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        try {
            while (($row = $stmt->fetch()) !== false) {
                yield $row;
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    // -----------------------------------------------------------------------------------------
    // Discovery
    // -----------------------------------------------------------------------------------------

    public function postTypes(): array
    {
        $rows = $this->all(
            "SELECT post_type, COUNT(*) AS c
             FROM {$this->table_('posts')}
             WHERE post_status NOT IN ('auto-draft', 'trash')
             GROUP BY post_type
             ORDER BY c DESC"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row['post_type']] = (int)$row['c'];
        }

        return $out;
    }

    public function taxonomies(): array
    {
        $rows = $this->all(
            "SELECT taxonomy, COUNT(*) AS c
             FROM {$this->table_('term_taxonomy')}
             GROUP BY taxonomy
             ORDER BY c DESC"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row['taxonomy']] = (int)$row['c'];
        }

        return $out;
    }

    /**
     * Every distinct meta key in use for a post type, with its row count.
     *
     * This is what lets the wizard show a real field-mapping screen instead of asking the user
     * to remember what their own site stores.
     *
     * @return array<string, int>
     */
    public function metaKeyHistogram(?string $postType = null, int $limit = 500): array
    {
        $sql = "SELECT pm.meta_key, COUNT(*) AS c
                FROM {$this->table_('postmeta')} pm";
        $params = [];

        if ($postType !== null) {
            $sql .= " INNER JOIN {$this->table_('posts')} p ON p.ID = pm.post_id
                      WHERE p.post_type = :pt";
            $params[':pt'] = $postType;
        }

        $sql .= ' GROUP BY pm.meta_key ORDER BY c DESC LIMIT ' . (int)$limit;

        $out = [];
        foreach ($this->all($sql, $params) as $row) {
            $out[(string)$row['meta_key']] = (int)$row['c'];
        }

        return $out;
    }

    // -----------------------------------------------------------------------------------------
    // Content
    // -----------------------------------------------------------------------------------------

    public function posts(string $postType, int $offset = 0, ?int $limit = null): \Generator
    {
        $batch = 100;
        $fetched = 0;

        while (true) {
            $take = $limit !== null ? min($batch, $limit - $fetched) : $batch;

            if ($take <= 0) {
                return;
            }

            // Collect the batch fully before enriching, because the unbuffered cursor forbids a
            // second query while this one is open.
            $rows = $this->all(
                "SELECT * FROM {$this->table_('posts')}
                 WHERE post_type = :pt AND post_status NOT IN ('auto-draft')
                 ORDER BY ID ASC
                 LIMIT :lim OFFSET :off",
                [':pt' => $postType, ':lim' => $take, ':off' => $offset + $fetched]
            );

            if ($rows === []) {
                return;
            }

            $ids = array_map(static fn(array $r) => (int)$r['ID'], $rows);
            $meta = $this->postMetaFor($ids);
            $terms = $this->termsForPosts($ids);

            foreach ($rows as $row) {
                $post = $this->hydratePost($row);
                $post->setMetaFromRows($meta[$post->id] ?? []);
                $post->terms = $terms[$post->id] ?? [];

                if ($post->isAttachment()) {
                    $post->attachmentUrl = $this->attachmentUrl($post);
                }

                yield $post;
            }

            $fetched += count($rows);

            if (count($rows) < $take) {
                return;
            }
        }
    }

    public function post(int $id): ?WpPost
    {
        $rows = $this->all("SELECT * FROM {$this->table_('posts')} WHERE ID = :id", [':id' => $id]);

        if ($rows === []) {
            return null;
        }

        $post = $this->hydratePost($rows[0]);
        $post->setMetaFromRows($this->postMetaFor([$id])[$id] ?? []);
        $post->terms = $this->termsForPosts([$id])[$id] ?? [];

        if ($post->isAttachment()) {
            $post->attachmentUrl = $this->attachmentUrl($post);
        }

        return $post;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydratePost(array $row): WpPost
    {
        $post = new WpPost();
        $post->id = (int)$row['ID'];
        $post->authorId = (int)$row['post_author'];
        $post->date = $this->nullDate($row['post_date'] ?? null);
        $post->dateGmt = $this->nullDate($row['post_date_gmt'] ?? null);
        $post->content = (string)($row['post_content'] ?? '');
        $post->title = (string)($row['post_title'] ?? '');
        $post->excerpt = (string)($row['post_excerpt'] ?? '');
        $post->status = (string)($row['post_status'] ?? 'publish');
        $post->commentStatus = (string)($row['comment_status'] ?? 'open');
        $post->pingStatus = (string)($row['ping_status'] ?? 'open');
        $post->name = (string)($row['post_name'] ?? '');
        $post->parentId = (int)($row['post_parent'] ?? 0);
        $post->modified = $this->nullDate($row['post_modified'] ?? null);
        $post->modifiedGmt = $this->nullDate($row['post_modified_gmt'] ?? null);
        $post->menuOrder = (int)($row['menu_order'] ?? 0);
        $post->type = (string)($row['post_type'] ?? 'post');
        $post->mimeType = (string)($row['post_mime_type'] ?? '');
        $post->commentCount = (int)($row['comment_count'] ?? 0);
        $post->guid = (string)($row['guid'] ?? '') ?: null;

        return $post;
    }

    /**
     * WordPress writes `0000-00-00 00:00:00` for "no date", which is not a date and which every
     * strict-mode MySQL and every PHP DateTime constructor disagrees about.
     */
    private function nullDate(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return $value;
    }

    /**
     * @param int[] $ids
     * @return array<int, array<int, array{meta_key: string, meta_value: mixed}>>
     */
    private function postMetaFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $in = implode(',', array_map('intval', $ids));
        $out = [];

        foreach ($this->all("SELECT post_id, meta_key, meta_value FROM {$this->table_('postmeta')} WHERE post_id IN ($in)") as $row) {
            $out[(int)$row['post_id']][] = [
                'meta_key' => (string)$row['meta_key'],
                'meta_value' => $row['meta_value'],
            ];
        }

        return $out;
    }

    /**
     * @param int[] $ids
     * @return array<int, array<string, WpTerm[]>>
     */
    private function termsForPosts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $in = implode(',', array_map('intval', $ids));

        $rows = $this->all(
            "SELECT tr.object_id, t.term_id, tt.term_taxonomy_id, t.name, t.slug,
                    tt.taxonomy, tt.description, tt.parent, tt.count
             FROM {$this->table_('term_relationships')} tr
             INNER JOIN {$this->table_('term_taxonomy')} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$this->table_('terms')} t ON t.term_id = tt.term_id
             WHERE tr.object_id IN ($in)
             ORDER BY tr.term_order ASC, t.name ASC"
        );

        $out = [];

        foreach ($rows as $row) {
            $term = $this->hydrateTerm($row);
            $out[(int)$row['object_id']][$term->taxonomy][] = $term;
        }

        return $out;
    }

    public function terms(string $taxonomy, int $offset = 0, ?int $limit = null): \Generator
    {
        $sql = "SELECT t.term_id, tt.term_taxonomy_id, t.name, t.slug,
                       tt.taxonomy, tt.description, tt.parent, tt.count
                FROM {$this->table_('term_taxonomy')} tt
                INNER JOIN {$this->table_('terms')} t ON t.term_id = tt.term_id
                WHERE tt.taxonomy = :tax
                ORDER BY tt.parent ASC, t.name ASC";

        $params = [':tax' => $taxonomy];

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        } elseif ($offset > 0) {
            $sql .= ' LIMIT 18446744073709551615 OFFSET ' . (int)$offset;
        }

        $rows = $this->all($sql, $params);
        $ids = array_map(static fn(array $r) => (int)$r['term_id'], $rows);
        $meta = $this->termMetaFor($ids);

        foreach ($rows as $row) {
            $term = $this->hydrateTerm($row);
            $term->meta = $meta[$term->id] ?? [];

            yield $term;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTerm(array $row): WpTerm
    {
        $term = new WpTerm();
        $term->id = (int)$row['term_id'];
        $term->taxonomyId = (int)($row['term_taxonomy_id'] ?? 0);
        $term->name = (string)($row['name'] ?? '');
        $term->slug = (string)($row['slug'] ?? '');
        $term->taxonomy = (string)($row['taxonomy'] ?? 'category');
        $term->description = (string)($row['description'] ?? '');
        $term->parentId = (int)($row['parent'] ?? 0);
        $term->count = (int)($row['count'] ?? 0);

        return $term;
    }

    /**
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    private function termMetaFor(array $ids): array
    {
        // termmeta arrived in WordPress 4.4; plenty of long-lived sites predate it.
        if ($ids === [] || !$this->hasTable('termmeta')) {
            return [];
        }

        $in = implode(',', array_map('intval', $ids));
        $out = [];

        foreach ($this->all("SELECT term_id, meta_key, meta_value FROM {$this->table_('termmeta')} WHERE term_id IN ($in)") as $row) {
            $out[(int)$row['term_id']][(string)$row['meta_key']] = WpSerialize::unserialize($row['meta_value']);
        }

        return $out;
    }

    // -----------------------------------------------------------------------------------------
    // Users and comments
    // -----------------------------------------------------------------------------------------

    public function users(int $offset = 0, ?int $limit = null): \Generator
    {
        $sql = "SELECT * FROM {$this->globalTable('users')} ORDER BY ID ASC";

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        }

        $rows = $this->all($sql);
        $ids = array_map(static fn(array $r) => (int)$r['ID'], $rows);
        $meta = $this->userMetaFor($ids);
        $capsKey = $this->tablePrefix() . 'capabilities';

        foreach ($rows as $row) {
            $user = new WpUser();
            $user->id = (int)$row['ID'];
            $user->login = (string)($row['user_login'] ?? '');
            $user->email = (string)($row['user_email'] ?? '');
            $user->niceName = (string)($row['user_nicename'] ?? '');
            $user->displayName = (string)($row['display_name'] ?? '');
            $user->url = ($row['user_url'] ?? '') ?: null;
            $user->registered = $this->nullDate($row['user_registered'] ?? null);
            $user->passwordHash = ($row['user_pass'] ?? '') ?: null;
            $user->meta = $meta[$user->id] ?? [];

            $caps = $user->meta[$capsKey] ?? null;
            if (is_array($caps)) {
                $user->roles = array_keys(array_filter($caps));
            }

            yield $user;
        }
    }

    /**
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    private function userMetaFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $in = implode(',', array_map('intval', $ids));
        $out = [];

        foreach ($this->all("SELECT user_id, meta_key, meta_value FROM {$this->globalTable('usermeta')} WHERE user_id IN ($in)") as $row) {
            $out[(int)$row['user_id']][(string)$row['meta_key']] = WpSerialize::unserialize($row['meta_value']);
        }

        return $out;
    }

    public function comments(int $offset = 0, ?int $limit = null): \Generator
    {
        $sql = "SELECT * FROM {$this->table_('comments')} ORDER BY comment_ID ASC";

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        }

        foreach ($this->stream($sql) as $row) {
            $comment = new WpComment();
            $comment->id = (int)$row['comment_ID'];
            $comment->postId = (int)$row['comment_post_ID'];
            $comment->authorName = (string)($row['comment_author'] ?? '');
            $comment->authorEmail = (string)($row['comment_author_email'] ?? '');
            $comment->authorUrl = (string)($row['comment_author_url'] ?? '');
            $comment->authorIp = (string)($row['comment_author_IP'] ?? '');
            $comment->date = $this->nullDate($row['comment_date'] ?? null);
            $comment->content = (string)($row['comment_content'] ?? '');
            $comment->karma = (int)($row['comment_karma'] ?? 0);
            $comment->approved = (string)($row['comment_approved'] ?? '1');
            $comment->agent = (string)($row['comment_agent'] ?? '');
            $comment->type = (string)($row['comment_type'] ?? 'comment') ?: 'comment';
            $comment->parentId = (int)($row['comment_parent'] ?? 0);
            $comment->userId = (int)($row['user_id'] ?? 0);

            yield $comment;
        }
    }

    // -----------------------------------------------------------------------------------------
    // Options, menus, widgets
    // -----------------------------------------------------------------------------------------

    public function option(string $name): mixed
    {
        $value = $this->scalar(
            "SELECT option_value FROM {$this->table_('options')} WHERE option_name = :n LIMIT 1",
            [':n' => $name]
        );

        return $value === null ? null : WpSerialize::unserialize($value);
    }

    public function optionsLike(string $prefix): array
    {
        $rows = $this->all(
            "SELECT option_name, option_value FROM {$this->table_('options')} WHERE option_name LIKE :p",
            [':p' => $prefix . '%']
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row['option_name']] = WpSerialize::unserialize($row['option_value']);
        }

        return $out;
    }

    public function menus(): array
    {
        $menus = [];

        foreach ($this->terms('nav_menu') as $term) {
            $menu = new WpMenu();
            $menu->id = $term->id;
            $menu->name = $term->name;
            $menu->slug = $term->slug;

            $itemIds = $this->all(
                "SELECT tr.object_id
                 FROM {$this->table_('term_relationships')} tr
                 INNER JOIN {$this->table_('term_taxonomy')} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tt.term_id = :id AND tt.taxonomy = 'nav_menu'",
                [':id' => $term->id]
            );

            foreach ($itemIds as $row) {
                $post = $this->post((int)$row['object_id']);

                if ($post === null || $post->type !== 'nav_menu_item') {
                    continue;
                }

                $menu->items[] = $this->menuItemFromPost($post);
            }

            usort($menu->items, static fn($a, $b) => $a->order <=> $b->order);

            $menus[] = $menu;
        }

        return $this->applyMenuLocations($menus, $this->navMenuLocations());
    }

    /**
     * Theme locations are a `theme_mods_<stylesheet>` option, so the active theme must be known
     * before they can be found.
     *
     * @return array<string, int>
     */
    private function navMenuLocations(): array
    {
        $stylesheet = $this->option('stylesheet');

        if (!is_string($stylesheet) || $stylesheet === '') {
            return [];
        }

        $mods = $this->option('theme_mods_' . $stylesheet);
        $locations = is_array($mods) ? ($mods['nav_menu_locations'] ?? []) : [];

        return is_array($locations) ? array_map('intval', $locations) : [];
    }

    public function widgets(): array
    {
        $sidebars = $this->option('sidebars_widgets');

        if (!is_array($sidebars)) {
            return [];
        }

        return $this->assembleWidgets($sidebars, $this->optionsLike('widget_'));
    }

    // -----------------------------------------------------------------------------------------
    // Arbitrary tables — how the Woo, form and redirect importers reach plugin data
    // -----------------------------------------------------------------------------------------

    public function table(string $table, array $where = [], int $offset = 0, ?int $limit = null): \Generator
    {
        // The table name comes from Passer's own importers, never from user input, but it is
        // interpolated into SQL so it is validated anyway.
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new SourceException("Refusing to read table name '$table'.");
        }

        $qualified = $this->hasTable($table) && in_array($table, $this->listTables(), true)
            ? "`$table`"
            : $this->table_($table);

        $sql = "SELECT * FROM $qualified";
        $params = [];

        if ($where !== []) {
            $clauses = [];
            $i = 0;

            foreach ($where as $column => $value) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$column)) {
                    throw new SourceException("Refusing to filter on column '$column'.");
                }

                if (is_array($value)) {
                    if ($value === []) {
                        return;
                    }

                    $placeholders = [];
                    foreach ($value as $v) {
                        $ph = ':w' . $i++;
                        $placeholders[] = $ph;
                        $params[$ph] = $v;
                    }
                    $clauses[] = "`$column` IN (" . implode(',', $placeholders) . ')';
                } else {
                    $ph = ':w' . $i++;
                    $clauses[] = "`$column` = $ph";
                    $params[$ph] = $value;
                }
            }

            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        } elseif ($offset > 0) {
            $sql .= ' LIMIT 18446744073709551615 OFFSET ' . (int)$offset;
        }

        yield from $this->stream($sql, $params);
    }

    /**
     * Build an attachment's public URL from `_wp_attached_file` and the uploads base.
     */
    private function attachmentUrl(WpPost $post): ?string
    {
        $file = $post->metaValue('_wp_attached_file');

        if (is_string($file) && $file !== '') {
            $base = $this->uploadsBaseUrl();

            if ($base !== null) {
                // Files uploaded before the year/month setting existed are stored with an
                // absolute path; WordPress detects this the same way.
                if (preg_match('#^https?://#i', $file)) {
                    return $file;
                }

                return $base . '/' . ltrim($file, '/');
            }
        }

        // Fall back to the GUID, which is usually — but explicitly not guaranteed to be — the URL.
        return $post->guid;
    }

    private ?string $uploadsBase = null;

    private function uploadsBaseUrl(): ?string
    {
        if ($this->uploadsBase !== null) {
            return $this->uploadsBase ?: null;
        }

        $siteUrl = $this->siteUrl();

        if ($siteUrl === null) {
            return $this->uploadsBase = '' ?: null;
        }

        $custom = $this->option('upload_url_path');

        if (is_string($custom) && $custom !== '') {
            return $this->uploadsBase = rtrim($custom, '/');
        }

        $path = $this->option('upload_path');
        $path = is_string($path) && $path !== '' ? trim($path, '/') : 'wp-content/uploads';

        return $this->uploadsBase = $siteUrl . '/' . $path;
    }

    public function close(): void
    {
        $this->pdo = null;
        parent::close();
    }
}
