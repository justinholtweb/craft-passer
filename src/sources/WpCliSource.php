<?php

namespace justinholtweb\passer\sources;

use craft\helpers\Json;
use justinholtweb\passer\helpers\WpSerialize;
use justinholtweb\passer\models\wp\WpComment;
use justinholtweb\passer\models\wp\WpMenu;
use justinholtweb\passer\models\wp\WpPost;
use justinholtweb\passer\models\wp\WpTerm;
use justinholtweb\passer\models\wp\WpUser;

/**
 * Runs `wp` on the WordPress host over SSH.
 *
 * This reaches sites that neither the database source nor REST can: a managed host that will not
 * open port 3306 and has REST behind a WAF will still, almost always, give you an SSH key and a
 * `wp` binary. Because wp-cli runs inside WordPress, it can also answer questions that need
 * WordPress's own runtime — which taxonomies are registered for a post type, what a theme calls
 * its sidebars — that no amount of table reading can settle.
 *
 * Everything goes through `wp db query`, `wp option get` and friends, and every command is built
 * from an argument array rather than a string, so nothing user-supplied is ever concatenated into
 * a shell command.
 */
class WpCliSource extends BaseSource
{
    public string $host = '';
    public int $port = 22;
    public string $user = '';

    /** @var string|null Path to a private key. Null uses the agent or the default identity. */
    public ?string $identityFile = null;

    /** @var string Absolute path to the WordPress root on the remote host. */
    public string $path = '';

    /** @var string The wp binary, in case it is not on PATH. */
    public string $wpBinary = 'wp';

    /** @var bool Pass --allow-root, which some managed hosts require. */
    public bool $allowRoot = false;

    public int $timeout = 120;

    /** @var string|null Extra ssh options, e.g. a jump host. */
    public ?string $sshOptions = null;

    private ?string $siteUrlCache = null;
    private ?string $prefixCache = null;
    private ?array $tableCache = null;

    public static function type(): string
    {
        return 'wpcli';
    }

