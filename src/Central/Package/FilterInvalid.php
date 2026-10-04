<?php

declare(strict_types=1);

namespace AA\Central\Package;

use RuntimeException;

/** A filter of items (ItemFilter) that cannot be used. $reason is short and for machines - the ref of the
 *  E ItemFilter::INVALID; $slice the source slice it belongs to, when known. */
final class FilterInvalid extends RuntimeException
{
    public readonly string $reason;

    public function __construct(string $reason, public readonly string $slice = '')
    {
        $this->reason = mb_strcut($reason, 0, ReferenceLint::MAX_REF, 'UTF-8');
        parent::__construct("The item filter cannot be used: $this->reason");
    }
}
