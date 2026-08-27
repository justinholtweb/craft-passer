<?php

namespace justinholtweb\passer\tests\unit;

use justinholtweb\passer\models\wp\WpPost;
use justinholtweb\passer\services\SeoReader;
use PHPUnit\Framework\TestCase;

/**
 * Reading SEO metadata out of the five plugins, and expanding their template variables.
 */
class SeoReaderTest extends TestCase
{
    private SeoReader $reader;

    protected function setUp(): void
    {
        $this->reader = new SeoReader();
    }

    private function post(array $meta, string $title = 'A post about migrations'): WpPost
    {
        $post = new WpPost();
        $post->id = 1;
        $post->title = $title;
        $post->excerpt = 'A short summary.';
        $post->date = '2024-03-01 10:00:00';
        $post->meta = $meta;

        return $post;
    }

    public function testReadsYoast(): void
    {
        $this->reader->setActivePlugins(['wordpress-seo']);

        $data = $this->reader->read($this->post([
            '_yoast_wpseo_title' => 'Custom SEO title',
            '_yoast_wpseo_metadesc' => 'Custom description',
            '_yoast_wpseo_canonical' => 'https://example.com/canonical',
            '_yoast_wpseo_focuskw' => 'migration',
            '_yoast_wpseo_meta-robots-noindex' => '1',
            '_yoast_wpseo_opengraph-title' => 'Social title',
        ]), $this->source());

        $this->assertSame('Yoast SEO', $data->source);
        $this->assertSame('Custom SEO title', $data->title);
        $this->assertSame('Custom description', $data->description);
        $this->assertSame('https://example.com/canonical', $data->canonical);
        $this->assertSame('migration', $data->focusKeyword);
        $this->assertTrue($data->noIndex);
        $this->assertSame('Social title', $data->ogTitle);
    }

    /**
     * Yoast writes `2` for "index", not absence, and reading that as noindex would deindex the
     * whole site.
     */
    public function testYoastIndexValueIsNotNoindex(): void
    {
        $this->reader->setActivePlugins(['wordpress-seo']);

        $data = $this->reader->read($this->post([
            '_yoast_wpseo_title' => 'Title',
            '_yoast_wpseo_meta-robots-noindex' => '2',
        ]), $this->source());

        $this->assertFalse($data->noIndex);
    }

    public function testReadsRankMath(): void
    {
        $this->reader->setActivePlugins(['seo-by-rank-math']);

        $data = $this->reader->read($this->post([
            'rank_math_title' => 'Rank Math title',
            'rank_math_description' => 'Rank Math description',
            'rank_math_focus_keyword' => 'primary,secondary,tertiary',
            'rank_math_robots' => ['noindex', 'nofollow', 'noarchive'],
        ]), $this->source());

        $this->assertSame('Rank Math', $data->source);
        $this->assertSame('Rank Math title', $data->title);
        $this->assertSame('primary', $data->focusKeyword);
        $this->assertSame(['secondary', 'tertiary'], $data->keywords);
        $this->assertTrue($data->noIndex);
        $this->assertTrue($data->noFollow);
        $this->assertSame(['noarchive'], $data->robots);
    }

    public function testReadsSeopress(): void
    {
        $this->reader->setActivePlugins(['wp-seopress']);

        $data = $this->reader->read($this->post([
            '_seopress_titles_title' => 'SEOPress title',
            '_seopress_titles_desc' => 'SEOPress description',
        ]), $this->source());

        $this->assertSame('SEOPress', $data->source);
        $this->assertSame('SEOPress title', $data->title);
    }

    public function testReadsTheSeoFramework(): void
    {
        $this->reader->setActivePlugins(['autodescription']);

        $data = $this->reader->read($this->post([
            '_genesis_title' => 'Framework title',
            '_genesis_noindex' => '1',
        ]), $this->source());

        $this->assertSame('The SEO Framework', $data->source);
        $this->assertSame('Framework title', $data->title);
        $this->assertTrue($data->noIndex);
    }

