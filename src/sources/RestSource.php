<?php

namespace justinholtweb\passer\sources;

use craft\helpers\Json;
use justinholtweb\passer\models\wp\WpComment;
use justinholtweb\passer\models\wp\WpMenu;
use justinholtweb\passer\models\wp\WpMenuItem;
use justinholtweb\passer\models\wp\WpPost;
use justinholtweb\passer\models\wp\WpTerm;
use justinholtweb\passer\models\wp\WpUser;

/**
 * Reads WordPress over the REST API, the way `craftcms/wp-import` does.
 *
 * It is the easiest source to set up and the weakest to migrate from, and both facts are worth
 * being explicit about. REST exposes what a theme needs, not what a migration needs: `wp_options`
 * is unreachable, so widgets are gone; post meta is hidden unless a plugin registers it, so most
 * SEO and ACF data is invisible unless those plugins expose their own routes; and WooCommerce's
 * own API needs separate consumer keys that most site owners no longer have.
 *
 * It is here for parity, and because sometimes it is genuinely all a client will grant.
 */
class RestSource extends BaseSource
{
    public string $url = '';
    public string $username = '';

    /** @var string A WordPress application password, not the account password. */
    public string $applicationPassword = '';

    public int $timeout = 30;
    public int $perPage = 100;

    /** @var bool Skip TLS verification. For a staging box with a self-signed certificate. */
    public bool $insecure = false;

    private ?array $routesCache = null;
    private ?array $typesCache = null;
    private ?array $taxonomiesCache = null;

    public static function type(): string
    {
        return 'rest';
    }

    public static function displayName(): string
    {
        return 'WordPress REST API';
    }

    protected function defineCapabilities(): SourceCapabilities
    {
        $caps = new SourceCapabilities([
            'posts' => true,
            'postMeta' => true,
            'terms' => true,
            'users' => true,
            'comments' => true,
            'media' => true,
            'options' => false,
            'widgets' => false,
            'commerce' => false,
            'forms' => false,
            'redirects' => false,
            'exactCounts' => true,
            'discoversPostTypes' => true,
            'limitations' => [
                'Post meta is only visible over REST when a plugin registers it with '
                . '`show_in_rest`. Most ACF and SEO data is therefore invisible unless those '
                . 'plugins expose their own routes.',
                'wp_options is not exposed, so widgets and theme settings cannot be read.',
                'WooCommerce data requires its own consumer key and secret, which Passer does '
                . 'not ask for; migrate a store from the database instead.',
            ],
        ]);

        // The menus endpoint arrived in WordPress 5.9 and is frequently disabled.
        $caps->menus = $this->hasRoute('/wp/v2/menus');

        return $caps;
    }

    protected function fingerprint(): string
    {
        return rtrim($this->url, '/');
    }

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        if ($this->url === '') {
            throw new SourceException('No WordPress URL was supplied.');
        }

