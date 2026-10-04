<?php

declare(strict_types=1);

namespace AA\Import;

use AA\IO\SourceRefused;
use AA_Object;
use AA_Property;

/**
 * What a source offers, as its last check() listed it page by page - so the import detail shows new
 * and vanished entries without asking the source. One record per definition, owned by it, apart
 * from StreamState so its large JSON is not written again with every step. All texts come from the
 * source: stored as clean UTF-8, escaped on output.
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
    /** {key: [name, updated, {meta}]} */
    private const DEPTH = 3;

    /** JSON {<catalog key>: [name, updated, {meta}]} */
    protected $entries = '';
    /** unixtime the last page came; '' while the catalog is being filled */
    protected $time = '';

    /** @var array<array-key, array{0: string, 1: string, 2: array<array-key, string>}>|null decoded $entries */
    private ?array $rows = null;

    public static function getClassProperties(): array
    {
        return [
            'entries' => new AA_Property('entries', 'entries', 'text', false, true, 'text'),
            'time'    => new AA_Property('time', 'time', 'text', false, true, 'text'),
        ];
    }

    protected static function ownProperties(): array
    {
        return ['entries', 'time'];
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
            if (($e->key === '') || (strlen($e->key) > self::MAX_KEY) || !mb_check_encoding($e->key, 'UTF-8') || self::hasControls($e->key)) {
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

    /** @return list<CatalogEntry> */
    public function entries(): array
    {
        $out = [];
        foreach ($this->rows() as $key => $row) {
            $out[] = new CatalogEntry((string) $key, $row[0], array_map('strval', $row[2]), $row[1]);
        }
        return $out;
    }

    public function entry(string $key): ?CatalogEntry
    {
        $row = $this->rows()[$key] ?? null;
        return ($row === null) ? null : new CatalogEntry($key, $row[0], array_map('strval', $row[2]), $row[1]);
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

    /** @return array<array-key, array{0: string, 1: string, 2: array<array-key, string>}> */
    private function rows(): array
    {
        if ($this->rows === null) {
            $this->rows = [];
            foreach ($this->decode((string) $this->entries, self::DEPTH) as $key => $row) {
                $row              = (array) $row;
                $this->rows[$key] = [(string) ($row[0] ?? ''), (string) ($row[1] ?? ''), array_map('strval', (array) ($row[2] ?? []))];
            }
        }
        return $this->rows;
    }

    /** @return array{0: string, 1: string, 2: array<array-key, string>} */
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
        return [self::clean($e->name, self::MAX_TEXT), self::clean($e->updated, self::MAX_TEXT), $meta];
    }
}
