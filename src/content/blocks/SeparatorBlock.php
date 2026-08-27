<?php

namespace justinholtweb\passer\content\blocks;

use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * Separators, spacers and page breaks — blocks whose whole content is their existence.
 */
class SeparatorBlock extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return ['core/separator', 'core/spacer', 'core/nextpage', 'core/more'];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        return match ($block->name) {
            'core/separator' => $this->tag('hr', ['class' => $this->classes($block, 'wp-block-separator')]),

            'core/spacer' => $this->tag('div', [
                'class' => $this->classes($block, 'wp-block-spacer'),
                'style' => ($height = $block->attribute('height')) ? 'height:' . (is_numeric($height) ? $height . 'px' : $height) : null,
            ], ''),

            // `more` and `nextpage` are WordPress rendering directives with no content of their
            // own. Dropping them silently would change where a post visibly ends, so they are
            // left as comments a template can find.
            'core/more' => '<!-- more -->',
            'core/nextpage' => '<!-- nextpage -->',

            default => null,
        };
    }
}
