<?php

declare(strict_types=1);

namespace AA\Central\Package;

use Closure;
use Generator;

/** A source whose filtered slices give only the items their ItemFilter selects. itemIds(), itemCount() and
 *  logins() of such a slice read the ids of the inner source a page at a time and keep the matching()
 *  ones, so the bins, the cursor and the id order stay the inner source's. A slice without a filter, and
 *  everything else, is the inner source's. */
final class FilteredSource implements Source
{
    /** ids of the inner source a call (as AppSource::ID_PAGE) */
    private const PAGE = 5000;

    /** @param array<string, ItemFilter> $filters slice id => the filter in the slice's charset */
    private function __construct(private readonly Source $source, private readonly array $filters) {}

    /** Each filter parsed, its fields checked against the slices and its values converted to the slice's charset.
     *  @param array<string, string> $filters slice id => the text as typed (UTF-8)
     *  @throws FilterInvalid with the slice */
    public static function of(Source $source, array $filters): self
    {
        $fields   = $charsets = [];
        $fieldsOf = static function (string $slice) use ($source, &$fields): array {
            return $fields[$slice] ??= array_column($source->select('field', [['slice_id', $slice, 'l']]), null, 'id');
        };
        $charsetOf = static function (string $slice) use ($source, &$charsets): string {
            return $charsets[$slice] ??= ModuleCharset::ofModule($source->select('module', [['id', $slice, 'l']])[0] ?? [])[1] ?? 'utf-8';
        };
        $parsed = [];
        foreach ($filters as $slice => $text) {
            $slice = strtolower((string) $slice);
            try {
                $filter = ItemFilter::parse($text);
                foreach ($filter->conditions() as $condition) {
                    $compared = self::compared($slice, $condition['fields'], $fieldsOf);
                    // one value, one charset: the value of a field of a slice in another one would not convert twice
                    if (!mb_check_encoding($condition['value'], 'ASCII') && array_filter($compared, static fn(string $s): bool => $charsetOf($s) !== $charsetOf($slice))) {
                        throw new FilterInvalid('charset');
                    }
                }
                $parsed[$slice] = $filter->inCharset($charsetOf($slice));
            } catch (FilterInvalid $e) {
                throw new FilterInvalid($e->reason, $slice);
            }
        }
        return new self($source, $parsed);
    }

    public function select(string $table, array $where): array
    {
        return $this->source->select($table, $where);
    }

    public function itemIds(string $slice_id, bool $all_bins, string $after, int $limit): array
    {
        $filter = $this->filters[strtolower($slice_id)] ?? null;
        if (($filter === null) || ($limit <= 0)) {
            return $this->source->itemIds($slice_id, $all_bins, $after, $limit);
        }
        $ids = [];
        foreach ($this->selected($slice_id, $filter, $all_bins, $after) as $page) {
            $ids = [...$ids, ...$page];
            if (count($ids) >= $limit) {
                break;
            }
        }
        return array_slice($ids, 0, $limit);
    }

    public function itemCount(string $slice_id, bool $all_bins, int $edited_after = 0): int
    {
        $filter = $this->filters[strtolower($slice_id)] ?? null;
        if ($filter === null) {
            return $this->source->itemCount($slice_id, $all_bins, $edited_after);
        }
        $count = 0;
        foreach ($this->selected($slice_id, $filter, $all_bins, '', $edited_after) as $page) {
            $count += count($page);
        }
        return $count;
    }

    public function items(array $ids, bool $with_secrets): iterable
    {
        return $this->source->items($ids, $with_secrets);
    }

    /** only the selected readers' - a login taken on the target by one not transferred would refuse the preview for nothing */
    public function logins(string $slice_id, bool $all_bins): array
    {
        $filter = $this->filters[strtolower($slice_id)] ?? null;
        if ($filter === null) {
            return $this->source->logins($slice_id, $all_bins);
        }
        $logins = [];
        foreach ($this->selected($slice_id, $filter, $all_bins) as $page) {
            array_push($logins, ...$this->values($page, ItemLint::USERNAME));
        }
        return $logins;
    }

    public function matching(string $slice_id, ItemFilter $filter, array $ids, int $edited_after = 0): array
    {
        return $this->source->matching($slice_id, $filter, $ids, $edited_after);
    }

    public function objectIds(string $owner_id): array
    {
        return $this->source->objectIds($owner_id);
    }

    public function objectRows(array $ids, array $properties = []): array
    {
        return $this->source->objectRows($ids, $properties);
    }

