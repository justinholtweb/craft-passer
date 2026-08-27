<?php

namespace justinholtweb\passer\tests\unit;

use justinholtweb\passer\content\ShortcodeExpander;
use PHPUnit\Framework\TestCase;

/**
 * The shortcode attribute parser.
 *
 * Expansion itself needs a run context and an ID map, so it is covered by the integration suite.
 * The attribute parsing is the fiddly part and is entirely self-contained.
 */
class ShortcodeExpanderTest extends TestCase
{
    private ShortcodeExpander $expander;

    protected function setUp(): void
    {
        $this->expander = new ShortcodeExpander();
    }

    public function testParsesDoubleQuotedAttributes(): void
    {
        $attributes = $this->expander->parseAttributes('id="42" size="large"');

        $this->assertSame('42', $attributes['id']);
        $this->assertSame('large', $attributes['size']);
    }

    public function testParsesSingleQuotedAttributes(): void
    {
        $attributes = $this->expander->parseAttributes("id='42' align='center'");

        $this->assertSame('42', $attributes['id']);
        $this->assertSame('center', $attributes['align']);
    }

    public function testParsesUnquotedAttributes(): void
    {
        $attributes = $this->expander->parseAttributes('columns=3 link=file');

        $this->assertSame('3', $attributes['columns']);
        $this->assertSame('file', $attributes['link']);
    }

    public function testLowercasesAttributeNames(): void
    {
        $attributes = $this->expander->parseAttributes('ID="7" Size="Large"');

        $this->assertSame('7', $attributes['id']);
        // The value's case is content and is left alone.
        $this->assertSame('Large', $attributes['size']);
    }

    public function testIndexesPositionalAttributes(): void
    {
        $attributes = $this->expander->parseAttributes('"first" "second"');

        $this->assertSame('first', $attributes['0']);
        $this->assertSame('second', $attributes['1']);
    }

    public function testMixesNamedAndPositionalAttributes(): void
    {
        $attributes = $this->expander->parseAttributes('id="9" "Some caption"');

        $this->assertSame('9', $attributes['id']);
        $this->assertSame('Some caption', $attributes['0']);
    }

    /**
     * The visual editor substitutes smart quotes and non-breaking spaces into shortcodes, which
     * stops naive parsing dead.
     */
    public function testNormalisesSmartQuotesAndNonBreakingSpaces(): void
    {
        $attributes = $this->expander->parseAttributes("id=\u{201c}42\u{201d}\u{00a0}size=\u{2018}large\u{2019}");

        $this->assertSame('42', $attributes['id']);
        $this->assertSame('large', $attributes['size']);
    }

    public function testHandlesNoAttributes(): void
    {
        $this->assertSame([], $this->expander->parseAttributes(''));
        $this->assertSame([], $this->expander->parseAttributes('   '));
    }

    public function testHandlesSpacesAroundEquals(): void
    {
        $attributes = $this->expander->parseAttributes('ids = "1,2,3"');

        $this->assertSame('1,2,3', $attributes['ids']);
    }

    public function testKeepsAnEmptyQuotedValue(): void
    {
        $attributes = $this->expander->parseAttributes('title="" id="4"');

        $this->assertArrayHasKey('title', $attributes);
        $this->assertSame('', $attributes['title']);
        $this->assertSame('4', $attributes['id']);
    }

    public function testTracksUnhandledShortcodes(): void
    {
        $this->assertSame([], $this->expander->unhandledShortcodes());
    }

    public function testResetsStats(): void
    {
        $this->expander->resetStats();

        $this->assertSame([], $this->expander->unhandledShortcodes());
    }

    /**
     * A registered handler must be reachable, which is the extension point third-party
     * shortcodes use.
     */
    public function testRegistersACustomHandler(): void
    {
        $this->expander->register('acme', static fn(array $attributes) => 'handled:' . ($attributes['x'] ?? ''));

        $reflection = new \ReflectionClass($this->expander);
        $property = $reflection->getProperty('handlers');
        $property->setAccessible(true);

        $this->assertArrayHasKey('acme', $property->getValue($this->expander));
    }
}
