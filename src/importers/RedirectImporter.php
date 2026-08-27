<?php

namespace justinholtweb\passer\importers;

use Craft;
use craft\helpers\FileHelper;
use justinholtweb\passer\models\wp\WpRedirect;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\services\IdMap;

/**
 * WordPress redirects become Craft redirects.
 *
 * This runs last, because a redirect's destination is frequently a post that had to be imported
 * first — and resolving it through the ID map is the whole point. A redirect imported as a
 * frozen URL points at a page whose address the migration is about to change; one resolved
 * through the map points at the entry, and keeps working.
 *
 * Four destinations, in preference order: Retour, Venveo's Redirect, Friends, and a plain
 * `config/redirects.php` that needs nothing installed at all.
 */
class RedirectImporter extends BaseImporter
{
    public static function phase(): string
    {
        return 'redirects';
    }

    public function import(RunContext $context, array $resumeFrom = []): array
    {
        $domain = $context->plan->domain('redirects');

        if ($domain === null || !$domain->enabled) {
            return [];
        }

        $reader = Plugin::getInstance()->redirects;
        $redirects = $reader->read($context->source);

        // A .htaccess uploaded through the wizard is parsed alongside whatever the plugins hold.
        $htaccess = (string)($domain->options['htaccess'] ?? '');

        if ($htaccess !== '') {
            $redirects = array_merge($redirects, $reader->parseHtaccess($htaccess));
        }

        if ($reader->hasUnreadableYoastRedirects($context->source)) {
            $this->warn($context, sprintf(
                'This site has Yoast Premium redirects. Their storage format is not published and '
                . 'changes between releases, so Passer does not read them rather than reading '
                . 'them wrongly. Export them from Yoast (SEO → Redirects → Import/Export) as a '
                . 'CSV or .htaccess and import that instead.'
            ));
        }

        if ($redirects === []) {
            $this->notice($context, 'No redirects were found in the source.');

            return [];
        }

        $context->progress->startPhase(self::phase(), count($redirects));
        $context->map->warm();

        $resolved = $this->resolveDestinations($redirects, $context);
        $processed = 0;

        // The config-file destination writes once at the end rather than per redirect.
        if ($domain->destination === 'config-file') {
            $this->writeConfigFile($resolved, $context);

            $context->progress->endPhase(self::phase());

            return [];
        }

        foreach ($resolved as $redirect) {
            $processed++;

            $this->attempt($context, 'redirect', $redirect->source, $redirect->source, function () use ($redirect, $domain, $context) {
                match ($domain->destination) {
                    'retour' => $this->writeRetour($redirect, $context),
                    'venveo-redirect' => $this->writeVenveo($redirect, $context),
                    'friends' => $this->writeFriends($redirect, $context),
                    default => null,
                };
            });

            $context->progress->advance($processed, $redirect->source);
        }

        $context->progress->endPhase(self::phase());

        $this->summarise($resolved, $domain->destination, $context);

        return [];
    }

    /**
     * Turn each redirect's destination into a live Craft URL where possible.
     *
     * @param WpRedirect[] $redirects
     * @return WpRedirect[]
     */
    private function resolveDestinations(array $redirects, RunContext $context): array
    {
        $siteUrl = $context->source->siteUrl();
        $resolvedCount = 0;

        foreach ($redirects as $redirect) {
            $redirect->source = $this->normalizePath($redirect->source, $siteUrl);

            // A regex destination often contains backreferences and must not be touched.
            if ($redirect->isRegex()) {
                continue;
            }

            $entry = $context->map->lookupByUrl($this->absolute($redirect->destination, $siteUrl));

            if ($entry !== null && $entry['destId'] !== null) {
                $element = $entry['destType']::find()->id($entry['destId'])->status(null)->one();
                $url = $element?->getUrl();

                if ($url !== null) {
                    $redirect->destination = $this->normalizePath($url, null);
                    $redirect->destinationPostId = $entry['destId'];
                    $resolvedCount++;

                    continue;
                }
            }

            $redirect->destination = $this->normalizePath($redirect->destination, $siteUrl);
        }

        if ($resolvedCount > 0) {
            $this->info($context, sprintf(
                '%d redirect destination%s were resolved to imported content rather than left as '
                . 'the old site\'s URLs.',
                $resolvedCount,
                $resolvedCount === 1 ? '' : 's'
            ));
        }

        return $redirects;
    }

