<?php

namespace justinholtweb\passer\content;

use craft\helpers\Html;
use justinholtweb\passer\importers\RunContext;
use justinholtweb\passer\services\IdMap;

/**
 * Expands WordPress shortcodes into markup.
 *
 * A shortcode is a function call stored in content. WordPress runs the function at render time,
 * so the database holds `[gallery ids="4,5,6"]` and the reader sees a gallery. Migrate the
 * content without expanding them and every one becomes literal text on the page — which is the
 * single most visible way a WordPress migration goes wrong.
 *
 * Only shortcodes whose meaning is knowable without the originating plugin are expanded. The
 * rest are removed or left alone according to a deliberate rule, and every distinct one is
 * counted so the run report can list what needs attention.
 */
class ShortcodeExpander
{
    /** @var array<string, int> Shortcode name => times seen but not expanded. */
    private array $unhandled = [];

    /** @var array<string, callable(array<string, string>, string, RunContext): string> */
    private array $handlers = [];

    public function __construct()
    {
        $this->registerBuiltIns();
    }

    /**
     * @return array<string, int>
     */
    public function unhandledShortcodes(): array
    {
        arsort($this->unhandled);

        return $this->unhandled;
    }

    public function resetStats(): void
    {
        $this->unhandled = [];
    }

    /**
     * @param callable(array<string, string>, string, RunContext): string $handler
     */
    public function register(string $name, callable $handler): void
    {
        $this->handlers[$name] = $handler;
    }

    public function expand(string $content, RunContext $context): string
    {
        if (!str_contains($content, '[')) {
            return $content;
        }

        // Two passes: enclosing shortcodes first, so `[caption]...[/caption]` is consumed whole
        // before the self-closing pass would otherwise eat its opening tag alone.
        $content = $this->expandEnclosing($content, $context);

        return $this->expandSelfClosing($content, $context);
    }

    private function expandEnclosing(string $content, RunContext $context): string
    {
        $pattern = '/\[(\[?)([a-zA-Z0-9_-]+)\b([^\]\/]*)\](.*?)\[\/\2\](\]?)/s';

        return preg_replace_callback($pattern, function (array $m) use ($context) {
            // `[[shortcode]]` is WordPress's escape for a literal shortcode.
            if ($m[1] === '[' && $m[5] === ']') {
                return substr($m[0], 1, -1);
            }

            $name = strtolower($m[2]);
            $attributes = $this->parseAttributes($m[3]);
            $inner = $m[4];

            if (isset($this->handlers[$name])) {
                return ($this->handlers[$name])($attributes, $inner, $context);
            }

            $this->unhandled[$name] = ($this->unhandled[$name] ?? 0) + 1;

            // Unknown enclosing shortcode: keep the content, drop the wrapper. The content is
            // real; the wrapper is a call to code that no longer exists.
            return $this->expand($inner, $context);
        }, $content) ?? $content;
    }

    private function expandSelfClosing(string $content, RunContext $context): string
    {
        $pattern = '/\[(\[?)([a-zA-Z0-9_-]+)\b([^\]]*?)(\/?)\](\]?)/s';

        return preg_replace_callback($pattern, function (array $m) use ($context) {
            if ($m[1] === '[' && $m[5] === ']') {
                return substr($m[0], 1, -1);
            }

            $name = strtolower($m[2]);
            $attributes = $this->parseAttributes($m[3]);

            if (isset($this->handlers[$name])) {
                return ($this->handlers[$name])($attributes, '', $context);
            }

            $this->unhandled[$name] = ($this->unhandled[$name] ?? 0) + 1;

            // Unknown self-closing shortcode: leave it in place. It is a marker of something
            // that needs a decision, and silently deleting it hides that.
            return $m[0];
        }, $content) ?? $content;
    }

