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
        return count($this->events($statSliceId, $itemId, 'open'));
    }

    /** the worker of the cache in clicks()
     *  @return list<array{url: string, reader: string}> */
    public function clicksFromDb(string $statSliceId, string $itemId): array
    {
        $clicks = [];
        foreach (array_chunk($this->events($statSliceId, $itemId, 'click'), self::CHUNK) as $chunk) {
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

    /** @return list<string> long ids of the events of $kind (open, click) of the sent item */
    protected function events(string $statSliceId, string $itemId, string $kind): array
    {
        $conds = [
            ['relation........' => 1, 'operator' => '==', 'value' => $itemId],
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