    // -----------------------------------------------------------------------------------------
    // Destinations
    // -----------------------------------------------------------------------------------------

    private function writeRetour(WpRedirect $redirect, RunContext $context): void
    {
        if ($context->dryRun) {
            $context->count(self::phase(), 'created');

            return;
        }

        $retour = Craft::$app->getPlugins()->getPlugin('retour');

        if ($retour === null) {
            throw new \RuntimeException('Retour is not installed.');
        }

        $config = [
            'redirectSrcUrl' => $redirect->source,
            'redirectDestUrl' => $redirect->destination,
            'redirectHttpCode' => $redirect->statusCode,
            'redirectMatchType' => $redirect->isRegex() ? 'regexmatch' : 'exactmatch',
            'redirectSrcMatch' => 'pathonly',
            'enabled' => $redirect->enabled,
            'hitCount' => $redirect->hits,
            'siteId' => $context->plan->defaultSiteId ?? Craft::$app->getSites()->getPrimarySite()->id,
        ];

        // Retour's redirect service has kept the same saveRedirect signature across its Craft 4
        // and 5 lines, but the guard costs nothing and turns an API change into a message.
        $service = method_exists($retour, 'getRedirects') ? $retour->getRedirects() : null;

        if ($service === null || !method_exists($service, 'saveRedirect')) {
            throw new \RuntimeException('Retour is installed but its redirects service could not be reached.');
        }

        $service->saveRedirect($config);

        $context->count(self::phase(), 'created');
    }

    private function writeVenveo(WpRedirect $redirect, RunContext $context): void
    {
        if ($context->dryRun) {
            $context->count(self::phase(), 'created');

            return;
        }

        $class = 'venveo\\redirect\\elements\\Redirect';

        if (!class_exists($class)) {
            throw new \RuntimeException('The Venveo Redirect plugin is not installed.');
        }

        $element = new $class();
        $element->sourceUrl = ltrim($redirect->source, '/');
        $element->destinationUrl = $redirect->destination;
        $element->statusCode = (string)$redirect->statusCode;
        $element->type = $redirect->isRegex() ? 'dynamic' : 'static';
        $element->siteId = $context->plan->defaultSiteId ?? Craft::$app->getSites()->getPrimarySite()->id;
        $element->enabled = $redirect->enabled;

        $this->save($element, false);

        $context->count(self::phase(), 'created');
    }

    private function writeFriends(WpRedirect $redirect, RunContext $context): void
    {
        if ($context->dryRun) {
            $context->count(self::phase(), 'created');

            return;
        }

        $friends = Craft::$app->getPlugins()->getPlugin('friends');

        if ($friends === null) {
            throw new \RuntimeException('Friends is not installed.');
        }

        // Friends resolves a 404 to a matching element rather than to a URL, so a redirect whose
        // destination Passer could resolve becomes a pin; one that could not has no element to
        // pin to and is reported instead.
        if ($redirect->destinationPostId === null) {
            $this->notice($context, sprintf(
                'The redirect %s → %s could not be pinned, because its destination is not '
                . 'imported content. Friends pins point at elements, not URLs.',
                $redirect->source,
                $redirect->destination
            ));
            $context->count(self::phase(), 'skipped');

            return;
        }

        if (!method_exists($friends, 'getPins')) {
            throw new \RuntimeException('Friends is installed but its pins service could not be reached.');
        }

        $pinClass = 'justinholtweb\\friends\\models\\Pin';

        if (!class_exists($pinClass)) {
            throw new \RuntimeException('Friends is installed but its Pin model could not be loaded.');
        }

        $pin = new $pinClass([
            'uri' => ltrim($redirect->source, '/'),
            'elementId' => $redirect->destinationPostId,
            'siteId' => $context->plan->defaultSiteId ?? Craft::$app->getSites()->getPrimarySite()->id,
            'enabled' => $redirect->enabled,
        ]);

        $friends->getPins()->savePin($pin);

        $context->count(self::phase(), 'created');
    }

