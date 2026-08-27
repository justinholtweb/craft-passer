<?php

namespace justinholtweb\passer\content\blocks;

use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;
use justinholtweb\passer\services\IdMap;

/**
 * Galleries.
 *
 * WordPress has had three gallery formats: the `[gallery]` shortcode, a `core/gallery` block
 * with an `ids` attribute, and — since 5.9 — a `core/gallery` block containing `core/image`
 * children. All three end up as one figure of images here, with every reference resolved through
 * the ID map so nothing points at the old domain.
 */
class GalleryBlock extends BaseBlockTransformer
{
    public static function handles(): array
    {
        return ['core/gallery'];
    }

    public function toHtml(Block $block, RunContext $context, ContentTransformer $transformer): ?string
    {
        $figures = [];

        // The modern shape: one core/image child per picture.
        foreach ($block->innerBlocks as $child) {
            if ($child->name !== 'core/image') {
                continue;
            }

            $rendered = $transformer->renderBlock($child, $context);

            if (trim($rendered) !== '') {
                $figures[] = $rendered;
            }
        }

        // The legacy shape: an `ids` attribute and nothing else.
        if ($figures === []) {
            foreach ($this->legacyIds($block) as $attachmentId) {
                $assetId = $context->map->lookup(IdMap::KEY_ATTACHMENT, $attachmentId);
                $asset = $assetId !== null ? \craft\elements\Asset::find()->id($assetId)->one() : null;

                if ($asset === null) {
                    continue;
                }

                $figures[] = $this->tag('figure', [], $this->tag('img', [
                    'src' => $asset->getUrl(),
                    'alt' => $asset->alt ?? '',
                    'data-entity-type' => 'craft\\elements\\Asset',
                    'data-entity-id' => (string)$asset->id,
                ]));
            }
        }

        if ($figures === []) {
            return $this->inner($block) ?: null;
        }

        $columns = $block->attribute('columns');
        $class = $this->classes($block, 'wp-block-gallery');

        if (is_numeric($columns)) {
            $class = trim($class . ' columns-' . (int)$columns);
        }

        return $this->tag('figure', ['class' => $class], "\n" . implode("\n", $figures) . "\n");
    }

    /**
     * @return int[]
     */
    private function legacyIds(Block $block): array
    {
        $ids = $block->attribute('ids');

        if (is_array($ids)) {
            return array_values(array_filter(array_map('intval', $ids)));
        }

        $images = $block->attribute('images');

        if (is_array($images)) {
            $out = [];

            foreach ($images as $image) {
                if (is_array($image) && isset($image['id']) && is_numeric($image['id'])) {
                    $out[] = (int)$image['id'];
                }
            }

            return $out;
        }

        return [];
    }
}
