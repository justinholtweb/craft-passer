<?php

namespace justinholtweb\passer\acf;

use craft\base\Component;
use craft\helpers\Json;
use justinholtweb\passer\helpers\WpSerialize;
use justinholtweb\passer\models\wp\WpPost;
use justinholtweb\passer\sources\SourceInterface;

/**
 * Reads ACF's field groups and makes sense of its storage convention.
 *
 * ACF stores each value twice in `wp_postmeta`: once under the field's name (`subtitle`) holding
 * the value, and once under an underscored key (`_subtitle`) holding the field's **key**
 * (`field_5f3a…`). The second row is the only link back to the field's definition, and therefore
 * the only way to know whether `42` is a number, a post ID, or the index of a select option.
 *
 * Definitions themselves live as `acf-field` posts (ACF 5.x+) whose `post_content` is a
 * serialized settings array, or in `acf-json` files on disk that no database can see. Both are
 * handled; a value whose definition is missing falls back to being read as text, which is
 * lossy but never wrong.
 */
class AcfReader extends Component
{
    /** @var array<string, array<string, mixed>>|null Field key => definition. */
    private ?array $fields = null;

    /** @var array<string, array<string, mixed>>|null Group key => definition. */
    private ?array $groups = null;

    /**
     * Load every ACF field definition the source can see.
     *
     * Named `fieldDefinitions` rather than `fields` because `craft\base\Model::fields()` — which
     * this class inherits through Component — is part of Yii's serialisation contract and takes
     * no arguments. Overriding it with a different signature is a fatal compile error.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fieldDefinitions(SourceInterface $source): array
    {
        if ($this->fields !== null) {
            return $this->fields;
        }

        $fields = [];

        try {
            foreach ($source->posts('acf-field') as $post) {
                $definition = WpSerialize::unserialize($post->content);

                if (!is_array($definition)) {
                    $definition = [];
                }

                // The post's own columns carry the parts of the definition ACF keeps out of the
                // serialized blob: the key, the label, the name and the parent.
                $definition['key'] = $post->name;
                $definition['label'] = $post->title;
                $definition['name'] = $definition['name'] ?? $post->excerpt;
                $definition['parent'] = $post->parentId;
                $definition['menu_order'] = $post->menuOrder;
                $definition['type'] = $definition['type'] ?? 'text';

                if ($definition['key'] !== '') {
                    $fields[$definition['key']] = $definition;
                }
            }
        } catch (\Throwable) {
            // A source that cannot read the acf-field post type contributes no definitions, and
            // every value falls back to text.
        }

        return $this->fields = $fields;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function groups(SourceInterface $source): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }

        $groups = [];

        try {
            foreach ($source->posts('acf-field-group') as $post) {
                $definition = WpSerialize::unserialize($post->content);
                $definition = is_array($definition) ? $definition : [];
                $definition['key'] = $post->name;
                $definition['title'] = $post->title;
                $definition['active'] = $post->status === 'publish';

                if ($definition['key'] !== '') {
                    $groups[$definition['key']] = $definition;
                }
            }
        } catch (\Throwable) {
            // As above.
        }

        return $this->groups = $groups;
    }

    /**
     * The ACF values on a post, as field name => [value, definition].
     *
     * @return array<string, array{value: mixed, definition: array<string, mixed>}>
     */
    public function valuesFor(WpPost $post, SourceInterface $source): array
    {
        $definitions = $this->fieldDefinitions($source);
        $out = [];

        foreach ($post->meta as $key => $value) {
            // Only the underscored rows name a field key, and they are what identifies an ACF
            // value at all — a bare `subtitle` row could have come from anywhere.
            if (!str_starts_with($key, '_')) {
                continue;
            }

            if (!is_string($value) || !str_starts_with($value, 'field_')) {
                continue;
            }

            $name = substr($key, 1);

            if (!array_key_exists($name, $post->meta)) {
                continue;
            }

            $out[$name] = [
                'value' => $post->meta[$name],
                'definition' => $definitions[$value] ?? ['type' => 'text', 'name' => $name, 'key' => $value],
            ];
        }

        return $out;
    }

    /**
     * Expand a repeater or flexible-content field, whose rows are stored as flattened meta keys.
     *
     * ACF writes `gallery` = 3 (the row count) and then `gallery_0_caption`, `gallery_1_caption`
     * and so on. Reassembling them means matching that naming convention, because there is no
     * other record of which meta rows belong to which repeater row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function expandRepeater(string $name, WpPost $post, SourceInterface $source): array
    {
        $count = $post->metaValue($name);

        if (!is_numeric($count) || (int)$count <= 0) {
            return [];
        }

        $definitions = $this->fieldDefinitions($source);
        $rows = [];

        for ($i = 0; $i < (int)$count; $i++) {
            $prefix = $name . '_' . $i . '_';
            $row = [];

            foreach ($post->meta as $key => $value) {
                if (!str_starts_with($key, $prefix) || str_starts_with($key, '_')) {
                    continue;
                }

                $subName = substr($key, strlen($prefix));

                // Skip the nested underscored key rows; they are metadata about metadata.
                if (str_starts_with($subName, '_')) {
                    continue;
                }

                $fieldKey = $post->metaValue('_' . $prefix . $subName);
                $definition = is_string($fieldKey) ? ($definitions[$fieldKey] ?? null) : null;

                $row[$subName] = [
                    'value' => $value,
                    'definition' => $definition ?? ['type' => 'text', 'name' => $subName],
                ];
            }

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The layout names of a flexible-content field's rows, in order.
     *
     * @return string[]
     */
    public function flexibleLayouts(string $name, WpPost $post): array
    {
        $value = $post->metaValue($name);

        if (is_string($value)) {
            $decoded = WpSerialize::unserialize($value);
            $value = $decoded;
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value)));
    }

    /**
     * Load definitions from ACF's local JSON, when the site's theme directory is available.
     *
     * Sites that keep field groups in version control have no `acf-field-group` posts at all, so
     * without this their entire field configuration is invisible.
     *
     * @return array<string, array<string, mixed>>
     */
    public function loadLocalJson(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $fields = [];

        foreach (glob(rtrim($directory, '/') . '/*.json') ?: [] as $file) {
            $contents = @file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            $group = Json::decodeIfJson($contents);

            if (!is_array($group)) {
                continue;
            }

            foreach ($this->flattenJsonFields($group['fields'] ?? []) as $field) {
                if (!empty($field['key'])) {
                    $fields[(string)$field['key']] = $field;
                }
            }
        }

        if ($this->fields !== null) {
            $this->fields = $fields + $this->fields;
        }

        return $fields;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function flattenJsonFields(array $fields): array
    {
        $out = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $out[] = $field;

            // Repeaters, groups and flexible layouts nest their sub-fields.
            foreach (['sub_fields', 'layouts'] as $key) {
                if (!empty($field[$key]) && is_array($field[$key])) {
                    foreach ($field[$key] as $nested) {
                        if (is_array($nested)) {
                            $out = array_merge($out, $this->flattenJsonFields(
                                isset($nested['sub_fields']) && is_array($nested['sub_fields'])
                                    ? $nested['sub_fields']
                                    : [$nested]
                            ));
                        }
                    }
                }
            }
        }

        return $out;
    }
}