    /**
     * A stored title is frequently a template rather than a string, and importing it unexpanded
     * puts percent signs on every page.
     */
    public function testExpandsYoastStyleVariables(): void
    {
        $this->reader->setActivePlugins(['wordpress-seo']);

        $data = $this->reader->read($this->post([
            '_yoast_wpseo_title' => '%%title%% %%sep%% %%sitename%%',
        ]), $this->source());

        // `sitename` is not knowable from the post, so it is removed rather than left as a token.
        $this->assertStringContainsString('A post about migrations', $data->title);
        $this->assertStringNotContainsString('%%', $data->title);
    }

    public function testExpandsRankMathStyleVariables(): void
    {
        $this->reader->setActivePlugins(['seo-by-rank-math']);

        $data = $this->reader->read($this->post([
            'rank_math_title' => '%title% %sep% %currentyear%',
        ]), $this->source());

        $this->assertStringContainsString('A post about migrations', $data->title);
        $this->assertStringContainsString(date('Y'), $data->title);
        $this->assertStringNotContainsString('%', $data->title);
    }

    public function testTrimsSeparatorsLeftBehindByRemovedVariables(): void
    {
        $this->reader->setActivePlugins(['wordpress-seo']);

        $data = $this->reader->read($this->post([
            '_yoast_wpseo_title' => '%%title%% %%sep%% %%page%%',
        ]), $this->source());

        $this->assertSame('A post about migrations', $data->title);
    }

    /**
     * A site that switched plugins has both sets of meta, and the one someone actually filled in
     * is the one worth keeping — per post, not per site.
     */
    public function testPrefersWhicheverPluginHasMoreDataForThisPost(): void
    {
        $this->reader->setActivePlugins(['wordpress-seo', 'seo-by-rank-math']);

        $data = $this->reader->read($this->post([
            '_yoast_wpseo_title' => 'Only a title',
            'rank_math_title' => 'Rank Math title',
            'rank_math_description' => 'Rank Math description',
            'rank_math_canonical_url' => 'https://example.com/x',
        ]), $this->source());

        $this->assertSame('Rank Math', $data->source);
    }

    public function testReturnsEmptyWhenNothingIsSet(): void
    {
        $this->reader->setActivePlugins(['wordpress-seo', 'seo-by-rank-math']);

        $data = $this->reader->read($this->post([]), $this->source());

        $this->assertTrue($data->isEmpty());
    }

    public function testReturnsEmptyWhenNoPluginIsActive(): void
    {
        $this->reader->setActivePlugins([]);

        $data = $this->reader->read($this->post(['_yoast_wpseo_title' => 'Ignored']), $this->source());

        $this->assertTrue($data->isEmpty());
    }

    /**
     * A source is only consulted for the AIOSEO table, which none of these tests exercise.
     */
    private function source(): \justinholtweb\passer\sources\SourceInterface
    {
        return new class () extends \justinholtweb\passer\sources\BaseSource {
            public static function type(): string
            {
                return 'stub';
            }

            protected function defineCapabilities(): \justinholtweb\passer\sources\SourceCapabilities
            {
                return new \justinholtweb\passer\sources\SourceCapabilities();
            }

            protected function fingerprint(): string
            {
                return 'stub';
            }

            public function connect(): void
            {
                $this->connected = true;
            }

            public function testConnection(): string
            {
                return 'stub';
            }

            public function siteUrl(): ?string
            {
                return 'https://example.com';
            }

            public function postTypes(): array
            {
                return [];
            }

            public function taxonomies(): array
            {
                return [];
            }

            public function posts(string $postType, int $offset = 0, ?int $limit = null): \Generator
            {
                yield from [];
            }

            public function terms(string $taxonomy, int $offset = 0, ?int $limit = null): \Generator
            {
                yield from [];
            }

            public function users(int $offset = 0, ?int $limit = null): \Generator
            {
                yield from [];
            }

            public function comments(int $offset = 0, ?int $limit = null): \Generator
            {
                yield from [];
            }
        };
    }
}
