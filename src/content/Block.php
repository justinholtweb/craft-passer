<?php

namespace justinholtweb\passer\content;

/**
 * One parsed Gutenberg block.
 */
class Block
{
    /**
     * @param string $name Fully-qualified block name, e.g. `core/paragraph`. Empty for the
     *        classic-editor HTML that sits between blocks.
     * @param array<string, mixed> $attributes The block's JSON attributes.
     * @param string $innerHtml The block's own markup, with child placeholders removed.
     * @param Block[] $innerBlocks
     * @param string $originalHtml The complete markup including comments, kept so an
     *        untransformable block can be passed through verbatim rather than lost.
     */
    public function __construct(
        public string $name = '',
        public array $attributes = [],
        public string $innerHtml = '',
        public array $innerBlocks = [],
        public string $originalHtml = '',
    ) {
    }

    public function isClassic(): bool
    {
        return $this->name === '';
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * The short block name without its namespace: `paragraph` for `core/paragraph`.
     */
    public function shortName(): string
    {
        $slash = strpos($this->name, '/');

        return $slash === false ? $this->name : substr($this->name, $slash + 1);
    }

    public function isCore(): bool
    {
        return str_starts_with($this->name, 'core/');
    }
}
