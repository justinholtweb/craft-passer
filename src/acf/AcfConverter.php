<?php

namespace justinholtweb\passer\acf;

use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Json;
use justinholtweb\passer\helpers\WpSerialize;
use justinholtweb\passer\importers\RunContext;
use justinholtweb\passer\Plugin;
use justinholtweb\passer\services\IdMap;

/**
 * Converts ACF values into values Craft fields will accept.
 *
 * The conversions are grouped by what the value *is*, not by what ACF calls it, because ACF has
 * thirty-odd field types and about eight underlying storage shapes.
 */
class AcfConverter extends Component
{
    /** @var array<string, AcfAdapterInterface> */
    private array $adapters = [];

    private bool $registered = false;

    /** @var array<string, int> ACF types encountered with no conversion, and how often. */
    private array $unhandled = [];

    /**
     * @return array<string, int>
     */
    public function unhandledTypes(): array
    {
        arsort($this->unhandled);

        return $this->unhandled;
    }

    public function register(AcfAdapterInterface $adapter): void
    {
        foreach ($adapter::handles() as $type) {
            $this->adapters[$type] = $adapter;
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function convert(mixed $value, array $definition, RunContext $context): mixed
    {
        $this->registerAdapters();

        $type = (string)($definition['type'] ?? 'text');

        if (isset($this->adapters[$type])) {
            return $this->adapters[$type]->convert($value, $definition, $context);
        }

        return match ($type) {
            'text', 'textarea', 'email', 'url', 'password', 'oembed', 'color_picker', 'wysiwyg' =>
                $this->scalar($value),

            'number', 'range' => $this->number($value),

            'true_false' => (bool)$value,

            'select', 'radio', 'button_group', 'checkbox' => $this->choice($value, $definition),

            'date_picker', 'date_time_picker', 'time_picker' => $this->date($value, $type),

            'image', 'file' => $this->relation($value, IdMap::KEY_ATTACHMENT, $context, true),
            'gallery' => $this->relation($value, IdMap::KEY_ATTACHMENT, $context, false),

            'post_object', 'page_link' => $this->relation($value, IdMap::KEY_POST, $context, true),
            'relationship' => $this->relation($value, IdMap::KEY_POST, $context, false),

            'taxonomy' => $this->relation($value, IdMap::KEY_TERM, $context, false),
            'user' => $this->relation($value, IdMap::KEY_USER, $context, false),

            'link' => $this->link($value),
            'google_map' => $this->map($value),

            // Layout-only field types hold nothing.
            'tab', 'message', 'accordion', 'clone' => null,

            default => $this->unknown($type, $value),
        };
    }

    // -----------------------------------------------------------------------------------------
    // Shape conversions
    // -----------------------------------------------------------------------------------------

    private function scalar(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return is_array($value) ? Json::encode($value) : null;
        }

        return (string)$value;
    }

    private function number(mixed $value): int|float|null
    {
        if (!is_numeric($value)) {
            return null;
        }

        // ACF stores every number as a string, so the distinction between 3 and 3.0 is lost
        // there and has to be recovered from the digits.
        return str_contains((string)$value, '.') ? (float)$value : (int)$value;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function choice(mixed $value, array $definition): mixed
    {
        $value = WpSerialize::unserialize($value);

        // A multi-select stores an array; a single select stores a scalar. Craft's Dropdown and
        // Checkboxes fields want exactly that distinction preserved.
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string)$value;
    }

    private function date(mixed $value, string $type): ?\DateTime
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        // ACF's storage format depends on the picker: `Ymd` for dates, `Y-m-d H:i:s` for
        // datetimes, `H:i:s` for times. Formats are tried in order of specificity.
        $formats = match ($type) {
            'time_picker' => ['H:i:s', 'H:i'],
            'date_time_picker' => ['Y-m-d H:i:s', 'Y-m-d H:i', 'Ymd H:i:s'],
            default => ['Ymd', 'Y-m-d', 'd/m/Y', 'm/d/Y'],
        };

        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat($format, $value);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        try {
            return new \DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve WordPress IDs to Craft element IDs through the map.
     *
     * @return int[]|int|null
     */
    private function relation(mixed $value, string $sourceKey, RunContext $context, bool $single): array|int|null
    {
        $value = WpSerialize::unserialize($value);
        $ids = [];

        foreach ((array)$value as $item) {
            // ACF can store either a bare ID or the whole object, depending on the field's
            // return format at the time the value was saved.
            $wpId = is_array($item) ? ($item['ID'] ?? $item['id'] ?? $item['term_id'] ?? null) : $item;

            if (!is_numeric($wpId)) {
                continue;
            }

            $destId = $context->map->lookup($sourceKey, (int)$wpId);

            if ($destId !== null) {
                $ids[] = $destId;
            }
        }

        if ($ids === []) {
            return $single ? null : [];
        }

        return $single ? $ids[0] : $ids;
    }

    /**
     * ACF's link field stores `['title' => …, 'url' => …, 'target' => …]`.
     *
     * @return array<string, string>|null
     */
    private function link(mixed $value): ?array
    {
        $value = WpSerialize::unserialize($value);

        if (is_string($value) && $value !== '') {
            return ['url' => $value, 'title' => '', 'target' => ''];
        }

        if (!is_array($value) || empty($value['url'])) {
            return null;
        }

        return [
            'url' => (string)$value['url'],
            'title' => (string)($value['title'] ?? ''),
            'target' => (string)($value['target'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function map(mixed $value): ?array
    {
        $value = WpSerialize::unserialize($value);

        if (!is_array($value) || (!isset($value['lat']) && !isset($value['address']))) {
            return null;
        }

        return [
            'lat' => isset($value['lat']) ? (float)$value['lat'] : null,
            'lng' => isset($value['lng']) ? (float)$value['lng'] : null,
            'address' => (string)($value['address'] ?? ''),
        ];
    }

    private function unknown(string $type, mixed $value): mixed
    {
        $this->unhandled[$type] = ($this->unhandled[$type] ?? 0) + 1;

        // Better an opaque string than nothing: the data is preserved and the report names the
        // type so it can be handled deliberately.
        return is_scalar($value) ? (string)$value : Json::encode(WpSerialize::unserialize($value));
    }

    private function registerAdapters(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        // wp-import's own adapters, where installed and where Passer has nothing better. Its
        // adapters take the same shape of input — a raw meta value and a field definition — so
        // the bridge is a thin one.
        if (Plugin::getInstance()->getSettings()->useWpImportBridge) {
            foreach (Plugin::getInstance()->bridge->acfAdapters() as $type => $adapter) {
                if (!isset($this->adapters[$type])) {
                    $this->adapters[$type] = $adapter;
                }
            }
        }
    }
}