    /** Per slice of $slices: the values of the relation fields of its selected items naming an item of a
     *  filtered slice of $slices (in any bin) that the filter or the bins leave out - links the transfer
     *  breaks. A lower bound: values naming no item, or an item of a slice not transferred, do not count.
     *  @param list<string> $slices @return array<string, int> slice => values */
    public function leftOut(array $slices, bool $allBins): array
    {
        $counts   = array_fill_keys($slices, 0);
        $selected = [];     // filtered slice => the ids it transfers
        $out      = [];     // id => true: an item of a filtered slice it leaves out
        foreach (array_intersect_key($this->filters, array_flip(array_map('strtolower', $slices))) as $slice => $filter) {
            $selected[$slice] = array_merge([], ...iterator_to_array($this->selected($slice, $filter, $allBins), false));
            $in               = array_flip($selected[$slice]);
            foreach ($this->selected($slice, null, true) as $page) {
                foreach ($page as $id) {
                    if (!isset($in[$id])) {
                        $out[strtolower($id)] = true;
                    }
                }
            }
        }
        if (!$out) {
            return $counts;
        }
        foreach ($slices as $slice) {
            $widgets   = array_column($this->source->select('field', [['slice_id', $slice, 'l']]), 'input_show_func', 'id');
            $relations = array_map('strval', array_keys(array_filter($widgets, static fn(mixed $widget): bool => ReferenceRewriter::relationSlice((string) $widget) !== null)));
            $pages     = isset($selected[strtolower($slice)]) ? array_chunk($selected[strtolower($slice)], self::PAGE) : $this->selected($slice, null, $allBins);
            foreach ($relations ? $pages : [] as $page) {
                foreach ($this->values($page, $relations) as $value) {
                    $counts[$slice] += (int) isset($out[strtolower($value)]);
                }
            }
        }
        return $counts;
    }

    /** The ids of the slice the filter selects (null: all), a page of ids of the inner source at a time, in
     *  its order. A page may be empty and the next one is read all the same - only a short page is the last.
     *  @return Generator<int, list<string>> */
    private function selected(string $slice_id, ?ItemFilter $filter, bool $all_bins, string $after = '', int $edited_after = 0): Generator
    {
        do {
            $candidates = $this->source->itemIds($slice_id, $all_bins, $after, self::PAGE);
            if ($filter === null) {
                yield $candidates;
            } else {
                $matching = $candidates ? array_flip($this->source->matching($slice_id, $filter, $candidates, $edited_after)) : [];
                yield array_values(array_filter($candidates, static fn(string $id): bool => isset($matching[$id])));
            }
            $after = (string) end($candidates);
        } while (count($candidates) >= self::PAGE);
    }

    /** the values of the fields of the items, as stored
     *  @param list<string> $ids @param string|list<string> $fields @return list<string> */
    private function values(array $ids, string|array $fields): array
    {
        return $ids ? array_map(static fn(array $row): string => (string) $row['text'], $this->source->select('content', [['item_id', $ids, 'l'], ['field_id', $fields, 's']])) : [];
    }

    /** The slices whose fields a condition compares its value with. Each field must be one QueryZids()
     *  finds: it skips an unknown one, and a condition skipped selects more. A relation field selects
     *  from the slice of its `#sLiCe-<id>` constants (AA_Field::getRelation()).
     *  @param list<string> $tokens @param Closure(string): array<string, array<string, mixed>> $fieldsOf memoised
     *  @return list<string> @throws FilterInvalid */
    private static function compared(string $slice, array $tokens, Closure $fieldsOf): array
    {
        $compared = [];
        foreach ($tokens as $token) {
            $form = ItemFilter::fieldForm($token) ?? [];
            $kind = $form[0] ?? 'all_fields';
            if ($kind === 'related') {
                $other = (string) ReferenceRewriter::relationSlice((string) ($fieldsOf($slice)[$form[1]]['input_show_func'] ?? ''));
                $found = preg_match('/^[0-9a-f]{32}\z/', $other) && (($form[2] === 'all_fields') || array_key_exists($form[2], $fieldsOf($other)));
            } elseif ($kind === 'remote') {
                $other = $form[1];
                $found = !array_diff([$form[2], $form[3]], array_keys($fieldsOf($other)));
            } else {
                $other = $slice;
                $found = ($kind === 'all_fields') || array_key_exists($form[1], $fieldsOf($slice));
            }
            $compared[] = $found ? $other : throw new FilterInvalid("field:$token");
        }
        return $compared;
    }
}
