<?php

declare(strict_types=1);

namespace AA\Import;

/** One thing a source offers - a candidate for a stream. All texts come from the source: the caller
 *  redacts them before storing and escapes them on output. */
final class CatalogEntry
{
    /**
     * @param array<string, string> $meta    shown with the entry (unit, period, ...)
     * @param string                $updated when the source last changed it, as the source says it
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly array $meta = [],
        public readonly string $updated = '',
    ) {}
}
