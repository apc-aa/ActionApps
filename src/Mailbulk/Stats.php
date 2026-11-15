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
 * Opened means the same everywhere: the readers with an open or a click - a click proves the mail was read,
 * even when its open was thrown out.
 * @phpstan-type Click array{url: string, reader: string, time: int}
 */
class Stats
{
    /** links listed at most */
    public const MAX_LINKS = 50;
    /** events read by one GetItemContent() call */
    private const CHUNK = 500;

    /** @param string $list the id of the list - the events of a reader are found by it
     *  @return array{opened: int, clicked: int} opened = the readers with an open or a click,
     *                                            clicked = the readers with a click */
    public function counts(string $statSliceId, string $list, string $itemId): array
    {
        $itemId = strtolower($itemId);
        return self::figures($statSliceId, $list, $itemId, $this->opens($statSliceId, $itemId), $this->clicks($statSliceId, $itemId));
    }

    /** The figures of many sendings at once - a page of them: the opens by their ids per sending (shared with
     *  counts()), one search of the clicks and one read of them, whatever the number of the sendings.
     *  @param list<string> $itemIds
     *  @return array<string, array{opened: int, clicked: int}> item id (lowercase) => figures, every one of $itemIds */
    public function countsFor(string $statSliceId, string $list, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('strtolower', $itemIds)));
        if (!$itemIds) {
            return [];
        }
        $clicks = [];
        foreach (array_chunk($this->events($statSliceId, $itemIds, 'click'), self::CHUNK) as $chunk) {
            foreach ($this->content($chunk, ['relation........', 'relation.......1']) as $fields) {
                $item            = strtolower((string) ($fields['relation........'][0]['value'] ?? ''));
                $clicks[$item][] = ['reader' => (string) ($fields['relation.......1'][0]['value'] ?? '')];
            }
        }
        $figures = [];
        foreach ($itemIds as $itemId) {
            // opens far outnumber clicks: reading them to learn their sending would cost more than their ids each
            $figures[$itemId] = self::figures($statSliceId, $list, $itemId, $this->opens($statSliceId, $itemId), $clicks[$itemId] ?? []);
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

    /** The clicks of one sending, read once per request - the figures, the links and the recipients share them.
     *  @return list<Click> */
    public function clicks(string $statSliceId, string $itemId): array
    {
        return AA::Contentcache()->get_result([$this, 'clicksFromDb'], [$statSliceId, strtolower($itemId)]);
    }

    /** When the readers opened the mail: their open events computed (Tracker::eventId()), only those read - the
     *  opens of a big list are many.
     *  @param list<string> $readerIds long ids in lower case
     *  @return array<string, int> reader => the time of the open; a reader without one is not in it */
    public function openTimes(string $statSliceId, string $list, string $itemId, array $readerIds): array
    {
        $readers = [];
        foreach ($readerIds as $reader) {
            $readers[Tracker::eventId($statSliceId, $list, strtolower($itemId), $reader, TrackToken::OPEN, '')] = $reader;
        }
        $times = [];
        foreach (array_chunk(array_keys($readers), self::CHUNK) as $chunk) {
            foreach ($this->postDates($chunk) as $id => $time) {
                $times[$readers[$id]] = $time;
            }
        }
        return $times;
    }

    /** the worker of the cache in opens()
     *  @return list<string> long ids of the open events */
    public function opensFromDb(string $statSliceId, string $itemId): array
    {
        return $this->events($statSliceId, [$itemId], 'open');
    }

    /** the worker of the cache in clicks()
     *  @return list<Click> */
    public function clicksFromDb(string $statSliceId, string $itemId): array
    {
        $clicks = [];
        foreach (array_chunk($this->events($statSliceId, [$itemId], 'click'), self::CHUNK) as $chunk) {
            foreach ($this->content($chunk, ['source_href.....', 'relation.......1', 'post_date.......']) as $fields) {
                $clicks[] = [
                    'url'    => (string) ($fields['source_href.....'][0]['value'] ?? ''),
                    'reader' => (string) ($fields['relation.......1'][0]['value'] ?? ''),
                    'time'   => (int) ($fields['post_date.......'][0]['value'] ?? 0),
                ];
            }
        }
        return $clicks;
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

    /** @param list<string> $ids long ids of events
     *  @return array<string, int> id => its time, for those there and not thrown out (as events()) */
    protected function postDates(array $ids): array
    {
        $dates = [];
        // the item table only; a reading password of the slice hides no time
        foreach (GetItemContent(new zids($ids, 'l'), false, true, ['post_date.......'], null, \AA_BIN_APPROVED) as $id => $fields) {
            $dates[(string) $id] = (int) ($fields['post_date.......'][0]['value'] ?? 0);
        }
        return $dates;
    }

    // ------------------------------------------------------------

    /** the open events of a sending by their ids, searched once per request
     *  @return array<string, true> */
    private function opens(string $statSliceId, string $itemId): array
    {
        return array_fill_keys(AA::Contentcache()->get_result([$this, 'opensFromDb'], [$statSliceId, $itemId]), true);
    }

    /** @param array<string, true> $opens the open events
     *  @param list<array{reader: string}> $clicks
     *  @return array{opened: int, clicked: int} */
    private static function figures(string $statSliceId, string $list, string $itemId, array $opens, array $clicks): array
    {
        $clickers = array_unique(array_map(static fn(array $click): string => strtolower($click['reader']), $clicks));
        $opened   = count($opens);
        foreach ($clickers as $reader) {
            // the open a click stores too - unless it was thrown out
            if (!isset($opens[Tracker::eventId($statSliceId, $list, $itemId, $reader, TrackToken::OPEN, '')])) {
                ++$opened;
            }
        }
        return ['opened' => $opened, 'clicked' => count($clickers)];
    }
}
