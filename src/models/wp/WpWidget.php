<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;

/**
 * A configured widget instance.
 *
 * WordPress stores widgets in two halves that must be read together: `sidebars_widgets` says
 * which widget instances sit in which sidebar and in what order, and `widget_<type>` holds each
 * instance's settings keyed by its number. Neither half is meaningful alone.
 *
 * Block-based widgets (WordPress 5.8+) are a third case: the whole sidebar is a single
 * `block` widget whose content is Gutenberg markup, which Passer routes through the same block
 * parser it uses for post content.
 */
class WpWidget extends Model
{
    /** @var string The widget type, e.g. `text`, `nav_menu`, `block`, `recent-posts`. */
    public string $type = '';

    /** @var int The instance number within its type. */
    public int $number = 0;

    /** @var string The sidebar this instance sits in, e.g. `sidebar-1`, `footer-2`. */
    public string $sidebar = '';

    /** @var string Human name of the sidebar, when the theme registered one. */
    public string $sidebarName = '';

    /** @var int Position within the sidebar. */
    public int $order = 0;

    /** @var array<string, mixed> The instance's settings as WordPress stored them. */
    public array $settings = [];

    public function title(): string
    {
        return (string)($this->settings['title'] ?? '');
    }

    /**
     * The widget's body, for the widget types that have one.
     */
    public function content(): string
    {
        foreach (['text', 'content', 'html'] as $key) {
            if (!empty($this->settings[$key]) && is_string($this->settings[$key])) {
                return $this->settings[$key];
            }
        }

        return '';
    }

    public function isBlockWidget(): bool
    {
        return $this->type === 'block';
    }

    public function instanceId(): string
    {
        return $this->type . '-' . $this->number;
    }
}