    /**
     * Write a `config/redirects.php` returning Craft URL rules.
     *
     * The dependency-free option. It is version-controlled with the rest of the config, it
     * costs nothing at runtime, and it is a file a developer can read and edit.
     *
     * @param WpRedirect[] $redirects
     */
    private function writeConfigFile(array $redirects, RunContext $context): void
    {
        $lines = [
            '<?php',
            '',
            '/**',
            ' * Redirects imported from WordPress by Passer on ' . date('j F Y') . '.',
            ' *',
            ' * Include these from config/routes.php:',
            ' *',
            ' *     return array_merge(require __DIR__ . \'/redirects.php\', [',
            ' *         // your own routes',
            ' *     ]);',
            ' */',
            '',
            'return [',
        ];

        $written = 0;
        $skipped = 0;

        foreach ($redirects as $redirect) {
            if (!$redirect->enabled) {
                continue;
            }

            if ($redirect->isRegex()) {
                // Craft's URL rules take a different pattern syntax to Apache's, and silently
                // producing a rule that does not match is worse than saying so.
                $skipped++;
                continue;
            }

            $source = ltrim($redirect->source, '/');
            $destination = $redirect->destination;
            $permanent = $redirect->statusCode === 301 ? 'true' : 'false';

            $lines[] = sprintf(
                "    %s => ['template' => null, 'redirect' => %s, 'permanent' => %s],",
                var_export($source, true),
                var_export($destination, true),
                $permanent
            );

            $written++;
        }

        $lines[] = '];';
        $lines[] = '';

        $path = Craft::$app->getPath()->getConfigPath() . '/redirects.php';

        if ($context->dryRun) {
            $this->info($context, sprintf('Would write %d redirects to %s.', $written, $path));

            return;
        }

        try {
            FileHelper::writeToFile($path, implode("\n", $lines));
        } catch (\Throwable $e) {
            throw new \RuntimeException("Could not write $path: " . $e->getMessage(), 0, $e);
        }

        $context->count(self::phase(), 'created', $written);

        $this->info($context, sprintf(
            '%d redirect%s written to config/redirects.php. Include it from config/routes.php to '
            . 'switch them on — the file itself does nothing until you do.',
            $written,
            $written === 1 ? '' : 's'
        ));

        if ($skipped > 0) {
            $this->notice($context, sprintf(
                '%d regular-expression redirect%s were not written, because Craft URL rules use a '
                . 'different pattern syntax to Apache. They are listed in this report.',
                $skipped,
                $skipped === 1 ? '' : 's'
            ));

            foreach ($redirects as $redirect) {
                if ($redirect->isRegex()) {
                    $this->notice($context, sprintf('Regex redirect not written: %s → %s', $redirect->source, $redirect->destination));
                }
            }
        }
    }

    // -----------------------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------------------

    /**
     * @param WpRedirect[] $redirects
     */
    private function summarise(array $redirects, string $destination, RunContext $context): void
    {
        $byOrigin = [];

        foreach ($redirects as $redirect) {
            $origin = $redirect->origin ?? 'unknown';
            $byOrigin[$origin] = ($byOrigin[$origin] ?? 0) + 1;
        }

        foreach ($byOrigin as $origin => $count) {
            $this->info($context, sprintf('%d redirect%s came from %s.', $count, $count === 1 ? '' : 's', $origin));
        }
    }

    private function normalizePath(string $url, ?string $siteUrl): string
    {
        $url = trim($url);

        if ($url === '') {
            return '/';
        }

        // Leave off-site destinations absolute; they are meant to point elsewhere.
        if (preg_match('#^https?://#i', $url)) {
            if ($siteUrl === null || !$this->sameHost($url, $siteUrl)) {
                return $url;
            }

            $path = parse_url($url, PHP_URL_PATH);
            $query = parse_url($url, PHP_URL_QUERY);

            $url = (is_string($path) ? $path : '/') . (is_string($query) && $query !== '' ? '?' . $query : '');
        }

        return '/' . ltrim($url, '/');
    }

    private function absolute(string $url, ?string $siteUrl): string
    {
        if (preg_match('#^https?://#i', $url) || $siteUrl === null) {
            return $url;
        }

        return rtrim($siteUrl, '/') . '/' . ltrim($url, '/');
    }

    private function sameHost(string $a, string $b): bool
    {
        $hostA = parse_url($a, PHP_URL_HOST);
        $hostB = parse_url($b, PHP_URL_HOST);

        if (!is_string($hostA) || !is_string($hostB)) {
            return false;
        }

        $strip = static fn(string $h) => str_starts_with(strtolower($h), 'www.') ? substr(strtolower($h), 4) : strtolower($h);

        return $strip($hostA) === $strip($hostB);
    }
}
