<?php

declare(strict_types=1);

namespace AA\Import;

use AA\Util\NamedInterface;

/**
 * A pluggable data source of the import. It only describes and fetches: saving goes through the
 * run, and keys and the network only through Context - a source never reads a secret constant
 * or the global configuration itself. Optional abilities are separate interfaces (Checkable,
 * Probeable, Cataloged, CatalogAdoptable).
 */
interface SourceInterface extends NamedInterface
{
    /** @return array<string, \AA_Property> settings of the source (slice ids, years, ...), never a secret */
    public static function getClassProperties(): array;

    /** @param array<string, string> $settings */
    public static function fromSettings(array $settings): static;

    /**
     * Streams in the order of the run; their keys must stay the same between runs - the run
     * continues from a stored key.
     * @return iterable<Stream>
     */
    public function streams(): iterable;

    /**
     * Jobs of one stream in the order of the run, keys stable and unique within the run. A
     * generator may learn the further pages only from an answer.
     * @param ?string $known data version stored for the stream, only when importing changed data
     * @return iterable<Job>
     */
    public function jobs(Stream $stream, Context $ctx, ?string $known): iterable;

    /** @return list<array{vendor: string, context: string}> keys the source needs - names only */
    public function credentials(): array;

    /** a job is not started when less of the step is left */
    public function minJobSeconds(): int;
}
