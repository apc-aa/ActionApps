<?php

declare(strict_types=1);

namespace AA\Central\Package\Model;

/** meta/batch: the package is one batch of a slice's items - the slice-module holds items only, no
 *  definition (the target has written it already). The source picks items by id, so the cursor is an id. */
final readonly class Batch
{
    /**
     * @param string $after    the cursor the batch continues from: the last item id of the previous batch, '' = the first batch
     * @param string $next     the cursor of the next batch: the last item id of this one, $after when it has none
     * @param bool   $finished no item of the slice after $next
     */
    public function __construct(
        public string $after,
        public string $next,
        public bool $finished,
    ) {}
}
