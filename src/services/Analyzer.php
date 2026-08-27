<?php

namespace justinholtweb\passer\services;

use craft\base\Component;
use justinholtweb\passer\models\Inventory;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\sources\SourceInterface;
use justinholtweb\passer\sources\UnsupportedOperationException;

/**
 * Measures a WordPress site before anything is imported from it.
 *
 * The whole wizard rests on this: a user cannot make a sensible mapping decision about a post
 * type they had forgotten existed, and cannot judge whether a migration worked without knowing
 * what should have come across.
 */
class Analyzer extends Component
{
    public function analyze(SourceInterface $source): Inventory
    {
        $source->connect();

        $capabilities = $source->capabilities();

        $inventory = new Inventory();
        $inventory->sourceType = $source::type();
        $inventory->siteUrl = $source->siteUrl();

        $this->readSiteIdentity($source, $inventory, $capabilities->options);

        $inventory->postTypes = $this->safely(static fn() => $source->postTypes(), []);
        $inventory->taxonomies = $this->safely(static fn() => $source->taxonomies(), []);
        $inventory->attachments = $inventory->postTypes['attachment'] ?? 0;

        $this->countUsers($source, $inventory);
        $this->countComments($source, $inventory);

        if (method_exists($source, 'metaKeyHistogram')) {
            $inventory->metaKeys = $this->safely(static fn() => $source->metaKeyHistogram(null, 500), []);
        }

        $inventory->plugins = Plugin::getInstance()->pluginDetector->detect($source);

        $this->countDomains($source, $inventory);
        $this->recordLimitations($source, $inventory);
        $this->addWarnings($inventory);

        return $inventory;
    }

    private function readSiteIdentity(SourceInterface $source, Inventory $inventory, bool $canReadOptions): void
    {
        if (!$canReadOptions) {
            return;
        }

        $name = $this->safely(static fn() => $source->option('blogname'), null);
        $inventory->siteName = is_string($name) ? $name : null;

        $version = $this->safely(static fn() => $source->option('db_version'), null);
        $inventory->wpVersion = $version !== null ? (string)$version : null;
    }

    private function countUsers(SourceInterface $source, Inventory $inventory): void
    {
        // The database source can count directly; everything else pays for it by iterating,
        // which is acceptable because user counts are small even on large sites.
        $count = 0;

        try {
            foreach ($source->users() as $ignored) {
                $count++;

                // A runaway count would make the wizard hang on a site with a spam-registration
                // problem, which is common. Stop and mark it approximate.
                if ($count >= 50_000) {
                    $inventory->warnings[] = 'More than 50,000 users found; the count shown is a floor, not a total.';
                    break;
                }
            }
        } catch (\Throwable) {
            return;
        }

        $inventory->users = $count;
    }

    private function countComments(SourceInterface $source, Inventory $inventory): void
    {
        if (!$source->capabilities()->comments) {
            return;
        }

        if (method_exists($source, 'hasTable') && $source->hasTable('comments')) {
            // Iterating a million comments to count them would be absurd; ask the source's own
            // table reader for a count where one exists.
            try {
                $count = 0;
                foreach ($source->table('comments', [], 0, 200_000) as $ignored) {
                    $count++;
                }
                $inventory->comments = $count;

                return;
            } catch (UnsupportedOperationException | \Throwable) {
                // Fall through to counting by iteration below.
            }
        }

        try {
            $count = 0;
            foreach ($source->comments() as $ignored) {
                if (++$count >= 200_000) {
                    break;
                }
            }
            $inventory->comments = $count;
        } catch (\Throwable) {
            // Leave at zero; `notScanned` already explains why.
        }
    }

    /**
     * Count the optional domains — the ones wp-import does not touch, and the reason a user is
     * looking at Passer rather than at it.
     */
    private function countDomains(SourceInterface $source, Inventory $inventory): void
    {
        $capabilities = $source->capabilities();

        if ($capabilities->menus) {
            $menus = $this->safely(static fn() => $source->menus(), []);
            $inventory->menus = count($menus);
        }

        if ($capabilities->widgets) {
            $widgets = $this->safely(static fn() => $source->widgets(), []);
            $inventory->widgets = count($widgets);
        }

        if ($inventory->hasPlugin('woocommerce')) {
            $inventory->products = $inventory->postTypes['product'] ?? 0;
            $inventory->coupons = $inventory->postTypes['shop_coupon'] ?? 0;
            $inventory->orders = $this->countOrders($source, $inventory);
        }

        if ($capabilities->forms) {
            $inventory->forms = $this->countForms($source, $inventory);
        }

        if ($capabilities->redirects) {
            $inventory->redirects = $this->countRedirects($source, $inventory);
        }

        $inventory->languages = $this->detectLanguages($source, $inventory);
    }

