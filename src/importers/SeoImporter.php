<?php

namespace justinholtweb\passer\importers;

use Craft;
use craft\elements\Entry;
use justinholtweb\passer\models\SeoData;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\services\IdMap;

/**
 * Yoast, Rank Math, All in One SEO, SEOPress and The SEO Framework metadata becomes Craft SEO.
 *
 * This runs after content, because it writes onto entries the content phase created. Which
 * fields it writes depends entirely on what the destination is: SEOmatic keeps metadata in its
 * own per-element records, `ether/seo` keeps it in a field on the entry, and the fallback writes
 * to three plain fields Passer created. All three take the same normalised SeoData.
 */
class SeoImporter extends BaseImporter
{
    public static function phase(): string
    {
        return 'seo';
    }

    public function estimate(RunContext $context): ?int
    {
        return $context->map->countFor(IdMap::KEY_POST) ?: null;
    }

    public function import(RunContext $context, array $resumeFrom = []): array
    {
        $domain = $context->plan->domain('seo');

        if ($domain === null || !$domain->enabled) {
            return [];
        }

        $reader = Plugin::getInstance()->seo;
        $reader->setActivePlugins($this->detectedSeoPlugins($context));

        if ($reader->activePlugins() === []) {
            $this->notice($context, 'No SEO plugin data was found in the source.');

            return [];
        }

        $completed = $resumeFrom['completed'] ?? [];
        $processed = 0;
        $written = 0;

        $context->progress->startPhase(self::phase(), $this->estimate($context));
        $context->map->warm();

        foreach ($context->plan->enabledPostTypes() as $mapping) {
            if (in_array($mapping->postType, $completed, true)) {
                continue;
            }

            foreach ($context->source->posts($mapping->postType) as $post) {
                $processed++;

                $entryId = $context->map->lookup(IdMap::KEY_POST, $post->id);

                if ($entryId === null) {
                    continue;
                }

                $data = $reader->read($post, $context->source);

                if ($data->isEmpty()) {
                    $context->count(self::phase(), 'skipped');
                    continue;
                }

                $ok = $this->attempt($context, IdMap::KEY_POST, $post->id, $post->title, function () use ($data, $entryId, $domain, $context) {
                    $this->write($data, $entryId, $domain->destination, $context);
                });

                if ($ok) {
                    $written++;
                }

                $context->progress->advance($processed, $post->title);
            }

            $completed[] = $mapping->postType;
        }

        $context->progress->endPhase(self::phase());

        $this->reportMissingSeomaticFields($context);

        $this->notice($context, sprintf(
            'SEO metadata was imported for %s entr%s from %s, into %s.',
            number_format($written),
            $written === 1 ? 'y' : 'ies',
            implode(' and ', $this->pluginNames($context)),
            $this->destinationName($domain->destination)
        ));

        return ['completed' => $completed];
    }

    private function write(SeoData $data, int $entryId, string $destination, RunContext $context): void
    {
        if ($context->dryRun) {
            $context->count(self::phase(), 'created');

            return;
        }

        match ($destination) {
            'seomatic' => $this->writeSeomatic($data, $entryId, $context),
            'ether-seo' => $this->writeEtherSeo($data, $entryId, $context),
            'sprout-seo' => $this->writeSprout($data, $entryId, $context),
            default => $this->writeNativeFields($data, $entryId, $context),
        };

        $context->count(self::phase(), 'created');
    }

    // -----------------------------------------------------------------------------------------
    // SEOmatic
    // -----------------------------------------------------------------------------------------

