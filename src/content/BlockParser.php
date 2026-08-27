<?php

namespace justinholtweb\passer\content;

use craft\helpers\Json;

/**
 * Parses Gutenberg's block-comment format out of `post_content`.
 *
 * WordPress stores blocks as HTML comments wrapping the block's own rendered markup:
 *
 *     <!-- wp:paragraph {"align":"center"} -->
 *     <p class="has-text-align-center">Hello</p>
 *     <!-- /wp:paragraph -->
 *
 * with self-closing blocks written as `<!-- wp:spacer {"height":40} /-->`. Content between
 * blocks — everything on a site that was migrated from the classic editor — has no comments at
 * all, and is returned as a nameless block so it is never silently dropped.
 *
 * This is a hand-written scanner rather than a regex because blocks nest arbitrarily
 * (`core/columns` inside `core/group` inside `core/cover`) and a regex cannot match balanced
 * delimiters.
 */
class BlockParser
{
    /**
     * @return Block[]
     */
    public function parse(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        $offset = 0;
        $closeStart = null;
        $blocks = $this->parseUntil($content, $offset, null, $closeStart);

        return $this->mergeClassic($blocks);
    }

    /**
     * True when the content uses the block editor at all.
     */
    public function hasBlocks(string $content): bool
    {
        return str_contains($content, '<!-- wp:');
    }

    /**
     * Parse blocks from $offset until the closing comment for $closeName, or end of input.
     *
     * `$closeStart` comes back as the offset of the `<!--` that closed this level, so the caller
     * can take the inner HTML without the closing delimiter in it. It stays null when the level
     * ended at end of input instead.
     *
     * @return Block[]
     */
    private function parseUntil(string $content, int &$offset, ?string $closeName, ?int &$closeStart = null): array
    {
        $closeStart = null;

        $blocks = [];
        $length = strlen($content);

        while ($offset < $length) {
            $commentStart = strpos($content, '<!-- ', $offset);

            if ($commentStart === false) {
                $this->pushClassic($blocks, substr($content, $offset));
                $offset = $length;
                break;
            }

            $commentEnd = strpos($content, '-->', $commentStart);

            if ($commentEnd === false) {
                $this->pushClassic($blocks, substr($content, $offset));
                $offset = $length;
                break;
            }

            $comment = substr($content, $commentStart + 5, $commentEnd - $commentStart - 5);
            $comment = trim($comment);

            // A comment that is not a block delimiter is just content.
            if (!str_starts_with($comment, 'wp:') && !str_starts_with($comment, '/wp:')) {
                $offset = $commentEnd + 3;
                continue;
            }

            // Everything before the delimiter is classic content.
            $this->pushClassic($blocks, substr($content, $offset, $commentStart - $offset));

            if (str_starts_with($comment, '/wp:')) {
                // Closing delimiters omit the `core/` namespace exactly as opening ones do, so
                // they have to be qualified the same way or nothing ever matches.
                $name = trim(substr($comment, 4));

                if ($name !== '' && !str_contains($name, '/')) {
                    $name = 'core/' . $name;
                }

                if ($closeName !== null && $name === $closeName) {
                    // The caller owns the delimiter: it is stepped past here, and its position
                    // handed back so the caller's inner HTML can stop short of it.
                    $closeStart = $commentStart;
                    $offset = $commentEnd + 3;

                    return $blocks;
                }

                // A stray close with no matching open. Skip it rather than treating the rest of
                // the post as its body — malformed block markup is common after a plugin
                // migration and should not swallow the page.
                $offset = $commentEnd + 3;
                continue;
            }

            $selfClosing = str_ends_with($comment, '/');
            $body = trim($selfClosing ? substr($comment, 3, -1) : substr($comment, 3));

            [$name, $attributes] = $this->splitDelimiter($body);
            $delimiterStart = $commentStart;
            $offset = $commentEnd + 3;

            if ($selfClosing) {
                $blocks[] = new Block(
                    name: $name,
                    attributes: $attributes,
                    innerHtml: '',
                    innerBlocks: [],
                    originalHtml: substr($content, $delimiterStart, $offset - $delimiterStart),
                );

                continue;
            }

            $innerStart = $offset;
            $innerClose = null;
            $innerBlocks = $this->parseUntil($content, $offset, $name, $innerClose);
            $innerEnd = $innerClose ?? $offset;
            $innerRaw = substr($content, $innerStart, max(0, $innerEnd - $innerStart));

            // Only real blocks are children. Classic fragments inside a container are the
            // container's own markup — its wrapper div, its whitespace — and stay in innerHtml,
            // which stripNestedDelimiters leaves them in.
            $realChildren = array_values(array_filter($innerBlocks, static fn(Block $b) => !$b->isClassic()));

            $blocks[] = new Block(
                name: $name,
                attributes: $attributes,
                innerHtml: $this->stripNestedDelimiters($innerRaw, $realChildren),
                innerBlocks: $realChildren,
                originalHtml: substr($content, $delimiterStart, $offset - $delimiterStart),
            );
        }

        return $blocks;
    }

    /**
     * Split `paragraph {"align":"center"}` into a qualified name and decoded attributes.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function splitDelimiter(string $body): array
    {
        $brace = strpos($body, '{');

        if ($brace === false) {
            $name = trim($body);
            $attributes = [];
        } else {
            $name = trim(substr($body, 0, $brace));
            $json = trim(substr($body, $brace));
            $decoded = Json::decodeIfJson($json);
            $attributes = is_array($decoded) ? $decoded : [];
        }

        // Core blocks are written without their namespace in the delimiter.
        if ($name !== '' && !str_contains($name, '/')) {
            $name = 'core/' . $name;
        }

        return [$name, $attributes];
    }

    /**
     * Remove nested blocks' markup from a parent's inner HTML, leaving only the parent's own.
     *
     * A `core/columns` block's inner HTML is `<div class="wp-block-columns">` plus each column's
     * full markup; keeping the children inline would render them twice once the children are
     * also transformed.
     *
     * @param Block[] $innerBlocks
     */
    private function stripNestedDelimiters(string $raw, array $innerBlocks): string
    {
        foreach ($innerBlocks as $block) {
            if ($block->isClassic() || $block->originalHtml === '') {
                continue;
            }

            $position = strpos($raw, $block->originalHtml);

            if ($position !== false) {
                $raw = substr_replace($raw, '', $position, strlen($block->originalHtml));
            }
        }

        return $raw;
    }

    /**
     * @param Block[] $blocks
     */
    private function pushClassic(array &$blocks, string $html): void
    {
        if (trim($html) === '') {
            return;
        }

        $blocks[] = new Block(name: '', innerHtml: $html, originalHtml: $html);
    }

    /**
     * Fold consecutive classic fragments into one block.
     *
     * Parsing produces a classic fragment for the whitespace between every pair of blocks;
     * without this, a normal post yields fifty near-empty blocks.
     *
     * @param Block[] $blocks
     * @return Block[]
     */
    private function mergeClassic(array $blocks): array
    {
        $out = [];

        foreach ($blocks as $block) {
            $previous = end($out);

            if ($block->isClassic() && $previous instanceof Block && $previous->isClassic()) {
                $previous->innerHtml .= $block->innerHtml;
                $previous->originalHtml .= $block->originalHtml;
                continue;
            }

            $out[] = $block;
        }

        return array_values(array_filter($out, static fn(Block $b) => !$b->isClassic() || trim($b->innerHtml) !== ''));
    }
}
