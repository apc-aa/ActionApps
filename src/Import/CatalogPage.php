<?php

declare(strict_types=1);

namespace AA\Import;

/** One page of a catalog */
final class CatalogPage
{
    /**
     * @param list<CatalogEntry> $entries
     * @param string             $next cursor of the next page, '' after the last one; from the
     *                                 source, untrusted: stored only within the run's cursor
     */
    public function __construct(public readonly array $entries, public readonly string $next = '') {}
}
