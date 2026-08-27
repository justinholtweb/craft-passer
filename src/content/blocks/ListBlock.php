<?php

namespace justinholtweb\passer\content\blocks;

use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * Ordered and unordered lists.
 *
 * WordPress 6.3 changed lists from one block containing raw `<li>` markup to a parent block with
 * a `core/list-item` child per item. Both shapes are still in the wild, often in the same site,
 * so both are handled.
 */
class ListBlock extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return ['core/list', 'core/list-item'];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        if ($block->name === 'core/list-item') {
            $inner = $this->inner($block);
            $nested = $block->innerBlocks !== [] ? $transformer->renderBlocks($block->innerBlocks, $context) : '';

            if ($inner === '' && $nested === '') {
                return null;
            }

            // The item's own markup already includes its `<li>`; a nested list goes inside it.
            if ($nested !== '' && str_ends_with(rtrim($inner), '</li>')) {
                return preg_replace('#</li>\s*$#', $nested . '</li>', $inner) ?? $inner;
            }

            return $inner !== '' ? $inner : '<li>' . $nested . '</li>';
        }

        // The modern shape: children carry the items.
        if ($block->innerBlocks !== []) {
            $tag = $block->attribute('ordered') ? 'ol' : 'ul';
            $items = $transformer->renderBlocks($block->innerBlocks, $context);

            $attributes = ['class' => $this->classes($block)];

            $start = $block->attribute('start');
            if ($tag === 'ol' && is_numeric($start)) {
                $attributes['start'] = (string)(int)$start;
            }

            if ($block->attribute('reversed')) {
                $attributes['reversed'] = 'reversed';
            }

            return $this->tag($tag, $attributes, "\n" . $items . "\n");
        }

        // The legacy shape: the whole list is already rendered.
        return $this->inner($block) ?: null;
    }
}
