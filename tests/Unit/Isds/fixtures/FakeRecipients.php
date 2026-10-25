<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\Recipients;

require_once __DIR__ . '/Sending.php';

/**
 * Recipients over the items given here instead of the database. The search answers as AA's does for
 * the conditions Recipients may use - any other operator fails the test - comparing as the collation
 * of the database (case and trailing spaces do not count), and, as AA, skips a condition on a field
 * the slice does not have. Unless sorted by a field, neither the search nor the content keeps the
 * order Recipients gives.
 */
final class FakeRecipients extends Recipients
{
    /** @var list<array{string, list<array<string, mixed>>, ?list<string>, list<array<string, string>>, int}> slice, conds, restrict, sort and limit of each search */
    public static array $searches = [];

    /** @var list<list<string>> the ids of each content() */
    public static array $read = [];

    /** @var list<list<string>> the fields of each content() */
    public static array $fieldsRead = [];

    /**
     * @param array<string, array{slice: string, active?: bool, fields: array<string, string|list<string>>}> $items
     *        item id => its slice, whether it is active (default yes) and its field values; Sending::item()
     * @param list<string> $fields the fields of the slices - a condition on another one is skipped
     */
    public function __construct(private array $items, private array $fields = [Sending::NAME, Sending::TYPE, Sending::DS, Sending::MEMBER]) {}

    public static function forget(): void
    {
        self::$searches   = [];
        self::$read       = [];
        self::$fieldsRead = [];
    }

    protected function search(string $slice, array $conds, ?array $restrict = null, array $sort = [], int $limit = 0): array
    {
        self::$searches[] = [$slice, $conds, $restrict, $sort, $limit];
        $collated         = static fn(mixed $v): string => mb_strtolower(rtrim((string) $v, ' '));
        $found            = [];
        foreach ($this->items as $id => $item) {
            if (($item['slice'] !== $slice) || !($item['active'] ?? true) || (!is_null($restrict) && !in_array($id, $restrict, true))) {
                continue;
            }
            foreach ($conds as $cond) {
                $field = (string) array_key_first(array_diff_key($cond, ['operator' => 1, 'value' => 1]));
                $values = array_map($collated, (array) ($item['fields'][$field] ?? []));
                $holds  = match ($cond['operator'] ?? '') {
                    '=='      => in_array($collated($cond['value']), $values, true),
                    // "a" OR "b": any of the quoted values
                    '='       => (bool) array_intersect(array_map($collated, preg_match_all('/"([^"]*)"/', (string) $cond['value'], $m) ? $m[1] : []), $values),
                    // LIKE '%...%' with % and _ escaped: the text as it is
                    'CONTAIN' => (bool) array_filter($values, static fn(string $v): bool => str_contains($v, mb_strtolower((string) $cond['value']))),
                    default   => throw new \LogicException('The fake knows ==, = of quoted values and CONTAIN only, not ' . var_export($cond['operator'] ?? null, true)),
                };
                if (in_array($field, $this->fields, true) && !$holds) {
                    continue 2;
                }
            }
            $found[] = (string) $id;
        }
        $by = (string) array_key_first($sort[0] ?? []);
        if (!in_array($by, ['', 'publish_date....'], true)) {
            usort($found, fn(string $a, string $b): int => strcmp($collated($this->items[$a]['fields'][$by] ?? ''), $collated($this->items[$b]['fields'][$by] ?? '')) ?: strcmp($a, $b));
        } else {
            $found = array_reverse($found);
        }

        return $limit ? array_slice($found, 0, $limit) : $found;
    }

    protected function content(array $ids, array $fields): array
    {
        self::$read[]       = $ids;
        self::$fieldsRead[] = $fields;
        $content            = [];
        foreach ($ids as $id) {
            foreach (array_intersect_key($this->items[$id]['fields'] ?? [], array_flip($fields)) as $field => $values) {
                $content[$id][$field] = array_map(static fn(string $v): array => ['value' => $v], (array) $values);
            }
        }
        krsort($content);

        return $content;
    }
}
