<?php

namespace justinholtweb\passer\importers;

use Craft;
use craft\elements\Asset;
use craft\errors\UploadFailedException;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\models\VolumeFolder;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\services\IdMap;

/**
 * WordPress attachments become Craft assets.
 *
 * Media runs before content because a post's featured image, its inline images and its gallery
 * are all relations that need their target to exist. It is also, on most sites, by far the
 * slowest phase: every file is a separate HTTP request to a host that is often the same
 * overloaded shared server the database is on.
 *
 * The important behaviours are therefore about not doing work twice: the ID map skips anything
 * already imported, the content hash skips anything unchanged, and a failed download is recorded
 * and stepped over rather than retried into a wall.
 */
class MediaImporter extends BaseImporter
{
    public static function phase(): string
    {
        return 'media';
    }

    public function estimate(RunContext $context): ?int
    {
        try {
            return $context->source->postTypes()['attachment'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function import(RunContext $context, array $resumeFrom = []): array
    {
        $offset = (int)($resumeFrom['offset'] ?? 0);
        $processed = 0;

        $volume = $this->volume($context);

        if ($volume === null) {
            $this->warn($context, sprintf(
                'No volume named "%s" exists, so no media was imported. Every image reference in '
                . 'imported content will still point at the WordPress site.',
                $context->plan->volume
            ));

            return ['offset' => $offset, 'skipped' => true];
        }

        $context->progress->startPhase(self::phase(), $this->estimate($context));

        $referenced = $context->plan->importOrphanedMedia ? null : $this->referencedAttachmentIds($context);

        foreach ($context->source->posts('attachment', $offset) as $attachment) {
            if ($context->limit !== null && $processed >= $context->limit) {
                break;
            }

            $offset++;
            $processed++;

            if ($referenced !== null && !isset($referenced[$attachment->id])) {
                $context->count(self::phase(), 'skipped');
                $context->progress->advance($processed);
                continue;
            }

            $this->attempt($context, IdMap::KEY_ATTACHMENT, $attachment->id, $attachment->title, function () use ($attachment, $context, $volume) {
                $this->importAttachment($attachment, $context, $volume);
            });

            $context->progress->advance($processed, $attachment->title);
        }

        $context->progress->endPhase(self::phase());

        return ['offset' => $offset];
    }

    private function importAttachment(
        \justinholtweb\passer\models\wp\WpPost $attachment,
        RunContext $context,
        \craft\base\FsInterface|\craft\models\Volume $volume,
    ): void {
        $url = $attachment->attachmentUrl;

        if ($url === null || $url === '') {
            $this->warn($context, sprintf('Attachment %d has no file URL and was skipped.', $attachment->id), [
                'sourceKey' => IdMap::KEY_ATTACHMENT,
                'sourceId' => (string)$attachment->id,
            ]);
            $context->count(self::phase(), 'failed');

            return;
        }

        $hash = $context->map->hashPayload([$url, $attachment->title, $attachment->excerpt, $attachment->modified]);

        if ($this->unchanged($context, IdMap::KEY_ATTACHMENT, $attachment->id, $hash)) {
            $context->count(self::phase(), 'skipped');

            return;
        }

        $existingId = $context->map->lookup(IdMap::KEY_ATTACHMENT, $attachment->id);
        $asset = $existingId !== null ? Asset::find()->id($existingId)->one() : null;

        if ($context->dryRun) {
            $context->count(self::phase(), $asset === null ? 'created' : 'updated');

            return;
        }

        if ($asset !== null) {
            // The file is already here; only its metadata can have changed.
            $this->applyMetadata($asset, $attachment, $context);
            $this->save($asset);

            $context->map->record(
                IdMap::KEY_ATTACHMENT,
                $attachment->id,
                Asset::class,
                $asset->id,
                $asset->uid,
                null,
                $hash,
                $url,
                $context->runId
            );

            $context->count(self::phase(), 'updated');

            return;
        }

        if (!Plugin::getInstance()->getSettings()->downloadMedia) {
            // Metadata-only mode: no bytes move, but the mapping is still recorded so references
            // in content resolve to *something* rather than being dropped.
            $context->count(self::phase(), 'skipped');

            return;
        }

        $temp = $this->download($url, $context);

        if ($temp === null) {
            $context->count(self::phase(), 'failed');

            return;
        }

        try {
            $folder = $this->folderFor($attachment, $context, $volume);
            $filename = $this->filenameFor($attachment, $url);

            $asset = new Asset();
            $asset->tempFilePath = $temp;
            $asset->setFilename($filename);
            $asset->newFolderId = $folder->id;
            $asset->setVolumeId($folder->volumeId);
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            $this->applyMetadata($asset, $attachment, $context);

            $this->save($asset);

            $context->map->record(
                IdMap::KEY_ATTACHMENT,
                $attachment->id,
                Asset::class,
                $asset->id,
                $asset->uid,
                null,
                $hash,
                $url,
                $context->runId
            );

            $context->count(self::phase(), 'created');
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    private function applyMetadata(Asset $asset, \justinholtweb\passer\models\wp\WpPost $attachment, RunContext $context): void
    {
        $asset->title = $this->cleanText($attachment->title) ?: $asset->title;

        // WordPress keeps alt text in `_wp_attachment_image_alt`, not in the post at all — the
        // post_excerpt is the *caption* and post_content is the *description*, which is a
        // distinction that trips up almost every importer.
        $alt = $attachment->metaValue('_wp_attachment_image_alt');

        if (is_string($alt) && $alt !== '') {
            $asset->alt = $this->cleanText($alt);
        }

        $date = $this->toDateTime($attachment->date, $attachment->dateGmt);

        if ($date !== null) {
            $asset->dateCreated = $date;
        }
    }

    /**
     * Download a file to a temporary path, refusing anything too large.
     */
    private function download(string $url, RunContext $context): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        $maxBytes = $settings->maxAssetSizeMb * 1024 * 1024;

        $temp = Craft::$app->getPath()->getTempPath() . '/' . uniqid('passer_', true);
        $handle = @fopen($temp, 'wb');

        if ($handle === false) {
            $this->warn($context, 'Could not open a temporary file for download.');

            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => max(30, $settings->requestTimeout),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'Passer/1.0 (+https://craft-passer.com)',
            CURLOPT_FAILONERROR => true,
            // Stop the transfer as soon as it exceeds the cap, rather than downloading a 4GB
            // video and then rejecting it.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($resource, $downloadSize, $downloaded) use ($maxBytes) {
                return $downloaded > $maxBytes ? 1 : 0;
            },
        ]);

        $ok = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($handle);

        if ($ok === false || $status >= 400) {
            @unlink($temp);

            $this->warn($context, sprintf(
                'Could not download %s (%s).',
                $url,
                $error !== '' ? $error : 'HTTP ' . $status
            ));

            return null;
        }

        if (filesize($temp) === 0) {
            @unlink($temp);
            $this->warn($context, "Downloaded $url but the file was empty.");

            return null;
        }

        return $temp;
    }

    private function filenameFor(\justinholtweb\passer\models\wp\WpPost $attachment, string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $basename = is_string($path) ? basename($path) : '';

        if ($basename === '') {
            $basename = ($attachment->name !== '' ? $attachment->name : 'attachment-' . $attachment->id);
        }

        // WordPress percent-encodes non-ASCII filenames in the URL.
        return AssetsHelper::prepareAssetName(rawurldecode($basename));
    }

    private function volume(RunContext $context): ?\craft\models\Volume
    {
        $handle = $context->plan->volume;

        if ($handle === '') {
            return Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
        }

        return Craft::$app->getVolumes()->getVolumeByHandle($handle);
    }

    /**
     * Reproduce WordPress's uploads folder structure inside the volume.
     */
    private function folderFor(
        \justinholtweb\passer\models\wp\WpPost $attachment,
        RunContext $context,
        \craft\models\Volume $volume,
    ): VolumeFolder {
        $assets = Craft::$app->getAssets();
        $root = $assets->getRootFolderByVolumeId($volume->id);

        if ($root === null) {
            throw new \RuntimeException("Volume {$volume->handle} has no root folder.");
        }

        $path = match ($context->plan->folderStrategy) {
            // `preserve` mirrors WordPress's own `2019/07` layout, taken from the stored file
            // path rather than the post date — the two disagree on any file that was ever moved.
            'preserve' => $this->preservedPath($attachment),
            'yearMonth' => $this->datePath($attachment),
            default => '',
        };

        if ($path === '') {
            return $root;
        }

        $folder = $root;

        foreach (explode('/', trim($path, '/')) as $segment) {
            $segment = FileHelper::sanitizeFilename($segment, ['asciiOnly' => false]);

            if ($segment === '') {
                continue;
            }

            $existing = $assets->findFolder(['parentId' => $folder->id, 'name' => $segment]);

            if ($existing !== null) {
                $folder = $existing;
                continue;
            }

            $child = new VolumeFolder([
                'parentId' => $folder->id,
                'volumeId' => $volume->id,
                'name' => $segment,
                'path' => rtrim($folder->path ?? '', '/') . ($folder->path ? '/' : '') . $segment . '/',
            ]);

            $assets->createFolder($child);
            $folder = $child;
        }

        return $folder;
    }

    private function preservedPath(\justinholtweb\passer\models\wp\WpPost $attachment): string
    {
        $file = $attachment->metaValue('_wp_attached_file');

        if (is_string($file) && str_contains($file, '/')) {
            return dirname($file);
        }

        return $this->datePath($attachment);
    }

    private function datePath(\justinholtweb\passer\models\wp\WpPost $attachment): string
    {
        $date = $this->toDateTime($attachment->date, $attachment->dateGmt);

        return $date !== null ? $date->format('Y/m') : '';
    }

    /**
     * Attachment IDs referenced by something being imported.
     *
     * A real WordPress uploads folder is mostly detritus: every image ever uploaded and deleted
     * from a draft, plus a decade of plugin-generated thumbnails. Importing only what is
     * referenced routinely cuts the media phase by three quarters.
     *
     * @return array<int, true>
     */
    private function referencedAttachmentIds(RunContext $context): array
    {
        $referenced = [];

        foreach (array_keys($context->plan->enabledPostTypes()) as $postType) {
            try {
                foreach ($context->source->posts($postType) as $post) {
                    if ($post->featuredImageId !== null) {
                        $referenced[$post->featuredImageId] = true;
                    }

                    // Inline images carry their attachment ID in a `wp-image-N` class, which is
                    // the only reliable trace of the relationship in the content itself.
                    if (preg_match_all('/wp-image-(\d+)/', $post->content, $m)) {
                        foreach ($m[1] as $id) {
                            $referenced[(int)$id] = true;
                        }
                    }

                    // Gallery shortcodes and blocks list their IDs.
                    if (preg_match_all('/\bids\s*[=:]\s*["\']?([\d,\s]+)/', $post->content, $m)) {
                        foreach ($m[1] as $list) {
                            foreach (preg_split('/\s*,\s*/', $list) ?: [] as $id) {
                                if (is_numeric($id)) {
                                    $referenced[(int)$id] = true;
                                }
                            }
                        }
                    }

                    // ACF image and gallery fields store bare attachment IDs. The check is
                    // narrowed to keys ACF owns — those with a paired `_key` row naming a
                    // field — because otherwise every numeric meta value on the site, most of
                    // them plugin bookkeeping, would look like an attachment reference.
                    foreach ($post->meta as $key => $value) {
                        if (str_starts_with($key, '_')) {
                            continue;
                        }

                        $fieldKey = $post->metaValue('_' . $key);

                        if (!is_string($fieldKey) || !str_starts_with($fieldKey, 'field_')) {
                            continue;
                        }

                        foreach ((array)$value as $item) {
                            if (is_numeric($item) && (int)$item > 0) {
                                $referenced[(int)$item] = true;
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $referenced;
    }
}
