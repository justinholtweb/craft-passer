<?php

namespace justinholtweb\passer\content\blocks;

use craft\helpers\Html;
use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\BlockTransformerInterface;
use justinholtweb\passer\importers\RunContext;
use justinholtweb\passer\services\IdMap;

/**
 * Shared helpers for block transformers.
 */
abstract class BaseBlockTransformer implements BlockTransformerInterface
{
    /**
     * Build a class attribute from a block's own class name plus whatever WordPress put in
     * `className` and the alignment attribute.
     */
    protected function classes(Block $block, string ...$extra): string
    {
        $classes = array_filter($extra);

        $align = $block->attribute('align');
        if (is_string($align) && $align !== '') {
            $classes[] = 'align' . $align;
        }

        $custom = $block->attribute('className');
        if (is_string($custom) && $custom !== '') {
            $classes[] = $custom;
        }

        return implode(' ', array_unique($classes));
    }

    /**
     * @param array<string, string|null> $attributes
     */
    protected function tag(string $name, array $attributes, ?string $content = null): string
    {
        $filtered = array_filter($attributes, static fn($v) => $v !== null && $v !== '');

        return $content === null
            ? Html::tag($name, '', $filtered)
            : Html::tag($name, $content, $filtered);
    }

    /**
     * Resolve a WordPress attachment ID to the Craft asset it became, and return its URL.
     */
    protected function assetUrl(?int $wpAttachmentId, RunContext $context, ?string $fallback = null): ?string
    {
        if ($wpAttachmentId === null || $wpAttachmentId <= 0) {
            return $fallback;
        }

        $assetId = $context->map->lookup(IdMap::KEY_ATTACHMENT, $wpAttachmentId);

        if ($assetId === null) {
            return $fallback;
        }

        $asset = \craft\elements\Asset::find()->id($assetId)->one();

        return $asset?->getUrl() ?? $fallback;
    }

    /**
     * A Craft asset reference tag, which survives the asset being moved or renamed later —
     * unlike the frozen URL WordPress stored.
     */
    protected function assetRefTag(?int $wpAttachmentId, RunContext $context): ?string
    {
        if ($wpAttachmentId === null || $wpAttachmentId <= 0) {
            return null;
        }

        $assetId = $context->map->lookup(IdMap::KEY_ATTACHMENT, $wpAttachmentId);

        return $assetId !== null ? "{asset:$assetId:url}" : null;
    }

    /**
     * The inner HTML with its outermost wrapper element removed.
     *
     * Gutenberg stores both the attributes and the rendered markup, so a paragraph's innerHtml
     * is `<p class="...">text</p>`. When rebuilding the tag from attributes, the original
     * wrapper has to come off first or it ends up nested inside itself.
     */
    protected function unwrap(string $html, string $tag): string
    {
        $trimmed = trim($html);

        if (preg_match('#^<' . preg_quote($tag, '#') . '\b[^>]*>(.*)</' . preg_quote($tag, '#') . '>$#is', $trimmed, $m)) {
            return trim($m[1]);
        }

        return $trimmed;
    }

    /**
     * The text content of the block's markup, tags and all.
     */
    protected function inner(Block $block): string
    {
        return trim($block->innerHtml);
    }
}
