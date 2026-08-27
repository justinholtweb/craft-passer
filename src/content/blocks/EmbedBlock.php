<?php

namespace justinholtweb\passer\content\blocks;

use craft\helpers\Html;
use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * Embeds — YouTube, Vimeo, Twitter, Spotify and the twenty-odd other providers Gutenberg has a
 * dedicated block for.
 *
 * The embed URL is what matters and is the one thing every variant has. WordPress resolves it to
 * provider markup at render time via oEmbed, so the stored block usually contains nothing but a
 * `url` attribute; producing an anchor is both the honest and the useful result, because a
 * Craft site will render it with its own embed handling.
 */
class EmbedBlock extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return [
            'core/embed',
            'core-embed/youtube',
            'core-embed/vimeo',
            'core-embed/twitter',
            'core-embed/instagram',
            'core-embed/facebook',
            'core-embed/spotify',
            'core-embed/soundcloud',
            'core-embed/flickr',
            'core-embed/tiktok',
            'core-embed/wordpress',
            'core-embed/dailymotion',
            'core-embed/reddit',
            'core-embed/slideshare',
            'core-embed/issuu',
            'core-embed/scribd',
            'core-embed/mixcloud',
            'core-embed/pinterest',
            'core-embed/imgur',
            'core-embed/animoto',
            'core-embed/cloudup',
            'core-embed/crowdsignal',
            'core-embed/kickstarter',
            'core-embed/screencast',
            'core-embed/speaker-deck',
            'core-embed/ted',
            'core-embed/tumblr',
            'core-embed/videopress',
            'core-embed/wordpress-tv',
            'core-embed/amazon-kindle',
            'core-embed/pocket-casts',
            'core-embed/wolfram-cloud',
            'core-embed/bluesky',
        ];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        $url = $block->attribute('url');

        if (!is_string($url) || $url === '') {
            $url = $this->urlFromMarkup($block->innerHtml);
        }

        if ($url === null || $url === '') {
            return $this->inner($block) ?: null;
        }

        $provider = (string)$block->attribute('providerNameSlug', $this->providerFromName($block->name));

        // An oEmbed-style figure with the URL as a plain link: readable if nothing renders it,
        // and trivially detectable by a template that wants to.
        return $this->tag('figure', [
            'class' => $this->classes($block, 'wp-block-embed', $provider !== '' ? 'is-provider-' . $provider : ''),
            'data-embed-url' => $url,
            'data-embed-provider' => $provider !== '' ? $provider : null,
        ], $this->tag('a', ['href' => $url], Html::encode($url)));
    }

    private function urlFromMarkup(string $html): ?string
    {
        // The stored markup is often nothing but the bare URL on its own line.
        $text = trim(strip_tags($html));

        if (preg_match('#^https?://\S+$#', $text)) {
            return $text;
        }

        if (preg_match('/<iframe[^>]*\bsrc=(["\'])(.*?)\1/is', $html, $m)) {
            return $m[2];
        }

        if (preg_match('/<a[^>]*\bhref=(["\'])(.*?)\1/is', $html, $m)) {
            return $m[2];
        }

        return null;
    }

    private function providerFromName(string $name): string
    {
        return str_starts_with($name, 'core-embed/') ? substr($name, 11) : '';
    }
}
