<?php

namespace justinholtweb\passer\importers;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use justinholtweb\passer\models\plan\PostTypeMapping;
use justinholtweb\passer\models\wp\WpPost;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\services\IdMap;

/**
 * Posts, pages and custom post types become Craft entries.
 *
 * The phase runs in two passes over each post type. The first creates or updates every entry
 * with its content, fields and taxonomy relations. The second sets parents, because a page
 * hierarchy in WordPress has no ordering guarantee and a child is routinely stored before its
 * parent — trying to nest as you go would silently flatten large parts of a site's structure.
 */
class ContentImporter extends BaseImporter
{
    public static function phase(): string
    {
        return 'content';
    }

    public function estimate(RunContext $context): ?int
    {
        try {
            $counts = $context->source->postTypes();
        } catch (\Throwable) {
            return null;
        }

        $total = 0;

        foreach ($context->plan->enabledPostTypes() as $mapping) {
            $total += $counts[$mapping->postType] ?? 0;
        }

        return $total;
    }

    public function import(RunContext $context, array $resumeFrom = []): array
    {
        $completed = $resumeFrom['completed'] ?? [];
        $offsets = $resumeFrom['offsets'] ?? [];
        $processed = (int)($resumeFrom['processed'] ?? 0);

        $context->progress->startPhase(self::phase(), $this->estimate($context));

        foreach ($context->plan->enabledPostTypes() as $mapping) {
            if (in_array($mapping->postType, $completed, true)) {
                continue;
            }

            $offset = (int)($offsets[$mapping->postType] ?? 0);
            [$offset, $processed] = $this->importPostType($mapping, $context, $offset, $processed);

            $offsets[$mapping->postType] = $offset;
            $completed[] = $mapping->postType;

            $this->applyHierarchy($mapping, $context);
        }

        $context->progress->endPhase(self::phase());

        $this->reportContentGaps($context);

        return ['completed' => $completed, 'offsets' => $offsets, 'processed' => $processed];
    }

    /**
     * @return array{0: int, 1: int} The offset reached and the running processed count.
     */
    private function importPostType(PostTypeMapping $mapping, RunContext $context, int $offset, int $processed): array
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($mapping->section);
        $entryType = Craft::$app->getEntries()->getEntryTypeByHandle($mapping->entryType);

        if ($section === null || $entryType === null) {
            $this->warn($context, sprintf(
                'Skipped %s: the section "%s" or entry type "%s" does not exist. Run provisioning '
                . 'first, or point the mapping at something that does.',
                $mapping->postType,
                $mapping->section,
                $mapping->entryType
            ));

            return [$offset, $processed];
        }

        foreach ($context->source->posts($mapping->postType, $offset) as $post) {
            if ($context->limit !== null && $processed >= $context->limit) {
                break;
            }

            $offset++;
            $processed++;

            if (!$this->shouldImport($post, $mapping)) {
                $context->count(self::phase(), 'skipped');
                $context->progress->advance($processed);
                continue;
            }

            $this->attempt($context, IdMap::KEY_POST, $post->id, $post->title, function () use ($post, $mapping, $context, $section, $entryType) {
                $this->importPost($post, $mapping, $context, $section, $entryType);
            });

            $context->progress->advance($processed, $post->title);
        }

