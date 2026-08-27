<?php

namespace justinholtweb\passer\sources;

use craft\base\Model;

/**
 * What a given source mode can actually answer.
 *
 * The wizard reads this to grey out phases the chosen source cannot supply, and the planner
 * refuses to plan them. Without it, a WXR-based run would quietly import zero menus and report
 * success, which is the single most misleading thing a migration tool can do.
 */
class SourceCapabilities extends Model
{
    public bool $posts = false;
    public bool $postMeta = false;
    public bool $terms = false;
    public bool $termMeta = false;
    public bool $users = false;
    public bool $userMeta = false;
    public bool $comments = false;
    public bool $media = false;
    public bool $options = false;
    public bool $menus = false;
    public bool $widgets = false;
    public bool $commerce = false;
    public bool $forms = false;
    public bool $redirects = false;

    /** @var bool Whether counts are exact, or estimated from a paged listing. */
    public bool $exactCounts = false;

    /** @var bool Whether arbitrary post types are discoverable, rather than hard-coded. */
    public bool $discoversPostTypes = false;

    /** @var string[] Human-readable notes explaining what this source cannot do, and why. */
    public array $limitations = [];

    /**
     * Domains, keyed as the planner names them, that this source can supply.
     *
     * @return string[]
     */
    public function supportedDomains(): array
    {
        $map = [
            'users' => $this->users,
            'media' => $this->media,
            'taxonomies' => $this->terms,
            'content' => $this->posts,
            'comments' => $this->comments,
            'commerce' => $this->commerce,
            'menus' => $this->menus,
            'widgets' => $this->widgets,
            'forms' => $this->forms,
            'seo' => $this->postMeta,
            'redirects' => $this->redirects,
        ];

        return array_keys(array_filter($map));
    }

    public function supports(string $domain): bool
    {
        return in_array($domain, $this->supportedDomains(), true);
    }
}
