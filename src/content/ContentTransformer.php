<?php

namespace justinholtweb\passer\content;

use Craft;
use craft\base\Component;
use craft\helpers\Html;
use justinholtweb\passer\bridge\WpImportBridge;
use justinholtweb\passer\importers\RunContext;
use justinholtweb\passer\Plugin;

/**
 * Turns WordPress `post_content` into markup a Craft field can hold.
 *
 * The order matters and is not obvious. Blocks are parsed first, because shortcodes appear
 * inside block bodies and expanding them first would corrupt the block delimiters. URL rewriting
 * comes last, because both the block transformers and the shortcode handlers emit URLs of their
 * own that need rewriting too.
 */
class ContentTransformer extends Component
{
    private BlockParser $parser;
    private ShortcodeExpander $shortcodes;
    private UrlRewriter $urls;

    /** @var array<string, BlockTransformerInterface> Block name => transformer. */
    private array $transformers = [];

    private bool $registered = false;

    /** @var array<string, int> Block names encountered that nothing could transform. */
    private array $unhandled = [];

    public function init(): void
    {
        parent::init();

        $this->parser = new BlockParser();
        $this->shortcodes = new ShortcodeExpander();
        $this->urls = new UrlRewriter();
    }

    /**
     * @return array<string, int> Unhandled block names and how often each was seen.
     */
    public function unhandledBlocks(): array
    {
        arsort($this->unhandled);

        return $this->unhandled;
    }

    public function resetStats(): void
    {
        $this->unhandled = [];
    }

    /**
     * The main entry point: WordPress content in, Craft-ready HTML out.
     */
    public function toHtml(string $content, RunContext $context): string
    {
        $content = $this->normalize($content);

        if ($this->parser->hasBlocks($content)) {
            $html = $this->renderBlocks($this->parser->parse($content), $context);
        } else {
            // Classic-editor content has no paragraph markup at all: WordPress adds it at render
            // time with wpautop(), and turns bare URLs into links with make_clickable(). Both
            // are applied here, once and permanently — otherwise a decade of posts arrives as
            // one giant paragraph full of unlinked, un-rewritable URLs.
            $html = $this->autop($this->makeClickable($content));
        }

        if ($context->plan->expandShortcodes) {
            $html = $this->shortcodes->expand($html, $context);
        }

        if ($context->plan->rewriteUrls) {
            $html = $this->urls->rewrite($html, $context);
        }

        return trim($html);
    }

    /**
     * Render a parsed block tree to HTML.
     *
     * @param Block[] $blocks
     */
    public function renderBlocks(array $blocks, RunContext $context): string
    {
        $out = [];

        foreach ($blocks as $block) {
            $out[] = $this->renderBlock($block, $context);
        }

        return implode("\n\n", array_filter($out, static fn(string $s) => trim($s) !== ''));
    }

    public function renderBlock(Block $block, RunContext $context): string
    {
        if ($block->isClassic()) {
            return $this->autop($block->innerHtml);
        }

        $transformer = $this->transformerFor($block->name);

        if ($transformer !== null) {
            $html = $transformer->toHtml($block, $context, $this);

            if ($html !== null) {
                return $html;
            }
        }

        // Nothing knows this block. Its rendered markup is still in `innerHtml` — Gutenberg
        // stores the rendered form alongside the attributes for exactly this reason — so the
        // content survives even though its structure does not.
        $this->unhandled[$block->name] = ($this->unhandled[$block->name] ?? 0) + 1;

        $inner = trim($block->innerHtml);

        if ($inner !== '') {
            return $inner . ($block->innerBlocks !== [] ? "\n" . $this->renderBlocks($block->innerBlocks, $context) : '');
        }

        return $this->renderBlocks($block->innerBlocks, $context);
    }

    /**
     * @return Block[]
     */
    public function parse(string $content): array
    {
        return $this->parser->parse($this->normalize($content));
    }

    public function hasBlocks(string $content): bool
    {
        return $this->parser->hasBlocks($content);
    }

    public function shortcodeExpander(): ShortcodeExpander
    {
        return $this->shortcodes;
    }

    public function urlRewriter(): UrlRewriter
    {
        return $this->urls;
    }

    // -----------------------------------------------------------------------------------------
    // Transformer registry
    // -----------------------------------------------------------------------------------------

    private function transformerFor(string $name): ?BlockTransformerInterface
    {
        $this->registerTransformers();

        return $this->transformers[$name] ?? null;
    }

    public function register(BlockTransformerInterface $transformer): void
    {
        foreach ($transformer::handles() as $name) {
            $this->transformers[$name] = $transformer;
        }
    }

    private function registerTransformers(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        foreach ($this->builtInTransformers() as $class) {
            /** @var BlockTransformerInterface $transformer */
            $transformer = new $class();
            $this->register($transformer);
        }

        // wp-import's own block transformers, where they exist and cover a block Passer does
        // not. Passer's own always win, because they are written against this pipeline.
        if (Plugin::getInstance()->getSettings()->useWpImportBridge) {
            foreach (Plugin::getInstance()->bridge->blockTransformers() as $name => $transformer) {
                if (!isset($this->transformers[$name])) {
                    $this->transformers[$name] = $transformer;
                }
            }
        }
    }

