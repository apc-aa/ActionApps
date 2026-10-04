<?php

declare(strict_types=1);

namespace AA\Import;

use AA\IO\SourceRefused;

/** One page of a catalog */
final class CatalogPage
{
    /**
     * @param list<CatalogEntry> $entries
     * @param string             $next cursor of the next page, '' after the last one; from the
     *                                 source, so kept to the characters of a job key (Job::KEY) -
     *                                 a token or a page number, never a URL: it is stored in the
     *                                 run's cursor and shown
     * @throws SourceRefused SOURCE for any other cursor
     */
    public function __construct(public readonly array $entries, public readonly string $next = '')
    {
        if (($next !== '') && (preg_match(Job::KEY, $next) !== 1)) {
            throw new SourceRefused('SOURCE', 'The source gave its catalog an unusable cursor');
        }
    }
}
