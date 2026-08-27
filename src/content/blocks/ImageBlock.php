<?php

namespace justinholtweb\passer\content\blocks;

use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;
use justinholtweb\passer\services\IdMap;

/**
 * Images, cover images and the featured-image block.
 *
 * The important work here is not the markup but the reference: WordPress wrote an absolute URL
 * pointing at the old site's uploads folder, which will 404 the moment the domain moves. The
 * block's `id` attribute is the attachment ID, so the ID map can supply the Craft asset that
 * replaced it.
 */
class ImageBlock extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return ['core/image', 'core/cover', 'core/post-featured-image', 'core/site-logo'];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        $attachmentId = $this->attachmentId($block);

        if ($block->name === 'core/cover') {
            return $this->cover($block, $context, $transformer, $attachmentId);
        }

        $assetId = $attachmentId !== null
            ? $context->map->lookup(IdMap::KEY_ATTACHMENT, $attachmentId)
            : null;

        $asset = $assetId !== null ? \craft\elements\Asset::find()->id($assetId)->one() : null;

        if ($asset === null) {
            // No mapped asset: keep the rendered markup so at least the image is still described,
            // and let the URL rewriter have a go at the src.
            return $this->inner($block) ?: null;
        }

        $alt = $this->altFrom($block, $asset->alt ?? '');
        $caption = (string)$block->attribute('caption', '');

        if ($caption === '') {
            $caption = $this->captionFrom($this->inner($block));
        }

        $img = $this->tag('img', [
            'src' => $asset->getUrl(),
            'alt' => $alt,
            'width' => $asset->getWidth() !== null ? (string)$asset->getWidth() : null,
            'height' => $asset->getHeight() !== null ? (string)$asset->getHeight() : null,
            // Craft's rich-text fields use this to keep the reference live when the asset moves.
            'data-entity-type' => 'craft\\elements\\Asset',
            'data-entity-id' => (string)$asset->id,
        ]);

        $href = $this->linkHref($block, $asset->getUrl());

        if ($href !== null) {
            $img = $this->tag('a', ['href' => $href], $img);
        }

        $figureClass = $this->classes($block, 'wp-block-image');

        if ($caption !== '') {
            return $this->tag('figure', ['class' => $figureClass], $img . $this->tag('figcaption', [], $caption));
        }

        return $figureClass !== '' ? $this->tag('figure', ['class' => $figureClass], $img) : $img;
    }

    private function cover(Block $block, RunContext $context, ContentTransformer $transformer, ?int $attachmentId): ?string
    {
        // A cover block is a background image with arbitrary content on top. Craft has no
        // equivalent in a rich-text field, so the content is kept and the background becomes a
        // data attribute rather than being thrown away.
        $inner = $block->innerBlocks !== []
            ? $transformer->renderBlocks($block->innerBlocks, $context)
            : $this->inner($block);

        if (trim($inner) === '') {
            return null;
        }

        $url = $this->assetUrl($attachmentId, $context, (string)$block->attribute('url', '') ?: null);

        return $this->tag('div', [
            'class' => $this->classes($block, 'wp-block-cover'),
            'data-background-image' => $url,
        ], "\n" . $inner . "\n");
    }

    private function attachmentId(Block $block): ?int
    {
        foreach (['id', 'mediaId'] as $key) {
            $value = $block->attribute($key);

            if (is_numeric($value) && (int)$value > 0) {
                return (int)$value;
            }
        }

        // The rendered markup carries the ID as a class, which is the only trace left when the
        // attribute was stripped by a theme or a migration.
        if (preg_match('/wp-image-(\d+)/', $block->innerHtml, $m)) {
            return (int)$m[1];
        }

        return null;
    }

    private function altFrom(Block $block, string $fallback): string
    {
        $alt = $block->attribute('alt');

        if (is_string($alt) && $alt !== '') {
            return $alt;
        }

        if (preg_match('/<img[^>]*\balt=(["\'])(.*?)\1/is', $block->innerHtml, $m)) {
            return $m[2];
        }

        return $fallback;
    }

    private function captionFrom(string $html): string
    {
        if (preg_match('#<figcaption[^>]*>(.*?)</figcaption>#is', $html, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private function linkHref(Block $block, ?string $assetUrl): ?string
    {
        $destination = $block->attribute('linkDestination');

        if ($destination === 'media') {
            return $assetUrl;
        }

        if ($destination === 'custom' || $destination === 'attachment') {
            $href = $block->attribute('href');

            if (is_string($href) && $href !== '') {
                return $href;
            }

            if (preg_match('/<a[^>]*\bhref=(["\'])(.*?)\1/is', $block->innerHtml, $m)) {
                return $m[2];
            }
        }

        return null;
    }
}
