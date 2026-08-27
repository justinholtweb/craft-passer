<?php

namespace justinholtweb\passer\importers;

use Craft;
use craft\elements\Category;
use craft\elements\Tag;
use justinholtweb\passer\models\plan\TaxonomyMapping;
use justinholtweb\passer\models\wp\WpTerm;
use justinholtweb\passer\services\IdMap;

/**
 * WordPress terms become Craft categories, tags or entries.
 *
 * Terms run before content because a post's category assignments are relations. Within a
 * taxonomy they are imported parents-first, because a Craft category cannot be nested under a
 * parent that does not exist yet — and WordPress term tables have no ordering guarantee at all.
 */
class TaxonomyImporter extends BaseImporter
{
    public static function phase(): string
    {
        return 'taxonomies';
    }

    public function estimate(RunContext $context): ?int
    {
        $total = 0;

        try {
            $counts = $context->source->taxonomies();
        } catch (\Throwable) {
            return null;
        }

        foreach ($context->plan->enabledTaxonomies() as $mapping) {
            $total += $counts[$mapping->taxonomy] ?? 0;
        }

        return $total;
    }

    public function import(RunContext $context, array $resumeFrom = []): array
    {
        $done = $resumeFrom['completed'] ?? [];
        $processed = 0;

        $context->progress->startPhase(self::phase(), $this->estimate($context));

        foreach ($context->plan->enabledTaxonomies() as $mapping) {
            if (in_array($mapping->taxonomy, $done, true)) {
                continue;
            }

            $processed += $this->importTaxonomy($mapping, $context, $processed);
            $done[] = $mapping->taxonomy;
        }

        $context->progress->endPhase(self::phase());

        return ['completed' => $done];
    }

    private function importTaxonomy(TaxonomyMapping $mapping, RunContext $context, int $processedSoFar): int
    {
        // Terms are buffered so they can be sorted parents-first. Taxonomies are small — even a
        // large site has thousands of terms, not millions — so this is affordable in a way that
        // buffering posts would not be.
        $terms = [];

        foreach ($context->source->terms($mapping->taxonomy) as $term) {
            $terms[$term->id] = $term;
        }

        if ($terms === []) {
            return 0;
        }

        $ordered = $this->parentsFirst($terms);
        $processed = 0;

        foreach ($ordered as $term) {
            $processed++;

            $this->attempt($context, IdMap::KEY_TERM, $term->id, $term->name, function () use ($term, $mapping, $context) {
                match ($mapping->destination) {
                    'categories' => $this->importCategory($term, $mapping, $context),
                    'tags' => $this->importTag($term, $mapping, $context),
                    'entries' => $this->importAsEntry($term, $mapping, $context),
                    default => null,
                };
            });

            $context->progress->advance($processedSoFar + $processed, $term->name);
        }

        return $processed;
    }

    /**
     * Sort terms so every parent precedes its children.
     *
     * A cycle — which a corrupted `wp_term_taxonomy` can contain — would loop forever, so
     * anything still unplaced after a full pass with no progress is emitted as a root term.
     *
     * @param array<int, WpTerm> $terms
     * @return WpTerm[]
     */
    private function parentsFirst(array $terms): array
    {
        $ordered = [];
        $placed = [];
        $remaining = $terms;

        while ($remaining !== []) {
            $progressed = false;

            foreach ($remaining as $id => $term) {
                if ($term->parentId === 0 || isset($placed[$term->parentId]) || !isset($terms[$term->parentId])) {
                    $ordered[] = $term;
                    $placed[$id] = true;
                    unset($remaining[$id]);
                    $progressed = true;
                }
            }

            if (!$progressed) {
                // Everything left is part of a cycle. Emit it flat rather than not at all.
                foreach ($remaining as $term) {
                    $term->parentId = 0;
                    $ordered[] = $term;
                }

                break;
            }
        }

        return $ordered;
    }

    private function importCategory(WpTerm $term, TaxonomyMapping $mapping, RunContext $context): void
    {
        $group = Craft::$app->getCategories()->getGroupByHandle($mapping->handle);

        if ($group === null) {
            throw new \RuntimeException("Category group '{$mapping->handle}' does not exist.");
        }

        $hash = $context->map->hashPayload([$term->name, $term->slug, $term->description, $term->parentId]);

        if ($this->unchanged($context, IdMap::KEY_TERM, $term->id, $hash)) {
            $context->count(self::phase(), 'skipped');

            return;
        }

        $existingId = $context->map->lookup(IdMap::KEY_TERM, $term->id);
        $category = $existingId !== null
            ? Category::find()->id($existingId)->status(null)->one()
            : null;

        $isNew = $category === null;
        $category ??= new Category(['groupId' => $group->id]);

        $category->title = $this->cleanText($term->name) ?: 'Untitled';
        $category->slug = $this->cleanSlug($term->slug, $term->name);

        if ($term->parentId > 0) {
            $parentId = $context->map->lookup(IdMap::KEY_TERM, $term->parentId);

            if ($parentId !== null) {
                $category->setParentId($parentId);
            }
        }

        $this->applyTermFields($category, $term, $mapping, $context);

        if ($context->dryRun) {
            $context->count(self::phase(), $isNew ? 'created' : 'updated');

            return;
        }

        $this->save($category);

        $context->map->record(
            IdMap::KEY_TERM,
            $term->id,
            Category::class,
            $category->id,
            $category->uid,
            null,
            $hash,
            $this->termUrl($term, $context),
            $context->runId
        );

        $context->count(self::phase(), $isNew ? 'created' : 'updated');
    }

