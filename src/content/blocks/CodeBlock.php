<?php

namespace justinholtweb\passer\content\blocks;

use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * Code blocks, including the third-party syntax-highlighter blocks that are everywhere on
 * developer sites.
 */
class CodeBlock extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return ['core/code', 'coblocks/gist', 'syntaxhighlighter/code', 'prismatic/blocks'];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        $inner = $this->inner($block);

        if ($inner === '') {
            $content = (string)$block->attribute('content', $block->attribute('code', ''));

            if ($content === '') {
                return null;
            }

            // Content held in an attribute has not been escaped, and code very much needs it to
            // be — an unescaped `<script>` in a code sample becomes a live script.
            $inner = $this->tag('pre', [], $this->tag('code', [], htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
        }

        $language = $block->attribute('language') ?? $block->attribute('lang');

        if (is_string($language) && $language !== '' && str_contains($inner, '<code')) {
            $inner = preg_replace(
                '/<code(?![^>]*\bclass=)/',
                '<code class="language-' . htmlspecialchars($language, ENT_QUOTES, 'UTF-8') . '"',
                $inner,
                1
            ) ?? $inner;
        }

        return $inner;
    }
}
