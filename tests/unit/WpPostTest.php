<?php

namespace justinholtweb\passer\tests\unit;

use justinholtweb\passer\models\wp\WpPost;
use PHPUnit\Framework\TestCase;

/**
 * How post meta is read, which is where a WordPress importer most easily corrupts data.
 */
class WpPostTest extends TestCase
{
    public function testReadsPlainMeta(): void
    {
        $post = new WpPost();
        $post->setMetaFromRows([
            ['meta_key' => 'subtitle', 'meta_value' => 'A subtitle'],
        ]);

        $this->assertSame('A subtitle', $post->metaValue('subtitle'));
        $this->assertSame(['A subtitle'], $post->metaList('subtitle'));
    }

    public function testUnserializesMeta(): void
    {
        $post = new WpPost();
        $post->setMetaFromRows([
            ['meta_key' => 'settings', 'meta_value' => serialize(['a' => 1, 'b' => 2])],
        ]);

        $this->assertSame(['a' => 1, 'b' => 2], $post->metaValue('settings'));
    }

    /**
     * A genuinely-array value must come back whole. Returning its first element — which an
     * importer that conflates arrays with repeated rows will do — turns Rank Math's robots
     * directives into the single word "noindex" and WooCommerce's attributes into one attribute.
     */
    public function testReturnsAnArrayValueWhole(): void
    {
        $post = new WpPost();
        $post->setMetaFromRows([
            ['meta_key' => 'rank_math_robots', 'meta_value' => serialize(['noindex', 'nofollow'])],
        ]);

        $this->assertSame(['noindex', 'nofollow'], $post->metaValue('rank_math_robots'));
    }

    /**
     * Repeated rows are a different thing entirely, and both must be readable.
     */
    public function testKeepsRepeatedRowsSeparately(): void
    {
        $post = new WpPost();
        $post->setMetaFromRows([
            ['meta_key' => 'tag', 'meta_value' => 'first'],
            ['meta_key' => 'tag', 'meta_value' => 'second'],
            ['meta_key' => 'tag', 'meta_value' => 'third'],
        ]);

        // WordPress's own single-value read returns the first row.
        $this->assertSame('first', $post->metaValue('tag'));
        $this->assertSame(['first', 'second', 'third'], $post->metaList('tag'));
    }

    public function testReturnsTheDefaultForAMissingKey(): void
    {
        $post = new WpPost();

        $this->assertNull($post->metaValue('nope'));
        $this->assertSame('fallback', $post->metaValue('nope', 'fallback'));
        $this->assertSame([], $post->metaList('nope'));
    }

    public function testFindsTheFeaturedImage(): void
    {
        $post = new WpPost();
        $post->setMetaFromRows([['meta_key' => '_thumbnail_id', 'meta_value' => '412']]);

        $this->assertSame(412, $post->featuredImageId);
    }

    public function testReadsAttachmentMetadata(): void
    {
        $post = new WpPost();
        $post->type = 'attachment';
        $post->setMetaFromRows([
            ['meta_key' => '_wp_attachment_metadata', 'meta_value' => serialize(['width' => 800, 'height' => 600])],
        ]);

        $this->assertTrue($post->isAttachment());
        $this->assertSame(800, $post->attachmentMeta['width']);
    }

    public function testFindsMetaByPrefix(): void
    {
        $post = new WpPost();
        $post->setMetaFromRows([
            ['meta_key' => '_yoast_wpseo_title', 'meta_value' => 'T'],
            ['meta_key' => '_yoast_wpseo_metadesc', 'meta_value' => 'D'],
            ['meta_key' => 'unrelated', 'meta_value' => 'X'],
        ]);

        $prefixed = $post->metaWithPrefix('_yoast_wpseo_');

        $this->assertCount(2, $prefixed);
        $this->assertArrayNotHasKey('unrelated', $prefixed);
    }

    public function testKnowsWhenItIsPublished(): void
    {
        $post = new WpPost();

        $post->status = 'publish';
        $this->assertTrue($post->isPublished());

        $post->status = 'draft';
        $this->assertFalse($post->isPublished());
    }

    public function testIgnoresRowsWithNoKey(): void
    {
        $post = new WpPost();
        $post->setMetaFromRows([
            ['meta_value' => 'orphan'],
            ['meta_key' => 'good', 'meta_value' => 'value'],
        ]);

        $this->assertSame(['good' => 'value'], $post->meta);
    }
}
