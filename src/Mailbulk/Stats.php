<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA;
use AA_Set;
use zids;

/**
 * The figures of one sending from the statistics slice of its list (events as Tracker stores them).
 * Rights are not checked here - the caller has. The figures are rough: mail clients fetch images and
 * mail checkers follow links on their own.
 */
class Stats
{
    /** links listed at most */
    public const MAX_LINKS = 50;
    /** events read by one GetItemContent() call */
    private const CHUNK = 500;

    /** @return array{opened: int, clicked: int} opened = the open events (one per reader),
     *                                            clicked = the readers with a click */
    public function counts(string $statSliceId, string $itemId): array
    {
        $opened = AA::Contentcache()->get_result([$this, 'opensFromDb'], [$statSliceId, strtolower($itemId)]);
        $clicks = $this->clicks($statSliceId, $itemId);
        return ['opened' => (int) $opened, 'clicked' => count(array_unique(array_column($clicks, 'reader')))];
    }

    /** The figures of many sendings at once - a page of them: one search per kind of event and one read of
     *  the events, whatever the number of the sendings.
     *  @param list<string> $itemIds
     *  @return array<string, array{opened: int, clicked: int}> item id (lowercase) => figures, every one of $itemIds */
    public function countsFor(string $statSliceId, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('strtolower', $itemIds)));
        $figures = array_fill_keys($itemIds, ['opened' => 0, 'clicked' => 0]);
        if (!$itemIds) {
            return $figures;
        }
        $opens   = array_fill_keys($this->events($statSliceId, $itemIds, 'open'), true);
        $ids     = array_values(array_unique([...array_keys($opens), ...$this->events($statSliceId, $itemIds, 'click')]));
        $clicked = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ($this->content($chunk, ['relation........', 'relation.......1']) as $id => $fields) {
                $item = strtolower((string) ($fields['relation........'][0]['value'] ?? ''));
                if (!isset($figures[$item])) {
                    continue;
                }
                if (isset($opens[(string) $id])) {
                    ++$figures[$item]['opened'];
                } else {
                    $clicked[$item][(string) ($fields['relation.......1'][0]['value'] ?? '')] = true;
                }
            }
        }
        foreach ($clicked as $item => $readers) {
            $figures[$item]['clicked'] = count($readers);
        }
        return $figures;
    }

    /** @return list<array{url: string, readers: int}> the most clicked links first, at most MAX_LINKS */
    public function links(string $statSliceId, string $itemId): array
    {
        $readers = [];
        foreach ($this->clicks($statSliceId, $itemId) as $click) {
            $readers[$click['url']][$click['reader']] = true;
        }
        $rows = [];
        foreach ($readers as $url => $who) {
            $rows[] = ['url' => (string) $url, 'readers' => count($who)];
        }
        usort($rows, static fn(array $a, array $b): int => [$b['readers'], $a['url']] <=> [$a['readers'], $b['url']]);
        return array_slice($rows, 0, self::MAX_LINKS);
    }

    /** the worker of the cache in counts() */
    public function opensFromDb(string $statSliceId, string $itemId): int
    {
        return count($this->events($statSliceId, [$itemId], 'open'));
    }

    /** the worker of the cache in clicks()
     *  @return list<array{url: string, reader: string}> */
    public function clicksFromDb(string $statSliceId, string $itemId): array
    {
        $clicks = [];
        foreach (array_chunk($this->events($statSliceId, [$itemId], 'click'), self::CHUNK) as $chunk) {
            foreach ($this->content($chunk, ['source_href.....', 'relation.......1']) as $fields) {
                $clicks[] = [
                    'url'    => (string) ($fields['source_href.....'][0]['value'] ?? ''),
                    'reader' => (string) ($fields['relation.......1'][0]['value'] ?? ''),
                ];
            }
        }
        return $clicks;
    }

    /** both figures read the clicks once per request
     *  @return list<array{url: string, reader: string}> */
    private function clicks(string $statSliceId, string $itemId): array
    {
        return AA::Contentcache()->get_result([$this, 'clicksFromDb'], [$statSliceId, strtolower($itemId)]);
    }

    // ------------------------------------------------------------ what tests replace

    /** @param list<string> $itemIds the sent items
     *  @return list<string> long ids of their events of $kind (open, click) */
    protected function events(string $statSliceId, array $itemIds, string $kind): array
    {
        $conds = [
            // a list of values is one condition, its values joined by OR
            ['relation........' => 1, 'operator' => '=', 'value' => $itemIds],
            ['category........' => 1, 'operator' => '==', 'value' => $kind],
        ];
        // an event moved to the trash or held back is one the editor of the slice threw out
        return array_map('strval', (new AA_Set([$statSliceId], $conds, null, \AA_BIN_APPROVED))->query()->longids());
    }

    /**
     * @param list<string> $ids
     * @param list<string> $fields
     * @return array<array-key, array<string, mixed>> as GetItemContent() returns it
     */
    protected function content(array $ids, array $fields): array
    {
        // a statistics slice may have a reading password; the figures leave no value of it but the links
        return GetItemContent(new zids($ids, 'l'), false, true, $fields);
    }
}