    private function writeSeomatic(SeoData $data, int $entryId, RunContext $context): void
    {
        $entry = Entry::find()->id($entryId)->status(null)->one();

        if ($entry === null) {
            return;
        }

        $layout = $entry->getFieldLayout();
        $handle = $this->seomaticFieldHandle($layout);

        if ($handle === null) {
            // SEOmatic's own metadata bundles are per section-and-entry-type, not per entry, so
            // writing this post's title into one would apply it to every entry in the section.
            // The per-entry home is the SEO Settings field; without one, the values go to plain
            // Craft fields instead, which loses nothing and is honest about where they are.
            $this->warnedMissingField[$entry->getType()->handle] ??= true;

            $this->writeNativeFields($data, $entryId, $context);

            return;
        }

        // SEOmatic's field value is a nested array of bundle sections. Only the keys being
        // imported are set, so the plugin's own defaults survive for everything else.
        $value = [
            'metaGlobalVars' => array_filter([
                'seoTitle' => $data->title,
                'seoDescription' => $data->description,
                'canonicalUrl' => $data->canonical,
                'seoKeywords' => $data->keywords !== [] ? implode(', ', $data->keywords) : null,
                'robots' => $this->robotsString($data),
                'ogTitle' => $data->ogTitle,
                'ogDescription' => $data->ogDescription,
                'ogType' => $data->ogType,
                'twitterTitle' => $data->twitterTitle,
                'twitterDescription' => $data->twitterDescription,
                'twitterCard' => $data->twitterCard,
            ], static fn($v) => $v !== null && $v !== ''),
        ];

        $ogAssetId = $this->assetIdFor($data->ogImageId, $context);

        if ($ogAssetId !== null) {
            $value['metaBundleSettings']['seoImageIds'] = [$ogAssetId];
            $value['metaBundleSettings']['ogImageIds'] = [$ogAssetId];
        }

        $twitterAssetId = $this->assetIdFor($data->twitterImageId, $context);

        if ($twitterAssetId !== null) {
            $value['metaBundleSettings']['twitterImageIds'] = [$twitterAssetId];
        }

        if ($data->inSitemap !== null) {
            $value['metaSitemapVars']['sitemapUrls'] = $data->inSitemap;
        }

        if ($data->priority !== null) {
            $value['metaSitemapVars']['sitemapPriority'] = $data->priority;
        }

        if ($data->changeFrequency !== null) {
            $value['metaSitemapVars']['sitemapChangeFreq'] = $data->changeFrequency;
        }

        $entry->setFieldValue($handle, $value);

        $this->save($entry, false);
    }

    /**
     * @var array<string, bool> Entry type handles reported as lacking an SEOmatic field.
     */
    private array $warnedMissingField = [];

    /**
     * Tell the user which entry types need an SEOmatic field, once each rather than per entry.
     */
    private function reportMissingSeomaticFields(RunContext $context): void
    {
        foreach (array_keys($this->warnedMissingField) as $handle) {
            $this->warn($context, sprintf(
                'The entry type "%s" has no SEO Settings field, so its metadata went to plain '
                . 'Craft fields instead. SEOmatic stores per-entry overrides in that field; its '
                . 'own metadata bundles are per section, and writing there would have applied '
                . 'one post\'s title to every entry in the section. Add an SEOmatic field to '
                . 'this entry type and re-run the SEO phase to move them across.',
                $handle
            ));
        }

        $this->warnedMissingField = [];
    }

