<?php

declare(strict_types=1);

namespace AA\Import;

/**
 * What a change of the settings, the source or the mapping of a definition did (Service): CHANGED,
 * SAME (nothing to change, the open run goes on), STALE (the stored settings are no longer those the
 * form was made from), or the code of a refusal with its detail - the label of the setting at fault,
 * or the problems of a mapping.
 */
final class ChangeResult
{
    public function __construct(public readonly string $code, public readonly string $detail = '') {}

    public function done(): bool
    {
        return in_array($this->code, ['CHANGED', 'SAME'], true);
    }

    /** why not, in words, plain text; null when it was done */
    public function refusal(): ?string
    {
        return $this->done() ? null : Definition::refusal($this->code, $this->detail);
    }
}
