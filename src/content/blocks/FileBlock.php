<?php

namespace justinholtweb\passer\content\blocks;

use craft\helpers\Html;
use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;
use justinholtweb\passer\services\IdMap;

/**
 * File, audio and video blocks — attachments that are not images.
 */
class FileBlock extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return ['core/file', 'core/audio', 'core/video'];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        $id = $block->attribute('id');
        $assetId = is_numeric($id) ? $context->map->lookup(IdMap::KEY_ATTACHMENT, (int)$id) : null;
        $asset = $assetId !== null ? \craft\elements\Asset::find()->id($assetId)->one() : null;

        $url = $asset?->getUrl() ?? ((string)$block->attribute('src', $block->attribute('href', '')) ?: null);

        if ($url === null) {
            return $this->inner($block) ?: null;
        }

        return match ($block->name) {
            'core/audio' => $this->tag('figure', ['class' => $this->classes($block, 'wp-block-audio')], $this->tag('audio', [
                'controls' => 'controls',
                'src' => $url,
            ], '')),

            'core/video' => $this->tag('figure', ['class' => $this->classes($block, 'wp-block-video')], $this->tag('video', [
                'controls' => 'controls',
                'src' => $url,
                'poster' => (string)$block->attribute('poster', '') ?: null,
            ], '')),

            default => $this->tag('div', ['class' => $this->classes($block, 'wp-block-file')], $this->tag('a', [
                'href' => $url,
                'download' => $block->attribute('downloadButtonText') !== null ? 'download' : null,
            ], Html::encode((string)$block->attribute('fileName', $asset?->filename ?? basename($url))))),
        };
    }
}
