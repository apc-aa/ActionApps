<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\Recipients;

require_once __DIR__ . '/Sending.php';

/**
 * Recipients over the items given here instead of the database. The search answers as AA's does for
 * the conditions Recipients may use - any other operator fails the test - comparing as the collation
 * of the database (case and trailing spaces do not count), and, as AA, skips a condition on a field
 * the slice does not have. Neither the search nor the content keeps the order Recipients gives.
 */
final class FakeRecipients extends Recipients
{
    /** @var list<array{string, list<array<string, mixed>>, ?list<string>}> slice, conds and restrict of each search */
    public static array $searches = [];

    /** @var list<list<string>> the ids of each content() */
    public static array $read = [];

    /**
     * @param array<string, array{slice: string, active?: bool, fields: array<string, string|list<string>>}> $items
     *        item id => its slice, whether it is active (default yes) and its field values; Sending::item()
     * @param list<string> $fields the fields of the slices - a condition on another one is skipped
     */
    public function __construct(private array $items, private array $fields = [Sending::NAME, Sending::TYPE, Sending::DS, Sending::MEMBER]) {}

    public static function forget(): void
    {
        self::$searches = [];
        self::$read     = [];
    }

    protected function search(string $slice, array $conds, ?array $restrict = null): array
    {
        self::$searches[] = [$slice, $conds, $restrict];
        $collated         = static fn(mixed $v): string => mb_strtolower(rtrim((string) $v, ' '));
        $found            = [];
        foreach ($this->items as $id => $item) {
            if (($item['slice'] !== $slice) || !($item['active'] ?? true) || (!is_null($restrict) && !in_array($id, $restrict, true))) {
                continue;
            }
            foreach ($conds as $cond) {
                $field = (string) array_key_first(array_diff_key($cond, ['operator' => 1, 'value' => 1]));
                if (($cond['operator'] ?? '') !== '==') {
                    throw new \LogicException('The fake knows the exact match only, not ' . var_export($cond['operator'] ?? null, true));
                }
                if (in_array($field, $this->fields, true) && !in_array($collated($cond['value']), array_map($collated, (array) ($item['fields'][$field] ?? [])), true)) {
                    continue 2;
                }
            }
            $found[] = (string) $id;
        }

        return array_reverse($found);
    }

    protected function content(array $ids, array $fields): array
    {
        self::$read[] = $ids;
        $content      = [];
        foreach ($ids as $id) {
            foreach (array_intersect_key($this->items[$id]['fields'] ?? [], array_flip($fields)) as $field => $values) {
                $content[$id][$field] = array_map(static fn(string $v): array => ['value' => $v], (array) $values);
            }
        }
        krsort($content);

        return $content;
    }
}