    private function seomaticFieldHandle(?\craft\models\FieldLayout $layout): ?string
    {
        if ($layout === null) {
            return null;
        }

        foreach ($layout->getCustomFields() as $field) {
            if (str_contains($field::class, 'seomatic')) {
                return $field->handle;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------------------------
    // ether/seo and Sprout
    // -----------------------------------------------------------------------------------------

    private function writeEtherSeo(SeoData $data, int $entryId, RunContext $context): void
    {
        $entry = Entry::find()->id($entryId)->status(null)->one();

        if ($entry === null) {
            return;
        }

        $handle = null;

        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (str_contains($field::class, 'ether\\seo')) {
                $handle = $field->handle;
                break;
            }
        }

        if ($handle === null) {
            $this->writeNativeFields($data, $entryId, $context);

            return;
        }

        $entry->setFieldValue($handle, array_filter([
            'title' => $data->title,
            'description' => $data->description,
            'keywords' => $data->keywords,
            'social' => array_filter([
                'facebook' => array_filter([
                    'title' => $data->ogTitle,
                    'description' => $data->ogDescription,
                    'image' => $this->assetIdFor($data->ogImageId, $context),
                ]),
                'twitter' => array_filter([
                    'title' => $data->twitterTitle,
                    'description' => $data->twitterDescription,
                    'image' => $this->assetIdFor($data->twitterImageId, $context),
                ]),
            ]),
            'advanced' => array_filter([
                'robots' => $this->robotsArray($data),
                'canonical' => $data->canonical,
            ]),
        ], static fn($v) => $v !== null && $v !== '' && $v !== []));

        $this->save($entry, false);
    }

    private function writeSprout(SeoData $data, int $entryId, RunContext $context): void
    {
        // Sprout's metadata API has changed shape across its major versions, so rather than
        // guess, the plain-field path is used and the report says so.
        $this->writeNativeFields($data, $entryId, $context);
    }

    // -----------------------------------------------------------------------------------------
    // Plain Craft fields
    // -----------------------------------------------------------------------------------------

    private function writeNativeFields(SeoData $data, int $entryId, RunContext $context): void
    {
        $entry = Entry::find()->id($entryId)->status(null)->one();

        if ($entry === null) {
            return;
        }

        $layout = $entry->getFieldLayout();

        if ($layout === null) {
            return;
        }

        $values = [
            'metaTitle' => $data->title,
            'metaDescription' => $data->description,
            'metaCanonical' => $data->canonical,
            'metaKeywords' => $data->keywords !== [] ? implode(', ', $data->keywords) : null,
            'metaRobots' => $this->robotsString($data),
            'ogTitle' => $data->ogTitle,
            'ogDescription' => $data->ogDescription,
            'twitterTitle' => $data->twitterTitle,
            'twitterDescription' => $data->twitterDescription,
        ];

        $wrote = false;

        foreach ($values as $handle => $value) {
            if ($value === null || $value === '' || $layout->getFieldByHandle($handle) === null) {
                continue;
            }

            $entry->setFieldValue($handle, $value);
            $wrote = true;
        }

        $ogAssetId = $this->assetIdFor($data->ogImageId, $context);

        if ($ogAssetId !== null && $layout->getFieldByHandle('ogImage') !== null) {
            $entry->setFieldValue('ogImage', [$ogAssetId]);
            $wrote = true;
        }

        if ($wrote) {
            $this->save($entry, false);
        }
    }

    // -----------------------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------------------

    private function assetIdFor(?int $wpAttachmentId, RunContext $context): ?int
    {
        return $wpAttachmentId !== null
            ? $context->map->lookup(IdMap::KEY_ATTACHMENT, $wpAttachmentId)
            : null;
    }

    private function robotsString(SeoData $data): ?string
    {
        $directives = $this->robotsArray($data);

        return $directives !== [] ? implode(', ', $directives) : null;
    }

    /**
     * @return string[]
     */
    private function robotsArray(SeoData $data): array
    {
        $directives = [];

        if ($data->noIndex === true) {
            $directives[] = 'noindex';
        }

        if ($data->noFollow === true) {
            $directives[] = 'nofollow';
        }

        foreach ($data->robots as $directive) {
            if ($directive !== '' && !in_array($directive, $directives, true)) {
                $directives[] = $directive;
            }
        }

        return $directives;
    }

    /**
     * @return string[]
     */
    private function detectedSeoPlugins(RunContext $context): array
    {
        $slugs = [];

        foreach (Plugin::getInstance()->pluginDetector->detect($context->source) as $plugin) {
            if ($plugin->category === 'seo') {
                $slugs[] = $plugin->slug;
            }
        }

        return $slugs;
    }

    /**
     * @return string[]
     */
    private function pluginNames(RunContext $context): array
    {
        $names = [];

        foreach (Plugin::getInstance()->pluginDetector->detect($context->source) as $plugin) {
            if ($plugin->category === 'seo') {
                $names[] = $plugin->name;
            }
        }

        return $names !== [] ? $names : ['an SEO plugin'];
    }

    private function destinationName(string $handle): string
    {
        return match ($handle) {
            'seomatic' => 'SEOmatic',
            'ether-seo' => 'the SEO field',
            'sprout-seo' => 'plain Craft fields (Sprout is not written to directly)',
            default => 'plain Craft fields',
        };
    }
}
