<?php

declare(strict_types=1);

namespace AA\Import;

/** A source that can tell cheaply whether a stream has new data since version $known */
interface Probeable
{
    public function probe(Stream $s, Context $ctx, ?string $known): Probe;
}
