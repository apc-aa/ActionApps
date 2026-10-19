<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

/**
 * The result of Matcher::match(), one row per item in the order of the items. Values are raw text
 * of the items and of the register, and so are the keys below (item types, box ids) and the item
 * ids - escape them (safe()) when shown.
 */
final readonly class MatchReport
{
    /** @param list<MatchRow> $rows */
    public function __construct(public array $rows) {}

    /** @return array<string, int> status => rows, every status of MatchRow::STATUSES, in its order */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(MatchRow::STATUSES, 0);
        foreach ($this->rows as $row) {
            $counts[$row->status]++;
        }
        return $counts;
    }

    /**
     * @return array<array-key, array<string, int>> item type => statusCounts() of its rows, types in
     *                                             the order met; PHP makes a numeric type an int key
     */
    public function statusCountsByType(): array
    {
        $counts = [];
        foreach ($this->rows as $row) {
            $counts[$row->type] ??= array_fill_keys(MatchRow::STATUSES, 0);
            $counts[$row->type][$row->status]++;
        }
        return $counts;
    }

    /**
     * Boxes more than one item would hold once the rows are written - its new box, else the one it has:
     * two items for one place (a duplicate, "Poruba" and "Ostrava - Poruba"), or a suggestion of a box
     * another item already has. Writing them would hide that.
     * @return array<array-key, list<int|string>> box id => item ids; PHP makes an all-digit id an int key
     */
    public function sharedBoxes(): array
    {
        $items = [];
        foreach ($this->rows as $row) {
            $held = ($row->newDs !== '') ? $row->newDs : strtolower(trim($row->oldDs));
            if ($held !== '') {
                $items[$held][] = $row->itemId;
            }
        }
        return array_filter($items, static fn(array $ids): bool => count($ids) > 1);
    }
}
