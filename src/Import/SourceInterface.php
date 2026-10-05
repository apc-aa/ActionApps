<?php

declare(strict_types=1);

namespace AA\Import;

use AA\Util\NamedInterface;

/**
 * A pluggable data source of the import. It only describes and fetches: saving goes through the
 * run, and keys and the network only through Context - a source never reads a secret constant
 * or the global configuration itself. Optional abilities are separate interfaces (Checkable,
 * Probeable, Cataloged, CatalogAdoptable).
 *
 * A source extends AbstractSource, and every method added here gets a default there: PHP reports
 * a missing method as a fatal error, not an exception, so a source of an installation that lacks
 * it would bring down the import pages, State Check and the queue step.
 */
interface SourceInterface extends NamedInterface
{
    /**
     * Settings of the source (slice ids, years, ...), never a secret. Each value is checked by the
     * validator of its property before it is stored; a slice id has SliceIdSetting, which also asks
     * the user's right to that slice. Only such checked ids and numbers may go into an expression the
     * source builds (a default mapping, a validation): free text goes there only through QuoteColons(),
     * or it would run as template code in the rights of the queue. QuoteColons() covers one round of
     * expansion only - free text never goes inside a command that expands its argument again
     * ({expand:...}, view parameters) nor where it could form an alias (_#...). A setting that names
     * slices or items is a SliceIdSetting, one id each: free text never goes where an id goes ({ids:},
     * {item:}, view or slice parameters), not even quoted, and a source never trims or cuts an id out
     * of a longer value.
     * @return array<string, \AA_Property>
     */
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

    /**
     * The mapping a new definition starts with, and what "reset mapping" brings back: rows, key,
     * store mode, validation and the expression after a stream.
     */
    public function defaultMapping(): Mapping;

    /**
     * Keys the source needs - names only, one fact of the source: static, so neither its settings
     * nor an instance can change them. The right to change a definition and the keys a run gets
     * come from this one list, so they cannot disagree; whoever sets the settings never chooses
     * which key is sent.
     * @return list<array{vendor: string, context: string}>
     */
    public static function credentials(): array;

    /** a job is not started when less of the step is left */
    public function minJobSeconds(): int;
}
