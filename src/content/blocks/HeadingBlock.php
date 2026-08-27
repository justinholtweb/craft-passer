<?php

namespace justinholtweb\passer\content\blocks;

use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * Headings, including the site-title/post-title variants that are headings in disguise.
 */
class HeadingBlock extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return ['core/heading', 'core/site-title', 'core/post-title'];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        $inner = $this->inner($block);

        if ($inner !== '') {
            return $inner;
        }

        // A heading with no rendered markup only happens in hand-edited content, but it happens.
        $level = (int)$block->attribute('level', 2);
        $level = max(1, min(6, $level));
        $content = (string)$block->attribute('content', '');

        if ($content === '') {
            return null;
        }

        return $this->tag("h$level", ['class' => $this->classes($block)], $content);
    }
}
