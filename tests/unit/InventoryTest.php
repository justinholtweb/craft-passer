<?php

namespace justinholtweb\passer\tests\unit;

use justinholtweb\passer\models\DetectedPlugin;
use justinholtweb\passer\models\Inventory;
use justinholtweb\passer\sources\SourceCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * What the wizard shows, and what it filters out of it.
 */
class InventoryTest extends TestCase
{
    public function testHidesWordpresssOwnBookkeepingPostTypes(): void
    {
        $inventory = new Inventory();
        $inventory->postTypes = [
            'post' => 120,
            'page' => 18,
            'revision' => 4000,
            'nav_menu_item' => 22,
            'acf-field' => 60,
            'attachment' => 900,
            'product_variation' => 300,
            'wp_global_styles' => 1,
            'case-study' => 12,
        ];

        $content = $inventory->contentPostTypes();

        $this->assertSame(['post' => 120, 'page' => 18, 'case-study' => 12], $content);
    }

    public function testHidesInternalTaxonomies(): void
    {
        $inventory = new Inventory();
        $inventory->taxonomies = [
            'category' => 10,
            'post_tag' => 45,
            'nav_menu' => 3,
            'wp_theme' => 1,
            'product_visibility' => 2,
            'genre' => 8,
        ];

        $this->assertSame(
            ['category' => 10, 'post_tag' => 45, 'genre' => 8],
            $inventory->contentTaxonomies()
        );
    }

    public function testFindsDetectedPlugins(): void
    {
        $inventory = new Inventory();
        $inventory->plugins = [
            new DetectedPlugin(['slug' => 'woocommerce', 'name' => 'WooCommerce', 'category' => 'commerce']),
            new DetectedPlugin(['slug' => 'wordpress-seo', 'name' => 'Yoast SEO', 'category' => 'seo']),
            new DetectedPlugin(['slug' => 'seo-by-rank-math', 'name' => 'Rank Math', 'category' => 'seo']),
        ];

        $this->assertTrue($inventory->hasPlugin('woocommerce'));
        $this->assertFalse($inventory->hasPlugin('elementor'));
        $this->assertSame('Yoast SEO', $inventory->plugin('wordpress-seo')->name);
        $this->assertCount(2, $inventory->pluginsInCategory('seo'));
        $this->assertSame([], $inventory->pluginsInCategory('forms'));
    }

    public function testCountsEverythingWorthMigrating(): void
    {
        $inventory = new Inventory();
        $inventory->postTypes = ['post' => 100, 'revision' => 5000, 'attachment' => 40];
        $inventory->taxonomies = ['category' => 10];
        $inventory->users = 5;
        $inventory->comments = 200;
        $inventory->attachments = 40;
        $inventory->products = 30;
        $inventory->orders = 500;

        // Revisions are excluded; attachments are counted once, from their own property.
        $this->assertSame(100 + 10 + 5 + 200 + 40 + 30 + 500, $inventory->totalItems());
    }
}

/**
 * What a source declares it can answer, which is what stops a run reporting success after
 * importing nothing.
 */
class SourceCapabilitiesTest extends TestCase
{
    public function testListsSupportedDomains(): void
    {
        $capabilities = new SourceCapabilities([
            'posts' => true,
            'postMeta' => true,
            'terms' => true,
            'users' => true,
            'media' => true,
            'comments' => true,
            'menus' => true,
        ]);

        $domains = $capabilities->supportedDomains();

        $this->assertContains('content', $domains);
        $this->assertContains('seo', $domains);
        $this->assertContains('menus', $domains);
        $this->assertNotContains('commerce', $domains);
        $this->assertNotContains('widgets', $domains);
    }

    public function testAnswersSupportsForEachDomain(): void
    {
        $capabilities = new SourceCapabilities(['posts' => true, 'commerce' => false]);

        $this->assertTrue($capabilities->supports('content'));
        $this->assertFalse($capabilities->supports('commerce'));
        $this->assertFalse($capabilities->supports('nonsense'));
    }

    /**
     * SEO lives in post meta, so a source that cannot read meta cannot migrate SEO, however much
     * else it can read.
     */
    public function testTiesSeoToPostMeta(): void
    {
        $withMeta = new SourceCapabilities(['posts' => true, 'postMeta' => true]);
        $withoutMeta = new SourceCapabilities(['posts' => true, 'postMeta' => false]);

        $this->assertTrue($withMeta->supports('seo'));
        $this->assertFalse($withoutMeta->supports('seo'));
    }

    public function testAnEmptyCapabilitySetSupportsNothing(): void
    {
        $this->assertSame([], (new SourceCapabilities())->supportedDomains());
    }
}
