<?php

declare(strict_types=1);

namespace AA\Import;

/** A source that can tell cheaply whether a stream has new data since version $known */
interface Probeable
{
    /** For a stream without a versionField, the version in the Probe is what the run stores as the
     *  stream's. With one, the run stores that field's maximum itself and $known is that maximum; a
     *  version the Probe gives must then be on the same scale. A baseline records it only for a
     *  FieldMaxProbeable source. */
    public function probe(Stream $s, Context $ctx, ?string $known): Probe;
}