    /**
     * Parse `id="4" size=large align='center' novalue` into an associative array.
     *
     * Positional (valueless) attributes are keyed by their index, which is how WordPress's own
     * `shortcode_parse_atts()` behaves and what shortcodes like `[caption id="x" width="300"]`
     * rely on.
     *
     * @return array<string, string>
     */
    public function parseAttributes(string $text): array
    {
        $attributes = [];
        $index = 0;

        // Non-breaking spaces and smart quotes get into shortcodes through the visual editor and
        // stop naive parsing dead.
        $text = str_replace(
            ["\u{00a0}", "\u{2018}", "\u{2019}", "\u{201c}", "\u{201d}"],
            [' ', "'", "'", '"', '"'],
            $text
        );

        $pattern = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)'
            . '|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)'
            . '|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)'
            . '|"([^"]*)"(?:\s|$)'
            . '|\'([^\']*)\'(?:\s|$)'
            . '|(\S+)(?:\s|$)/';

        if (!preg_match_all($pattern, trim($text), $matches, PREG_SET_ORDER)) {
            return $attributes;
        }

        foreach ($matches as $match) {
            if (!empty($match[1])) {
                $attributes[strtolower($match[1])] = $match[2];
            } elseif (!empty($match[3])) {
                $attributes[strtolower($match[3])] = $match[4];
            } elseif (!empty($match[5])) {
                $attributes[strtolower($match[5])] = $match[6];
            } elseif (isset($match[7]) && $match[7] !== '') {
                $attributes[(string)$index++] = $match[7];
            } elseif (isset($match[8]) && $match[8] !== '') {
                $attributes[(string)$index++] = $match[8];
            } elseif (isset($match[9]) && $match[9] !== '') {
                $attributes[(string)$index++] = $match[9];
            }
        }

