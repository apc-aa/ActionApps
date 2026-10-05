<?php

declare(strict_types=1);

namespace AA\Import;

/** One thing a source offers - a candidate for a stream. Catalog data is source data: the check masks
 *  the source's keys in it before it is stored (adopt() so gets an entry with them masked), and
 *  whoever shows it escapes it. */
final class CatalogEntry
{
    /**
     * @param array<string, mixed>  $meta    shown with the entry (unit, period, ...): plain values,
     *                                       which Catalog stores as strings - it refuses anything else
     * @param string                $updated when the source last changed it, as the source says it
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly array $meta = [],
        public readonly string $updated = '',
    ) {}
}
