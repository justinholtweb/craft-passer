<?php

namespace justinholtweb\passer\content;

use justinholtweb\passer\importers\RunContext;

/**
 * Turns one kind of Gutenberg block into something Craft can store.
 *
 * Transformers are looked up by block name, so adding support for a plugin's block is a matter
 * of registering one class — which is also how the wp-import bridge works.
 */
interface BlockTransformerInterface
{
    /**
     * Block names this handles, e.g. `['core/paragraph', 'core/heading']`.
     *
     * @return string[]
     */
    public static function handles(): array;

    /**
     * Render the block to HTML.
     *
     * Returning null means "I cannot handle this after all", and the caller falls back to the
     * block's original markup rather than losing it.
     */
    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string;
}
