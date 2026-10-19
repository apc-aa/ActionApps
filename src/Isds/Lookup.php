<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Isds\Registry\Box;

/**
 * What became of messages of unknown fate, by the list of sent messages (GetListOfSentMessages). A
 * record with the message's reference and its recipient's box, delivered no sooner than SKEW before
 * the attempt, is a candidate; the subject only tells candidates apart. One candidate of the subject
 * is the message; two, or only ones of another subject, are for a person: a reference is unique in one
 * database only - another install or a box two installs share may have sent one alike. A restored copy
 * of the database sending the same campaign from the same box makes a match no list tells apart, which
 * is left so. ISDS lists a message once it reached the box, after an antivirus check of up to 240 s,
 * so one not listed is waited for till CREATE_TIMEOUT + GRACE after its attempt, and only then taken
 * for one that never went. The list is read page by page while $more allows, over as many steps as it
 * takes; only a list read to its end decides. Pure: the list comes from $list.
 */
final readonly class Lookup
{
    /** seconds the clocks of AA and ISDS may differ by: the list starts this long before the first
     *  attempt, and a message delivered this long before its attempt is still the one */
    public const SKEW = 60;
    /** seconds after the longest CreateMessage a message may still be missing from the list - a wrong
     *  "never sent" makes a person send it twice */
    public const GRACE = 3600;
    /** pages of one reading of the list at most: one that does not end tells nothing */
    public const MAX_PAGES = 50;
    /** records each page repeats of the one before: the list moves between pages, a message in the
     *  antivirus check takes its place by the time it was submitted */
    public const OVERLAP = 20;
    /** seconds an attempt is looked up for: ISDS deletes a sent message some 90 days after its delivery,
     *  so the list of an older one may be empty without telling anything */
    public const KEPT = 30 * 86400;

    /**
     * @param array<int, SentMessage> $found   index => the message the list holds for it
     * @param list<int>               $missing not listed past its wait: never sent
     * @param list<int>               $unsure  listed more than once, only with another subject, the list did
     *                                         not end, or its attempt at no sane time: a person decides
     * @param array<int, int>         $waiting index => the time till which it may still be listed
     * @param array<string, mixed>    $scan    [] = decided; else nothing is, and the next step reads on
     *                                         from here (resolve() $scan)
     */
    public function __construct(public array $found, public array $missing, public array $unsure, public array $waiting, public array $scan = []) {}

    /** when to look again: the first time a waiting message is decided, 0 = none waits */
    public function until(): int
    {
        return ($this->waiting === []) ? 0 : min($this->waiting);
    }

    /**
     * @param \Closure(int, int, int, int): list<SentMessage>      $list       Client::sentMessages() - from, to, offset, limit
     * @param array<int, array{ref: string, box: string, at: int}> $wanted     index => the reference, the recipient's
     *                                                                         box and the time its message was tried
     * @param string                                               $annotation the subject of the sending
     * @param array<string, mixed>                                 $scan       the $scan of the step before, [] = read anew
     * @param ?\Closure(): bool                                    $more       may another page be read now; null = yes.
     *                                                                         The first page of a call is read anyway: a
     *                                                                         step that reads none would make no progress
     */
    public static function resolve(\Closure $list, array $wanted, string $annotation, int $now, array $scan = [], ?\Closure $more = null): self
    {
        ksort($wanted);
        // a time no attempt had - the record edited by hand, a clock set back - or one too old for the list
        $valid  = array_filter($wanted, static fn(array $w): bool => ($w['at'] > $now - self::KEPT) && ($w['at'] <= $now + self::SKEW));
        $unsure = array_keys(array_diff_key($wanted, $valid));
        if ($valid === []) {
            return new self([], [], $unsure, []);
        }
        $at    = array_column($valid, 'at');
        $fresh = ['k' => array_keys($valid), 'f' => min($at) - self::SKEW, 't' => min($now, max($at) + Client::CREATE_TIMEOUT + self::GRACE), 'o' => 1, 'a' => '', 'c' => 0, 'x' => [], 'n' => []];
        $scan  = self::fits($scan, $fresh) ? $scan : $fresh;
        $keys = [];
        foreach ($valid as $k => $w) {
            $keys[$w['ref'] . "\n" . Box::normalizeId($w['box'])] = $k;
        }
        $subject = Text::isdsKey($annotation);
        $ended   = false;
        for ($read = 0; !$ended && ($scan['c'] < self::MAX_PAGES) && (($read === 0) || is_null($more) || $more()); ++$read) {
            $records = $list($scan['f'], $scan['t'], $scan['o'], Client::MAX_LIST);
            ++$scan['c'];
            foreach ($records as $sent) {
                $k = $keys[$sent->senderRef . "\n" . $sent->recipient] ?? null;
                // one refused by the antivirus check has no delivery time, and is listed all the same
                if (is_null($k) || (!is_null($sent->deliveredAt) && ($sent->deliveredAt < $valid[$k]['at'] - self::SKEW))) {
                    continue;
                }
                if (Text::isdsKey($sent->annotation) !== $subject) {
                    $scan['n'][$k] = 1;
                } elseif (isset($scan['x'][$k][$sent->dmId]) || (count($scan['x'][$k] ?? []) < 2)) {
                    // by its id: a record read twice where pages overlap is one message
                    $scan['x'][$k][$sent->dmId] = [$sent->dmId, $sent->status, $sent->senderRef, $sent->recipient, $sent->deliveredAt, $sent->acceptedAt, $sent->annotation];
                }
            }
            $ids = array_map(static fn(SentMessage $s): string => $s->dmId, $records);
            if (($scan['a'] !== '') && !in_array($scan['a'], $ids, true)) {
                // the last record of the page before is gone from this one: the list moved by more
                // than OVERLAP, and what lay between may have been skipped
                [$scan['o'], $scan['a']] = [1, ''];
                continue;
            }
            $ended      = (count($records) < Client::MAX_LIST);
            $scan['o'] += Client::MAX_LIST - self::OVERLAP;
            $scan['a']  = ($ids === []) ? '' : $ids[count($ids) - 1];
        }
        if (!$ended) {
            return ($scan['c'] < self::MAX_PAGES) ? new self([], [], [], [], $scan) : new self([], [], self::sorted([...$unsure, ...array_keys($valid)]), []);
        }
        [$found, $missing, $waiting] = [[], [], []];
        foreach ($valid as $k => $w) {
            $exact = array_values($scan['x'][$k] ?? []);
            $until = $w['at'] + Client::CREATE_TIMEOUT + self::GRACE;
            match (true) {
                count($exact) > 1     => $unsure[]    = $k,
                count($exact) === 1   => $found[$k]   = new SentMessage(...$exact[0]),
                isset($scan['n'][$k]) => $unsure[]    = $k,
                $scan['t'] < $until   => $waiting[$k] = $until,
                default               => $missing[]   = $k,
            };
        }
        return new self($found, $missing, self::sorted($unsure), $waiting);
    }

    /**
     * The reading of a step before goes on only when it is consistent with how this class leaves it:
     * of these messages, in the window it started with, no further than its pages took it, past page 1
     * only with the last record of the page before (which the next page must hold), matches of the
     * shape SentMessage takes - a position edited by hand would fail every step, or skip the list it
     * never read
     * @param array<string, mixed> $scan
     * @param array<string, mixed> $fresh a reading started now
     */
    private static function fits(array $scan, array $fresh): bool
    {
        [$t, $o, $c] = [$scan['t'] ?? null, $scan['o'] ?? null, $scan['c'] ?? null];
        if ((($scan['k'] ?? null) !== $fresh['k']) || (($scan['f'] ?? null) !== $fresh['f']) || !is_int($t) || !is_int($o) || !is_int($c)
            || !is_string($scan['a'] ?? null) || !is_array($scan['n'] ?? null) || !is_array($scan['x'] ?? null)
            || ($t < $fresh['f']) || ($t > $fresh['t']) || ($c < 0) || ($o < 1) || ($o > 1 + $c * (Client::MAX_LIST - self::OVERLAP))
            || (($o === 1) !== ($scan['a'] === ''))) {
            return false;
        }
        foreach ($scan['x'] as $k => $rows) {
            if (!in_array($k, $fresh['k'], true) || !is_array($rows)) {
                return false;
            }
            foreach ($rows as $row) {
                $types = (is_array($row) && array_is_list($row)) ? implode(',', array_map(get_debug_type(...), $row)) : '';
                if (preg_match('/^string,int,string,string,(int|null),(int|null),string\z/', $types) !== 1) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @param list<int> $indexes
     * @return list<int>
     */
    private static function sorted(array $indexes): array
    {
        sort($indexes);
        return $indexes;
    }
}
