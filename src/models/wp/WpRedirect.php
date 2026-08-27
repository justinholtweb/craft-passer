<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;

/**
 * A redirect, normalised out of whichever WordPress plugin recorded it.
 *
 * Six plugins store redirects six ways — Redirection has its own tables, Yoast Premium and
 * RankMath use options and custom tables respectively, Safe Redirect Manager uses a post type,
 * and plenty of sites have nothing but rules in `.htaccess`. They all arrive here.
 */
class WpRedirect extends Model
{
    public string $source = '';
    public string $destination = '';
    public int $statusCode = 301;

    /** @var string exact|regex|prefix */
    public string $matchType = 'exact';

    public bool $enabled = true;
    public ?string $origin = null;
    public int $hits = 0;
    public ?string $lastHit = null;

    /**
     * When the destination is a WordPress post rather than a URL, its ID — so the importer can
     * resolve it through the ID map into a live Craft element instead of freezing a URL that
     * the migration itself is about to change.
     */
    public ?int $destinationPostId = null;

    public function isRegex(): bool
    {
        return $this->matchType === 'regex';
    }
}
