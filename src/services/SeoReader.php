<?php

namespace justinholtweb\passer\services;

use craft\base\Component;
use justinholtweb\passer\helpers\WpSerialize;
use justinholtweb\passer\models\SeoData;
use justinholtweb\passer\models\wp\WpPost;
use justinholtweb\passer\sources\SourceInterface;

/**
 * Reads SEO metadata out of whichever plugin the site uses.
 *
 * Five plugins cover essentially the whole WordPress market, and they disagree about everything:
 * Yoast and SEOPress use prefixed post meta, Rank Math uses unprefixed post meta, All in One SEO
 * abandoned meta entirely for its own `aioseo_posts` table, and The SEO Framework inherited
 * Genesis's key names. Their variable substitutions differ too — `%%title%%` against `%title%`
 * against `%%sep%%` — and a title imported without expanding them reads like a template.
 *
 * A site with two of them installed (which is common, because people switch and never clean up)
 * gets the one with more data, per post, rather than a fixed precedence: the plugin someone
 * actually filled in is the one whose values are worth keeping.
 */
class SeoReader extends Component
{
    /** @var string[] Plugin slugs found on this site, most likely first. */
    private array $active = [];

    private ?array $aioseoRows = null;

    /**
     * @param string[] $detectedSlugs
     */
    public function setActivePlugins(array $detectedSlugs): void
    {
        $this->active = $detectedSlugs;
    }

    /**
     * @return string[]
     */
    public function activePlugins(): array
    {
        return $this->active;
    }

    /**
     * The best SEO data available for a post.
     */
    public function read(WpPost $post, SourceInterface $source): SeoData
    {
        $candidates = [];

        foreach ($this->active as $slug) {
            $data = match ($slug) {
                'wordpress-seo' => $this->yoast($post),
                'seo-by-rank-math' => $this->rankMath($post),
                'all-in-one-seo-pack' => $this->aioseo($post, $source),
                'wp-seopress' => $this->seopress($post),
                'autodescription' => $this->seoFramework($post),
                default => null,
            };

            if ($data !== null && !$data->isEmpty()) {
                $candidates[] = $data;
            }
        }

        if ($candidates === []) {
            return new SeoData();
        }

        // Pick whichever plugin has the most filled in for this post. On a site that switched
        // plugins two years ago, the old one still has data for old posts and the new one for
        // new posts, and per-post selection is the only way to keep both.
        usort($candidates, fn(SeoData $a, SeoData $b) => $this->weight($b) <=> $this->weight($a));

        return $candidates[0];
    }

    private function weight(SeoData $data): int
    {
        $weight = 0;

        foreach (['title', 'description', 'canonical', 'ogTitle', 'ogDescription', 'twitterTitle', 'breadcrumbTitle', 'focusKeyword'] as $property) {
            if ($data->$property !== null && $data->$property !== '') {
                $weight++;
            }
        }

        if ($data->ogImageId !== null || $data->ogImageUrl !== null) {
            $weight++;
        }

        return $weight;
    }

    // -----------------------------------------------------------------------------------------
    // Yoast
    // -----------------------------------------------------------------------------------------

