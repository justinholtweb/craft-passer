<?php

namespace justinholtweb\passer\bridge;

use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\BlockTransformerInterface;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * Presents a wp-import block transformer as one of Passer's.
 *
 * wp-import's transformers are written against its own block array shape — `['blockName' => …,
 * 'attrs' => …, 'innerHTML' => …, 'innerBlocks' => …]`, which is what WordPress's own
 * `parse_blocks()` returns — so the wrapper converts Passer's Block object back into that shape
 * before calling through.
 */
class WrappedBlockTransformer implements BlockTransformerInterface
{
    public function __construct(
        private object $transformer,
        private string $blockName,
    ) {
    }

    public static function handles(): array
    {
        return [];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        foreach (['render', 'toHtml', 'transform'] as $method) {
            if (!method_exists($this->transformer, $method)) {
                continue;
            }

            try {
                $result = $this->transformer->$method($this->toWpShape($block));

                if (is_string($result)) {
                    return $result;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toWpShape(Block $block): array
    {
        return [
            'blockName' => $block->name !== '' ? $block->name : null,
            'attrs' => $block->attributes,
            'innerHTML' => $block->innerHtml,
            'innerContent' => [$block->innerHtml],
            'innerBlocks' => array_map(fn(Block $child) => $this->toWpShape($child), $block->innerBlocks),
        ];
    }

    public function blockName(): string
    {
        return $this->blockName;
    }
}
