<?php

namespace justinholtweb\passer\bridge;

use justinholtweb\passer\acf\AcfAdapterInterface;
use justinholtweb\passer\importers\RunContext;

/**
 * Presents a wp-import ACF adapter as one of Passer's.
 *
 * wp-import's adapters expose `normalizeValue($value, $field)` on current versions and
 * `convert()` on older ones; both are tried, and anything that throws falls back to returning
 * the raw value rather than losing it.
 */
class WrappedAcfAdapter implements AcfAdapterInterface
{
    public function __construct(
        private object $adapter,
        private string $type,
    ) {
    }

    public static function handles(): array
    {
        // Instances declare their own type; the static list is unused because the bridge
        // registers each wrapper against the type it discovered.
        return [];
    }

    public function convert(mixed $value, array $definition, RunContext $context): mixed
    {
        foreach (['normalizeValue', 'convert', 'normalize'] as $method) {
            if (!method_exists($this->adapter, $method)) {
                continue;
            }

            try {
                return $this->adapter->$method($value, $definition);
            } catch (\Throwable) {
                // Try the next signature, then give up gracefully.
                continue;
            }
        }

        return $value;
    }

    public function acfType(): string
    {
        return $this->type;
    }
}
