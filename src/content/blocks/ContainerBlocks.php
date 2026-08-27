<?php

namespace justinholtweb\passer\content\blocks;

use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * Blocks that exist to hold other blocks: groups, columns, rows, stacks, template parts.
 *
 * The layout intent — a three-column row, a stacked group — is not representable in a rich-text
 * field, so it is preserved as classes and data attributes rather than dropped. The children are
 * what actually matter and they are rendered in order.
 */
class ContainerBlocks extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return [
            'core/group',
            'core/columns',
            'core/column',
            'core/row',
            'core/stack',
            'core/template-part',
            'core/block',
            'core/query',
            'core/post-template',
        ];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        // A reusable block (`core/block`) is a reference to a `wp_block` post, with no content
        // of its own. Resolving it would need the referenced post, which is imported separately
        // — so it becomes a marker rather than an empty div.
        if ($block->name === 'core/block') {
            $ref = $block->attribute('ref');

            return $ref !== null
                ? sprintf('<!-- reusable block %s -->', (int)$ref)
                : null;
        }

        // A query loop renders posts at request time. There is nothing to import.
        if (in_array($block->name, ['core/query', 'core/post-template'], true)) {
            return null;
        }

        if ($block->name === 'core/template-part') {
            $slug = $block->attribute('slug');

            return $slug !== null ? sprintf('<!-- template part %s -->', (string)$slug) : null;
        }

        $inner = $transformer->renderBlocks($block->innerBlocks, $context);

        if (trim($inner) === '') {
            $inner = $this->inner($block);
        }

        if (trim($inner) === '') {
            return null;
        }

        $class = $this->classes($block, 'wp-block-' . $block->shortName());

        $attributes = ['class' => $class];

        if ($block->name === 'core/columns') {
            $count = count(array_filter($block->innerBlocks, static fn(Block $b) => $b->name === 'core/column'));

            if ($count > 0) {
                $attributes['data-columns'] = (string)$count;
            }
        }

        if ($block->name === 'core/column') {
            $width = $block->attribute('width');

            if (is_string($width) && $width !== '') {
                $attributes['style'] = 'flex-basis:' . $width;
            }
        }

        $tag = (string)$block->attribute('tagName', 'div');

        // `tagName` is author-supplied, so it is constrained to tags that are safe to emit.
        if (!in_array($tag, ['div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav'], true)) {
            $tag = 'div';
        }

        return $this->tag($tag, $attributes, "\n" . trim($inner) . "\n");
    }
}
