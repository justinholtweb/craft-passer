<?php

namespace justinholtweb\passer\content\blocks;

use craft\helpers\Html;
use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * Tables.
 */
class TableBlock extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return ['core/table'];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        $inner = $this->inner($block);

        if ($inner !== '') {
            return $inner;
        }

        // Rebuild from attributes when the rendered markup is missing.
        $sections = [];

        foreach (['head' => 'thead', 'body' => 'tbody', 'foot' => 'tfoot'] as $key => $tag) {
            $rows = $block->attribute($key);

            if (!is_array($rows) || $rows === []) {
                continue;
            }

            $html = '';

            foreach ($rows as $row) {
                $cells = is_array($row) ? ($row['cells'] ?? []) : [];

                if (!is_array($cells)) {
                    continue;
                }

                $cellHtml = '';

                foreach ($cells as $cell) {
                    $cellTag = ($cell['tag'] ?? 'td') === 'th' ? 'th' : 'td';
                    $attributes = [];

                    if (!empty($cell['align'])) {
                        $attributes['style'] = 'text-align:' . $cell['align'];
                    }

                    if (!empty($cell['colspan'])) {
                        $attributes['colspan'] = (string)$cell['colspan'];
                    }

                    if (!empty($cell['rowspan'])) {
                        $attributes['rowspan'] = (string)$cell['rowspan'];
                    }

                    // Cell content is stored as rich text, so it is emitted as-is.
                    $cellHtml .= $this->tag($cellTag, $attributes, (string)($cell['content'] ?? ''));
                }

                $html .= $this->tag('tr', [], $cellHtml);
            }

            if ($html !== '') {
                $sections[] = $this->tag($tag, [], $html);
            }
        }

        if ($sections === []) {
            return null;
        }

        $table = $this->tag('table', [], implode('', $sections));
        $caption = (string)$block->attribute('caption', '');

        if ($caption !== '') {
            $table .= $this->tag('figcaption', [], $caption);
        }

        return $this->tag('figure', ['class' => $this->classes($block, 'wp-block-table')], $table);
    }
}
