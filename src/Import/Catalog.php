<?php

declare(strict_types=1);

namespace AA\Import;

use AA\IO\SourceRefused;
use AA_Object;
use AA_Property;

/**
 * What a source offers, as its last check() listed it page by page, with the streams the source had
 * then - so the import detail shows new and vanished entries without asking the source. One record
 * per definition, owned by it, apart from StreamState so its large JSON is not written again with
 * every step. All texts come from the source: stored as clean UTF-8, escaped on output.
 */
final class Catalog extends AA_Object
{
    use OwnedByDefinition;

    public const MAX_BYTES = 5 * 1024 * 1024;
    /** many tiny entries cost memory far beyond their bytes (290 000 in 5 MB took 245 MB) */
    public const MAX_ENTRIES = 50000;

    /** a catalog key is the source's id of a thing (ČSÚ var|per|mon|day) */
    private const MAX_KEY = 255;
    private const MAX_TEXT = 1000;
    /** {key: [name, updated, {meta}, refusal]} */
    private const DEPTH = 3;
    /** {stream key: [catalog key, label]} */
    private const STREAMS_DEPTH = 2;

    /** JSON {<catalog key>: [name, updated, {meta}, refusal]}; refusal only when there is one */
    protected $entries = '';
    /** unixtime the last page came; '' while the catalog is being filled */
    protected $time = '';
    /** JSON {<stream key>: [catalog key, label]} of the streams that come from an entry, as the fill found them */
    protected $streams = '';

    /** @var array<array-key, array{0: string, 1: string, 2: array<array-key, string>, 3?: string}>|null decoded $entries */
    private ?array $rows = null;

    public static function getClassProperties(): array
    {
        return [
            'entries' => new AA_Property('entries', 'entries', 'text', false, true, 'text'),
            'time'    => new AA_Property('time', 'time', 'text', false, true, 'text'),
            'streams' => new AA_Property('streams', 'streams', 'text', false, true, 'text'),
        ];
    }

    protected static function ownProperties(): array
    {
        return ['entries', 'time', 'streams'];
    }

    /** Adds the entries of a page (an entry of the same key is replaced); the last page ('' next)
     *  marks the catalog filled at $now. A fill starts with create() for its first page and goes on
     *  with forDefinition() for the others - starting from forDefinition() keeps the entries the
     *  source no longer has. Nothing of a refused page is added.
     *  @throws SourceRefused CATALOG_TOO_BIG over MAX_BYTES or MAX_ENTRIES; SOURCE for an unusable key or meta */
    public function addPage(CatalogPage $p, int $now): void
    {
        $rows = $this->rows();
        foreach ($p->entries as $e) {
            if (!self::usableKey($e->key)) {
                throw new SourceRefused('SOURCE', 'The source gave a catalog entry an unusable key');
            }
            $rows[$e->key] = self::row($e);
            if (count($rows) > self::MAX_ENTRIES) {
                throw new SourceRefused('CATALOG_TOO_BIG', 'The catalog of the source has over ' . self::MAX_ENTRIES . ' entries');
            }
        }
        $json = self::encode($rows, self::DEPTH);
        if (strlen($json) > self::MAX_BYTES) {
            throw new SourceRefused('CATALOG_TOO_BIG', 'The catalog of the source is over ' . self::MAX_BYTES . ' bytes');
        }
        $this->rows    = $rows;
        $this->entries = $json;
        $this->time    = ($p->next === '') ? (string) $now : '';
    }

    /** null while a check is still filling the catalog */
    public function filledAt(): ?int
    {
        return ($this->time === '') ? null : (int) $this->time;
    }

    /** Keeps the streams of the source that come from an entry, for diff() on a page that does not ask
     *  the source - a fill starts with them. A stream whose catalog key no entry could have is left out.
     *  @param iterable<Stream> $streams
     *  @throws SourceRefused CATALOG_TOO_BIG over MAX_ENTRIES streams or MAX_BYTES */
    public function listStreams(iterable $streams): void
    {
        $list = [];
        foreach ($streams as $stream) {
            if (self::usableKey($stream->catalogKey)) {
                $list[$stream->key] = [$stream->catalogKey, self::clean($stream->label, self::MAX_TEXT)];
                if (count($list) > self::MAX_ENTRIES) {
                    throw new SourceRefused('CATALOG_TOO_BIG', 'The source has over ' . self::MAX_ENTRIES . ' streams from its catalog');
                }
            }
        }
        $json = self::encode($list, self::STREAMS_DEPTH);
        if (strlen($json) > self::MAX_BYTES) {
            throw new SourceRefused('CATALOG_TOO_BIG', 'The streams from the catalog of the source are over ' . self::MAX_BYTES . ' bytes');
        }
        $this->streams = $json;
    }

