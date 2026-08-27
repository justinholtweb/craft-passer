<?php

namespace justinholtweb\passer\services;

use Craft;
use craft\base\Component;
use justinholtweb\passer\models\Destination;

/**
 * What this Craft install can actually receive.
 *
 * Passer hard-requires none of the plugins it can write to. That constraint shapes everything:
 * every domain has a fallback that needs nothing but Craft itself, so a migration never stalls
 * because the user has not bought a particular plugin — it just lands somewhere plainer, and
 * says so.
 */
class DestinationRegistry extends Component
{
    /**
     * Craft plugin handles this registry knows how to write to, mapped to a human name.
     */
    private const KNOWN_PLUGINS = [
        'commerce' => 'Craft Commerce',
        'seomatic' => 'SEOmatic',
        'seo' => 'SEO (ether)',
        'sprout-seo' => 'Sprout SEO',
        'retour' => 'Retour',
        'vredirect' => 'Redirect (Venveo)',
        'free-nav' => 'FreeNav',
        'navigation' => 'Navigation (Verbb)',
        'formie' => 'Formie',
        'bandage' => 'Bandage',
        'comments' => 'Comments (Verbb)',
        'ckeditor' => 'CKEditor',
        'friends' => 'Friends',
        'wp-import' => 'WordPress Import',
    ];

    private ?array $cache = null;

    public function isInstalled(string $handle): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled($handle);
    }

    /**
     * Every destination Passer knows about, available or not.
     *
     * @return array<string, Destination[]> Keyed by domain.
     */
    public function all(): array
    {
        return $this->cache ??= $this->build();
    }

    /**
     * @return Destination[]
     */
    public function forDomain(string $domain): array
    {
        $destinations = $this->all()[$domain] ?? [];

        usort($destinations, static function (Destination $a, Destination $b) {
            if ($a->available !== $b->available) {
                return $a->available ? -1 : 1;
            }

            return $b->priority <=> $a->priority;
        });

        return $destinations;
    }

    /**
     * The destination the wizard should preselect: the highest-priority available one.
     */
    public function preferredFor(string $domain): ?Destination
    {
        foreach ($this->forDomain($domain) as $destination) {
            if ($destination->available) {
                return $destination;
            }
        }

        return null;
    }

    public function get(string $domain, string $handle): ?Destination
    {
        foreach ($this->all()[$domain] ?? [] as $destination) {
            if ($destination->handle === $handle) {
                return $destination;
            }
        }

        return null;
    }

    /**
     * @return array<string, Destination[]>
     */
    private function build(): array
    {
        $domains = [
            'seo' => [
                ['seomatic', 'SEOmatic', 'seomatic', 100, 'Writes to SEOmatic\'s per-entry metadata, so imported titles, descriptions and social images appear exactly where SEOmatic expects them.'],
                ['ether-seo', 'SEO (ether)', 'seo', 80, 'Writes to an ether/seo field on each entry type.'],
                ['sprout-seo', 'Sprout SEO', 'sprout-seo', 60, 'Writes to Sprout\'s metadata records.'],
                ['native-fields', 'Plain Craft fields', null, 10, 'Creates metaTitle, metaDescription and ogImage fields and writes to those. No plugin required, and the values are yours to render however you like.'],
            ],
            'redirects' => [
                ['retour', 'Retour', 'retour', 100, 'Imports into Retour\'s static and regular-expression redirect tables, with their status codes and hit counts.'],
                ['venveo-redirect', 'Redirect (Venveo)', 'vredirect', 80, 'Imports into Venveo\'s redirect elements.'],
                ['friends', 'Friends', 'friends', 60, 'Imports each redirect as a Friends pin, so a 404 on the old URL resolves to the imported entry rather than to a guess.'],
                ['config-file', 'A Craft URL rules file', null, 10, 'Writes config/redirects.php, ready to include from config/routes.php. Nothing to install, and it is version-controlled like the rest of your config.'],
            ],
            'menus' => [
                ['freenav', 'FreeNav', 'free-nav', 100, 'Creates a FreeNav menu per WordPress menu, with nodes related to the imported entries and categories rather than to frozen URLs.'],
                ['verbb-navigation', 'Navigation (Verbb)', 'navigation', 80, 'Creates a Verbb navigation per WordPress menu.'],
                ['structure-section', 'A structure section', null, 10, 'Creates a structure section of link entries, one per menu item, nested the way the menu was. No plugin required.'],
            ],
            'forms' => [
                ['formie', 'Formie', 'formie', 100, 'Rebuilds each form as a Formie form, field by field, with its notifications. Stored entries become Formie submissions.'],
                ['bandage', 'Bandage', 'bandage', 60, 'Imports submissions into Bandage. Form definitions are reported rather than rebuilt, because Bandage extends Craft\'s Contact Form rather than building forms.'],
                ['report-only', 'A written specification', null, 10, 'Imports nothing, and produces a full written description of every form — fields, types, validation, notifications — so it can be rebuilt deliberately.'],
            ],
            'comments' => [
                ['verbb-comments', 'Comments (Verbb)', 'comments', 100, 'Imports comments, threading and approval state onto the imported entries.'],
                ['skip', 'Skip comments', null, 10, 'Comments are counted and reported, but not imported.'],
            ],
            'commerce' => [
                ['craft-commerce', 'Craft Commerce', 'commerce', 100, 'Products, variants, categories, orders, customers and coupons become Commerce records.'],
                ['entries-only', 'Products as entries', null, 10, 'Products become plain entries with their price and SKU as fields. Orders and customers are not imported.'],
            ],
            'widgets' => [
                ['global-sets', 'Global sets', null, 100, 'Each sidebar becomes a global set, each widget a block in a Matrix field on it.'],
                ['entries', 'A widgets section', null, 80, 'Each widget becomes an entry in a structure section, grouped by sidebar.'],
                ['report-only', 'A written specification', null, 10, 'Widgets are described in the run report and not imported.'],
            ],
        ];

        $out = [];

        foreach ($domains as $domain => $rows) {
            $out[$domain] = [];

            foreach ($rows as [$handle, $name, $plugin, $priority, $description]) {
                $destination = new Destination([
                    'handle' => $handle,
                    'name' => $name,
                    'domain' => $domain,
                    'requiresPlugin' => $plugin,
                    'priority' => $priority,
                    'description' => $description,
                    'isFallback' => $plugin === null,
                ]);

                if ($plugin === null) {
                    $destination->available = true;
                } else {
                    $destination->available = $this->isInstalled($plugin);

                    if (!$destination->available) {
                        $destination->unavailableReason = sprintf(
                            '%s is not installed.',
                            self::KNOWN_PLUGINS[$plugin] ?? $plugin
                        );
                    }
                }

                $out[$domain][] = $destination;
            }
        }

        return $out;
    }

    /**
     * Every installed plugin Passer can write to, for the wizard's "what we found" panel.
     *
     * @return array<string, string> Handle => name.
     */
    public function installedTargets(): array
    {
        $out = [];

        foreach (self::KNOWN_PLUGINS as $handle => $name) {
            if ($this->isInstalled($handle)) {
                $out[$handle] = $name;
            }
        }

        return $out;
    }
}
