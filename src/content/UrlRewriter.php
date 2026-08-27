<?php

namespace justinholtweb\passer\content;

use craft\elements\Asset;
use justinholtweb\passer\importers\RunContext;
use justinholtweb\passer\services\IdMap;

/**
 * Rewrites links and image sources that point at the WordPress site being migrated away from.
 *
 * Every internal link in ten years of posts is an absolute URL on the old domain. Left alone,
 * they either 404 on launch day or — worse — keep working, quietly holding the old site alive
 * and splitting the site's traffic between two places forever.
 *
 * Three kinds get rewritten, in this order of preference:
 *
 * 1. A link to a post that has been imported becomes a Craft reference tag, which survives the
 *    entry being moved or renamed later.
 * 2. An image whose attachment has been imported gets the Craft asset's URL and an entity
 *    reference alongside it.
 * 3. Anything else on the old domain that was not imported becomes a root-relative path, which
 *    at least fails on the new site where it can be seen, rather than succeeding on the old one.
 */
class UrlRewriter
{
    /** @var array<string, int> URLs that could not be resolved, and how often each was seen. */
    private array $unresolved = [];

    /**
     * @return array<string, int>
     */
    public function unresolvedUrls(): array
    {
        arsort($this->unresolved);

        return $this->unresolved;
    }

    public function resetStats(): void
    {
        $this->unresolved = [];
    }

    public function rewrite(string $html, RunContext $context): string
    {
        $siteUrl = $context->source->siteUrl();

        if ($siteUrl === null || $siteUrl === '' || $html === '') {
            return $html;
        }

        // Link text is rewritten first, while it still matches the href it belongs to.
        $html = $this->rewriteVisibleUrls($html, $siteUrl, $context);

        $html = $this->rewriteAttribute($html, 'href', $siteUrl, $context);
        $html = $this->rewriteAttribute($html, 'src', $siteUrl, $context);

        // srcset holds several URLs in one attribute and needs its own pass.
        return $this->rewriteSrcset($html, $siteUrl, $context);
    }

    /**
     * Rewrite a link's visible text when it is the old URL spelled out.
     *
     * WordPress renders a bare URL as a link whose text is the URL. Rewriting only the href
     * would leave the dead domain printed on the page for every reader to see, which defeats
     * most of the point of migrating away from it.
     */
    private function rewriteVisibleUrls(string $html, string $siteUrl, RunContext $context): string
    {
        return preg_replace_callback(
            '#<a\b([^>]*\bhref=(["\'])(.*?)\2[^>]*)>(.*?)</a>#is',
            function (array $m) use ($siteUrl, $context) {
                $href = $m[3];
                $text = $m[4];

                // Only when the text *is* the URL, not when it merely mentions it.
                if (trim(html_entity_decode($text)) !== trim(html_entity_decode($href))) {
                    return $m[0];
                }

                $rewritten = $this->rewriteUrl($href, $siteUrl, $context, false);

                if ($rewritten === null || str_starts_with($rewritten, '{')) {
                    // A reference tag is not something to print as link text.
                    return $m[0];
                }

                return '<a' . $m[1] . '>' . htmlspecialchars($rewritten, ENT_QUOTES, 'UTF-8') . '</a>';
            },
            $html
        ) ?? $html;
    }

    private function rewriteAttribute(string $html, string $attribute, string $siteUrl, RunContext $context): string
    {
        $pattern = '/\b' . preg_quote($attribute, '/') . '=(["\'])(.*?)\1/i';

        return preg_replace_callback($pattern, function (array $m) use ($attribute, $siteUrl, $context) {
            $quote = $m[1];
            $url = $m[2];
            $rewritten = $this->rewriteUrl($url, $siteUrl, $context, $attribute === 'src');

            if ($rewritten === null) {
                return $m[0];
            }

            return $attribute . '=' . $quote . $rewritten . $quote;
        }, $html) ?? $html;
    }

