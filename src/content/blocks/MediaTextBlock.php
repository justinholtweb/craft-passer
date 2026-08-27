<?php

namespace justinholtweb\passer\content\blocks;

use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * The media-and-text block: an image beside a column of content.
 */
class MediaTextBlock extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return ['core/media-text'];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        $mediaId = $block->attribute('mediaId');
        $url = $this->assetUrl(
            is_numeric($mediaId) ? (int)$mediaId : null,
            $context,
            (string)$block->attribute('mediaUrl', '') ?: null
        );

        $figure = '';

        if ($url !== null) {
            $figure = $this->tag('figure', ['class' => 'wp-block-media-text__media'], $this->tag('img', [
                'src' => $url,
                'alt' => (string)$block->attribute('mediaAlt', ''),
            ]));
        }

        $content = $transformer->renderBlocks($block->innerBlocks, $context);

        if (trim($content) === '') {
            $content = $this->inner($block);
        }

        if (trim($content) === '' && $figure === '') {
            return null;
        }

        $body = $this->tag('div', ['class' => 'wp-block-media-text__content'], "\n" . trim($content) . "\n");

        // `mediaPosition` decides whether the image comes first, which is the block's only real
        // semantic and is worth keeping in the markup order rather than in a class alone.
        $mediaFirst = ($block->attribute('mediaPosition', 'left')) !== 'right';

        return $this->tag(
            'div',
            ['class' => $this->classes($block, 'wp-block-media-text', $mediaFirst ? '' : 'has-media-on-the-right')],
            "\n" . ($mediaFirst ? $figure . "\n" . $body : $body . "\n" . $figure) . "\n"
        );
    }
}
