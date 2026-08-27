<?php

namespace justinholtweb\passer\importers;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\DateTimeHelper;
use justinholtweb\passer\records\LogRecord;

/**
 * Shared machinery for the phases: error handling, element saving, date conversion, logging.
 */
abstract class BaseImporter extends Component implements ImporterInterface
{
    public function estimate(RunContext $context): ?int
    {
        return null;
    }

    /**
     * Run one record's import, catching anything it throws.
     *
     * A migration that stops on the first malformed record is useless — real WordPress databases
     * contain posts with invalid UTF-8, orphaned meta and dates from 1970, and the useful
     * behaviour is to import the other 40,000 records and list the failures.
     *
     * @param callable(): void $callback
     */
    protected function attempt(
        RunContext $context,
        string $sourceKey,
        int|string $sourceId,
        string $title,
        callable $callback,
    ): bool {
        try {
            $callback();

            return true;
        } catch (\Throwable $e) {
            $context->count(static::phase(), 'failed');
            $context->log(LogRecord::LEVEL_ERROR, $e->getMessage(), [
                'phase' => static::phase(),
                'sourceKey' => $sourceKey,
                'sourceId' => (string)$sourceId,
                'sourceTitle' => $title,
                'exception' => $e::class,
            ]);

            if (!$context->continueOnError) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * Save an element, turning validation failure into a readable message.
     */
    protected function save(ElementInterface $element, bool $runValidation = true): void
    {
        if (Craft::$app->getElements()->saveElement($element, $runValidation)) {
            return;
        }

        $errors = [];

        foreach ($element->getErrors() as $attribute => $messages) {
            $errors[] = $attribute . ': ' . implode(', ', (array)$messages);
        }

        throw new \RuntimeException(sprintf(
            'Could not save %s "%s": %s',
            (new \ReflectionClass($element))->getShortName(),
            (string)$element->title,
            $errors !== [] ? implode('; ', $errors) : 'no reason given'
        ));
    }

    /**
     * Convert a WordPress datetime string into a DateTime in the site's timezone.
     *
     * WordPress stores two columns: `post_date` in the site's configured timezone and
     * `post_date_gmt` in UTC. The GMT column is authoritative and the local one is not — sites
     * that have changed timezone have a local column that is simply wrong for older posts — so
     * the GMT value is preferred whenever it is present and non-zero.
     */
    protected function toDateTime(?string $local, ?string $gmt = null): ?\DateTime
    {
        if ($gmt !== null && $gmt !== '' && !str_starts_with($gmt, '0000-00-00')) {
            try {
                return new \DateTime($gmt, new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                // Fall through to the local column.
            }
        }

        if ($local === null || $local === '' || str_starts_with($local, '0000-00-00')) {
            return null;
        }

        try {
            return new \DateTime($local, new \DateTimeZone(Craft::$app->getTimeZone()));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * WordPress content is nominally UTF-8 but frequently is not: a site migrated from latin1
     * carries bytes that are invalid UTF-8 and that MySQL will reject on insert.
     */
    protected function cleanText(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!mb_check_encoding($value, 'UTF-8')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
            $value = is_string($converted) ? $converted : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        // Strip control characters that are legal UTF-8 but illegal in XML and unwelcome in a
        // database — WordPress content picks these up from pasted Word documents.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    }

    /**
     * A slug Craft will accept, derived from WordPress's `post_name`.
     */
    protected function cleanSlug(string $name, string $fallback = ''): string
    {
        // WordPress URL-encodes non-Latin slugs in the database, so a Japanese post's `post_name`
        // arrives as a long percent-encoded string that is technically valid and completely
        // unreadable. Decoding first gives Craft the real characters to slugify.
        $decoded = rawurldecode($name);
        $slug = \craft\helpers\ElementHelper::generateSlug($decoded !== '' ? $decoded : $fallback);

        return $slug !== '' ? $slug : \craft\helpers\ElementHelper::generateSlug($fallback ?: 'untitled');
    }

    /**
     * The Craft site IDs a mapping should import into.
     *
     * @param int[] $configured
     * @return int[]
     */
    protected function siteIdsFor(array $configured, RunContext $context): array
    {
        if ($configured !== []) {
            return $configured;
        }

        if ($context->plan->defaultSiteId !== null) {
            return [$context->plan->defaultSiteId];
        }

        return [Craft::$app->getSites()->getPrimarySite()->id];
    }

    /**
     * Whether a record can be skipped because it has not changed since the last run.
     */
    protected function unchanged(RunContext $context, string $sourceKey, int|string $sourceId, string $hash, ?int $siteId = null): bool
    {
        if (!$context->updateExisting) {
            // Not updating: anything already mapped is skipped regardless of whether it changed.
            return $context->map->has($sourceKey, $sourceId, $siteId);
        }

        return $context->map->contentHash($sourceKey, $sourceId, $siteId) === $hash;
    }

    protected function info(RunContext $context, string $message, array $extra = []): void
    {
        $context->log(LogRecord::LEVEL_INFO, $message, ['phase' => static::phase()] + $extra);
    }

    protected function warn(RunContext $context, string $message, array $extra = []): void
    {
        $context->log(LogRecord::LEVEL_WARNING, $message, ['phase' => static::phase()] + $extra);
    }

    protected function notice(RunContext $context, string $message, array $extra = []): void
    {
        $context->log(LogRecord::LEVEL_NOTICE, $message, ['phase' => static::phase()] + $extra);
    }
}
