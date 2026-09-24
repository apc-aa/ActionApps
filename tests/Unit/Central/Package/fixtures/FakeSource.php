<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package\Fixtures;

use AA\Central\Package\Source;
use Generator;
use InvalidArgumentException;

/** An in-memory source AA for CollectorTest: tables of rows as DbSource returns them (all columns,
 *  packed ids unpacked). select() answers the equality/IN conditions Collector sends. */
final class FakeSource implements Source
{
    /** @var list<array{string, mixed}> every call - table ('item' for items()), conditions */
    public array $log = [];

    /**
     * @param array<string, list<array<string, mixed>>> $tables table => rows
     * @param array<string, list<array<string, mixed>>> $items  slice id => items {id, content}
     */
    public function __construct(public array $tables, public array $items = []) {}

    public function select(string $table, array $where): array
    {
        $this->log[] = [$table, $where];
        if (!$where) {
            throw new InvalidArgumentException('FakeSource: select without a condition');
        }
        return array_values(array_filter($this->tables[$table] ?? [], static function (array $row) use ($where): bool {
            foreach ($where as [$column, $value, $type]) {
                $norm = match ($type) {
                    'l'     => static fn($v): string => strtolower((string) $v),
                    'i'     => static fn($v): int => (int) $v,
                    's'     => static fn($v): string => (string) $v,
                    default => throw new InvalidArgumentException("FakeSource: condition type $type"),
                };
                if (!in_array($norm($row[$column] ?? null), array_map($norm, (array) $value), true)) {
                    return false;
                }
            }
            return true;
        }));
    }

    public function objectIds(string $owner_id): array
    {
        $this->log[] = ['objectIds', [$owner_id]];
        $ids         = [];
        foreach ($this->tables['object_text'] ?? [] as $row) {
            if (($row['property'] === 'aa_owner') && ($row['value'] === $owner_id)) {
                $ids[] = $row['object_id'];
            }
        }
        return $ids;
    }

    /** from the tables object_text, object_integer, object_float, as DbSource reads them */
    public function objectRows(array $ids, array $properties = []): array
    {
        $this->log[] = ['objectRows', [$ids, $properties]];
        $rows        = [];
        foreach (['object_text', 'object_integer', 'object_float'] as $table) {
            foreach ($this->tables[$table] ?? [] as $row) {
                if (in_array($row['object_id'], $ids, true) && (!$properties || in_array($row['property'], $properties, true))) {
                    $rows[] = ['table' => $table] + $row;
                }
            }
        }
        return $rows;
    }

    public function items(string $slice_id, bool $all_bins): Generator
    {
        $this->log[] = ['item', [$slice_id, $all_bins]];
        foreach ($this->items[$slice_id] ?? [] as $item) {
            if ($all_bins || (($item['content']['status_code.....'][0]['value'] ?? '') === '1')) {
                yield $item;
            }
        }
    }
}