    public static function displayName(): string
    {
        return 'wp-cli over SSH';
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
                'Every read is a separate SSH round trip, so a large site imports considerably '
                . 'more slowly than it would from a database connection.',
            ],
        ]);
    }

    protected function fingerprint(): string
    {
        return $this->siteUrl() ?? sprintf('ssh://%s@%s:%d%s', $this->user, $this->host, $this->port, $this->path);
    }

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        if ($this->host === '' || $this->user === '') {
            throw new SourceException('An SSH host and user are required.');
        }

        if (!function_exists('proc_open')) {
            throw new SourceException(
                'proc_open() is disabled on this server, so Passer cannot run ssh. Use the '
                . 'database, WXR or REST source instead.'
            );
        }

        $this->connected = true;
    }

    public function testConnection(): string
    {
        $this->connect();

        $version = trim($this->wp(['core', 'version']));

        if ($version === '') {
            throw new SourceException(
                'Connected over SSH, but `wp core version` returned nothing. Check the WordPress '
                . 'path, and whether the wp binary needs --allow-root.'
            );
        }

        $posts = trim($this->wp(['db', 'query', 'SELECT COUNT(*) FROM ' . $this->tablePrefix() . 'posts', '--skip-column-names']));

        return sprintf(
            'WordPress %s at %s — %s rows in %sposts.',
            $version,
            $this->siteUrl() ?? $this->host,
            number_format((int)$posts),
            $this->tablePrefix()
        );
    }

    public function siteUrl(): ?string
    {
        if ($this->siteUrlCache === null) {
            try {
                $this->siteUrlCache = rtrim(trim($this->wp(['option', 'get', 'home'])), '/');
            } catch (SourceException) {
                $this->siteUrlCache = '';
            }
        }

        return $this->siteUrlCache ?: null;
    }

    // -----------------------------------------------------------------------------------------
    // Running commands
    // -----------------------------------------------------------------------------------------

    /**
     * Run a wp-cli command and return its stdout.
     *
     * @param string[] $args
     */
    private function wp(array $args): string
    {
        $this->connect();

        $remote = [$this->wpBinary];

        if ($this->path !== '') {
            $remote[] = '--path=' . $this->path;
        }

        if ($this->allowRoot) {
            $remote[] = '--allow-root';
        }

        $remote[] = '--skip-plugins';
        $remote[] = '--skip-themes';

        foreach ($args as $arg) {
            $remote[] = $arg;
        }

        // The remote command is a single argv element passed to ssh, so it is escaped once as a
        // shell word here and never interpolated anywhere else.
        $remoteCommand = implode(' ', array_map('escapeshellarg', $remote));

        $ssh = ['ssh', '-p', (string)$this->port, '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new'];

        if ($this->identityFile !== null && $this->identityFile !== '') {
            $ssh[] = '-i';
            $ssh[] = $this->identityFile;
        }

        if ($this->sshOptions !== null && $this->sshOptions !== '') {
            foreach (preg_split('/\s+/', trim($this->sshOptions)) ?: [] as $option) {
                $ssh[] = $option;
            }
        }

        $ssh[] = $this->user . '@' . $this->host;
        $ssh[] = $remoteCommand;

        return $this->run($ssh);
    }

    /**
     * @param string[] $command
     */
    private function run(array $command): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new SourceException('Could not start ssh.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = time() + $this->timeout;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if (time() > $deadline) {
                proc_terminate($process, 9);
                throw new SourceException("Command timed out after {$this->timeout}s.");
            }

            usleep(20_000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            throw new SourceException(trim($stderr) !== '' ? trim($stderr) : "Command failed with exit code $exit.");
        }

        return $stdout;
    }

    /**
     * Run a SELECT and return its rows.
     *
     * `wp db query` prints a tab-separated table, which is unambiguous enough for the values
     * WordPress stores except when a value contains a tab or newline — post content, most
     * obviously. So content is fetched base64-encoded by the caller wherever it matters, and this
     * helper only ever handles scalar columns.
     *
     * @return array<int, array<string, string>>
     */
    private function query(string $sql): array
    {
        $output = $this->wp(['db', 'query', $sql, '--skip-column-names=false']);
        $lines = preg_split('/\r?\n/', trim($output)) ?: [];

        if ($lines === [] || $lines[0] === '') {
            return [];
        }

        $headers = explode("\t", array_shift($lines));
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = explode("\t", $line);
            $row = [];

            foreach ($headers as $i => $header) {
                $value = $values[$i] ?? '';
                // MySQL's tab output writes SQL NULL as the literal string NULL.
                $row[$header] = $value === 'NULL' ? '' : $value;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Run a query whose rows are returned as one base64-encoded JSON blob per row.
     *
     * This is how anything containing post content is read: MySQL builds the JSON and encodes
     * it, so no value can be confused with the row or column separator.
     *
     * @return array<int, array<string, mixed>>
     */
    private function jsonQuery(string $selectList, string $from): array
    {
        $sql = "SELECT TO_BASE64(JSON_OBJECT($selectList)) FROM $from";
        $output = $this->wp(['db', 'query', $sql, '--skip-column-names']);
        $rows = [];

        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line === 'NULL') {
                continue;
            }

            $decoded = base64_decode($line, true);

            if ($decoded === false) {
                continue;
            }

            $json = Json::decodeIfJson($decoded);

            if (is_array($json)) {
                $rows[] = $json;
            }
        }

        return $rows;
    }

    public function tablePrefix(): string
    {
        if ($this->prefixCache !== null) {
            return $this->prefixCache;
        }

        // wp-cli knows the real prefix, including a multisite blog's, without any guessing.
        $prefix = trim($this->wp(['eval', 'global $wpdb; echo $wpdb->prefix;']));

        if ($prefix === '') {
            throw new SourceException('Could not determine the WordPress table prefix over wp-cli.');
        }

        return $this->prefixCache = $prefix;
    }

    /**
     * @return string[]
     */
    private function listTables(): array
    {
        if ($this->tableCache !== null) {
            return $this->tableCache;
        }

        $output = $this->wp(['db', 'tables', '--all-tables-with-prefix', '--format=csv']);

        return $this->tableCache = array_values(array_filter(array_map('trim', explode(',', trim($output)))));
    }

    public function hasTable(string $table): bool
    {
        $tables = $this->listTables();

        return in_array($table, $tables, true) || in_array($this->tablePrefix() . $table, $tables, true);
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }

    // -----------------------------------------------------------------------------------------
    // Discovery
    // -----------------------------------------------------------------------------------------

    public function postTypes(): array
    {
        $p = $this->tablePrefix();
        $rows = $this->query(
            "SELECT post_type, COUNT(*) AS c FROM {$p}posts
             WHERE post_status NOT IN ('auto-draft','trash') GROUP BY post_type ORDER BY c DESC"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row['post_type']] = (int)$row['c'];
        }

        return $out;
    }

    public function taxonomies(): array
    {
        $p = $this->tablePrefix();
        $rows = $this->query(
            "SELECT taxonomy, COUNT(*) AS c FROM {$p}term_taxonomy GROUP BY taxonomy ORDER BY c DESC"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row['taxonomy']] = (int)$row['c'];
        }

        return $out;
    }

    // -----------------------------------------------------------------------------------------
    // Content
    // -----------------------------------------------------------------------------------------

    public function posts(string $postType, int $offset = 0, ?int $limit = null): \Generator
    {
        $p = $this->tablePrefix();
        $batch = 50;
        $fetched = 0;

        while (true) {
            $take = $limit !== null ? min($batch, $limit - $fetched) : $batch;

            if ($take <= 0) {
                return;
            }

            $rows = $this->jsonQuery(
                "'ID', ID, 'post_author', post_author, 'post_date', post_date, "
                . "'post_date_gmt', post_date_gmt, 'post_content', post_content, "
                . "'post_title', post_title, 'post_excerpt', post_excerpt, 'post_status', post_status, "
                . "'comment_status', comment_status, 'ping_status', ping_status, 'post_name', post_name, "
                . "'post_parent', post_parent, 'post_modified', post_modified, "
                . "'post_modified_gmt', post_modified_gmt, 'menu_order', menu_order, 'post_type', post_type, "
                . "'post_mime_type', post_mime_type, 'comment_count', comment_count, 'guid', guid",
                "{$p}posts WHERE post_type = " . $this->quote($postType)
                . " AND post_status != 'auto-draft' ORDER BY ID ASC"
                . ' LIMIT ' . (int)$take . ' OFFSET ' . ($offset + $fetched)
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
                    $file = $post->metaValue('_wp_attached_file');
                    $post->attachmentUrl = is_string($file) && $file !== ''
                        ? ($this->siteUrl() ?? '') . '/wp-content/uploads/' . ltrim($file, '/')
                        : $post->guid;
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
        $p = $this->tablePrefix();
        $rows = $this->jsonQuery(
            "'ID', ID, 'post_author', post_author, 'post_date', post_date, 'post_content', post_content, "
            . "'post_title', post_title, 'post_excerpt', post_excerpt, 'post_status', post_status, "
            . "'post_name', post_name, 'post_parent', post_parent, 'menu_order', menu_order, "
            . "'post_type', post_type, 'post_mime_type', post_mime_type, 'guid', guid",
            "{$p}posts WHERE ID = " . (int)$id
        );

        if ($rows === []) {
            return null;
        }

        $post = $this->hydratePost($rows[0]);
        $post->setMetaFromRows($this->postMetaFor([$id])[$id] ?? []);
        $post->terms = $this->termsForPosts([$id])[$id] ?? [];

        return $post;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydratePost(array $row): WpPost
    {
        $post = new WpPost();
        $post->id = (int)($row['ID'] ?? 0);
        $post->authorId = (int)($row['post_author'] ?? 0);
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
        $post->guid = ((string)($row['guid'] ?? '')) ?: null;

        return $post;
    }

    private function nullDate(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return ($value === '' || str_starts_with($value, '0000-00-00')) ? null : $value;
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

        $p = $this->tablePrefix();
        $in = implode(',', array_map('intval', $ids));

        $rows = $this->jsonQuery(
            "'post_id', post_id, 'meta_key', meta_key, 'meta_value', meta_value",
            "{$p}postmeta WHERE post_id IN ($in)"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['post_id']][] = [
                'meta_key' => (string)($row['meta_key'] ?? ''),
                'meta_value' => $row['meta_value'] ?? null,
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

        $p = $this->tablePrefix();
        $in = implode(',', array_map('intval', $ids));

        $rows = $this->query(
            "SELECT tr.object_id, t.term_id, tt.term_taxonomy_id, t.name, t.slug, tt.taxonomy,
                    tt.parent, tt.count
             FROM {$p}term_relationships tr
             INNER JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$p}terms t ON t.term_id = tt.term_id
             WHERE tr.object_id IN ($in)"
        );

        $out = [];

        foreach ($rows as $row) {
            $term = new WpTerm();
            $term->id = (int)$row['term_id'];
            $term->taxonomyId = (int)$row['term_taxonomy_id'];
            $term->name = $row['name'];
            $term->slug = $row['slug'];
            $term->taxonomy = $row['taxonomy'];
            $term->parentId = (int)$row['parent'];
            $term->count = (int)$row['count'];

            $out[(int)$row['object_id']][$term->taxonomy][] = $term;
        }

        return $out;
    }

    public function terms(string $taxonomy, int $offset = 0, ?int $limit = null): \Generator
    {
        $p = $this->tablePrefix();
        $sql = "SELECT t.term_id, tt.term_taxonomy_id, t.name, t.slug, tt.taxonomy, tt.description,
                       tt.parent, tt.count
                FROM {$p}term_taxonomy tt
                INNER JOIN {$p}terms t ON t.term_id = tt.term_id
                WHERE tt.taxonomy = " . $this->quote($taxonomy) . '
                ORDER BY tt.parent ASC, t.name ASC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        }

        foreach ($this->query($sql) as $row) {
            $term = new WpTerm();
            $term->id = (int)$row['term_id'];
            $term->taxonomyId = (int)$row['term_taxonomy_id'];
            $term->name = $row['name'];
            $term->slug = $row['slug'];
            $term->taxonomy = $row['taxonomy'];
            $term->description = $row['description'] ?? '';
            $term->parentId = (int)$row['parent'];
            $term->count = (int)$row['count'];

            yield $term;
        }
    }

    public function users(int $offset = 0, ?int $limit = null): \Generator
    {
        $args = ['user', 'list', '--format=json', '--fields=ID,user_login,user_email,user_nicename,display_name,user_url,user_registered,roles'];

        if ($limit !== null) {
            $args[] = '--number=' . $limit;
        }

        if ($offset > 0) {
            $args[] = '--offset=' . $offset;
        }

        $rows = Json::decodeIfJson($this->wp($args));

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $user = new WpUser();
            $user->id = (int)($row['ID'] ?? 0);
            $user->login = (string)($row['user_login'] ?? '');
            $user->email = (string)($row['user_email'] ?? '');
            $user->niceName = (string)($row['user_nicename'] ?? '');
            $user->displayName = (string)($row['display_name'] ?? '');
            $user->url = ((string)($row['user_url'] ?? '')) ?: null;
            $user->registered = $this->nullDate($row['user_registered'] ?? null);

            $roles = $row['roles'] ?? '';
            $user->roles = is_array($roles)
                ? array_map('strval', $roles)
                : array_values(array_filter(array_map('trim', explode(',', (string)$roles))));

            foreach (['first_name', 'last_name', 'description'] as $key) {
                $value = trim($this->wp(['user', 'meta', 'get', (string)$user->id, $key]));
                if ($value !== '') {
                    $user->meta[$key] = $value;
                }
            }

            yield $user;
        }
    }

    public function comments(int $offset = 0, ?int $limit = null): \Generator
    {
        $p = $this->tablePrefix();
        $batch = 100;
        $fetched = 0;

        while (true) {
            $take = $limit !== null ? min($batch, $limit - $fetched) : $batch;

            if ($take <= 0) {
                return;
            }

            $rows = $this->jsonQuery(
                "'comment_ID', comment_ID, 'comment_post_ID', comment_post_ID, "
                . "'comment_author', comment_author, 'comment_author_email', comment_author_email, "
                . "'comment_author_url', comment_author_url, 'comment_author_IP', comment_author_IP, "
                . "'comment_date', comment_date, 'comment_content', comment_content, "
                . "'comment_approved', comment_approved, 'comment_type', comment_type, "
                . "'comment_parent', comment_parent, 'user_id', user_id",
                "{$p}comments ORDER BY comment_ID ASC LIMIT " . (int)$take . ' OFFSET ' . ($offset + $fetched)
            );

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $comment = new WpComment();
                $comment->id = (int)($row['comment_ID'] ?? 0);
                $comment->postId = (int)($row['comment_post_ID'] ?? 0);
                $comment->authorName = (string)($row['comment_author'] ?? '');
                $comment->authorEmail = (string)($row['comment_author_email'] ?? '');
                $comment->authorUrl = (string)($row['comment_author_url'] ?? '');
                $comment->authorIp = (string)($row['comment_author_IP'] ?? '');
                $comment->date = $this->nullDate($row['comment_date'] ?? null);
                $comment->content = (string)($row['comment_content'] ?? '');
                $comment->approved = (string)($row['comment_approved'] ?? '1');
                $comment->type = ((string)($row['comment_type'] ?? 'comment')) ?: 'comment';
                $comment->parentId = (int)($row['comment_parent'] ?? 0);
                $comment->userId = (int)($row['user_id'] ?? 0);

                yield $comment;
            }

            $fetched += count($rows);

            if (count($rows) < $take) {
                return;
            }
        }
    }

    public function option(string $name): mixed
    {
        try {
            $raw = $this->wp(['option', 'get', $name, '--format=json']);
        } catch (SourceException) {
            // wp-cli exits non-zero for an option that does not exist.
            return null;
        }

        $decoded = Json::decodeIfJson(trim($raw));

        return is_string($decoded) ? WpSerialize::unserialize($decoded) : $decoded;
    }

    public function optionsLike(string $prefix): array
    {
        $rows = $this->jsonQuery(
            "'option_name', option_name, 'option_value', option_value",
            $this->tablePrefix() . 'options WHERE option_name LIKE ' . $this->quote($prefix . '%')
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string)($row['option_name'] ?? '')] = WpSerialize::unserialize($row['option_value'] ?? null);
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

            $p = $this->tablePrefix();
            $itemIds = $this->query(
                "SELECT tr.object_id FROM {$p}term_relationships tr
                 INNER JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tt.term_id = " . (int)$term->id . " AND tt.taxonomy = 'nav_menu'"
            );

            foreach ($itemIds as $row) {
                $post = $this->post((int)$row['object_id']);

                if ($post !== null && $post->type === 'nav_menu_item') {
                    $menu->items[] = $this->menuItemFromPost($post);
                }
            }

            usort($menu->items, static fn($a, $b) => $a->order <=> $b->order);
            $menus[] = $menu;
        }

        $stylesheet = $this->option('stylesheet');
        $mods = is_string($stylesheet) ? $this->option('theme_mods_' . $stylesheet) : null;
        $locations = is_array($mods) && isset($mods['nav_menu_locations']) && is_array($mods['nav_menu_locations'])
            ? array_map('intval', $mods['nav_menu_locations'])
            : [];

        return $this->applyMenuLocations($menus, $locations);
    }

    public function widgets(): array
    {
        $sidebars = $this->option('sidebars_widgets');

        if (!is_array($sidebars)) {
            return [];
        }

        return $this->assembleWidgets($sidebars, $this->optionsLike('widget_'));
    }

    public function table(string $table, array $where = [], int $offset = 0, ?int $limit = null): \Generator
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new SourceException("Refusing to read table name '$table'.");
        }

        $qualified = in_array($table, $this->listTables(), true) ? $table : $this->tablePrefix() . $table;
        $from = "`$qualified`";

        if ($where !== []) {
            $clauses = [];

            foreach ($where as $column => $value) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$column)) {
                    throw new SourceException("Refusing to filter on column '$column'.");
                }

                if (is_array($value)) {
                    if ($value === []) {
                        return;
                    }
                    $quoted = implode(',', array_map(fn($v) => $this->quote((string)$v), $value));
                    $clauses[] = "`$column` IN ($quoted)";
                } else {
                    $clauses[] = "`$column` = " . $this->quote((string)$value);
                }
            }

            $from .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if ($limit !== null) {
            $from .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        }

        // Every column is named explicitly so the row can be rebuilt as JSON, which survives
        // values containing tabs and newlines where wp-cli's default table output does not.
        $selectList = [];

        foreach ($this->query("SHOW COLUMNS FROM `$qualified`") as $column) {
            $name = $column['Field'] ?? null;

            if ($name !== null && preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                $selectList[] = $this->quote($name) . ", `$name`";
            }
        }

        if ($selectList === []) {
            return;
        }

        yield from $this->jsonQuery(implode(', ', $selectList), $from);
    }
}
