<?php

namespace justinholtweb\passer\models;

use craft\base\Model;

/**
 * SEO metadata for one piece of content, normalised out of whichever plugin stored it.
 *
 * Five plugins, five vocabularies, one shape. Everything is nullable because no plugin sets
 * every field and an unset value must not overwrite a good one.
 */
class SeoData extends Model
{
    public ?string $title = null;
    public ?string $description = null;
    public ?string $canonical = null;
    public ?string $focusKeyword = null;

    /** @var string[] Secondary keywords, where the plugin supports them. */
    public array $keywords = [];

    public ?bool $noIndex = null;
    public ?bool $noFollow = null;

    /** @var string[] Extra robots directives: noarchive, nosnippet, noimageindex, … */
    public array $robots = [];

    public ?string $ogTitle = null;
    public ?string $ogDescription = null;
    public ?int $ogImageId = null;
    public ?string $ogImageUrl = null;
    public ?string $ogType = null;

    public ?string $twitterTitle = null;
    public ?string $twitterDescription = null;
    public ?int $twitterImageId = null;
    public ?string $twitterImageUrl = null;
    public ?string $twitterCard = null;

    public ?string $breadcrumbTitle = null;
    public ?string $schemaType = null;

    /** @var float|null Sitemap priority, 0–1. */
    public ?float $priority = null;

    /** @var string|null Sitemap change frequency. */
    public ?string $changeFrequency = null;

    /** @var bool|null Whether to include this in the sitemap. */
    public ?bool $inSitemap = null;

    /** @var string The plugin this came from, for the report. */
    public string $source = '';

    /**
     * True when there is nothing here worth writing.
     */
    public function isEmpty(): bool
    {
        foreach (['title', 'description', 'canonical', 'ogTitle', 'ogDescription', 'twitterTitle', 'twitterDescription', 'breadcrumbTitle'] as $property) {
            if ($this->$property !== null && $this->$property !== '') {
                return false;
            }
        }

        return $this->noIndex === null
            && $this->ogImageId === null
            && $this->ogImageUrl === null
            && $this->keywords === []
            && $this->robots === [];
    }
}
