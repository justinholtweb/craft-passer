<?php

namespace justinholtweb\passer\sources;

use justinholtweb\passer\models\wp\WpComment;
use justinholtweb\passer\models\wp\WpPost;
use justinholtweb\passer\models\wp\WpTerm;
use justinholtweb\passer\models\wp\WpUser;
use justinholtweb\passer\helpers\WpSerialize;
use SimpleXMLElement;
use XMLReader;

/**
 * Reads WordPress's own export format (WXR).
 *
 * This is the source that needs no credentials, no network route and no cooperation from the
 * origin host — an editor can produce it from Tools → Export and email it. What it cannot
 * contain is anything WordPress's exporter does not write: user email addresses and roles are
 * partial, options are absent entirely, so menus survive only as `nav_menu_item` posts, and
 * widgets, WooCommerce orders and plugin tables are simply not there.
 *
 * Large exports are common and are frequently split into numbered files by the WXR splitter, so
 * this source accepts a list of paths and streams every one with XMLReader rather than loading
 * any of them into memory.
 */
class WxrSource extends BaseSource
{
    /** @var string[] Absolute paths to one or more WXR files, read in order. */
    public array $paths = [];

    private const NS_WP = 'http://wordpress.org/export/1.2/';
    private const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    private const NS_EXCERPT = 'http://wordpress.org/export/1.2/excerpt/';
    private const NS_DC = 'http://purl.org/dc/elements/1.1/';

    private ?array $index = null;
    private ?string $siteUrlCache = null;

    public static function type(): string
    {
        return 'wxr';
    }

    public static function displayName(): string
    {
        return 'WordPress export file (WXR)';
    }

    protected function defineCapabilities(): SourceCapabilities
    {
        return new SourceCapabilities([
            'posts' => true,
            'postMeta' => true,
            'terms' => true,
            'termMeta' => false,
            'users' => true,
            'userMeta' => false,
            'comments' => true,
            'media' => true,
            'options' => false,
            // Menus are reachable only because their items are posts; the menu term itself is
            // exported, but its theme location is not.
            'menus' => true,
            'widgets' => false,
            'commerce' => false,
            'forms' => false,
            'redirects' => false,
            'exactCounts' => true,
            'discoversPostTypes' => true,
            'limitations' => [
                'WordPress exports do not include wp_options, so widgets, theme settings and '
                . 'menu theme-locations are unavailable.',
                'User records carry a login and display name but no email address or role, so '
                . 'imported users are created without either.',
                'WooCommerce orders, coupons and plugin tables are not exported at all.',
                'Redirects stored by a plugin are not exported.',
            ],
        ]);
    }

    protected function fingerprint(): string
    {
        return $this->siteUrl() ?? 'wxr:' . implode('|', array_map('basename', $this->paths));
    }

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        if ($this->paths === []) {
            throw new SourceException('No WXR file was supplied.');
        }

        foreach ($this->paths as $path) {
            if (!is_file($path) || !is_readable($path)) {
                throw new SourceException("WXR file not readable: $path");
            }
        }

