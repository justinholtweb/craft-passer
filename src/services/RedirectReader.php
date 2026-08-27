<?php

namespace justinholtweb\passer\services;

use craft\base\Component;
use justinholtweb\passer\models\wp\WpRedirect;
use justinholtweb\passer\sources\SourceInterface;

/**
 * Reads redirects out of whichever plugin — or file — recorded them.
 *
 * Redirects are the single highest-stakes thing in a WordPress migration and the thing
 * `wp-import` most conspicuously does not do. A site with fifteen years of URL changes has
 * hundreds of them, each one holding a link somebody else made still working, and losing them
 * loses the traffic that link carried.
 *
 * Six sources are read, because there is no standard: Redirection's own tables, Rank Math's
 * table, All in One SEO's table, Safe Redirect Manager's post type, Simple 301 Redirects'
 * option, and the EPS plugin's table. Yoast Premium is deliberately absent — it stores its
 * redirects in an option whose format is not published and which changes between releases; what
 * Passer does instead is say so, rather than half-read it.
 */
class RedirectReader extends Component
{
    /**
     * @return WpRedirect[]
     */
    public function read(SourceInterface $source): array
    {
        $redirects = [];

        foreach ([
            'redirection' => $this->redirection(...),
            'rankmath' => $this->rankMath(...),
            'aioseo' => $this->aioseo(...),
            'safeRedirect' => $this->safeRedirectManager(...),
            'simple301' => $this->simple301(...),
            'eps' => $this->eps(...),
        ] as $reader) {
            try {
                foreach ($reader($source) as $redirect) {
                    $redirects[] = $redirect;
                }
            } catch (\Throwable) {
                // A reader whose table is absent contributes nothing; that is the normal case
                // for five of the six on any given site.
                continue;
            }
        }

        return $this->deduplicate($redirects);
    }