    private function yoast(WpPost $post): ?SeoData
    {
        $meta = $post->metaWithPrefix('_yoast_wpseo_');

        if ($meta === []) {
            return null;
        }

        $data = new SeoData(['source' => 'Yoast SEO']);
        $data->title = $this->expand($this->str($post, '_yoast_wpseo_title'), $post);
        $data->description = $this->expand($this->str($post, '_yoast_wpseo_metadesc'), $post);
        $data->canonical = $this->str($post, '_yoast_wpseo_canonical');
        $data->focusKeyword = $this->str($post, '_yoast_wpseo_focuskw');
        $data->breadcrumbTitle = $this->str($post, '_yoast_wpseo_bctitle');

        // Yoast writes `1` for noindex and `2` for index, with absent meaning "use the default".
        $noindex = $post->metaValue('_yoast_wpseo_meta-robots-noindex');
        $data->noIndex = $noindex === null || $noindex === '' ? null : ((string)$noindex === '1');

        $nofollow = $post->metaValue('_yoast_wpseo_meta-robots-nofollow');
        $data->noFollow = $nofollow === null || $nofollow === '' ? null : ((string)$nofollow === '1');

        $adv = $this->str($post, '_yoast_wpseo_meta-robots-adv');
        if ($adv !== null && $adv !== 'none') {
            $data->robots = array_values(array_filter(array_map('trim', explode(',', $adv))));
        }

        $data->ogTitle = $this->expand($this->str($post, '_yoast_wpseo_opengraph-title'), $post);
        $data->ogDescription = $this->expand($this->str($post, '_yoast_wpseo_opengraph-description'), $post);
        $data->ogImageUrl = $this->str($post, '_yoast_wpseo_opengraph-image');
        $data->ogImageId = $this->int($post, '_yoast_wpseo_opengraph-image-id');

        $data->twitterTitle = $this->expand($this->str($post, '_yoast_wpseo_twitter-title'), $post);
        $data->twitterDescription = $this->expand($this->str($post, '_yoast_wpseo_twitter-description'), $post);
        $data->twitterImageUrl = $this->str($post, '_yoast_wpseo_twitter-image');
        $data->twitterImageId = $this->int($post, '_yoast_wpseo_twitter-image-id');

        $data->schemaType = $this->str($post, '_yoast_wpseo_schema_page_type');

        return $data;
    }

    // -----------------------------------------------------------------------------------------
    // Rank Math
    // -----------------------------------------------------------------------------------------

    private function rankMath(WpPost $post): ?SeoData
    {
        $meta = $post->metaWithPrefix('rank_math_');

        if ($meta === []) {
            return null;
        }

        $data = new SeoData(['source' => 'Rank Math']);
        $data->title = $this->expand($this->str($post, 'rank_math_title'), $post);
        $data->description = $this->expand($this->str($post, 'rank_math_description'), $post);
        $data->canonical = $this->str($post, 'rank_math_canonical_url');
        $data->breadcrumbTitle = $this->str($post, 'rank_math_breadcrumb_title');

        $focus = $this->str($post, 'rank_math_focus_keyword');

        if ($focus !== null) {
            // Rank Math stores the focus keyword and its secondaries as one comma-separated
            // string, primary first.
            $parts = array_values(array_filter(array_map('trim', explode(',', $focus))));
            $data->focusKeyword = $parts[0] ?? null;
            $data->keywords = array_slice($parts, 1);
        }

        $robots = $post->metaValue('rank_math_robots');
        $robots = is_array($robots) ? array_map('strval', $robots) : [];

        if ($robots !== []) {
            $data->noIndex = in_array('noindex', $robots, true);
            $data->noFollow = in_array('nofollow', $robots, true);
            $data->robots = array_values(array_diff($robots, ['index', 'noindex', 'follow', 'nofollow']));
        }

        $data->ogTitle = $this->expand($this->str($post, 'rank_math_facebook_title'), $post);
        $data->ogDescription = $this->expand($this->str($post, 'rank_math_facebook_description'), $post);
        $data->ogImageUrl = $this->str($post, 'rank_math_facebook_image');
        $data->ogImageId = $this->int($post, 'rank_math_facebook_image_id');

        $data->twitterTitle = $this->expand($this->str($post, 'rank_math_twitter_title'), $post);
        $data->twitterDescription = $this->expand($this->str($post, 'rank_math_twitter_description'), $post);
        $data->twitterImageUrl = $this->str($post, 'rank_math_twitter_image');
        $data->twitterImageId = $this->int($post, 'rank_math_twitter_image_id');
        $data->twitterCard = $this->str($post, 'rank_math_twitter_card_type');

        return $data;
    }

    // -----------------------------------------------------------------------------------------
    // All in One SEO
    // -----------------------------------------------------------------------------------------

