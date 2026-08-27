<?php

namespace justinholtweb\passer\tests\unit;

use justinholtweb\passer\content\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * The Gutenberg block-comment parser.
 */
class BlockParserTest extends TestCase
{
    private BlockParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BlockParser();
    }

    public function testDetectsBlockContent(): void
    {
        $this->assertTrue($this->parser->hasBlocks('<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->'));
        $this->assertFalse($this->parser->hasBlocks('<p>Just classic content</p>'));
    }

    public function testParsesASimpleBlock(): void
    {
        $blocks = $this->parser->parse('<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->');

        $this->assertCount(1, $blocks);
        $this->assertSame('core/paragraph', $blocks[0]->name);
        $this->assertSame('<p>Hello</p>', trim($blocks[0]->innerHtml));
    }

    public function testQualifiesCoreBlockNames(): void
    {
        $blocks = $this->parser->parse('<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->');

        $this->assertSame('core/heading', $blocks[0]->name);
        $this->assertTrue($blocks[0]->isCore());
        $this->assertSame('heading', $blocks[0]->shortName());
    }

    public function testKeepsThirdPartyNamespaces(): void
    {
        $blocks = $this->parser->parse('<!-- wp:acme/slider --><div></div><!-- /wp:acme/slider -->');

        $this->assertSame('acme/slider', $blocks[0]->name);
        $this->assertFalse($blocks[0]->isCore());
    }

    public function testParsesAttributes(): void
    {
        $blocks = $this->parser->parse(
            '<!-- wp:image {"id":42,"align":"center","sizeSlug":"large"} --><figure></figure><!-- /wp:image -->'
        );

        $this->assertSame(42, $blocks[0]->attribute('id'));
        $this->assertSame('center', $blocks[0]->attribute('align'));
        $this->assertNull($blocks[0]->attribute('missing'));
        $this->assertSame('fallback', $blocks[0]->attribute('missing', 'fallback'));
    }

    public function testParsesSelfClosingBlocks(): void
    {
        $blocks = $this->parser->parse('<!-- wp:spacer {"height":40} /-->');

        $this->assertCount(1, $blocks);
        $this->assertSame('core/spacer', $blocks[0]->name);
        $this->assertSame(40, $blocks[0]->attribute('height'));
        $this->assertSame([], $blocks[0]->innerBlocks);
    }

    public function testParsesNestedBlocks(): void
    {
        $content = <<<HTML
<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph --><p>Left</p><!-- /wp:paragraph --></div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph --><p>Right</p><!-- /wp:paragraph --></div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
HTML;

        $blocks = $this->parser->parse($content);

        $this->assertCount(1, $blocks);
        $this->assertSame('core/columns', $blocks[0]->name);
        $this->assertCount(2, $blocks[0]->innerBlocks);
        $this->assertSame('core/column', $blocks[0]->innerBlocks[0]->name);
        $this->assertSame('core/paragraph', $blocks[0]->innerBlocks[0]->innerBlocks[0]->name);
        $this->assertStringContainsString('Left', $blocks[0]->innerBlocks[0]->innerBlocks[0]->innerHtml);
    }

    /**
     * A parent's inner HTML must not still contain its children's markup, or the children get
     * rendered twice.
     */
    public function testStripsChildMarkupFromTheParentsInnerHtml(): void
    {
        $content = '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:paragraph --><p>Inside</p><!-- /wp:paragraph -->'
            . '</div><!-- /wp:group -->';

        $blocks = $this->parser->parse($content);

        $this->assertStringNotContainsString('Inside', $blocks[0]->innerHtml);
        $this->assertStringContainsString('wp-block-group', $blocks[0]->innerHtml);
    }

    public function testKeepsClassicContentBetweenBlocks(): void
    {
        $content = '<p>Before</p><!-- wp:paragraph --><p>Block</p><!-- /wp:paragraph --><p>After</p>';

        $blocks = $this->parser->parse($content);

        $this->assertCount(3, $blocks);
        $this->assertTrue($blocks[0]->isClassic());
        $this->assertStringContainsString('Before', $blocks[0]->innerHtml);
        $this->assertFalse($blocks[1]->isClassic());
        $this->assertTrue($blocks[2]->isClassic());
        $this->assertStringContainsString('After', $blocks[2]->innerHtml);
    }

    /**
     * Without merging, the whitespace between every pair of blocks becomes its own block.
     */
    public function testMergesConsecutiveClassicFragments(): void
    {
        $content = "<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->\n\n"
            . "<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->\n\n"
            . "<!-- wp:paragraph --><p>C</p><!-- /wp:paragraph -->";

        $blocks = $this->parser->parse($content);

        $this->assertCount(3, $blocks);

        foreach ($blocks as $block) {
            $this->assertFalse($block->isClassic());
        }
    }

    public function testIgnoresNonBlockComments(): void
    {
        $blocks = $this->parser->parse('<!-- just a comment --><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->');

        $this->assertCount(1, $blocks);
        $this->assertSame('core/paragraph', $blocks[0]->name);
    }

    /**
     * Malformed block markup is common after a plugin migration, and a stray closing delimiter
     * must not swallow the rest of the post.
     */
    public function testSurvivesAStrayClosingDelimiter(): void
    {
        $content = '<!-- /wp:paragraph --><!-- wp:heading --><h2>Still here</h2><!-- /wp:heading -->';

        $blocks = $this->parser->parse($content);

        $this->assertNotEmpty($blocks);
        $this->assertSame('core/heading', end($blocks)->name);
    }

    public function testHandlesAnUnclosedBlock(): void
    {
        $blocks = $this->parser->parse('<!-- wp:paragraph --><p>Never closed</p>');

        $this->assertCount(1, $blocks);
        $this->assertSame('core/paragraph', $blocks[0]->name);
        $this->assertStringContainsString('Never closed', $blocks[0]->innerHtml);
    }

    public function testReturnsNothingForEmptyContent(): void
    {
        $this->assertSame([], $this->parser->parse(''));
        $this->assertSame([], $this->parser->parse("   \n  "));
    }

    public function testKeepsTheOriginalMarkupForPassthrough(): void
    {
        $source = '<!-- wp:acme/unknown {"x":1} --><div>Payload</div><!-- /wp:acme/unknown -->';
        $blocks = $this->parser->parse($source);

        $this->assertSame($source, $blocks[0]->originalHtml);
    }
}
