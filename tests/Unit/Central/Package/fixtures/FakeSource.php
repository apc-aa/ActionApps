<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package\Fixtures;

use AA\Central\Package\ItemFilter;
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

    public function itemIds(string $slice_id, bool $all_bins, string $after, int $limit): array
    {
        $this->log[] = ['itemIds', [$slice_id, $all_bins, $after, $limit]];
        $ids         = array_column(array_filter($this->items[$slice_id] ?? [], static fn(array $item): bool => self::inBins($item, $all_bins) && (strcmp($item['id'], $after) > 0)), 'id');
        sort($ids, SORT_STRING);
        return array_slice($ids, 0, $limit);
    }

    public function itemCount(string $slice_id, bool $all_bins, int $edited_after = 0): int
    {
        $this->log[] = ['itemCount', [$slice_id, $all_bins, $edited_after]];
        return count(array_filter($this->items[$slice_id] ?? [], static fn(array $item): bool => self::inBins($item, $all_bins)
            && (($edited_after === 0) || ((int) ($item['last_edit'] ?? 0) > $edited_after))));
    }

    /** In memory, far less than QueryZids(): plain fields of the item content only; = and == as an exact
     *  value (no phrases), ISNULL, NOTNULL; any value of any field of a condition, the conditions joined by
     *  AND, last_edit as itemCount(). Anything else throws. */
    public function matching(string $slice_id, ItemFilter $filter, array $ids, int $edited_after = 0): array
    {
        $this->log[] = ['matching', [$slice_id, $filter->conditions(), $ids, $edited_after]];
        $asked       = array_flip($ids);
        $found       = [];
        foreach ($this->items[$slice_id] ?? [] as $item) {
            if (!isset($asked[$item['id']]) || ($edited_after && ((int) ($item['last_edit'] ?? 0) <= $edited_after))) {
                continue;
            }
            foreach ($filter->conditions() as ['fields' => $fields, 'operator' => $operator, 'value' => $value]) {
                $values = [];
                foreach ($fields as $field) {
                    $values = [...$values, ...array_map('strval', array_column($item['content'][$field] ?? [], 'value'))];
                }
                $filled = array_filter($values, static fn(string $v): bool => $v !== '');
                $match  = match ($operator) {
                    '=', '==' => in_array($value, $values, true),
                    'ISNULL'  => !$filled,
                    'NOTNULL' => (bool) $filled,
                    default   => throw new InvalidArgumentException("FakeSource: operator $operator"),
                };
                if (!$match) {
                    continue 2;
                }
            }
            $found[] = $item['id'];
        }
        return $found;
    }

    /** @var list<string> items itemIds() still lists, items() no more (deleted meanwhile) */
    public array $vanished = [];

    /** as DbSource: GetItemContent() (no secret* field), the 2FA secrets on request; any order */
    public function items(array $ids, bool $with_secrets): Generator
    {
        $this->log[] = ['item', $ids, $with_secrets];
        foreach (array_reverse(array_merge([], ...array_values($this->items))) as $item) {
            if (in_array($item['id'], $ids, true) && !in_array($item['id'], $this->vanished, true)) {
                $secrets = array_filter(array_keys($item['content']), static fn($f): bool => str_starts_with((string) $f, 'secret'));
                yield $with_secrets ? $item : ['id' => $item['id'], 'content' => array_diff_key($item['content'], array_flip($secrets))];
            }
        }
    }

    public function logins(string $slice_id, bool $all_bins): array
    {
        $this->log[] = ['logins', [$slice_id, $all_bins]];
        $logins      = [];
        foreach ($this->items[$slice_id] ?? [] as $item) {
            if (self::inBins($item, $all_bins)) {
                array_push($logins, ...array_column($item['content']['headline........'] ?? [], 'value'));
            }
        }
        return $logins;
    }

    private static function inBins(array $item, bool $all_bins): bool
    {
        return $all_bins || (($item['content']['status_code.....'][0]['value'] ?? '') === '1');
    }
}
