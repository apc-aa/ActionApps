<?php

declare(strict_types=1);

namespace AA\Import;

/** Defaults of a source; name() and description() stay with each source */
abstract class AbstractSource implements SourceInterface
{
    public static function credentials(): array
    {
        return [];
    }

    public function minJobSeconds(): int
    {
        return 15;
    }

    public function maxJobsPerStream(): int
    {
        return RunCursor::MAX_PAGES;
    }

    public function maxBytes(): int
    {
        return Context::DEFAULT_MAX_BYTES;
    }

    /** no rows - a source that imports overrides this */
    public function defaultMapping(): Mapping
    {
        return new Mapping([]);
    }
}