        return [$offset, $processed];
    }

    private function shouldImport(WpPost $post, PostTypeMapping $mapping): bool
    {
        if ($post->status === 'auto-draft') {
            return false;
        }

        if ($post->status === 'trash' && !$mapping->includeTrashed) {
            return false;
        }

        // `inherit` is WordPress's status for revisions and attachments, both of which are
        // handled elsewhere or not at all.
        return !($post->status === 'inherit' && $post->type !== $mapping->postType);
    }

    private function importPost(
        WpPost $post,
        PostTypeMapping $mapping,
        RunContext $context,
        \craft\models\Section $section,
        \craft\models\EntryType $entryType,
    ): void {
        $hash = $context->map->hashPayload([
            $post->title, $post->content, $post->excerpt, $post->status, $post->name,
            $post->modified, $post->meta, array_keys($post->terms),
        ]);

        foreach ($this->siteIdsFor($mapping->siteIds, $context) as $siteId) {
            if ($this->unchanged($context, IdMap::KEY_POST, $post->id, $hash, $siteId)) {
                $context->count(self::phase(), 'skipped');
                continue;
            }

            $existingId = $context->map->lookup(IdMap::KEY_POST, $post->id, $siteId);
            $entry = $existingId !== null
                ? Entry::find()->id($existingId)->siteId($siteId)->status(null)->one()
                : null;

            $isNew = $entry === null;

            if ($entry === null) {
                $entry = new Entry();
                $entry->sectionId = $section->id;
                $entry->typeId = $entryType->id;
                $entry->siteId = $siteId;
            }

            $this->applyCore($entry, $post, $mapping, $context);
            $this->applyContent($entry, $post, $mapping, $context);
            $this->applyTaxonomies($entry, $post, $mapping, $context);
            $this->applyFields($entry, $post, $mapping, $context);

            if ($context->dryRun) {
                $context->count(self::phase(), $isNew ? 'created' : 'updated');
                continue;
            }

            $this->save($entry);

            $context->map->record(
                IdMap::KEY_POST,
                $post->id,
                Entry::class,
                $entry->id,
                $entry->uid,
                $siteId,
                $hash,
                $this->postUrl($post, $context),
                $context->runId
            );

            $context->count(self::phase(), $isNew ? 'created' : 'updated');
        }
    }

    // -----------------------------------------------------------------------------------------
    // Field application
    // -----------------------------------------------------------------------------------------

    private function applyCore(Entry $entry, WpPost $post, PostTypeMapping $mapping, RunContext $context): void
    {
        $entry->title = $this->cleanText($post->title) ?: 'Untitled';
        $entry->slug = $this->cleanSlug($post->name, $post->title);

        $postDate = $this->toDateTime($post->date, $post->dateGmt);

        if ($postDate !== null) {
            $entry->postDate = $postDate;
        }

        $modified = $this->toDateTime($post->modified, $post->modifiedGmt);

        if ($modified !== null) {
            $entry->dateUpdated = $modified;
        }

        // A scheduled WordPress post keeps its future post date, and Craft's `pending` status
        // follows from the date rather than being set directly — so `live` here is correct even
        // for something that will not appear until next month.
        $status = $mapping->statusMap[$post->status] ?? 'disabled';
        $entry->enabled = $status !== 'disabled';
        $entry->setEnabledForSite($entry->enabled);

        if ($status === 'expired') {
            $entry->expiryDate = new \DateTime('-1 minute');
        }

        $entry->authorIds = [$this->authorIdFor($post, $mapping, $context)];

        if ($mapping->preserveUris) {
            $uri = $this->uriFor($post, $context);

            if ($uri !== null) {
                // Craft recomputes the URI from the section's format on save unless the entry's
                // own slug produces it, so the preserved path is stored and applied by the
                // Provisioner's URI format where possible; where it is not, the report says so.
                $entry->slug = $this->cleanSlug(basename($uri), $entry->title);
            }
        }
    }

    private function applyContent(Entry $entry, WpPost $post, PostTypeMapping $mapping, RunContext $context): void
    {
        $layout = $entry->getFieldLayout();

        if ($layout === null) {
            return;
        }

        $transformer = Plugin::getInstance()->contentTransformer;

        if ($mapping->contentField !== null && $layout->getFieldByHandle($mapping->contentField) !== null) {
            $html = $transformer->toHtml($this->cleanText($post->content), $context);
            $entry->setFieldValue($mapping->contentField, $html);
        }

        if ($mapping->excerptField !== null && $layout->getFieldByHandle($mapping->excerptField) !== null) {
            $excerpt = $this->cleanText($post->excerpt);

            // WordPress leaves the excerpt empty and generates one at render time. Recreating
            // that here means the field is populated rather than blank across the whole site.
            if ($excerpt === '') {
                $body = $transformer->toPlainText($transformer->toHtml($this->cleanText($post->content), $context));
                $excerpt = mb_substr($body, 0, 300);

                if (mb_strlen($body) > 300) {
                    $excerpt = mb_substr($excerpt, 0, (int)mb_strrpos($excerpt, ' ')) . '…';
                }
            }

            $entry->setFieldValue($mapping->excerptField, $excerpt);
        }

        if (
            $mapping->featuredImageField !== null
            && $post->featuredImageId !== null
            && $layout->getFieldByHandle($mapping->featuredImageField) !== null
        ) {
            $assetId = $context->map->lookup(IdMap::KEY_ATTACHMENT, $post->featuredImageId);

            if ($assetId !== null) {
                $entry->setFieldValue($mapping->featuredImageField, [$assetId]);
            }
        }
    }

    private function applyTaxonomies(Entry $entry, WpPost $post, PostTypeMapping $mapping, RunContext $context): void
    {
        $layout = $entry->getFieldLayout();

        if ($layout === null) {
            return;
        }

        foreach ($mapping->taxonomyFields as $taxonomy => $handle) {
            if ($layout->getFieldByHandle($handle) === null) {
                continue;
            }

            $ids = [];

            foreach ($post->terms[$taxonomy] ?? [] as $term) {
                // REST and WXR give a term ID and a slug respectively; the map holds IDs, so a
                // slug-only term is resolved by looking it up in Craft directly.
                $destId = $term->id > 0
                    ? $context->map->lookup(IdMap::KEY_TERM, $term->id)
                    : $this->resolveTermBySlug($term->slug, $taxonomy, $context);

                if ($destId !== null) {
                    $ids[] = $destId;
                }
            }

            if ($ids !== []) {
                $entry->setFieldValue($handle, array_values(array_unique($ids)));
            }
        }
    }

    private function resolveTermBySlug(string $slug, string $taxonomy, RunContext $context): ?int
    {
        if ($slug === '') {
            return null;
        }

        $mapping = $context->plan->taxonomies[$taxonomy] ?? null;

        if ($mapping === null) {
            return null;
        }

        return match ($mapping->destination) {
            'tags' => Tag::find()->group($mapping->handle)->title($slug)->ids()[0]
                ?? Tag::find()->group($mapping->handle)->slug($slug)->ids()[0] ?? null,
            'categories' => Category::find()->group($mapping->handle)->slug($slug)->ids()[0] ?? null,
            'entries' => Entry::find()->section($mapping->handle)->slug($slug)->ids()[0] ?? null,
            default => null,
        };
    }

    private function applyFields(Entry $entry, WpPost $post, PostTypeMapping $mapping, RunContext $context): void
    {
        $layout = $entry->getFieldLayout();

        if ($layout === null || $mapping->fieldMap === []) {
            return;
        }

        $acfReader = Plugin::getInstance()->acfReader;
        $acfConverter = Plugin::getInstance()->acfConverter;
        $acfValues = $acfReader->valuesFor($post, $context->source);

        foreach ($mapping->fieldMap as $metaKey => $handle) {
            if ($layout->getFieldByHandle($handle) === null) {
                continue;
            }

            if (isset($acfValues[$metaKey])) {
                $converted = $acfConverter->convert(
                    $acfValues[$metaKey]['value'],
                    $acfValues[$metaKey]['definition'],
                    $context
                );

                if ($converted !== null) {
                    $entry->setFieldValue($handle, $converted);
                }

                continue;
            }

            $value = $post->metaValue($metaKey);

            if ($value === null || $value === '') {
                continue;
            }

            // Plain meta is untyped. A scalar goes straight in; anything structured is JSON so
            // it is preserved rather than stringified into `Array`.
            $entry->setFieldValue($handle, is_scalar($value) ? (string)$value : \craft\helpers\Json::encode($value));
        }
    }

    // -----------------------------------------------------------------------------------------
    // Hierarchy
    // -----------------------------------------------------------------------------------------

    /**
     * Second pass: nest structure entries under their parents.
     */
    private function applyHierarchy(PostTypeMapping $mapping, RunContext $context): void
    {
        if ($mapping->sectionType !== 'structure' || $context->dryRun) {
            return;
        }

        $context->progress->message('Rebuilding the page hierarchy');

        $parents = [];

        foreach ($context->source->posts($mapping->postType) as $post) {
            if ($post->parentId > 0) {
                $parents[$post->id] = $post->parentId;
            }
        }

        if ($parents === []) {
            return;
        }

        foreach ($parents as $childWpId => $parentWpId) {
            $childId = $context->map->lookup(IdMap::KEY_POST, $childWpId);
            $parentId = $context->map->lookup(IdMap::KEY_POST, $parentWpId);

            if ($childId === null || $parentId === null || $childId === $parentId) {
                continue;
            }

            $child = Entry::find()->id($childId)->status(null)->one();
            $parent = Entry::find()->id($parentId)->status(null)->one();

            if ($child === null || $parent === null) {
                continue;
            }

            // Already in the right place; moving it again would rewrite the whole structure
            // lft/rgt range for nothing.
            if ($child->getParentId() === $parentId) {
                continue;
            }

            try {
                Craft::$app->getStructures()->append($parent->structureId, $child, $parent);
            } catch (\Throwable $e) {
                $this->warn($context, sprintf(
                    'Could not nest "%s" under "%s": %s',
                    $child->title,
                    $parent->title,
                    $e->getMessage()
                ), ['sourceKey' => IdMap::KEY_POST, 'sourceId' => (string)$childWpId]);
            }
        }
    }

    // -----------------------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------------------

    private function authorIdFor(WpPost $post, PostTypeMapping $mapping, RunContext $context): int
    {
        if ($post->authorId > 0) {
            $id = $context->map->lookup(IdMap::KEY_USER, $post->authorId);

            if ($id !== null) {
                return $id;
            }
        }

        // WXR names the author by login instead of ID.
        $login = $post->metaValue('_passer_author_login');

        if (is_string($login) && $login !== '') {
            $id = $context->map->lookup(IdMap::KEY_USER_LOGIN, $login);

            if ($id !== null) {
                return $id;
            }
        }

        $fallback = $mapping->fallbackAuthorId ?? $context->plan->fallbackAuthorId;

        if ($fallback !== null) {
            return $fallback;
        }

        // Craft requires an author. The first admin is the least surprising owner for content
        // whose real author cannot be identified.
        $admin = User::find()->admin()->status(null)->orderBy(['id' => SORT_ASC])->one();

        if ($admin === null) {
            throw new \RuntimeException('No author could be resolved and this Craft install has no admin user.');
        }

        return $admin->id;
    }

    private function postUrl(WpPost $post, RunContext $context): ?string
    {
        if ($post->link !== null && $post->link !== '') {
            return $post->link;
        }

        $siteUrl = $context->source->siteUrl();

        if ($siteUrl === null) {
            return null;
        }

        // `?p=N` always works on a WordPress site regardless of its permalink structure, so it
        // is recorded alongside whatever pretty URL was available.
        return rtrim($siteUrl, '/') . '/?p=' . $post->id;
    }

    private function uriFor(WpPost $post, RunContext $context): ?string
    {
        if ($post->link === null || $post->link === '') {
            return null;
        }

        $path = parse_url($post->link, PHP_URL_PATH);

        return is_string($path) && $path !== '/' ? trim($path, '/') : null;
    }

    /**
     * Tell the user what the content pipeline could not fully handle.
     */
    private function reportContentGaps(RunContext $context): void
    {
        $transformer = Plugin::getInstance()->contentTransformer;

        foreach ($transformer->unhandledBlocks() as $name => $count) {
            $this->notice($context, sprintf(
                'The Gutenberg block "%s" appeared %d time%s and has no transformer, so its '
                . 'rendered markup was kept but its structure was not.',
                $name,
                $count,
                $count === 1 ? '' : 's'
            ));
        }

        foreach ($transformer->shortcodeExpander()->unhandledShortcodes() as $name => $count) {
            $this->notice($context, sprintf(
                'The shortcode [%s] appeared %d time%s and could not be expanded.',
                $name,
                $count,
                $count === 1 ? '' : 's'
            ));
        }

        foreach ($transformer->urlRewriter()->unresolvedUrls() as $url => $count) {
            $this->notice($context, sprintf(
                '%s was linked %d time%s but never imported, so the link was made relative.',
                $url,
                $count,
                $count === 1 ? '' : 's'
            ));
        }

        foreach (Plugin::getInstance()->acfConverter->unhandledTypes() as $type => $count) {
            $this->notice($context, sprintf(
                'The ACF field type "%s" appeared %d time%s and was imported as raw text.',
                $type,
                $count,
                $count === 1 ? '' : 's'
            ));
        }
    }
}
