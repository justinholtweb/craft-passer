<?php

namespace justinholtweb\passer\importers;

use Craft;
use craft\elements\Entry;
use justinholtweb\passer\models\wp\WpComment;
use justinholtweb\passer\services\IdMap;

/**
 * WordPress comments become Verbb Comments comments, when that plugin is installed.
 *
 * Threading is rebuilt in a second pass for the same reason page hierarchy is: `wp_comments` has
 * no ordering guarantee and a reply is frequently stored before the comment it replies to.
 *
 * Spam is not imported. A site with ten years of comments typically has more spam than comments,
 * WordPress already made the judgement, and re-importing it would hand the new site a moderation
 * queue nobody asked for. The count is reported instead.
 */
class CommentImporter extends BaseImporter
{
    public static function phase(): string
    {
        return 'comments';
    }

    public function import(RunContext $context, array $resumeFrom = []): array
    {
        $domain = $context->plan->domain('comments');

        if ($domain === null || $domain->destination === 'skip') {
            $this->reportOnly($context);

            return [];
        }

        if (!Craft::$app->getPlugins()->isPluginEnabled('comments')) {
            $this->warn($context, 'Verbb Comments is not installed, so comments were counted but not imported.');
            $this->reportOnly($context);

            return [];
        }

        $offset = (int)($resumeFrom['offset'] ?? 0);
        $processed = 0;
        $spam = 0;
        $pingbacks = 0;
        $orphaned = 0;

        $context->progress->startPhase(self::phase());

        foreach ($context->source->comments($offset) as $comment) {
            if ($context->limit !== null && $processed >= $context->limit) {
                break;
            }

            $offset++;
            $processed++;

            if ($comment->isSpam() || $comment->approved === 'trash') {
                $spam++;
                $context->count(self::phase(), 'skipped');
                continue;
            }

            if ($comment->isPingback()) {
                $pingbacks++;
                $context->count(self::phase(), 'skipped');
                continue;
            }

            $ownerId = $context->map->lookup(IdMap::KEY_POST, $comment->postId);

            if ($ownerId === null) {
                $orphaned++;
                $context->count(self::phase(), 'skipped');
                continue;
            }

            $this->attempt($context, IdMap::KEY_COMMENT, $comment->id, $comment->authorName, function () use ($comment, $ownerId, $context) {
                $this->importComment($comment, $ownerId, $context);
            });

            $context->progress->advance($processed);
        }

        $this->applyThreading($context);

        $context->progress->endPhase(self::phase());

        if ($spam > 0) {
            $this->notice($context, sprintf('%s spam or trashed comment%s were not imported.', number_format($spam), $spam === 1 ? '' : 's'));
        }

        if ($pingbacks > 0) {
            $this->notice($context, sprintf('%s pingback%s and trackback%s were not imported.', number_format($pingbacks), $pingbacks === 1 ? '' : 's', $pingbacks === 1 ? '' : 's'));
        }

        if ($orphaned > 0) {
            $this->notice($context, sprintf(
                '%s comment%s belonged to posts that were not imported, so they were skipped.',
                number_format($orphaned),
                $orphaned === 1 ? '' : 's'
            ));
        }

        return ['offset' => $offset];
    }

    private function importComment(WpComment $comment, int $ownerId, RunContext $context): void
    {
        $class = 'verbb\\comments\\elements\\Comment';

        if (!class_exists($class)) {
            throw new \RuntimeException('Verbb Comments is enabled but its Comment element class could not be loaded.');
        }

        $hash = $context->map->hashPayload([$comment->content, $comment->approved, $comment->authorName]);

        if ($this->unchanged($context, IdMap::KEY_COMMENT, $comment->id, $hash)) {
            $context->count(self::phase(), 'skipped');

            return;
        }

        $existingId = $context->map->lookup(IdMap::KEY_COMMENT, $comment->id);
        $element = $existingId !== null ? $class::find()->id($existingId)->status(null)->one() : null;
        $isNew = $element === null;

        /** @var \craft\base\ElementInterface $element */
        $element ??= new $class();

        $element->ownerId = $ownerId;
        $element->ownerSiteId = Craft::$app->getSites()->getPrimarySite()->id;
        $element->comment = $this->cleanText($comment->content);
        $element->name = $this->cleanText($comment->authorName);
        $element->email = $comment->authorEmail !== '' ? $comment->authorEmail : null;
        $element->url = $comment->authorUrl !== '' ? $comment->authorUrl : null;
        $element->ipAddress = $comment->authorIp !== '' ? $comment->authorIp : null;
        $element->userAgent = $comment->agent !== '' ? $comment->agent : null;

        // Verbb's statuses are approved/pending/spam/trashed; WordPress's are 1/0/spam/trash.
        $element->status = $comment->isApproved() ? 'approved' : 'pending';

        if ($comment->userId > 0) {
            $userId = $context->map->lookup(IdMap::KEY_USER, $comment->userId);

            if ($userId !== null) {
                $element->userId = $userId;
            }
        }

        $date = $this->toDateTime($comment->date);

        if ($date !== null) {
            $element->dateCreated = $date;
        }

        if ($context->dryRun) {
            $context->count(self::phase(), $isNew ? 'created' : 'updated');

            return;
        }

        $this->save($element, false);

        $context->map->record(
            IdMap::KEY_COMMENT,
            $comment->id,
            $class,
            $element->id,
            $element->uid,
            null,
            $hash,
            null,
            $context->runId
        );

        $context->count(self::phase(), $isNew ? 'created' : 'updated');
    }

    /**
     * Second pass: attach replies to the comments they reply to.
     */
    private function applyThreading(RunContext $context): void
    {
        if ($context->dryRun) {
            return;
        }

        $class = 'verbb\\comments\\elements\\Comment';

        if (!class_exists($class)) {
            return;
        }

        $context->progress->message('Rebuilding comment threads');

        foreach ($context->source->comments() as $comment) {
            if ($comment->parentId <= 0) {
                continue;
            }

            $childId = $context->map->lookup(IdMap::KEY_COMMENT, $comment->id);
            $parentId = $context->map->lookup(IdMap::KEY_COMMENT, $comment->parentId);

            if ($childId === null || $parentId === null) {
                continue;
            }

            $child = $class::find()->id($childId)->status(null)->one();

            if ($child === null || (int)($child->newParentId ?? $child->getParentId() ?? 0) === $parentId) {
                continue;
            }

            $child->newParentId = $parentId;

            try {
                Craft::$app->getElements()->saveElement($child, false);
            } catch (\Throwable $e) {
                $this->warn($context, 'Could not thread a comment: ' . $e->getMessage(), [
                    'sourceKey' => IdMap::KEY_COMMENT,
                    'sourceId' => (string)$comment->id,
                ]);
            }
        }
    }

    private function reportOnly(RunContext $context): void
    {
        $total = 0;
        $spam = 0;

        try {
            foreach ($context->source->comments() as $comment) {
                $total++;

                if ($comment->isSpam()) {
                    $spam++;
                }
            }
        } catch (\Throwable) {
            return;
        }

        $this->notice($context, sprintf(
            '%s comment%s (%s spam) were found and not imported. Install Verbb Comments and '
            . 're-run this phase to bring them across.',
            number_format($total),
            $total === 1 ? '' : 's',
            number_format($spam)
        ));
    }
}
