<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;
use justinholtweb\passer\helpers\WpSerialize;

/**
 * A row from `wp_posts`, with its meta and term assignments attached.
 *
 * Every source mode produces this same shape, which is what keeps the importers
 * source-agnostic. Fields WordPress stores as strings stay strings here; interpretation is the
 * importers' job.
 */
class WpPost extends Model
{
    public int $id = 0;
    public int $authorId = 0;
    public ?string $date = null;
    public ?string $dateGmt = null;
    public string $content = '';
    public string $title = '';
    public string $excerpt = '';

    /** @var string publish|draft|pending|private|future|trash|inherit|auto-draft */
    public string $status = 'publish';

    public string $commentStatus = 'open';
    public string $pingStatus = 'open';
    public string $name = '';
    public int $parentId = 0;
    public ?string $modified = null;
    public ?string $modifiedGmt = null;
    public int $menuOrder = 0;
    public string $type = 'post';
    public string $mimeType = '';
    public int $commentCount = 0;
    public ?string $guid = null;

    /** @var string|null The post's public URL on the source site, when knowable. */
    public ?string $link = null;

    /**
     * Meta, keyed by meta_key, values already unserialized.
     *
     * A value here is exactly what WordPress stored: a string for a plain value, an array for a
     * serialized one. Repeated rows for the same key are *not* folded in — they go to
     * `$metaRepeats` instead — because otherwise a genuinely-array value (Rank Math's robots
     * directives, WooCommerce's `_product_attributes`) is indistinguishable from two rows of a
     * string, and reading either one as the other silently corrupts it.
     *
     * @var array<string, mixed>
     */
    public array $meta = [];

    /**
     * Keys that appeared more than once in `wp_postmeta`, with every value in row order.
     *
     * @var array<string, mixed[]>
     */
    public array $metaRepeats = [];

    /**
     * Terms assigned to this post, as taxonomy => WpTerm[].
     *
     * @var array<string, WpTerm[]>
     */
    public array $terms = [];

    /** @var int|null Attachment ID of the featured image (`_thumbnail_id`). */
    public ?int $featuredImageId = null;

    /** @var string|null For attachments: the absolute URL of the file. */
    public ?string $attachmentUrl = null;

    /** @var array<string, mixed> For attachments: the `_wp_attachment_metadata` payload. */
    public array $attachmentMeta = [];

    public function isAttachment(): bool
    {
        return $this->type === 'attachment';
    }

    /**
     * True when WordPress would show this post publicly.
     */
    public function isPublished(): bool
    {
        return $this->status === 'publish';
    }

    /**
     * A meta value exactly as WordPress stored it — a string, or an array for serialized data.
     *
     * When a key has several rows this returns the first, which is what `get_post_meta($id,
     * $key, true)` does.
     */
    public function metaValue(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->meta) ? $this->meta[$key] : $default;
    }

    /**
     * Every row's value for a meta key, in the order WordPress stored them.
     *
     * @return mixed[]
     */
    public function metaList(string $key): array
    {
        if (isset($this->metaRepeats[$key])) {
            return $this->metaRepeats[$key];
        }

        return array_key_exists($key, $this->meta) ? [$this->meta[$key]] : [];
    }

    /**
     * Meta keys beginning with a prefix — how the SEO and ACF importers find their own data.
     *
     * @return array<string, mixed>
     */
    public function metaWithPrefix(string $prefix): array
    {
        $out = [];

        foreach ($this->meta as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public function setMetaFromRows(array $rows): void
    {
        foreach ($rows as $row) {
            $key = $row['meta_key'] ?? null;

            if ($key === null) {
                continue;
            }

            $value = WpSerialize::unserialize($row['meta_value'] ?? null);

            if (array_key_exists($key, $this->meta)) {
                // A repeat. The first value stays authoritative — matching WordPress's own
                // single-value read — and every value is kept alongside it.
                $this->metaRepeats[$key] ??= [$this->meta[$key]];
                $this->metaRepeats[$key][] = $value;
            } else {
                $this->meta[$key] = $value;
            }
        }

        $thumb = $this->metaValue('_thumbnail_id');
        if ($thumb !== null && is_numeric($thumb)) {
            $this->featuredImageId = (int)$thumb;
        }

        $attachmentMeta = $this->metaValue('_wp_attachment_metadata');
        if (is_array($attachmentMeta)) {
            $this->attachmentMeta = $attachmentMeta;
        }
    }
}