    private function rewriteSrcset(string $html, string $siteUrl, RunContext $context): string
    {
        return preg_replace_callback('/\bsrcset=(["\'])(.*?)\1/i', function (array $m) use ($siteUrl, $context) {
            $parts = [];

            foreach (explode(',', $m[2]) as $candidate) {
                $candidate = trim($candidate);

                if ($candidate === '') {
                    continue;
                }

                // Each candidate is `<url> <descriptor>`, and the descriptor is optional.
                $pieces = preg_split('/\s+/', $candidate, 2) ?: [$candidate];
                $url = $pieces[0];
                $descriptor = $pieces[1] ?? '';

                $rewritten = $this->rewriteUrl($url, $siteUrl, $context, true) ?? $url;
                $parts[] = trim($rewritten . ' ' . $descriptor);
            }

            return 'srcset=' . $m[1] . implode(', ', $parts) . $m[1];
        }, $html) ?? $html;
    }

    /**
     * @return string|null The rewritten URL, or null to leave it alone.
     */
    private function rewriteUrl(string $url, string $siteUrl, RunContext $context, bool $isMedia): ?string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'data:') || str_starts_with($url, 'mailto:')) {
            return null;
        }

        if (!$this->isOnSite($url, $siteUrl)) {
            return null;
        }

        // Split off a fragment or query so the path can be matched, then reattach it.
        $suffix = '';
        $path = $url;

        foreach (['#', '?'] as $separator) {
            $position = strpos($path, $separator);

            if ($position !== false) {
                $suffix = substr($path, $position) . $suffix;
                $path = substr($path, 0, $position);
            }
        }

        $entry = $context->map->lookupByUrl($path);

        if ($entry !== null && $entry['destId'] !== null) {
            $resolved = $this->referenceFor($entry, $isMedia);

            if ($resolved !== null) {
                return $resolved . $suffix;
            }
        }

        // An uploads URL that is not in the map is a file that was never imported — usually
        // because it was orphaned. Recording it is more useful than silently relativising it.
        $relative = $this->toRelative($path, $siteUrl);

        if ($this->looksLikeUpload($path)) {
            $this->unresolved[$path] = ($this->unresolved[$path] ?? 0) + 1;
        }

        return $relative . $suffix;
    }

    /**
     * @param array{destId: int|null, destUid: string|null, destType: string} $entry
     */
    private function referenceFor(array $entry, bool $isMedia): ?string
    {
        $id = $entry['destId'];

        if ($id === null) {
            return null;
        }

        if ($entry['destType'] === Asset::class) {
            $asset = Asset::find()->id($id)->one();

            return $asset?->getUrl();
        }

        // A Craft reference tag: resolved at render time, so it keeps working when the entry's
        // URI changes — which it will, because changing URIs is half the point of a migration.
        return sprintf('{%s:%d:url}', $this->refHandleFor($entry['destType']), $id);
    }

    private function refHandleFor(string $elementType): string
    {
        return match ($elementType) {
            \craft\elements\Entry::class => 'entry',
            \craft\elements\Category::class => 'category',
            \craft\elements\Asset::class => 'asset',
            \craft\elements\User::class => 'user',
            \craft\elements\Tag::class => 'tag',
            default => 'entry',
        };
    }

    private function isOnSite(string $url, string $siteUrl): bool
    {
        $host = parse_url($siteUrl, PHP_URL_HOST);

        if ($host === null || $host === false) {
            return false;
        }

        // Protocol-relative URLs are common in content that predates HTTPS.
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        $urlHost = parse_url($url, PHP_URL_HOST);

        if ($urlHost === null || $urlHost === false) {
            // A relative URL is on the site by definition, but has nothing to rewrite.
            return false;
        }

        return $this->normalizeHost($urlHost) === $this->normalizeHost($host);
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function toRelative(string $url, string $siteUrl): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    private function looksLikeUpload(string $url): bool
    {
        return str_contains($url, '/wp-content/uploads/');
    }
}
