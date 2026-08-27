<?php

namespace justinholtweb\passer\content\blocks;

use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * Quotes and pullquotes.
 */
class QuoteBlock extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return ['core/quote', 'core/pullquote'];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        // Since WordPress 6.0 a quote's paragraphs are child blocks rather than inline markup.
        $body = $block->innerBlocks !== []
            ? $transformer->renderBlocks($block->innerBlocks, $context)
            : $this->unwrap($this->inner($block), 'blockquote');

        if (trim($body) === '') {
            return $this->inner($block) ?: null;
        }

        $citation = (string)$block->attribute('citation', '');

        if ($citation === '' && preg_match('#<cite[^>]*>(.*?)</cite>#is', $block->innerHtml, $m)) {
            $citation = trim($m[1]);
        }

        if ($citation !== '') {
            $body .= "\n" . $this->tag('cite', [], $citation);
        }

        $class = $this->classes($block, $block->name === 'core/pullquote' ? 'wp-block-pullquote' : '');

        return $this->tag('blockquote', ['class' => $class], "\n" . trim($body) . "\n");
    }
}
