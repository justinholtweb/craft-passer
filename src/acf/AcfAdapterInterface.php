<?php

namespace justinholtweb\passer\acf;

use justinholtweb\passer\importers\RunContext;

/**
 * Converts one ACF field type's stored value into something a Craft field can hold.
 */
interface AcfAdapterInterface
{
    /**
     * ACF field types this handles: `text`, `repeater`, `relationship`, …
     *
     * @return string[]
     */
    public static function handles(): array;

    /**
     * @param mixed $value The raw value from `wp_postmeta`, already unserialized.
     * @param array<string, mixed> $definition The ACF field definition.
     * @return mixed A value suitable for `$element->setFieldValue()`, or null to skip.
     */
    public function convert(mixed $value, array $definition, RunContext $context): mixed;
}
