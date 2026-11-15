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

    /** The figures of many sendings at once - a page of them: the opens counted per sending (their ids only,
     *  shared with counts()), one search of the clicks and one read of them, whatever the number of the sendings.
     *  @param list<string> $itemIds
     *  @return array<string, array{opened: int, clicked: int}> item id (lowercase) => figures, every one of $itemIds */
    public function countsFor(string $statSliceId, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('strtolower', $itemIds)));
        if (!$itemIds) {
            return [];
        }
        $figures = [];
        foreach ($itemIds as $itemId) {
            // opens far outnumber clicks: reading them to learn their sending would cost more than a count each
            $opened           = AA::Contentcache()->get_result([$this, 'opensFromDb'], [$statSliceId, $itemId]);
            $figures[$itemId] = ['opened' => (int) $opened, 'clicked' => 0];
        }
        $clicked = [];
        foreach (array_chunk($this->events($statSliceId, $itemIds, 'click'), self::CHUNK) as $chunk) {
            foreach ($this->content($chunk, ['relation........', 'relation.......1']) as $fields) {
                $item = strtolower((string) ($fields['relation........'][0]['value'] ?? ''));
                if (isset($figures[$item])) {
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

    /** The events of one sending by reader, for the table of its recipients: one search of both kinds, read in chunks.
     *  @return array<string, array{opened: int, clicks: list<array{url: string, time: int}>}> reader (lowercase) =>
     *          the time of the open event (0 = none) and each click, the first first */
    public function readers(string $statSliceId, string $itemId): array
    {
        $readers = [];
        $fields  = ['category........', 'relation.......1', 'source_href.....', 'post_date.......'];
        foreach (array_chunk($this->events($statSliceId, [strtolower($itemId)], ''), self::CHUNK) as $chunk) {
            foreach ($this->content($chunk, $fields) as $event) {
                $reader             = strtolower((string) ($event['relation.......1'][0]['value'] ?? ''));
                $time               = (int) ($event['post_date.......'][0]['value'] ?? 0);
                $kind               = (string) ($event['category........'][0]['value'] ?? '');
                $readers[$reader] ??= ['opened' => 0, 'clicks' => []];
                if ($kind === 'open') {
                    // one open event per reader; should there be more, the first counts
                    $opened                      = $readers[$reader]['opened'];
                    $readers[$reader]['opened'] = ($opened > 0) ? min($opened, $time) : $time;
                } elseif ($kind === 'click') {
                    $readers[$reader]['clicks'][] = ['url' => (string) ($event['source_href.....'][0]['value'] ?? ''), 'time' => $time];
                }
            }
        }
        foreach ($readers as &$events) {
            usort($events['clicks'], static fn(array $a, array $b): int => [$a['time'], $a['url']] <=> [$b['time'], $b['url']]);
        }
        unset($events);
        return $readers;
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
     *  @return list<string> long ids of their events of $kind (open, click; '' = both) */
    protected function events(string $statSliceId, array $itemIds, string $kind): array
    {
        // a list of values is one condition, its values joined by OR
        $conds = [['relation........' => 1, 'operator' => '=', 'value' => $itemIds]];
        if ($kind !== '') {
            $conds[] = ['category........' => 1, 'operator' => '==', 'value' => $kind];
        }
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