    /**
     * WooCommerce keeps orders either as `shop_order` posts or in the HPOS `wc_orders` table,
     * and a site mid-migration between the two has both.
     */
    private function countOrders(SourceInterface $source, Inventory $inventory): int
    {
        $legacy = $inventory->postTypes['shop_order'] ?? 0;

        if ($legacy > 0) {
            return $legacy;
        }

        try {
            if ($source->hasTable('wc_orders')) {
                $count = 0;
                foreach ($source->table('wc_orders', [], 0, 500_000) as $ignored) {
                    $count++;
                }

                return $count;
            }
        } catch (\Throwable) {
            // Not countable from this source.
        }

        return 0;
    }

    private function countForms(SourceInterface $source, Inventory $inventory): int
    {
        $total = 0;

        // Post-type-backed form plugins are already counted in the post type census.
        foreach (['wpcf7_contact_form' => 'contact-form-7', 'wpforms' => 'wpforms-lite'] as $postType => $slug) {
            if ($inventory->hasPlugin($slug)) {
                $total += $inventory->postTypes[$postType] ?? 0;
            }
        }

        foreach (['gf_form', 'nf3_forms', 'frm_forms'] as $table) {
            try {
                if (!$source->hasTable($table)) {
                    continue;
                }

                foreach ($source->table($table, [], 0, 10_000) as $ignored) {
                    $total++;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $total;
    }

    private function countRedirects(SourceInterface $source, Inventory $inventory): int
    {
        $total = 0;

        foreach (['redirection_items', 'eps_redirects', 'aioseo_redirects', 'rank_math_redirections'] as $table) {
            try {
                if (!$source->hasTable($table)) {
                    continue;
                }

                foreach ($source->table($table, [], 0, 100_000) as $ignored) {
                    $total++;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $total += $inventory->postTypes['redirect_rule'] ?? 0;

        try {
            $simple = $source->option('301_redirects');
            if (is_array($simple)) {
                $total += count($simple);
            }
        } catch (\Throwable) {
            // Not readable from this source.
        }

        return $total;
    }

    /**
     * @return string[]
     */
    private function detectLanguages(SourceInterface $source, Inventory $inventory): array
    {
        if ($inventory->hasPlugin('sitepress-multilingual-cms')) {
            try {
                $codes = [];
                foreach ($source->table('icl_languages', ['active' => 1]) as $row) {
                    $code = (string)($row['code'] ?? '');
                    if ($code !== '') {
                        $codes[] = $code;
                    }
                }

                return $codes;
            } catch (\Throwable) {
                return [];
            }
        }

        if ($inventory->hasPlugin('polylang')) {
            // Polylang's languages are terms in its own taxonomy.
            try {
                $codes = [];
                foreach ($source->terms('language') as $term) {
                    $codes[] = $term->slug;
                }

                return $codes;
            } catch (\Throwable) {
                return [];
            }
        }

        return [];
    }

    private function recordLimitations(SourceInterface $source, Inventory $inventory): void
    {
        $capabilities = $source->capabilities();

        $labels = [
            'options' => 'WordPress options',
            'widgets' => 'widgets',
            'commerce' => 'WooCommerce data',
            'forms' => 'form definitions and entries',
            'redirects' => 'redirects',
            'menus' => 'navigation menus',
            'comments' => 'comments',
        ];

        foreach ($labels as $property => $label) {
            if (!$capabilities->$property) {
                $inventory->notScanned[] = $label;
            }
        }

        foreach ($capabilities->limitations as $limitation) {
            $inventory->warnings[] = $limitation;
        }
    }

    /**
     * Things that will not migrate cleanly and that the user should know about before, not after.
     */
    private function addWarnings(Inventory $inventory): void
    {
        foreach ($inventory->plugins as $plugin) {
            if (!$plugin->supported && $plugin->category !== 'other') {
                $inventory->warnings[] = sprintf(
                    '%s was found but has no importer. %s',
                    $plugin->name,
                    $plugin->handling ?? 'Its data will not be migrated.'
                );
            }
        }

        foreach (['elementor', 'wpbakery', 'divi'] as $builder) {
            $plugin = $inventory->plugin($builder);

            if ($plugin !== null) {
                $inventory->warnings[] = sprintf(
                    '%s builds pages in a format only it can render. %s',
                    $plugin->name,
                    $plugin->handling ?? ''
                );
            }
        }

        if ($inventory->languages !== []) {
            $inventory->warnings[] = sprintf(
                'This site is multilingual (%s). Map each language to a Craft site on the next step, '
                . 'or the translations will all import into one site.',
                implode(', ', $inventory->languages)
            );
        }

        $revisions = $inventory->postTypes['revision'] ?? 0;

        if ($revisions > 10_000) {
            $inventory->warnings[] = sprintf(
                '%s post revisions found. Passer does not import revisions, so this does not affect '
                . 'the migration — but it does mean the database is much larger than the content.',
                number_format($revisions)
            );
        }
    }

    /**
     * Run a probe, returning a fallback rather than failing the whole analysis.
     *
     * Analysis is best-effort by design: a source that cannot answer one question should still
     * produce a useful inventory from the questions it can answer.
     *
     * @template T
     * @param callable(): T $callback
     * @param T $fallback
     * @return T
     */
    private function safely(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
