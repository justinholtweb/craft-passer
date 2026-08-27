<?php

namespace justinholtweb\passer\content\blocks;

use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * Blocks whose rendered markup is already exactly what a Craft site wants, and blocks that are
 * chrome rather than content.
 *
 * Being explicit about these matters: without an entry here they would be counted as
 * "unhandled" and reported to the user as a gap, when in fact nothing is missing.
 */
class PassthroughBlocks extends BaseBlockTransformer
{
    /**
     * Blocks that are part of a theme's furniture and have no content to migrate.
     */
    private const DISCARD = [
        'core/site-tagline',
        'core/navigation',
        'core/navigation-link',
        'core/navigation-submenu',
        'core/post-date',
        'core/post-author',
        'core/post-author-name',
        'core/post-terms',
        'core/post-navigation-link',
        'core/query-pagination',
        'core/query-pagination-next',
        'core/query-pagination-previous',
        'core/query-pagination-numbers',
        'core/query-no-results',
        'core/query-title',
        'core/read-more',
        'core/comments',
        'core/comment-template',
        'core/post-comments-form',
        'core/loginout',
        'core/search',
        'core/archives',
        'core/calendar',
        'core/categories',
        'core/latest-posts',
        'core/latest-comments',
        'core/tag-cloud',
        'core/rss',
        'core/social-links',
        'core/social-link',
        'core/avatar',
        'core/post-excerpt',
        'core/post-content',
        'core/term-description',
        'core/pattern',
    ];

    public static function handles(): array
    {
        return array_merge(self::DISCARD, [
            'core/details',
            'core/footnotes',
            'core/text-columns',
        ]);
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        if (in_array($block->name, self::DISCARD, true)) {
            // Discarded on purpose: this is theme chrome, not content. Returning an empty string
            // rather than null keeps it out of the unhandled-block report.
            return '';
        }

        $inner = $transformer->renderBlocks($block->innerBlocks, $context);

        if (trim($inner) === '') {
            $inner = $this->inner($block);
        }

        return trim($inner) !== '' ? $inner : '';
    }
}
