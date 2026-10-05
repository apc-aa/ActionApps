<?php

declare(strict_types=1);

namespace AA\Later;

/** Where a batch run continues. Positions round-trip through JSON in object_text, so
 *  they are compared by meaning: key order and scalar types (5 vs "5", false vs 0,
 *  null vs '') would otherwise look like progress and change the queue fingerprint.
 *  A position holds only int, string, bool, null and arrays of them. */
final class BatchPosition
{
    /** Keys sorted recursively, scalars as strings (null → '', false → '0', true → '1'), then JSON.
     *  Only for comparing and fingerprinting - never store it (a position is stored by plain json_encode).
     *  @throws \InvalidArgumentException on a float, object or resource - its string form is not stable
     *  @throws \JsonException on invalid UTF-8 */
    public static function canonical(array $position): string
    {
        return json_encode(self::normalize($position), JSON_THROW_ON_ERROR);
    }

    public static function same(array $a, array $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    /** The step number makes every queued step distinct, even one that did not move the position. */
    public static function fingerprint(int $step, array $position): string
    {
        return sha1($step . "\n" . self::canonical($position));
    }

    private static function normalize(array $value): array
    {
        // SORT_STRING: a total order even for mixed int and string keys
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = match (true) {
                is_array($item)                 => self::normalize($item),
                is_bool($item)                  => $item ? '1' : '0',
                ($item === null)                => '',
                is_int($item), is_string($item) => (string) $item,
                // a float prints by ini precision, which differs between the queue and the web
                default => throw new \InvalidArgumentException('Batch position holds ' . get_debug_type($item)),
            };
        }
        return $value;
    }
}