        return $attributes;
    }

    // -----------------------------------------------------------------------------------------
    // Built-in handlers
    // -----------------------------------------------------------------------------------------

    private function registerBuiltIns(): void
    {
        $this->register('caption', $this->caption(...));
        $this->register('wp_caption', $this->caption(...));
        $this->register('gallery', $this->gallery(...));
        $this->register('embed', $this->embed(...));
        $this->register('audio', $this->media(...));
        $this->register('video', $this->media(...));
        $this->register('playlist', $this->playlist(...));

        // Layout shortcodes from the page builders. Their wrappers carry no information a Craft
        // site can use, but their content is the page.
        foreach ([
            'vc_row', 'vc_column', 'vc_column_text', 'vc_row_inner', 'vc_column_inner',
            'vc_section', 'vc_tta_section', 'vc_tta_tabs',
            'et_pb_section', 'et_pb_row', 'et_pb_column', 'et_pb_text', 'et_pb_blurb',
            'fusion_builder_container', 'fusion_builder_row', 'fusion_builder_column',
            'fusion_text', 'row', 'column', 'col', 'one_half', 'one_third', 'one_fourth',
            'two_third', 'three_fourth', 'span',
        ] as $name) {
            $this->register($name, $this->unwrapContent(...));
        }

        $this->register('button', $this->button(...));
        $this->register('vc_btn', $this->button(...));
        $this->register('et_pb_button', $this->button(...));
    }

    /**
     * `[caption id="attachment_42" align="alignnone" width="300"]<img …/> The caption[/caption]`
     */
    private function caption(array $attributes, string $inner, RunContext $context): string
    {
        // The image markup comes first and the caption is whatever text follows it.
        if (preg_match('#^(.*?<(?:img|a)\b.*?(?:>|</a>))(.*)$#is', trim($inner), $m)) {
            $media = trim($m[1]);
            $caption = trim($m[2]);
        } else {
            $media = trim($inner);
            $caption = '';
        }

        $classes = ['wp-caption'];
        $align = $attributes['align'] ?? '';

        if ($align !== '') {
            $classes[] = $align;
        }

        $figure = $media;

        if ($caption !== '') {
            $figure .= Html::tag('figcaption', $caption, ['class' => 'wp-caption-text']);
        }

        $style = null;
        $width = $attributes['width'] ?? '';

        if (is_numeric($width)) {
            $style = 'max-width:' . (int)$width . 'px';
        }

        return Html::tag('figure', $figure, array_filter([
            'class' => implode(' ', $classes),
            'style' => $style,
        ]));
    }

    /**
     * `[gallery ids="4,5,6" columns="3"]`
     */
    private function gallery(array $attributes, string $inner, RunContext $context): string
    {
        $ids = array_values(array_filter(array_map(
            'intval',
            preg_split('/\s*,\s*/', (string)($attributes['ids'] ?? '')) ?: []
        )));

        if ($ids === []) {
            return '';
        }

        $figures = [];

        foreach ($ids as $wpId) {
            $assetId = $context->map->lookup(IdMap::KEY_ATTACHMENT, $wpId);

            if ($assetId === null) {
                continue;
            }

            $asset = \craft\elements\Asset::find()->id($assetId)->one();

            if ($asset === null) {
                continue;
            }

            $figures[] = Html::tag('figure', Html::tag('img', '', [
                'src' => $asset->getUrl(),
                'alt' => $asset->alt ?? '',
                'data-entity-type' => 'craft\\elements\\Asset',
                'data-entity-id' => (string)$asset->id,
            ]));
        }

        if ($figures === []) {
            return '';
        }

        $columns = $attributes['columns'] ?? '3';

        return Html::tag('figure', "\n" . implode("\n", $figures) . "\n", [
            'class' => 'wp-block-gallery columns-' . (int)$columns,
        ]);
    }

    private function embed(array $attributes, string $inner, RunContext $context): string
    {
        $url = trim(strip_tags($inner));

        if ($url === '') {
            return '';
        }

        return Html::tag('figure', Html::tag('a', Html::encode($url), ['href' => $url]), [
            'class' => 'wp-block-embed',
            'data-embed-url' => $url,
        ]);
    }

    private function media(array $attributes, string $inner, RunContext $context): string
    {
        $src = $attributes['src'] ?? $attributes['mp4'] ?? $attributes['mp3'] ?? $attributes['0'] ?? '';

        if ($src === '') {
            return '';
        }

        // Guessing from the extension is unreliable, so the shortcode name is not available here
        // — an <audio> tag on a video file still plays its sound, which is a better failure than
        // nothing at all.
        $tag = preg_match('/\.(mp4|m4v|webm|ogv|mov)(\?|$)/i', $src) ? 'video' : 'audio';

        return Html::tag($tag, '', ['controls' => true, 'src' => $src]);
    }

    private function playlist(array $attributes, string $inner, RunContext $context): string
    {
        $ids = array_values(array_filter(array_map(
            'intval',
            preg_split('/\s*,\s*/', (string)($attributes['ids'] ?? '')) ?: []
        )));

        $items = [];

        foreach ($ids as $wpId) {
            $assetId = $context->map->lookup(IdMap::KEY_ATTACHMENT, $wpId);
            $asset = $assetId !== null ? \craft\elements\Asset::find()->id($assetId)->one() : null;

            if ($asset !== null) {
                $items[] = Html::tag('li', Html::tag('a', Html::encode($asset->filename), ['href' => $asset->getUrl()]));
            }
        }

        return $items === [] ? '' : Html::tag('ul', implode('', $items), ['class' => 'wp-playlist']);
    }

    /**
     * Keep the content, drop the wrapper.
     */
    private function unwrapContent(array $attributes, string $inner, RunContext $context): string
    {
        return $this->expand($inner, $context);
    }

    private function button(array $attributes, string $inner, RunContext $context): string
    {
        $url = $attributes['link'] ?? $attributes['url'] ?? $attributes['href'] ?? $attributes['button_url'] ?? '';

        // WPBakery encodes its link attribute as `url:https%3A%2F%2F…|title:Text|target:_blank`.
        if (str_contains($url, 'url:')) {
            $parts = [];

            foreach (explode('|', $url) as $segment) {
                [$key, $value] = array_pad(explode(':', $segment, 2), 2, '');
                $parts[$key] = rawurldecode($value);
            }

            $url = $parts['url'] ?? '';
            $inner = $inner !== '' ? $inner : ($parts['title'] ?? '');
        }

        $label = trim(strip_tags($inner)) ?: ($attributes['title'] ?? $attributes['button_text'] ?? 'Read more');

        if ($url === '') {
            return Html::encode($label);
        }

        return Html::tag('a', Html::encode($label), ['href' => $url, 'class' => 'wp-block-button__link']);
    }
}