        $this->connected = true;
    }

    public function testConnection(): string
    {
        $this->connect();
        $index = $this->buildIndex();

        $types = [];
        foreach ($index['postTypes'] as $type => $count) {
            $types[] = "$count $type";
        }

        return sprintf(
            '%s — %s across %d file%s.',
            $index['siteName'] ?: ($this->siteUrl() ?? 'WordPress export'),
            $types !== [] ? implode(', ', $types) : 'no items',
            count($this->paths),
            count($this->paths) === 1 ? '' : 's'
        );
    }

    public function siteUrl(): ?string
    {
        if ($this->siteUrlCache === null) {
            $this->siteUrlCache = $this->buildIndex()['siteUrl'] ?? '';
        }

        return $this->siteUrlCache ?: null;
    }

    // -----------------------------------------------------------------------------------------
    // Indexing
    // -----------------------------------------------------------------------------------------

    /**
     * One streaming pass over every file, recording what is in them.
     *
     * The counts have to be real rather than estimated, because the wizard's whole value is
     * telling the user what they are about to migrate before they migrate it.
     *
     * @return array{siteUrl: string, siteName: string, postTypes: array<string, int>, taxonomies: array<string, int>, users: int, comments: int}
     */
    private function buildIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $this->connect();

        $index = [
            'siteUrl' => '',
            'siteName' => '',
            'postTypes' => [],
            'taxonomies' => [],
            'users' => 0,
            'comments' => 0,
        ];

        foreach ($this->paths as $path) {
            $reader = $this->openReader($path);

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                switch ($reader->name) {
                    case 'wp:base_blog_url':
                        if ($index['siteUrl'] === '') {
                            $index['siteUrl'] = rtrim(trim((string)$reader->readString()), '/');
                        }
                        break;

                    case 'title':
                        // The first <title> in the document is the channel's.
                        if ($index['siteName'] === '' && $reader->depth <= 2) {
                            $index['siteName'] = trim((string)$reader->readString());
                        }
                        break;

                    case 'wp:author':
                        $index['users']++;
                        $reader->next();
                        break;

                    case 'wp:category':
                        $index['taxonomies']['category'] = ($index['taxonomies']['category'] ?? 0) + 1;
                        $reader->next();
                        break;

                    case 'wp:tag':
                        $index['taxonomies']['post_tag'] = ($index['taxonomies']['post_tag'] ?? 0) + 1;
                        $reader->next();
                        break;

                    case 'wp:term':
                        $node = $this->expand($reader);
                        $tax = $node !== null ? $this->wp($node, 'term_taxonomy') : '';
                        if ($tax !== '') {
                            $index['taxonomies'][$tax] = ($index['taxonomies'][$tax] ?? 0) + 1;
                        }
                        break;

                    case 'item':
                        $node = $this->expand($reader);
                        if ($node === null) {
                            break;
                        }
                        $type = $this->wp($node, 'post_type') ?: 'post';
                        $status = $this->wp($node, 'status');
                        if ($status !== 'auto-draft') {
                            $index['postTypes'][$type] = ($index['postTypes'][$type] ?? 0) + 1;
                        }
                        $index['comments'] += count($node->children(self::NS_WP)->comment ?? []);
                        break;
                }
            }

            $reader->close();
        }

        arsort($index['postTypes']);
        arsort($index['taxonomies']);

        return $this->index = $index;
    }

    private function openReader(string $path): XMLReader
    {
        $reader = new XMLReader();

        // WXR from a badly-behaved plugin routinely contains bare `&` and stray control
        // characters. Recovery mode gets the readable 99% out rather than failing the migration
        // over one malformed post.
        if (!$reader->open($path, null, LIBXML_NOCDATA | LIBXML_PARSEHUGE | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new SourceException("Could not open WXR file: $path");
        }

        return $reader;
    }

    /**
     * Expand the reader's current node into SimpleXML and advance past it.
     */
    private function expand(XMLReader $reader): ?SimpleXMLElement
    {
        $xml = $reader->readOuterXml();

        if ($xml === '') {
            return null;
        }

        // The fragment loses the document's namespace declarations, so they are reattached.
        $wrapped = sprintf(
            '<wrap xmlns:wp="%s" xmlns:content="%s" xmlns:excerpt="%s" xmlns:dc="%s">%s</wrap>',
            self::NS_WP,
            self::NS_CONTENT,
            self::NS_EXCERPT,
            self::NS_DC,
            $xml
        );

        $previous = libxml_use_internal_errors(true);
        $wrap = simplexml_load_string($wrapped, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $reader->next();

        if ($wrap === false) {
            return null;
        }

        $children = $wrap->children();

        return isset($children[0]) ? $children[0] : null;
    }

    private function wp(SimpleXMLElement $node, string $name): string
    {
        $children = $node->children(self::NS_WP);

        return isset($children->{$name}) ? trim((string)$children->{$name}) : '';
    }

    // -----------------------------------------------------------------------------------------
    // Discovery
    // -----------------------------------------------------------------------------------------

    public function postTypes(): array
    {
        return $this->buildIndex()['postTypes'];
    }

    public function taxonomies(): array
    {
        return $this->buildIndex()['taxonomies'];
    }

    // -----------------------------------------------------------------------------------------
    // Content
    // -----------------------------------------------------------------------------------------

    public function posts(string $postType, int $offset = 0, ?int $limit = null): \Generator
    {
        $seen = 0;
        $yielded = 0;

        foreach ($this->items() as $node) {
            if (($this->wp($node, 'post_type') ?: 'post') !== $postType) {
                continue;
            }

            if ($this->wp($node, 'status') === 'auto-draft') {
                continue;
            }

            if ($seen++ < $offset) {
                continue;
            }

            yield $this->postFromItem($node);

            if ($limit !== null && ++$yielded >= $limit) {
                return;
            }
        }
    }

    public function post(int $id): ?WpPost
    {
        foreach ($this->items() as $node) {
            if ((int)$this->wp($node, 'post_id') === $id) {
                return $this->postFromItem($node);
            }
        }

        return null;
    }

    /**
     * Every `<item>` in every file, in document order.
     *
     * @return \Generator<SimpleXMLElement>
     */
    private function items(): \Generator
    {
        $this->connect();

        foreach ($this->paths as $path) {
            $reader = $this->openReader($path);

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'item') {
                    $node = $this->expand($reader);

                    if ($node !== null) {
                        yield $node;
                    }
                }
            }

            $reader->close();
        }
    }

    private function postFromItem(SimpleXMLElement $node): WpPost
    {
        $wp = $node->children(self::NS_WP);
        $content = $node->children(self::NS_CONTENT);
        $excerpt = $node->children(self::NS_EXCERPT);
        $dc = $node->children(self::NS_DC);

        $post = new WpPost();
        $post->id = (int)$this->wp($node, 'post_id');
        $post->title = trim((string)$node->title);
        $post->link = trim((string)$node->link) ?: null;
        $post->guid = trim((string)$node->guid) ?: null;
        $post->content = isset($content->encoded) ? (string)$content->encoded : '';
        $post->excerpt = isset($excerpt->encoded) ? (string)$excerpt->encoded : '';
        $post->date = $this->nullDate($this->wp($node, 'post_date'));
        $post->dateGmt = $this->nullDate($this->wp($node, 'post_date_gmt'));
        $post->modified = $this->nullDate($this->wp($node, 'post_modified'));
        $post->modifiedGmt = $this->nullDate($this->wp($node, 'post_modified_gmt'));
        $post->status = $this->wp($node, 'status') ?: 'publish';
        $post->name = $this->wp($node, 'post_name');
        $post->type = $this->wp($node, 'post_type') ?: 'post';
        $post->parentId = (int)$this->wp($node, 'post_parent');
        $post->menuOrder = (int)$this->wp($node, 'menu_order');
        $post->commentStatus = $this->wp($node, 'comment_status') ?: 'open';
        $post->pingStatus = $this->wp($node, 'ping_status') ?: 'open';
        $post->attachmentUrl = $this->wp($node, 'attachment_url') ?: null;

        // WXR records the author by login, not ID. The importer resolves it through the ID map
        // keyed on login; `authorId` stays 0 and `meta['_passer_author_login']` carries the name.
        $login = isset($dc->creator) ? trim((string)$dc->creator) : '';

        $rows = [];
        foreach ($wp->postmeta ?? [] as $meta) {
            $rows[] = [
                'meta_key' => trim((string)$meta->children(self::NS_WP)->meta_key),
                'meta_value' => (string)$meta->children(self::NS_WP)->meta_value,
            ];
        }

        if ($login !== '') {
            $rows[] = ['meta_key' => '_passer_author_login', 'meta_value' => $login];
        }

        $post->setMetaFromRows($rows);

        foreach ($node->category ?? [] as $category) {
            $taxonomy = (string)($category['domain'] ?? '');
            $slug = (string)($category['nicename'] ?? '');

            if ($taxonomy === '') {
                continue;
            }

            $term = new WpTerm();
            $term->name = trim((string)$category);
            $term->slug = $slug !== '' ? $slug : $this->slugify($term->name);
            $term->taxonomy = $taxonomy;

            $post->terms[$taxonomy][] = $term;
        }

        return $post;
    }

    public function terms(string $taxonomy, int $offset = 0, ?int $limit = null): \Generator
    {
        $this->connect();

        $seen = 0;
        $yielded = 0;

        foreach ($this->paths as $path) {
            $reader = $this->openReader($path);

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $term = match ($reader->name) {
                    'wp:category' => $this->termFromNode($this->expand($reader), 'category'),
                    'wp:tag' => $this->termFromNode($this->expand($reader), 'post_tag'),
                    'wp:term' => $this->termFromNode($this->expand($reader), null),
                    // `item` marks the end of the term block; nothing after it is a term.
                    'item' => null,
                    default => false,
                };

                if ($term === false) {
                    continue;
                }

                if ($term === null || $term->taxonomy !== $taxonomy) {
                    continue;
                }

                if ($seen++ < $offset) {
                    continue;
                }

                yield $term;

                if ($limit !== null && ++$yielded >= $limit) {
                    $reader->close();
                    return;
                }
            }

            $reader->close();
        }
    }

    private function termFromNode(?SimpleXMLElement $node, ?string $taxonomy): ?WpTerm
    {
        if ($node === null) {
            return null;
        }

        $term = new WpTerm();

        if ($taxonomy === 'category') {
            $term->taxonomy = 'category';
            $term->id = (int)$this->wp($node, 'term_id');
            $term->slug = $this->wp($node, 'category_nicename');
            $term->name = $this->wp($node, 'cat_name');
            $term->description = $this->wp($node, 'category_description');
            // WXR records the parent by slug, not ID; the importer resolves it.
            $parentSlug = $this->wp($node, 'category_parent');
            if ($parentSlug !== '') {
                $term->meta['_passer_parent_slug'] = $parentSlug;
            }
        } elseif ($taxonomy === 'post_tag') {
            $term->taxonomy = 'post_tag';
            $term->id = (int)$this->wp($node, 'term_id');
            $term->slug = $this->wp($node, 'tag_slug');
            $term->name = $this->wp($node, 'tag_name');
            $term->description = $this->wp($node, 'tag_description');
        } else {
            $term->taxonomy = $this->wp($node, 'term_taxonomy');
            $term->id = (int)$this->wp($node, 'term_id');
            $term->slug = $this->wp($node, 'term_slug');
            $term->name = $this->wp($node, 'term_name');
            $term->description = $this->wp($node, 'term_description');
            $parentSlug = $this->wp($node, 'term_parent');
            if ($parentSlug !== '') {
                $term->meta['_passer_parent_slug'] = $parentSlug;
            }
        }

        foreach ($node->children(self::NS_WP)->termmeta ?? [] as $meta) {
            $key = trim((string)$meta->children(self::NS_WP)->meta_key);
            if ($key !== '') {
                $term->meta[$key] = WpSerialize::unserialize((string)$meta->children(self::NS_WP)->meta_value);
            }
        }

        return $term->name !== '' || $term->slug !== '' ? $term : null;
    }

    public function users(int $offset = 0, ?int $limit = null): \Generator
    {
        $this->connect();

        $seen = 0;
        $yielded = 0;

        foreach ($this->paths as $path) {
            $reader = $this->openReader($path);

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($reader->name === 'item') {
                    // Authors are declared before the first item; nothing after it is one.
                    break;
                }

                if ($reader->name !== 'wp:author') {
                    continue;
                }

                $node = $this->expand($reader);

                if ($node === null) {
                    continue;
                }

                if ($seen++ < $offset) {
                    continue;
                }

                $user = new WpUser();
                $user->id = (int)$this->wp($node, 'author_id');
                $user->login = $this->wp($node, 'author_login');
                $user->email = $this->wp($node, 'author_email');
                $user->displayName = $this->wp($node, 'author_display_name');
                $user->niceName = $user->login;
                $user->meta['first_name'] = $this->wp($node, 'author_first_name');
                $user->meta['last_name'] = $this->wp($node, 'author_last_name');

                yield $user;

                if ($limit !== null && ++$yielded >= $limit) {
                    $reader->close();
                    return;
                }
            }

            $reader->close();
        }
    }

    public function comments(int $offset = 0, ?int $limit = null): \Generator
    {
        $seen = 0;
        $yielded = 0;

        foreach ($this->items() as $node) {
            $postId = (int)$this->wp($node, 'post_id');

            foreach ($node->children(self::NS_WP)->comment ?? [] as $node2) {
                if ($seen++ < $offset) {
                    continue;
                }

                $comment = new WpComment();
                $comment->id = (int)$this->wp($node2, 'comment_id');
                $comment->postId = $postId;
                $comment->authorName = $this->wp($node2, 'comment_author');
                $comment->authorEmail = $this->wp($node2, 'comment_author_email');
                $comment->authorUrl = $this->wp($node2, 'comment_author_url');
                $comment->authorIp = $this->wp($node2, 'comment_author_IP');
                $comment->date = $this->nullDate($this->wp($node2, 'comment_date'));
                $comment->content = $this->wp($node2, 'comment_content');
                $comment->approved = $this->wp($node2, 'comment_approved') ?: '1';
                $comment->type = $this->wp($node2, 'comment_type') ?: 'comment';
                $comment->parentId = (int)$this->wp($node2, 'comment_parent');
                $comment->userId = (int)$this->wp($node2, 'comment_user_id');

                yield $comment;

                if ($limit !== null && ++$yielded >= $limit) {
                    return;
                }
            }
        }
    }

    public function menus(): array
    {
        $this->connect();

        /** @var array<int, \justinholtweb\passer\models\wp\WpMenu> $menus */
        $menus = [];

        foreach ($this->terms('nav_menu') as $term) {
            $menu = new \justinholtweb\passer\models\wp\WpMenu();
            $menu->id = $term->id;
            $menu->name = $term->name;
            $menu->slug = $term->slug;
            $menus[$term->slug] = $menu;
        }

        if ($menus === []) {
            return [];
        }

        // Menu items carry their menu as a `nav_menu` category on the item itself, by slug —
        // the only link back, because the export has no term_relationships table.
        foreach ($this->posts('nav_menu_item') as $post) {
            foreach ($post->terms['nav_menu'] ?? [] as $navTerm) {
                if (isset($menus[$navTerm->slug])) {
                    $menus[$navTerm->slug]->items[] = $this->menuItemFromPost($post);
                }
            }
        }

        foreach ($menus as $menu) {
            usort($menu->items, static fn($a, $b) => $a->order <=> $b->order);
        }

        return array_values($menus);
    }

    private function nullDate(string $value): ?string
    {
        $value = trim($value);

        return ($value === '' || str_starts_with($value, '0000-00-00')) ? null : $value;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '', '-'));

        return $slug !== '' ? $slug : 'term';
    }
}