    private function importTag(WpTerm $term, TaxonomyMapping $mapping, RunContext $context): void
    {
        $group = Craft::$app->getTags()->getTagGroupByHandle($mapping->handle);

        if ($group === null) {
            throw new \RuntimeException("Tag group '{$mapping->handle}' does not exist.");
        }

        $hash = $context->map->hashPayload([$term->name, $term->slug]);

        if ($this->unchanged($context, IdMap::KEY_TERM, $term->id, $hash)) {
            $context->count(self::phase(), 'skipped');

            return;
        }

        $existingId = $context->map->lookup(IdMap::KEY_TERM, $term->id);
        $tag = $existingId !== null ? Tag::find()->id($existingId)->status(null)->one() : null;

        $isNew = $tag === null;
        $tag ??= new Tag(['groupId' => $group->id]);
        $tag->title = $this->cleanText($term->name) ?: 'Untitled';

        $this->applyTermFields($tag, $term, $mapping, $context);

        if ($context->dryRun) {
            $context->count(self::phase(), $isNew ? 'created' : 'updated');

            return;
        }

        $this->save($tag);

        $context->map->record(
            IdMap::KEY_TERM,
            $term->id,
            Tag::class,
            $tag->id,
            $tag->uid,
            null,
            $hash,
            $this->termUrl($term, $context),
            $context->runId
        );

        $context->count(self::phase(), $isNew ? 'created' : 'updated');
    }

    private function importAsEntry(WpTerm $term, TaxonomyMapping $mapping, RunContext $context): void
    {
        $entryType = $mapping->entryType !== null
            ? Craft::$app->getEntries()->getEntryTypeByHandle($mapping->entryType)
            : null;

        $section = Craft::$app->getEntries()->getSectionByHandle($mapping->handle);

        if ($section === null || $entryType === null) {
            throw new \RuntimeException(
                "Cannot import taxonomy '{$mapping->taxonomy}' as entries: the section or entry "
                . 'type named in the plan does not exist.'
            );
        }

        $hash = $context->map->hashPayload([$term->name, $term->slug, $term->description, $term->parentId]);

        if ($this->unchanged($context, IdMap::KEY_TERM, $term->id, $hash)) {
            $context->count(self::phase(), 'skipped');

            return;
        }

        $existingId = $context->map->lookup(IdMap::KEY_TERM, $term->id);
        $entry = $existingId !== null
            ? \craft\elements\Entry::find()->id($existingId)->status(null)->one()
            : null;

        $isNew = $entry === null;

        if ($entry === null) {
            $entry = new \craft\elements\Entry();
            $entry->sectionId = $section->id;
            $entry->typeId = $entryType->id;
        }

        $entry->title = $this->cleanText($term->name) ?: 'Untitled';
        $entry->slug = $this->cleanSlug($term->slug, $term->name);

        if ($term->parentId > 0 && $section->type === 'structure') {
            $parentId = $context->map->lookup(IdMap::KEY_TERM, $term->parentId);

            if ($parentId !== null) {
                $entry->setParentId($parentId);
            }
        }

        $this->applyTermFields($entry, $term, $mapping, $context);

        if ($context->dryRun) {
            $context->count(self::phase(), $isNew ? 'created' : 'updated');

            return;
        }

        $this->save($entry);

        $context->map->record(
            IdMap::KEY_TERM,
            $term->id,
            \craft\elements\Entry::class,
            $entry->id,
            $entry->uid,
            null,
            $hash,
            $this->termUrl($term, $context),
            $context->runId
        );

        $context->count(self::phase(), $isNew ? 'created' : 'updated');
    }

    private function applyTermFields(
        \craft\base\ElementInterface $element,
        WpTerm $term,
        TaxonomyMapping $mapping,
        RunContext $context,
    ): void {
        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return;
        }

        // WordPress term descriptions are plain text with newlines; anything richer came from a
        // plugin and is already markup.
        $description = $this->cleanText($term->description);

        if ($description !== '' && $layout->getFieldByHandle('description') !== null) {
            $element->setFieldValue('description', $description);
        }

        foreach ($mapping->fieldMap as $metaKey => $handle) {
            if ($layout->getFieldByHandle($handle) === null) {
                continue;
            }

            $value = $term->metaValue($metaKey);

            if ($value !== null) {
                $element->setFieldValue($handle, is_scalar($value) ? $value : $value);
            }
        }
    }

    /**
     * The term's URL on the source site, for the URL rewriter to match against.
     */
    private function termUrl(WpTerm $term, RunContext $context): ?string
    {
        $siteUrl = $context->source->siteUrl();

        if ($siteUrl === null || $term->slug === '') {
            return null;
        }

        // WordPress's real term permalink depends on rewrite rules Passer cannot see, so this is
        // the conventional default rather than a guarantee. A miss simply means the URL is
        // relativised instead of resolved, which is the safe outcome.
        $base = match ($term->taxonomy) {
            'category' => 'category',
            'post_tag' => 'tag',
            default => $term->taxonomy,
        };

        return rtrim($siteUrl, '/') . '/' . $base . '/' . $term->slug . '/';
    }
}
