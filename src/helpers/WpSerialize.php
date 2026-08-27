<?php

namespace justinholtweb\passer\helpers;

/**
 * Reading WordPress's serialized `option_value` and `meta_value` columns.
 *
 * PHP's own `unserialize()` is not enough here, for one reason that bites every WordPress
 * migration: serialized strings carry a byte length, and WordPress sites are routinely
 * search-and-replaced with a plain `sed` or a careless SQL `REPLACE()` when they move domain.
 * That changes the string but not the recorded length, and every such value fails to
 * unserialize from then on — silently, because WordPress itself treats an unserialize failure
 * as "this was never serialized" and hands back the raw string.
 *
 * `unserialize()` here repairs those lengths before parsing, so a site that has moved domain
 * twice still yields its ACF field groups and its widget layout.
 */
class WpSerialize
{
    /**
     * True if the value looks like PHP-serialized data. Mirrors WordPress's own `is_serialized()`
     * closely enough to agree with it on real data.
     */
    public static function isSerialized(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $value = trim($value);

        if ($value === 'N;') {
            return true;
        }

        if (strlen($value) < 4 || $value[1] !== ':') {
            return false;
        }

        $last = substr($value, -1);
        if ($last !== ';' && $last !== '}') {
            return false;
        }

        return in_array($value[0], ['s', 'a', 'O', 'b', 'i', 'd'], true);
    }

    /**
     * Unserialize a WordPress value, repairing broken string lengths first.
     *
     * Returns the raw value unchanged when it is not serialized at all, which is what callers
     * want: most meta values are plain strings.
     */
    public static function unserialize(mixed $value): mixed
    {
        if (!self::isSerialized($value)) {
            return $value;
        }

        $result = @unserialize($value, ['allowed_classes' => false]);

        if ($result !== false || $value === 'b:0;') {
            return $result;
        }

        $repaired = self::repairLengths($value);

        if ($repaired !== $value) {
            $result = @unserialize($repaired, ['allowed_classes' => false]);

            if ($result !== false) {
                return $result;
            }
        }

        // Unrepairable. Hand back the raw string rather than null, so at worst the value is
        // imported as opaque text instead of disappearing.
        return $value;
    }

    /**
     * Rewrite every `s:<n>:"..."` prefix so `<n>` matches the actual byte length of the string
     * that follows.
     *
     * The parse is deliberately literal rather than regex-based: a regex cannot know where a
     * serialized string ends when the recorded length is wrong, which is exactly the case being
     * repaired. Instead we find each closing `";` that is followed by a plausible next token.
     */
    public static function repairLengths(string $value): string
    {
        $out = '';
        $i = 0;
        $len = strlen($value);

        while ($i < $len) {
            // Look for the start of a serialized string: s:<digits>:"
            if (
                $value[$i] === 's'
                && $i + 1 < $len
                && $value[$i + 1] === ':'
                && preg_match('/^s:(\d+):"/', substr($value, $i), $m)
            ) {
                $headerLen = strlen($m[0]);
                $bodyStart = $i + $headerLen;
                $bodyEnd = self::findStringEnd($value, $bodyStart);

                if ($bodyEnd === null) {
                    // Cannot locate a terminator; copy the rest verbatim and stop trying.
                    $out .= substr($value, $i);
                    break;
                }

                $body = substr($value, $bodyStart, $bodyEnd - $bodyStart);
                $out .= 's:' . strlen($body) . ':"' . $body . '";';
                $i = $bodyEnd + 2; // step past the closing `";`
                continue;
            }

            $out .= $value[$i];
            $i++;
        }

        return $out;
    }

    /**
     * Find the offset of the `"` that closes a serialized string starting at $start.
     *
     * A `";` inside the string body is indistinguishable from the terminator on its own, so we
     * require the terminator to be followed by something that can legally begin the next token:
     * another type marker, a closing brace, or end of input.
     */
    private static function findStringEnd(string $value, int $start): ?int
    {
        $len = strlen($value);
        $offset = $start;

        while (($pos = strpos($value, '";', $offset)) !== false) {
            $after = $pos + 2;

            if ($after >= $len) {
                return $pos;
            }

            $next = $value[$after];

            if ($next === '}' || in_array($next, ['s', 'a', 'O', 'b', 'i', 'd', 'N'], true)) {
                // A type marker must be followed by `:` (or be the whole of `N;`).
                if ($next === '}' || $next === 'N' || (isset($value[$after + 1]) && $value[$after + 1] === ':')) {
                    return $pos;
                }
            }

            $offset = $pos + 1;
        }

        // Unterminated: treat a trailing `"` as the end if there is one.
        if ($len > $start && $value[$len - 1] === '"') {
            return $len - 1;
        }

        return null;
    }

    /**
     * Serialized WordPress data often nests arrays of scalars. Flatten one to a list of strings,
     * which is what most Craft destinations actually want.
     *
     * @return string[]
     */
    public static function flattenScalars(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_array($value)) {
            return [(string)$value];
        }

        $out = [];

        array_walk_recursive($value, function ($item) use (&$out) {
            if (is_scalar($item) && $item !== '') {
                $out[] = (string)$item;
            }
        });

        return $out;
    }
}
