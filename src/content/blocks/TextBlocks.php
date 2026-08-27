<?php

namespace justinholtweb\passer\content\blocks;

use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * Paragraphs, preformatted text, verse and freeform (classic) content.
 */
class TextBlocks extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return [
            'core/paragraph',
            'core/preformatted',
            'core/verse',
            'core/freeform',
            'core/html',
            'core/missing',
        ];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        return match ($block->name) {
            // Freeform is classic-editor content that Gutenberg wrapped rather than converted,
            // so it needs the same paragraph handling as a fully classic post.
            'core/freeform' => $transformer->autop($this->inner($block)),

            // A custom-HTML block is exactly what the author typed. Passing it through is the
            // whole point of the block existing.
            'core/html' => $this->inner($block),

            // `core/missing` is Gutenberg's placeholder for a block whose plugin is gone. Its
            // markup is all that is left of it.
            'core/missing' => $this->inner($block) !== '' ? $this->inner($block) : null,

            'core/paragraph' => $this->paragraph($block),

            default => $this->inner($block),
        };
    }

    private function paragraph(Block $block): ?string
    {
        $inner = $this->inner($block);

        if ($inner === '') {
            return null;
        }

        // Gutenberg already rendered the paragraph with its alignment classes; rebuilding it
        // from attributes would only lose the inline formatting inside it.
        return $inner;
    }
}
