<?php

namespace justinholtweb\passer\content\blocks;

use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * The `core/shortcode` block, which is a shortcode Gutenberg declined to interpret.
 *
 * It is handed straight to the shortcode expander, which is the same code that handles
 * shortcodes found loose in classic content — so a `[gallery]` behaves identically whether it
 * came from a block or from a 2014 post.
 */
class ShortcodeBlock extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return ['core/shortcode'];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        $text = trim($this->inner($block));

        if ($text === '') {
            $text = (string)$block->attribute('text', '');
        }

        if ($text === '') {
            return null;
        }

        return $transformer->shortcodeExpander()->expand($text, $context);
    }
}
