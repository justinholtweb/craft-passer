<?php

namespace justinholtweb\passer\tests\unit;

use justinholtweb\passer\helpers\WpSerialize;
use PHPUnit\Framework\TestCase;

/**
 * The serialized-meta reader, including the broken-length repair that every real WordPress
 * database needs.
 */
class WpSerializeTest extends TestCase
{
    public function testDetectsSerializedValues(): void
    {
        $this->assertTrue(WpSerialize::isSerialized('a:1:{s:3:"key";s:5:"value";}'));
        $this->assertTrue(WpSerialize::isSerialized('s:5:"hello";'));
        $this->assertTrue(WpSerialize::isSerialized('N;'));
        $this->assertTrue(WpSerialize::isSerialized('b:1;'));
        $this->assertTrue(WpSerialize::isSerialized('i:42;'));

        $this->assertFalse(WpSerialize::isSerialized('a plain string'));
        $this->assertFalse(WpSerialize::isSerialized(''));
        $this->assertFalse(WpSerialize::isSerialized(42));
        $this->assertFalse(WpSerialize::isSerialized(null));
        $this->assertFalse(WpSerialize::isSerialized('{"json":true}'));
    }

    public function testPassesThroughPlainValues(): void
    {
        $this->assertSame('just text', WpSerialize::unserialize('just text'));
        $this->assertSame(42, WpSerialize::unserialize(42));
        $this->assertNull(WpSerialize::unserialize(null));
    }

    public function testUnserializesIntactValues(): void
    {
        $original = ['title' => 'Hello', 'count' => 3, 'nested' => ['a', 'b']];

        $this->assertSame($original, WpSerialize::unserialize(serialize($original)));
    }

    /**
     * The case this class exists for: a site search-and-replaced with sed when it changed domain,
     * leaving every serialized string's recorded length wrong.
     */
    public function testRepairsLengthsBrokenBySearchAndReplace(): void
    {
        $original = serialize(['url' => 'http://old.example.com/page']);
        $broken = str_replace('http://old.example.com', 'https://new.example.org', $original);

        // Prove the premise: PHP itself cannot read this.
        $this->assertFalse(@unserialize($broken));

        $repaired = WpSerialize::unserialize($broken);

        $this->assertIsArray($repaired);
        $this->assertSame('https://new.example.org/page', $repaired['url']);
    }

    public function testRepairsLengthsWhenTheStringGotShorter(): void
    {
        $original = serialize(['url' => 'https://averylongdomainname.example.com/page']);
        $broken = str_replace('https://averylongdomainname.example.com', 'http://x.io', $original);

        $repaired = WpSerialize::unserialize($broken);

        $this->assertIsArray($repaired);
        $this->assertSame('http://x.io/page', $repaired['url']);
    }

    public function testRepairsNestedStructures(): void
    {
        $original = serialize([
            'options' => [
                'home' => 'http://old.test',
                'siteurl' => 'http://old.test',
            ],
            'count' => 7,
            'flag' => true,
        ]);

        $broken = str_replace('http://old.test', 'https://brand-new-domain.test', $original);
        $repaired = WpSerialize::unserialize($broken);

        $this->assertIsArray($repaired);
        $this->assertSame('https://brand-new-domain.test', $repaired['options']['home']);
        $this->assertSame(7, $repaired['count']);
        $this->assertTrue($repaired['flag']);
    }

    /**
     * A string containing `";` is indistinguishable from a terminator without the surrounding
     * context, which is what the end-finder is careful about.
     */
    public function testRepairsStringsContainingTheTerminatorSequence(): void
    {
        $original = serialize(['html' => '<a title="x";>link</a> at http://old.test']);
        $broken = str_replace('http://old.test', 'https://much-longer.example', $original);

        $repaired = WpSerialize::unserialize($broken);

        $this->assertIsArray($repaired);
        $this->assertStringContainsString('https://much-longer.example', $repaired['html']);
        $this->assertStringContainsString('<a title="x";>link</a>', $repaired['html']);
    }

    /**
     * An unrepairable value must come back as its raw string rather than as null, so the worst
     * case is opaque text rather than lost data.
     */
    public function testReturnsRawStringWhenRepairFails(): void
    {
        $garbage = 'a:2:{s:3:"key";s:9:"unterminated';

        $this->assertSame($garbage, WpSerialize::unserialize($garbage));
    }

    public function testRefusesToInstantiateObjects(): void
    {
        // `O:` payloads are how PHP object injection works. Nothing WordPress stores needs them.
        $payload = 'O:8:"stdClass":1:{s:4:"prop";s:5:"value";}';
        $result = WpSerialize::unserialize($payload);

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $result);
    }

    public function testFlattensScalars(): void
    {
        $this->assertSame([], WpSerialize::flattenScalars(null));
        $this->assertSame([], WpSerialize::flattenScalars(''));
        $this->assertSame(['one'], WpSerialize::flattenScalars('one'));
        $this->assertSame(['a', 'b', 'c'], WpSerialize::flattenScalars(['a', ['b', 'c']]));
        $this->assertSame(['1', '2'], WpSerialize::flattenScalars([1, 2]));
        $this->assertSame(['keep'], WpSerialize::flattenScalars(['keep', '', null]));
    }
}
