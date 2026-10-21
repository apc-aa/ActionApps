<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * The one reading of the list of sent messages (GetListOfSentMessages), page by page, over as many
 * steps as it takes. Each page repeats OVERLAP records of the one before and must hold its last
 * record (the anchor); a page without it was moved by more than that, and the list is read again
 * from its start. The cursor is flat and survives JSON: f, t, o, a and c are this class's, any
 * other key is its consumer's and stays as the consumer leaves it. Pure: the list comes from $list.
 */
final class SentList
{
    /** pages of one reading of the list at most: one that does not end tells nothing */
    public const MAX_PAGES = 50;
    /** records each page repeats of the one before: the list moves between pages, a message in the
     *  antivirus check takes its place by the time it was submitted */
    public const OVERLAP = 20;
    /** the keys of the cursor read() keeps, whatever its consumer returns */
    private const OWN = ['f' => true, 't' => true, 'o' => true, 'a' => true, 'c' => true];

    /**
     * A reading of the window from $f to $t, started now
     * @return array{f: int, t: int, o: int, a: string, c: int}
     */
    public static function fresh(int $f, int $t): array
    {
        return ['f' => $f, 't' => $t, 'o' => 1, 'a' => '', 'c' => 0];
    }

    /**
     * Reads on from $cursor. A call reads at least one page even when $more says no - a step that
     * reads none would make no progress - unless the reading has used up its MAX_PAGES.
     * @param \Closure(int, int, int, int): list<SentMessage>                         $list   Client::sentMessages() - from, to, offset, limit
     * @param array<string, mixed>                                                     $cursor fresh() with the consumer's keys, or one fits() took:
     *                                                                                         any other may end in a TypeError
     * @param \Closure(list<SentMessage>, array<string, mixed>): array<string, mixed> $page   takes each page and returns the cursor with its
     *                                                                                         own keys as it needs them. It gets a page before
     *                                                                                         the anchor is checked: a page the list moved under
     *                                                                                         still lists true records
     * @param ?\Closure(): bool                                                        $more   may another page be read now; null = yes
     * @return array{0: array<string, mixed>, 1: ''|'ended'|'gave_up'} the cursor, and 'ended' = read to its end,
     *                                                                 'gave_up' = MAX_PAGES without an end, '' = to
     *                                                                 read on in another call. After 'ended' or
     *                                                                 'gave_up' the cursor is done with: a new reading
     *                                                                 starts with fresh()
     */
    public static function read(\Closure $list, array $cursor, \Closure $page, ?\Closure $more = null): array
    {
        for ($read = 0; ($cursor['c'] < self::MAX_PAGES) && (($read === 0) || is_null($more) || $more()); ++$read) {
            $records = $list($cursor['f'], $cursor['t'], $cursor['o'], Client::MAX_LIST);
            ++$cursor['c'];
            // array_replace() keeps the consumer's integer keys, which a spread would renumber
            $cursor = array_replace($page($records, $cursor), array_intersect_key($cursor, self::OWN));
            $ids    = array_map(static fn(SentMessage $s): string => $s->dmId, $records);
            if (($cursor['a'] !== '') && !in_array($cursor['a'], $ids, true)) {
                // the last record of the page before is gone from this one: the list moved by more
                // than OVERLAP, and what lay between may have been skipped
                [$cursor['o'], $cursor['a']] = [1, ''];
                continue;
            }
            if (count($records) < Client::MAX_LIST) {
                return [$cursor, 'ended'];
            }
            $cursor['o'] += Client::MAX_LIST - self::OVERLAP;
            $cursor['a']  = $ids[count($ids) - 1];
        }
        return [$cursor, ($cursor['c'] < self::MAX_PAGES) ? '' : 'gave_up'];
    }

    /**
     * A cursor of a call before goes on only when it is consistent with how read() leaves it: in the
     * window it started with, no further than its pages took it, past page 1 only with an anchor - a
     * cursor edited by hand would fail every step, or skip the list it never read
     * @param array<string, mixed> $cursor
     * @param int                  $f      the start of the window a reading started now would have
     * @param int                  $tMax   the furthest end its window may have
     */
    public static function fits(array $cursor, int $f, int $tMax): bool
    {
        [$t, $o, $a, $c] = [$cursor['t'] ?? null, $cursor['o'] ?? null, $cursor['a'] ?? null, $cursor['c'] ?? null];
        return (($cursor['f'] ?? null) === $f) && is_int($t) && is_int($o) && is_string($a) && is_int($c)
            && ($f <= $t) && ($t <= $tMax) && ($c >= 0)
            && ($o >= 1) && ($o <= 1 + $c * (Client::MAX_LIST - self::OVERLAP)) && (($o === 1) === ($a === ''));
    }
}
