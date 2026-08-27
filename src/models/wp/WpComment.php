<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;

/**
 * A row from `wp_comments`.
 */
class WpComment extends Model
{
    public int $id = 0;
    public int $postId = 0;
    public string $authorName = '';
    public string $authorEmail = '';
    public string $authorUrl = '';
    public string $authorIp = '';
    public ?string $date = null;
    public string $content = '';
    public int $karma = 0;

    /** @var string '1' approved, '0' pending, 'spam', 'trash' */
    public string $approved = '1';

    public string $agent = '';
    public string $type = 'comment';
    public int $parentId = 0;
    public int $userId = 0;

    /** @var array<string, mixed> */
    public array $meta = [];

    public function isApproved(): bool
    {
        return $this->approved === '1';
    }

    public function isSpam(): bool
    {
        return $this->approved === 'spam';
    }

    /**
     * Pingbacks and trackbacks share the comments table but are not comments in any sense a
     * Craft site cares about.
     */
    public function isPingback(): bool
    {
        return in_array($this->type, ['pingback', 'trackback'], true);
    }
}
