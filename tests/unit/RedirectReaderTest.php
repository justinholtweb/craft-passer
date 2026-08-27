<?php

namespace justinholtweb\passer\tests\unit;

use justinholtweb\passer\services\RedirectReader;
use PHPUnit\Framework\TestCase;

/**
 * Parsing redirects out of a hand-maintained .htaccess, which is what a site with no redirect
 * plugin has instead.
 */
class RedirectReaderTest extends TestCase
{
    private RedirectReader $reader;

    protected function setUp(): void
    {
        $this->reader = new RedirectReader();
    }

    public function testParsesRedirectDirectives(): void
    {
        $redirects = $this->reader->parseHtaccess("Redirect 301 /old-page /new-page\n");

        $this->assertCount(1, $redirects);
        $this->assertSame('/old-page', $redirects[0]->source);
        $this->assertSame('/new-page', $redirects[0]->destination);
        $this->assertSame(301, $redirects[0]->statusCode);
        $this->assertSame('.htaccess', $redirects[0]->origin);
    }

    public function testDefaultsToPermanentForABareRedirect(): void
    {
        $redirects = $this->reader->parseHtaccess('Redirect /a /b');

        $this->assertSame(301, $redirects[0]->statusCode);
    }

    public function testReadsRedirectTempAsTemporary(): void
    {
        $redirects = $this->reader->parseHtaccess('RedirectTemp /a /b');

        $this->assertSame(302, $redirects[0]->statusCode);
    }

    public function testParsesRewriteRulesWithARedirectFlag(): void
    {
        $redirects = $this->reader->parseHtaccess('RewriteRule ^old/(.*)$ /new/$1 [R=301,L]');

        $this->assertCount(1, $redirects);
        $this->assertSame('^old/(.*)$', $redirects[0]->source);
        $this->assertSame('/new/$1', $redirects[0]->destination);
        $this->assertTrue($redirects[0]->isRegex());
        $this->assertSame(301, $redirects[0]->statusCode);
    }

    /**
     * A RewriteRule without an R flag is an internal rewrite, and importing it as a redirect
     * would send visitors somewhere WordPress never sent them.
     */
    public function testIgnoresInternalRewrites(): void
    {
        $redirects = $this->reader->parseHtaccess('RewriteRule ^index\.php$ - [L]');

        $this->assertSame([], $redirects);
    }

    public function testIgnoresCommentsAndBlankLines(): void
    {
        $htaccess = <<<CONF
# BEGIN WordPress

Redirect 301 /a /b

# A comment mentioning Redirect 301 /c /d
CONF;

        $redirects = $this->reader->parseHtaccess($htaccess);

        $this->assertCount(1, $redirects);
        $this->assertSame('/a', $redirects[0]->source);
    }

    /**
     * Sites accumulate the same rule twice — added in one plugin, then again in its replacement.
     * Importing both creates a rule that shadows itself.
     */
    public function testDeduplicatesBySource(): void
    {
        $redirects = $this->reader->parseHtaccess("Redirect 301 /a /b\nRedirect 302 /a /c\n");

        $this->assertCount(1, $redirects);
        $this->assertSame('/b', $redirects[0]->destination);
    }

    public function testTreatsTrailingSlashesAsTheSameRule(): void
    {
        $redirects = $this->reader->parseHtaccess("Redirect 301 /a /b\nRedirect 301 /a/ /c\n");

        $this->assertCount(1, $redirects);
    }

    /**
     * A regex rule and an exact rule on the same path are different rules.
     */
    public function testKeepsRegexAndExactRulesApart(): void
    {
        $redirects = $this->reader->parseHtaccess("Redirect 301 /a /b\nRewriteRule /a /c [R=301]\n");

        $this->assertCount(2, $redirects);
    }

    public function testHandlesAnEmptyFile(): void
    {
        $this->assertSame([], $this->reader->parseHtaccess(''));
    }

    public function testReadsAStatusFromTheRewriteFlags(): void
    {
        $redirects = $this->reader->parseHtaccess('RewriteRule ^a$ /b [R=302,NC,L]');

        $this->assertSame(302, $redirects[0]->statusCode);
    }
}
