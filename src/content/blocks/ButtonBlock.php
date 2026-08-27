<?php

namespace justinholtweb\passer\content\blocks;

use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * Buttons and button groups.
 */
class ButtonBlock extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return ['core/button', 'core/buttons'];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        if ($block->name === 'core/buttons') {
            $inner = $transformer->renderBlocks($block->innerBlocks, $context);

            if (trim($inner) === '') {
                return $this->inner($block) ?: null;
            }

            return $this->tag('div', ['class' => $this->classes($block, 'wp-block-buttons')], "\n" . $inner . "\n");
        }

        $inner = $this->inner($block);

        if ($inner !== '') {
            return $inner;
        }

        $text = (string)$block->attribute('text', '');
        $url = (string)$block->attribute('url', '');

        if ($text === '') {
            return null;
        }

        $anchor = $this->tag('a', [
            'class' => 'wp-block-button__link',
            'href' => $url !== '' ? $url : null,
            'rel' => $block->attribute('rel'),
            'target' => $block->attribute('linkTarget'),
        ], $text);

        return $this->tag('div', ['class' => $this->classes($block, 'wp-block-button')], $anchor);
    }
}