    /**
     * @return class-string<BlockTransformerInterface>[]
     */
    private function builtInTransformers(): array
    {
        return [
            blocks\TextBlocks::class,
            blocks\HeadingBlock::class,
            blocks\ListBlock::class,
            blocks\ImageBlock::class,
            blocks\GalleryBlock::class,
            blocks\EmbedBlock::class,
            blocks\QuoteBlock::class,
            blocks\CodeBlock::class,
            blocks\TableBlock::class,
            blocks\ButtonBlock::class,
            blocks\SeparatorBlock::class,
            blocks\ContainerBlocks::class,
            blocks\MediaTextBlock::class,
            blocks\FileBlock::class,
            blocks\ShortcodeBlock::class,
            blocks\PassthroughBlocks::class,
        ];
    }

    // -----------------------------------------------------------------------------------------
    // Text handling
    // -----------------------------------------------------------------------------------------

    private function normalize(string $content): string
    {
        // WordPress writes \r\n on Windows-authored posts, and the block parser's delimiter
        // matching is easier to reason about with one line ending.
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    /**
     * WordPress's `wpautop()`, near enough.
     *
     * Double newlines become paragraphs and single newlines become `<br>`, except inside block
     * level elements — which is the part that matters, because a classic post is a mixture of
     * bare text and pasted HTML and treating the HTML as prose destroys it.
     */
    public function autop(string $content, bool $lineBreaks = true): string
    {
        if (trim($content) === '') {
            return '';
        }

        $blockTags = 'address|article|aside|blockquote|details|div|dl|fieldset|figcaption|figure|'
            . 'footer|form|h[1-6]|header|hr|main|nav|ol|p|pre|section|table|ul|li|dd|dt|'
            . 'tbody|thead|tfoot|tr|td|th|caption|iframe|video|audio|script|style|noscript|'
            . 'object|embed|canvas|map|area|col|colgroup|legend|optgroup|option|select|textarea';

        $content = $content . "\n";

        // Protect pre/script/style content, whose whitespace is significant.
        $protected = [];
        $content = preg_replace_callback(
            '#<(pre|script|style|textarea)\b[^>]*>.*?</\1>#is',
            static function (array $m) use (&$protected) {
                $token = "\0PASSER" . count($protected) . "\0";
                $protected[$token] = $m[0];

                return $token;
            },
            $content
        ) ?? $content;

        // Standalone block tags get their own line so they are never wrapped in a paragraph.
        $content = preg_replace('!(<(?:' . $blockTags . ')\b[^>]*>)!i', "\n$1", $content) ?? $content;
        $content = preg_replace('!(</(?:' . $blockTags . ')>)!i', "$1\n\n", $content) ?? $content;
        $content = preg_replace("/\n\n+/", "\n\n", $content) ?? $content;

        $paragraphs = preg_split('/\n\s*\n/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';

        foreach ($paragraphs as $paragraph) {
            $trimmed = trim($paragraph);

            if ($trimmed === '') {
                continue;
            }

            // Something that is already a block element is left alone.
            if (preg_match('!^</?(?:' . $blockTags . ')\b!i', $trimmed)) {
                $out .= $trimmed . "\n";
                continue;
            }

            if ($lineBreaks) {
                $trimmed = preg_replace('/(?<!<br>)\n/', "<br>\n", $trimmed) ?? $trimmed;
            }

            $out .= '<p>' . $trimmed . "</p>\n";
        }

        foreach ($protected as $token => $original) {
            $out = str_replace($token, $original, $out);
        }

        return trim($out);
    }

    /**
     * Turn bare URLs into links, the way WordPress's `make_clickable()` does at render time.
     *
     * This matters beyond appearance: a URL that is only text is invisible to the URL rewriter,
     * so every bare link to the old site would survive the migration pointing at a domain that
     * is about to stop existing.
     */
    public function makeClickable(string $content): string
    {
        if (!str_contains($content, 'http') && !str_contains($content, 'www.')) {
            return $content;
        }

        // Anything already inside a tag or an existing link is left completely alone.
        $parts = preg_split('#(<a\b.*?</a>|<[^>]+>)#is', $content, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$content];
        $out = '';

        foreach ($parts as $index => $part) {
            // The odd indices are the captured tags and existing links.
            if ($index % 2 === 1) {
                $out .= $part;
                continue;
            }

            $out .= preg_replace_callback(
                '#(^|[\s(<])((?:https?://|www\.)[^\s<>"\']+[^\s<>"\'.,;:!?)\]])#i',
                static function (array $m): string {
                    $url = $m[2];
                    $href = str_starts_with(strtolower($url), 'www.') ? 'https://' . $url : $url;

                    return $m[1] . '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
                        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>';
                },
                $part
            ) ?? $part;
        }

        return $out;
    }

    /**
     * Strip every tag but the ones a plain-text field can usefully keep.
     */
    public function toPlainText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = preg_replace('#</(p|div|h[1-6]|li)>#i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = Html::decode($text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