    /** @return list<Stream> the streams listStreams() kept, without their parameters */
    public function streams(): array
    {
        $streams = [];
        foreach ($this->decode((string) $this->streams, self::STREAMS_DEPTH) as $key => $row) {
            if (is_array($row) && is_string($row[0] ?? null) && is_string($row[1] ?? '') && (preg_match(Stream::KEY, (string) $key) === 1)) {
                $streams[] = new Stream((string) $key, (string) ($row[1] ?? ''), [], $row[0]);
            }
        }
        return $streams;
    }

    public function count(): int
    {
        return count($this->rows());
    }

    /** @return list<CatalogEntry> $length entries from the $offset-th on - a page of a listing, without
     *                             making all of them */
    public function entriesAt(int $offset, int $length): array
    {
        $out = [];
        foreach (array_slice($this->rows(), max(0, $offset), max(0, $length), true) as $key => $row) {
            $out[] = self::entryOf((string) $key, $row);
        }
        return $out;
    }

    /** @return list<CatalogEntry> */
    public function entries(): array
    {
        return $this->entriesAt(0, $this->count());
    }

    public function entry(string $key): ?CatalogEntry
    {
        $row = $this->rows()[$key] ?? null;
        return ($row === null) ? null : self::entryOf($key, $row);
    }

    /** @param array{0: string, 1: string, 2: array<array-key, string>, 3?: string} $row */
    private static function entryOf(string $key, array $row): CatalogEntry
    {
        return new CatalogEntry($key, $row[0], array_map('strval', $row[2]), $row[1], $row[3] ?? '');
    }

    /** a key an entry may have: it is stored, shown and posted back as it is */
    private static function usableKey(string $key): bool
    {
        return ($key !== '') && (strlen($key) <= self::MAX_KEY) && mb_check_encoding($key, 'UTF-8') && !self::hasControls($key);
    }

    /**
     * The catalog against the streams of the source (by their catalogKey; a stream without one is
     * left out). While the catalog is being filled nothing is missing - its entry may come later.
     * @param list<Stream> $streams
     * @return array{new: list<string>, missing: list<string>, renamed: list<string>} new = catalog keys
     *         no stream comes from; missing = keys of streams whose entry is not in the catalog; renamed
     *         = keys of streams whose label differs from the name of their entry
     */
    public function diff(array $streams): array
    {
        $rows    = $this->rows();
        $filled  = ($this->filledAt() !== null);
        $used    = [];
        $missing = [];
        $renamed = [];
        foreach ($streams as $stream) {
            if ($stream->catalogKey === '') {
                continue;
            }
            $used[$stream->catalogKey] = true;
            $row                       = $rows[$stream->catalogKey] ?? null;
            if ($row === null) {
                if ($filled) {
                    $missing[] = $stream->key;
                }
            } elseif ($row[0] !== self::clean($stream->label, self::MAX_TEXT)) {
                $renamed[] = $stream->key;
            }
        }
        $new = array_values(array_filter(array_map('strval', array_keys($rows)), static fn(string $key): bool => !isset($used[$key])));
        return ['new' => $new, 'missing' => $missing, 'renamed' => $renamed];
    }

    /** see storeOwned() for the lock the caller holds */
    public function store(): void
    {
        $this->storeOwned();
    }

    /** @return array<array-key, array{0: string, 1: string, 2: array<array-key, string>, 3?: string}> */
    private function rows(): array
    {
        if ($this->rows === null) {
            $this->rows = [];
            foreach ($this->decode((string) $this->entries, self::DEPTH) as $key => $row) {
                $row              = (array) $row;
                $this->rows[$key] = [(string) ($row[0] ?? ''), (string) ($row[1] ?? ''), array_map('strval', (array) ($row[2] ?? []))]
                    + (isset($row[3]) ? [3 => (string) $row[3]] : []);
            }
        }
        return $this->rows;
    }

    /** @return array{0: string, 1: string, 2: array<array-key, string>, 3?: string} */
    private static function row(CatalogEntry $e): array
    {
        $meta = [];
        foreach ($e->meta as $name => $value) {
            if (!is_scalar($value) && ($value !== null)) {
                throw new SourceRefused('SOURCE', 'The source gave a catalog entry a nested value');
            }
            // a JSON null of the source is an empty value
            $meta[self::clean((string) $name, self::MAX_KEY)] = self::clean((string) $value, self::MAX_TEXT);
        }
        $refusal = self::clean($e->refusal, self::MAX_TEXT);
        // an entry that may be added keeps its three parts, so it is stored as one without a refusal always was
        return [self::clean($e->name, self::MAX_TEXT), self::clean($e->updated, self::MAX_TEXT), $meta] + (($refusal === '') ? [] : [3 => $refusal]);
    }
}