    private function aioseo(WpPost $post, SourceInterface $source): ?SeoData
    {
        $rows = $this->aioseoRows($source);
        $row = $rows[$post->id] ?? null;

        if ($row === null) {
            return null;
        }

        $data = new SeoData(['source' => 'All in One SEO']);
        $data->title = $this->expand($this->rowStr($row, 'title'), $post);
        $data->description = $this->expand($this->rowStr($row, 'description'), $post);
        $data->canonical = $this->rowStr($row, 'canonical_url');
        $data->focusKeyword = $this->aioseoFocusKeyword($row);

        // AIOSEO stores robots settings as individual boolean columns plus a "default" flag.
        if (empty($row['robots_default'])) {
            $data->noIndex = !empty($row['robots_noindex']);
            $data->noFollow = !empty($row['robots_nofollow']);

            foreach (['noarchive', 'nosnippet', 'noimageindex', 'notranslate'] as $directive) {
                if (!empty($row['robots_' . $directive])) {
                    $data->robots[] = $directive;
                }
            }
        }

        $data->ogTitle = $this->expand($this->rowStr($row, 'og_title'), $post);
        $data->ogDescription = $this->expand($this->rowStr($row, 'og_description'), $post);
        $data->ogImageUrl = $this->rowStr($row, 'og_image_url');
        $data->ogType = $this->rowStr($row, 'og_object_type');

        $data->twitterTitle = $this->expand($this->rowStr($row, 'twitter_title'), $post);
        $data->twitterDescription = $this->expand($this->rowStr($row, 'twitter_description'), $post);
        $data->twitterImageUrl = $this->rowStr($row, 'twitter_image_url');
        $data->twitterCard = $this->rowStr($row, 'twitter_card');

        if (isset($row['priority']) && is_numeric($row['priority'])) {
            $data->priority = (float)$row['priority'];
        }

        $data->changeFrequency = $this->rowStr($row, 'frequency');

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function aioseoRows(SourceInterface $source): array
    {
        if ($this->aioseoRows !== null) {
            return $this->aioseoRows;
        }

        $rows = [];

        try {
            if ($source->hasTable('aioseo_posts')) {
                foreach ($source->table('aioseo_posts') as $row) {
                    $rows[(int)($row['post_id'] ?? 0)] = $row;
                }
            }
        } catch (\Throwable) {
            // No table, or a source that cannot read one. Fall back to nothing.
        }

        return $this->aioseoRows = $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function aioseoFocusKeyword(array $row): ?string
    {
        $keyphrase = $row['keyphrases'] ?? null;

        if (!is_string($keyphrase) || $keyphrase === '') {
            return null;
        }

        $decoded = \craft\helpers\Json::decodeIfJson($keyphrase);

        if (is_array($decoded) && isset($decoded['focus']['keyphrase'])) {
            return (string)$decoded['focus']['keyphrase'];
        }

        return null;
    }

    // -----------------------------------------------------------------------------------------
    // SEOPress and The SEO Framework
    // -----------------------------------------------------------------------------------------

    private function seopress(WpPost $post): ?SeoData
    {
        $meta = $post->metaWithPrefix('_seopress_');

        if ($meta === []) {
            return null;
        }

        $data = new SeoData(['source' => 'SEOPress']);
        $data->title = $this->expand($this->str($post, '_seopress_titles_title'), $post);
        $data->description = $this->expand($this->str($post, '_seopress_titles_desc'), $post);
        $data->canonical = $this->str($post, '_seopress_robots_canonical');
        $data->focusKeyword = $this->str($post, '_seopress_analysis_target_kw');

        $data->noIndex = $this->str($post, '_seopress_robots_index') === 'yes' ? true : null;
        $data->noFollow = $this->str($post, '_seopress_robots_follow') === 'yes' ? true : null;

        $data->ogTitle = $this->expand($this->str($post, '_seopress_social_fb_title'), $post);
        $data->ogDescription = $this->expand($this->str($post, '_seopress_social_fb_desc'), $post);
        $data->ogImageUrl = $this->str($post, '_seopress_social_fb_img');

        $data->twitterTitle = $this->expand($this->str($post, '_seopress_social_twitter_title'), $post);
        $data->twitterDescription = $this->expand($this->str($post, '_seopress_social_twitter_desc'), $post);
        $data->twitterImageUrl = $this->str($post, '_seopress_social_twitter_img');

        return $data;
    }

    private function seoFramework(WpPost $post): ?SeoData
    {
        $title = $this->str($post, '_genesis_title');
        $description = $this->str($post, '_genesis_description');

        if ($title === null && $description === null) {
            return null;
        }

        $data = new SeoData(['source' => 'The SEO Framework']);
        $data->title = $title;
        $data->description = $description;
        $data->canonical = $this->str($post, '_genesis_canonical_uri');

        $noindex = $post->metaValue('_genesis_noindex');
        $data->noIndex = $noindex === null ? null : ((string)$noindex === '1');

        $nofollow = $post->metaValue('_genesis_nofollow');
        $data->noFollow = $nofollow === null ? null : ((string)$nofollow === '1');

        $data->ogTitle = $this->str($post, '_open_graph_title');
        $data->ogDescription = $this->str($post, '_open_graph_description');
        $data->twitterTitle = $this->str($post, '_twitter_title');
        $data->twitterDescription = $this->str($post, '_twitter_description');

        return $data;
    }

    // -----------------------------------------------------------------------------------------
    // Variables
    // -----------------------------------------------------------------------------------------

    /**
     * Replace the SEO plugins' template variables with their values.
     *
     * A stored title is frequently `%%title%% %%page%% %%sep%% %%sitename%%` rather than a
     * literal string, and importing it unexpanded gives every page on the new site a title full
     * of percent signs. Only the variables whose values are knowable from the post itself are
     * substituted; the rest are removed, because a leftover token is worse than a slightly
     * shorter title.
     */
    private function expand(?string $value, WpPost $post): ?string
    {
        if ($value === null || $value === '' || !str_contains($value, '%')) {
            return $value;
        }

        $replacements = [
            'title' => $post->title,
            'post_title' => $post->title,
            'page_title' => $post->title,
            'excerpt' => $post->excerpt,
            'excerpt_only' => $post->excerpt,
            'post_excerpt' => $post->excerpt,
            'sep' => '-',
            'separator_sa' => '-',
            'currentdate' => date('j F Y'),
            'currentyear' => date('Y'),
            'currentmonth' => date('F'),
            'date' => $post->date !== null ? date('j F Y', strtotime($post->date)) : '',
            'modified' => $post->modified !== null ? date('j F Y', strtotime($post->modified)) : '',
            'page' => '',
            'pagenumber' => '',
            'pagetotal' => '',
        ];

        // Yoast and SEOPress use `%%name%%`; Rank Math uses `%name%`. Both are handled, longest
        // delimiter first so `%%title%%` is not half-matched by the single-percent pattern.
        foreach ([['%%', '%%'], ['%', '%']] as [$open, $close]) {
            $value = preg_replace_callback(
                '/' . preg_quote($open, '/') . '([a-z_]+)(?:\([^)]*\))?' . preg_quote($close, '/') . '/i',
                static function (array $m) use ($replacements) {
                    $key = strtolower($m[1]);

                    return $replacements[$key] ?? '';
                },
                $value
            ) ?? $value;
        }

        // Collapse the whitespace and stray separators left where variables were removed.
        $value = preg_replace('/\s{2,}/', ' ', $value) ?? $value;
        $value = trim($value);
        $value = trim($value, " -|·»–—");

        return $value !== '' ? $value : null;
    }

    private function str(WpPost $post, string $key): ?string
    {
        $value = $post->metaValue($key);

        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value !== '' ? $value : null;
    }

    private function int(WpPost $post, string $key): ?int
    {
        $value = $post->metaValue($key);

        return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowStr(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        return trim((string)$value) ?: null;
    }
}