    /**
     * Whether a site has Yoast Premium redirects Passer will not read.
     */
    public function hasUnreadableYoastRedirects(SourceInterface $source): bool
    {
        try {
            $option = $source->option('wpseo-premium-redirects-base');

            return is_array($option) && $option !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------------------------------
    // Redirection
    // -----------------------------------------------------------------------------------------

    /**
     * @return \Generator<WpRedirect>
     */
    private function redirection(SourceInterface $source): \Generator
    {
        if (!$source->hasTable('redirection_items')) {
            return;
        }

        foreach ($source->table('redirection_items') as $row) {
            $redirect = new WpRedirect();
            $redirect->source = (string)($row['url'] ?? '');
            $redirect->destination = (string)($row['action_data'] ?? '');
            $redirect->statusCode = (int)($row['action_code'] ?? 301);
            $redirect->enabled = ($row['status'] ?? 'enabled') === 'enabled';
            $redirect->origin = 'Redirection';
            $redirect->hits = (int)($row['last_count'] ?? 0);

            // Redirection stores its match type as a `regex` flag rather than a mode.
            $redirect->matchType = !empty($row['regex']) ? 'regex' : 'exact';

            // `url` and `pass` actions are not redirects; only `url` with a target is.
            $actionType = (string)($row['action_type'] ?? 'url');

            if ($actionType !== 'url' || $redirect->source === '' || $redirect->destination === '') {
                continue;
            }

            yield $redirect;
        }
    }

    // -----------------------------------------------------------------------------------------
    // Rank Math
    // -----------------------------------------------------------------------------------------

    /**
     * @return \Generator<WpRedirect>
     */
    private function rankMath(SourceInterface $source): \Generator
    {
        if (!$source->hasTable('rank_math_redirections')) {
            return;
        }

        foreach ($source->table('rank_math_redirections') as $row) {
            $sources = \justinholtweb\passer\helpers\WpSerialize::unserialize($row['sources'] ?? null);

            if (!is_array($sources)) {
                continue;
            }

            $destination = (string)($row['url_to'] ?? '');
            $status = (int)($row['header_code'] ?? 301);
            $enabled = ($row['status'] ?? 'active') === 'active';

            // Rank Math allows several sources per rule, each with its own comparison mode.
            foreach ($sources as $entry) {
                if (!is_array($entry) || empty($entry['pattern'])) {
                    continue;
                }

                $redirect = new WpRedirect();
                $redirect->source = (string)$entry['pattern'];
                $redirect->destination = $destination;
                $redirect->statusCode = $status;
                $redirect->enabled = $enabled;
                $redirect->origin = 'Rank Math';
                $redirect->hits = (int)($row['hits'] ?? 0);

                $redirect->matchType = match ((string)($entry['comparison'] ?? 'exact')) {
                    'regex' => 'regex',
                    'start', 'contains' => 'prefix',
                    default => 'exact',
                };

                if ($redirect->destination !== '') {
                    yield $redirect;
                }
            }
        }
    }

    // -----------------------------------------------------------------------------------------
    // All in One SEO
    // -----------------------------------------------------------------------------------------

    /**
     * @return \Generator<WpRedirect>
     */
    private function aioseo(SourceInterface $source): \Generator
    {
        if (!$source->hasTable('aioseo_redirects')) {
            return;
        }

        foreach ($source->table('aioseo_redirects') as $row) {
            $redirect = new WpRedirect();
            $redirect->source = (string)($row['source_url'] ?? '');
            $redirect->destination = (string)($row['target_url'] ?? '');
            $redirect->statusCode = (int)($row['type'] ?? 301);
            $redirect->enabled = !empty($row['enabled']);
            $redirect->origin = 'All in One SEO';
            $redirect->hits = (int)($row['hits'] ?? 0);
            $redirect->matchType = !empty($row['regex']) ? 'regex' : 'exact';

            if ($redirect->source !== '' && $redirect->destination !== '') {
                yield $redirect;
            }
        }
    }

    // -----------------------------------------------------------------------------------------
    // Safe Redirect Manager
    // -----------------------------------------------------------------------------------------

    /**
     * @return \Generator<WpRedirect>
     */
    private function safeRedirectManager(SourceInterface $source): \Generator
    {
        foreach ($source->posts('redirect_rule') as $post) {
            $redirect = new WpRedirect();
            $redirect->source = (string)($post->metaValue('_redirect_rule_from') ?? '');
            $redirect->destination = (string)($post->metaValue('_redirect_rule_to') ?? '');
            $redirect->statusCode = (int)($post->metaValue('_redirect_rule_status_code') ?? 302);
            $redirect->enabled = $post->status === 'publish';
            $redirect->origin = 'Safe Redirect Manager';
            $redirect->matchType = $post->metaValue('_redirect_rule_from_regex') ? 'regex' : 'exact';

            if ($redirect->source !== '' && $redirect->destination !== '') {
                yield $redirect;
            }
        }
    }

    // -----------------------------------------------------------------------------------------
    // Simple 301 Redirects and EPS
    // -----------------------------------------------------------------------------------------

    /**
     * @return \Generator<WpRedirect>
     */
    private function simple301(SourceInterface $source): \Generator
    {
        $option = $source->option('301_redirects');

        if (!is_array($option)) {
            return;
        }

        foreach ($option as $from => $to) {
            if (!is_string($from) || !is_string($to) || $from === '' || $to === '') {
                continue;
            }

            $redirect = new WpRedirect();
            $redirect->source = $from;
            $redirect->destination = $to;
            $redirect->statusCode = 301;
            $redirect->origin = 'Simple 301 Redirects';

            // This plugin's only wildcard is a trailing `*`.
            if (str_ends_with($from, '*')) {
                $redirect->source = rtrim($from, '*');
                $redirect->matchType = 'prefix';
            }

            yield $redirect;
        }
    }

    /**
     * @return \Generator<WpRedirect>
     */
    private function eps(SourceInterface $source): \Generator
    {
        if (!$source->hasTable('eps_redirects')) {
            return;
        }

        foreach ($source->table('eps_redirects') as $row) {
            $redirect = new WpRedirect();
            $redirect->source = (string)($row['url_from'] ?? '');
            $redirect->destination = (string)($row['url_to'] ?? '');
            $redirect->statusCode = (int)($row['status'] ?? 301);
            $redirect->origin = '301 Redirects (EPS)';
            $redirect->hits = (int)($row['count'] ?? 0);

            if ($redirect->source !== '' && $redirect->destination !== '') {
                yield $redirect;
            }
        }
    }

    // -----------------------------------------------------------------------------------------
    // .htaccess
    // -----------------------------------------------------------------------------------------

    /**
     * Parse `Redirect` and `RewriteRule` directives out of an .htaccess file.
     *
     * Plenty of sites have no redirect plugin at all and a hand-maintained .htaccess instead.
     * The file cannot be read from a database, so the wizard offers to upload it.
     *
     * @return WpRedirect[]
     */
    public function parseHtaccess(string $contents): array
    {
        $redirects = [];

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Redirect [status] /from /to
            if (preg_match('/^Redirect(?:Permanent|Temp)?\s+(?:(\d{3})\s+)?(\S+)\s+(\S+)/i', $line, $m)) {
                $redirect = new WpRedirect();
                $redirect->source = $m[2];
                $redirect->destination = $m[3];
                $redirect->statusCode = $m[1] !== '' ? (int)$m[1] : (stripos($line, 'RedirectTemp') === 0 ? 302 : 301);
                $redirect->origin = '.htaccess';
                $redirects[] = $redirect;

                continue;
            }

            // RewriteRule ^from$ /to [R=301,L]
            if (preg_match('/^RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?/i', $line, $m)) {
                $flags = strtoupper($m[3] ?? '');

                // A RewriteRule without an R flag is an internal rewrite, not a redirect.
                if (!str_contains($flags, 'R=') && !str_contains($flags, 'R,') && !str_ends_with($flags, 'R')) {
                    continue;
                }

                $redirect = new WpRedirect();
                $redirect->source = $m[1];
                $redirect->destination = $m[2];
                $redirect->matchType = 'regex';
                $redirect->statusCode = preg_match('/R=(\d{3})/', $flags, $status) ? (int)$status[1] : 302;
                $redirect->origin = '.htaccess';
                $redirects[] = $redirect;
            }
        }

        return $this->deduplicate($redirects);
    }

    /**
     * Drop duplicate rules, keeping the first.
     *
     * Sites accumulate the same redirect in two plugins routinely — someone adds it in Yoast,
     * switches to Rank Math, and adds it again. Importing both would create a rule that shadows
     * itself.
     *
     * @param WpRedirect[] $redirects
     * @return WpRedirect[]
     */
    private function deduplicate(array $redirects): array
    {
        $seen = [];
        $out = [];

        foreach ($redirects as $redirect) {
            $key = $redirect->matchType . '|' . $this->normalize($redirect->source);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $redirect;
        }

        return $out;
    }

    private function normalize(string $path): string
    {
        $path = trim($path);

        // A stored source may be a full URL or a path, with or without a trailing slash.
        $parsed = parse_url($path, PHP_URL_PATH);

        if (is_string($parsed) && $parsed !== '') {
            $path = $parsed;
        }

        return '/' . trim(strtolower($path), '/');
    }
}