        $this->connected = true;
    }

    public function testConnection(): string
    {
        $this->connect();

        $root = $this->request('');

        if (!isset($root['name'])) {
            throw new SourceException(
                'That URL responded, but not with a WordPress REST API index. Check the URL, and '
                . 'that a security plugin is not blocking /wp-json/.'
            );
        }

        // The index is public; this call proves the credentials actually work.
        $me = $this->request('/wp/v2/users/me');

        $types = $this->postTypes();
        $total = array_sum($types);

        return sprintf(
            '%s — authenticated as %s, %s items across %d type%s.',
            (string)$root['name'],
            (string)($me['name'] ?? $this->username),
            number_format($total),
            count($types),
            count($types) === 1 ? '' : 's'
        );
    }

    public function siteUrl(): ?string
    {
        return rtrim($this->url, '/') ?: null;
    }

    // -----------------------------------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    private function request(string $route, array $query = [], ?array &$headers = null): array
    {
        $this->connect();

        $url = rtrim($this->url, '/') . '/wp-json' . $route;

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'Passer/1.0 (+https://justinholt.com/plugins/craft-passer)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => !$this->insecure,
            CURLOPT_SSL_VERIFYHOST => $this->insecure ? 0 : 2,
        ]);

        if ($this->username !== '') {
            // WordPress application passwords are displayed with spaces for legibility and are
            // routinely pasted that way. They are not part of the secret.
            $password = str_replace(' ', '', $this->applicationPassword);
            curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $password);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new SourceException("Request to $url failed: $error");
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $headers = $this->parseHeaders($rawHeaders);

        if ($status === 401 || $status === 403) {
            throw new SourceException(
                "WordPress refused the request to $route (HTTP $status). Check the username and "
                . 'application password, and that the server is not stripping the Authorization '
                . 'header — Apache does this by default under CGI.'
            );
        }

        if ($status >= 400) {
            $decoded = Json::decodeIfJson($body);
            $message = is_array($decoded) ? ($decoded['message'] ?? $body) : $body;

            throw new SourceException("WordPress returned HTTP $status for $route: $message");
        }

        $decoded = Json::decodeIfJson($body);

        if (!is_array($decoded)) {
            throw new SourceException("WordPress returned a non-JSON response for $route.");
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $raw): array
    {
        $out = [];

        foreach (explode("\r\n", $raw) as $line) {
            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $out[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $out;
    }

    /**
     * Page through a collection endpoint, honouring `X-WP-TotalPages`.
     *
     * @return \Generator<array<string, mixed>>
     */
    private function paged(string $route, array $query = [], int $offset = 0, ?int $limit = null): \Generator
    {
        $perPage = min($this->perPage, 100);
        $page = intdiv($offset, $perPage) + 1;
        $skip = $offset % $perPage;
        $yielded = 0;

        while (true) {
            $headers = null;
            $rows = $this->request($route, $query + ['per_page' => $perPage, 'page' => $page], $headers);

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                if ($skip-- > 0) {
                    continue;
                }

                yield $row;

                if ($limit !== null && ++$yielded >= $limit) {
                    return;
                }
            }

            $totalPages = (int)($headers['x-wp-totalpages'] ?? 0);

            if ($totalPages > 0 && $page >= $totalPages) {
                return;
            }

            if (count($rows) < $perPage) {
                return;
            }

            $page++;
        }
    }

    /**
     * @return string[]
     */
    private function routes(): array
    {
        if ($this->routesCache !== null) {
            return $this->routesCache;
        }

        try {
            $index = $this->request('');
        } catch (SourceException) {
            return $this->routesCache = [];
        }

        return $this->routesCache = array_keys($index['routes'] ?? []);
    }

    private function hasRoute(string $route): bool
    {
        foreach ($this->routes() as $known) {
            if ($known === $route || str_starts_with($known, $route . '/')) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------------------------
    // Discovery
    // -----------------------------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    private function typeDefinitions(): array
    {
        return $this->typesCache ??= $this->request('/wp/v2/types');
    }

    public function postTypes(): array
    {
        $out = [];

        foreach ($this->typeDefinitions() as $name => $definition) {
            $restBase = (string)($definition['rest_base'] ?? '');

            // A type with no REST base is not readable over REST at all — WordPress lists
            // `nav_menu_item`, `wp_block` and most CPTs from older plugins this way.
            if ($restBase === '') {
                continue;
            }

            $headers = null;

            try {
                $this->request('/wp/v2/' . $restBase, ['per_page' => 1, 'status' => 'any'], $headers);
            } catch (SourceException) {
                // Some types reject `status=any` without the right capability; retry public-only.
                try {
                    $this->request('/wp/v2/' . $restBase, ['per_page' => 1], $headers);
                } catch (SourceException) {
                    continue;
                }
            }

            $out[(string)$name] = (int)($headers['x-wp-total'] ?? 0);
        }

        arsort($out);

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function taxonomyDefinitions(): array
    {
        return $this->taxonomiesCache ??= $this->request('/wp/v2/taxonomies');
    }

    public function taxonomies(): array
    {
        $out = [];

        foreach ($this->taxonomyDefinitions() as $name => $definition) {
            $restBase = (string)($definition['rest_base'] ?? '');

            if ($restBase === '') {
                continue;
            }

            $headers = null;

            try {
                $this->request('/wp/v2/' . $restBase, ['per_page' => 1], $headers);
            } catch (SourceException) {
                continue;
            }

            $out[(string)$name] = (int)($headers['x-wp-total'] ?? 0);
        }

        arsort($out);

        return $out;
    }

    private function restBaseForType(string $postType): string
    {
        $definition = $this->typeDefinitions()[$postType] ?? null;
        $base = is_array($definition) ? (string)($definition['rest_base'] ?? '') : '';

        if ($base === '') {
            throw new UnsupportedOperationException(
                "The post type '$postType' is not exposed over the REST API, so it cannot be "
                . 'read this way. Migrate from the database instead.'
            );
        }

        return $base;
    }

    private function restBaseForTaxonomy(string $taxonomy): string
    {
        $definition = $this->taxonomyDefinitions()[$taxonomy] ?? null;
        $base = is_array($definition) ? (string)($definition['rest_base'] ?? '') : '';

        if ($base === '') {
            throw new UnsupportedOperationException(
                "The taxonomy '$taxonomy' is not exposed over the REST API."
            );
        }

        return $base;
    }

    // -----------------------------------------------------------------------------------------
    // Content
    // -----------------------------------------------------------------------------------------

    public function posts(string $postType, int $offset = 0, ?int $limit = null): \Generator
    {
        $base = $this->restBaseForType($postType);

        // `context=edit` is what returns raw, unrendered content — the only form worth
        // migrating, since `rendered` has already had shortcodes expanded and filters applied.
        $query = ['context' => 'edit', 'status' => 'any', 'orderby' => 'id', 'order' => 'asc'];

        foreach ($this->paged('/wp/v2/' . $base, $query, $offset, $limit) as $row) {
            yield $this->postFromRow($row, $postType);
        }
    }

    public function post(int $id): ?WpPost
    {
        foreach ($this->typeDefinitions() as $name => $definition) {
            $base = (string)($definition['rest_base'] ?? '');

            if ($base === '') {
                continue;
            }

            try {
                $row = $this->request('/wp/v2/' . $base . '/' . $id, ['context' => 'edit']);
            } catch (SourceException) {
                continue;
            }

            return $this->postFromRow($row, (string)$name);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function postFromRow(array $row, string $postType): WpPost
    {
        $post = new WpPost();
        $post->id = (int)($row['id'] ?? 0);
        $post->type = (string)($row['type'] ?? $postType);
        $post->authorId = (int)($row['author'] ?? 0);
        $post->date = $this->normalizeDate($row['date'] ?? null);
        $post->dateGmt = $this->normalizeDate($row['date_gmt'] ?? null);
        $post->modified = $this->normalizeDate($row['modified'] ?? null);
        $post->modifiedGmt = $this->normalizeDate($row['modified_gmt'] ?? null);
        $post->status = (string)($row['status'] ?? 'publish');
        $post->name = (string)($row['slug'] ?? '');
        $post->parentId = (int)($row['parent'] ?? 0);
        $post->menuOrder = (int)($row['menu_order'] ?? 0);
        $post->link = (string)($row['link'] ?? '') ?: null;
        $post->guid = $this->raw($row['guid'] ?? null);
        $post->title = $this->raw($row['title'] ?? null) ?? '';
        $post->content = $this->raw($row['content'] ?? null) ?? '';
        $post->excerpt = $this->raw($row['excerpt'] ?? null) ?? '';
        $post->commentStatus = (string)($row['comment_status'] ?? 'open');
        $post->pingStatus = (string)($row['ping_status'] ?? 'open');
        $post->mimeType = (string)($row['mime_type'] ?? '');

        if (!empty($row['featured_media'])) {
            $post->featuredImageId = (int)$row['featured_media'];
        }

        if ($post->type === 'attachment') {
            $post->attachmentUrl = (string)($row['source_url'] ?? '') ?: null;
            $media = $row['media_details'] ?? null;
            $post->attachmentMeta = is_array($media) ? $media : [];
        }

        $meta = $row['meta'] ?? [];
        if (is_array($meta)) {
            $post->meta = $meta;
        }

        // Terms arrive as bare ID lists on numeric taxonomy keys.
        foreach ($this->taxonomyDefinitions() as $taxonomy => $definition) {
            $restBase = (string)($definition['rest_base'] ?? '');
            $ids = $row[$restBase] ?? $row[$taxonomy] ?? null;

            if (!is_array($ids)) {
                continue;
            }

            foreach ($ids as $id) {
                $term = new WpTerm();
                $term->id = (int)$id;
                $term->taxonomy = (string)$taxonomy;
                $post->terms[(string)$taxonomy][] = $term;
            }
        }

        return $post;
    }

    /**
     * REST returns `{raw, rendered}` for editable strings under `context=edit`, and only
     * `{rendered}` otherwise. Raw is what a migration wants.
     */
    private function raw(mixed $value): ?string
    {
        if (is_array($value)) {
            return (string)($value['raw'] ?? $value['rendered'] ?? '');
        }

        return $value === null ? null : (string)$value;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return str_replace('T', ' ', substr($value, 0, 19));
    }

    public function terms(string $taxonomy, int $offset = 0, ?int $limit = null): \Generator
    {
        $base = $this->restBaseForTaxonomy($taxonomy);

        foreach ($this->paged('/wp/v2/' . $base, ['context' => 'edit'], $offset, $limit) as $row) {
            $term = new WpTerm();
            $term->id = (int)($row['id'] ?? 0);
            $term->name = $this->raw($row['name'] ?? null) ?? '';
            $term->slug = (string)($row['slug'] ?? '');
            $term->description = $this->raw($row['description'] ?? null) ?? '';
            $term->taxonomy = (string)($row['taxonomy'] ?? $taxonomy);
            $term->parentId = (int)($row['parent'] ?? 0);
            $term->count = (int)($row['count'] ?? 0);

            $meta = $row['meta'] ?? [];
            if (is_array($meta)) {
                $term->meta = $meta;
            }

            yield $term;
        }
    }

    public function users(int $offset = 0, ?int $limit = null): \Generator
    {
        foreach ($this->paged('/wp/v2/users', ['context' => 'edit'], $offset, $limit) as $row) {
            $user = new WpUser();
            $user->id = (int)($row['id'] ?? 0);
            $user->login = (string)($row['username'] ?? $row['slug'] ?? '');
            $user->email = (string)($row['email'] ?? '');
            $user->niceName = (string)($row['slug'] ?? '');
            $user->displayName = (string)($row['name'] ?? '');
            $user->url = (string)($row['url'] ?? '') ?: null;
            $user->registered = $this->normalizeDate($row['registered_date'] ?? null);
            $user->roles = array_map('strval', $row['roles'] ?? []);
            $user->meta['first_name'] = (string)($row['first_name'] ?? '');
            $user->meta['last_name'] = (string)($row['last_name'] ?? '');
            $user->meta['description'] = (string)($row['description'] ?? '');

            yield $user;
        }
    }

    public function comments(int $offset = 0, ?int $limit = null): \Generator
    {
        $query = ['context' => 'edit', 'status' => 'any'];

        foreach ($this->paged('/wp/v2/comments', $query, $offset, $limit) as $row) {
            $comment = new WpComment();
            $comment->id = (int)($row['id'] ?? 0);
            $comment->postId = (int)($row['post'] ?? 0);
            $comment->parentId = (int)($row['parent'] ?? 0);
            $comment->userId = (int)($row['author'] ?? 0);
            $comment->authorName = (string)($row['author_name'] ?? '');
            $comment->authorEmail = (string)($row['author_email'] ?? '');
            $comment->authorUrl = (string)($row['author_url'] ?? '');
            $comment->authorIp = (string)($row['author_ip'] ?? '');
            $comment->date = $this->normalizeDate($row['date'] ?? null);
            $comment->content = $this->raw($row['content'] ?? null) ?? '';
            $comment->type = (string)($row['type'] ?? 'comment') ?: 'comment';

            // REST reports status as a word; the rest of Passer speaks wp_comments' encoding.
            $comment->approved = match ((string)($row['status'] ?? 'approved')) {
                'approved' => '1',
                'hold', 'unapproved' => '0',
                'spam' => 'spam',
                'trash' => 'trash',
                default => '1',
            };

            yield $comment;
        }
    }

    public function menus(): array
    {
        if (!$this->hasRoute('/wp/v2/menus')) {
            throw new UnsupportedOperationException(
                'This WordPress does not expose the menus endpoint (it needs WordPress 5.9 or '
                . 'newer, and it is often disabled). Migrate menus from the database or a WXR '
                . 'export instead.'
            );
        }

        $menus = [];

        foreach ($this->paged('/wp/v2/menus', ['context' => 'edit']) as $row) {
            $menu = new WpMenu();
            $menu->id = (int)($row['id'] ?? 0);
            $menu->name = $this->raw($row['name'] ?? null) ?? '';
            $menu->slug = (string)($row['slug'] ?? '');
            $menu->locations = array_map('strval', $row['locations'] ?? []);

            foreach ($this->paged('/wp/v2/menu-items', ['menus' => $menu->id, 'context' => 'edit', 'per_page' => 100]) as $itemRow) {
                $item = new WpMenuItem();
                $item->id = (int)($itemRow['id'] ?? 0);
                $item->title = $this->raw($itemRow['title'] ?? null) ?? '';
                $item->order = (int)($itemRow['menu_order'] ?? 0);
                $item->parentItemId = (int)($itemRow['parent'] ?? 0);
                $item->objectType = (string)($itemRow['type'] ?? 'custom');
                $item->object = (string)($itemRow['object'] ?? '');
                $item->objectId = (int)($itemRow['object_id'] ?? 0);
                $item->url = (string)($itemRow['url'] ?? '') ?: null;
                $item->target = (string)($itemRow['target'] ?? '');
                $item->attrTitle = (string)($itemRow['attr_title'] ?? '');
                $item->description = (string)($itemRow['description'] ?? '');
                $item->classes = array_values(array_filter(array_map('strval', $itemRow['classes'] ?? [])));
                $item->xfn = (string)($itemRow['xfn'] ?? '') ?: null;

                $menu->items[] = $item;
            }

            usort($menu->items, static fn($a, $b) => $a->order <=> $b->order);

            $menus[] = $menu;
        }

        return $menus;
    }
}
