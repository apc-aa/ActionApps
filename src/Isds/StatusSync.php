<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * The rules of following the delivery of sent messages: which windows of state changes
 * (GetMessageStateChanges) a round reads, which local days it reads whole again in the morning, how
 * what ISDS tells joins what the record keeps, and how long following lasts. The record keeps each
 * message as an entry "s:t:d:a" - dmMessageStatus, the time of that status, the time it was delivered
 * to the box and the time it was accepted (by a login, or 10 days after delivery), unix seconds, 0 =
 * not known. Local time (Europe/Prague) serves only for the morning, the day and where a window is
 * cut. What the state changes never tell - the antivirus check, a window ISDS no longer keeps, the
 * end - comes from the list of sent messages (due()). Pure: the time comes as a parameter.
 */
final class StatusSync
{
    /** a round reads up to a whole hour */
    public const ROUND = 3600;
    /** ISDS may record a change this long after its time (WS 1.11.4), so a round reads no closer to now */
    public const LAG = 300;
    /** each round reads again this long before where the round before ended (WS 1.11.5) */
    public const OVERLAP = 120;
    /** the local hour from which yesterday is read whole: ISDS records the acceptances after 10 days
     *  of a day only after its midnight */
    public const MORNING = 6;
    /** calls of ISDS in one step at most */
    public const MAX_CALLS = 20;
    /** days following lasts at most: an acceptance after 10 days moves on to a working day, up to the
     *  14th, and is recorded only after the midnight that ends it */
    public const WATCH_DAYS = 16;
    /** no call for state changes starts more than this before now: ISDS gives those of the last 15 days
     *  (WS 2.9.3), and a day stays in reserve */
    public const CHANGES_KEPT = 14 * 86400;
    /** the longest call: one local day, which is 25 h when summer time ends */
    public const MAX_SPAN = 25 * 3600;
    /** dmMessageStatus that settles the outcome of delivery: refused by the antivirus check, accepted by
     *  a login, read, undeliverable, deleted, in the vault - what may come after it changes nothing */
    public const FINAL = [3, 6, 7, 8, 9, 10];
    /** dmMessageStatus that fails a message sent: refused by the antivirus check, or undeliverable - an
     *  acceptance it had before does not make it delivered (WS 1.12.1) */
    public const FAILED = [3, 8];
    /** dmMessageStatus => rank; the higher rank wins a merge, 3 (refused by the antivirus check) and 8
     *  (undeliverable) over any other */
    public const ORDER = [1 => 1, 2 => 2, 4 => 3, 5 => 4, 6 => 5, 7 => 6, 9 => 7, 10 => 8, 3 => 9, 8 => 10];
    /** an entry: a status 1-10 and three times of at most 10 digits, each written one way only, so that
     *  entries alike are strings alike */
    public const ENTRY = '/^(10|[1-9]):(0|[1-9]\d{0,9}):(0|[1-9]\d{0,9}):(0|[1-9]\d{0,9})\z/';

    private const ZONE = 'Europe/Prague';
    /** an entry never holds a time of more digits */
    private const LATEST = 9_999_999_999;

    /**
     * dmID => the index of its message. The map may have been edited by hand, so a value that is not a
     * dmID of digits is left out, and an id listed twice keeps its first index.
     * @param array<array-key, mixed> $dmIds index => dmID, the record's dm_ids
     * @return array<array-key, int> PHP turns a key of digits that fits an int into an int, so look a
     *                               dmID up here; a key read back is no string
     */
    public static function indexOf(array $dmIds): array
    {
        $indexOf = [];
        foreach ($dmIds as $k => $id) {
            if (is_int($k) && ($k >= 0) && is_string($id) && (preg_match('/^\d{1,20}\z/', $id) === 1)) {
                $indexOf[$id] ??= $k;
            }
        }
        return $indexOf;
    }

    /**
     * An entry of the record; null when it is not of the shape ENTRY - edited by hand, it counts as not
     * known and the next merge overwrites it
     * @return ?array{s: int, t: int, d: int, a: int}
     */
    public static function entry(string $s): ?array
    {
        if (preg_match(self::ENTRY, $s) !== 1) {
            return null;
        }
        [$status, $t, $d, $a] = array_map(intval(...), explode(':', $s));
        return ['s' => $status, 't' => $t, 'd' => $d, 'a' => $a];
    }

    /** @param array{s: int, t: int, d: int, a: int} $e */
    public static function encode(array $e): string
    {
        return "{$e['s']}:{$e['t']}:{$e['d']}:{$e['a']}";
    }

    /**
     * $state with what $events tell. The status of the higher rank wins (ORDER), its time is the earliest
     * the status was told with; delivered and accepted are the earliest known. The result does not depend
     * on the order of the events or on an event told twice, so windows may overlap and a day may be read
     * again. An event of a message the sending did not send - another sending, someone else sending from
     * the box - is passed over, and so is a status out of 1-10.
     * - A time out of [started - SKEW, now + SKEW] is not known: the times of delivery have legal weight,
     *   and a minimum would keep a nonsense time for good.
     * - A record of the list of sent messages dates status 4 by its delivery and 5 by its acceptance. The
     *   acceptance it gives for 6 may be the one after 10 days rather than the login (WS 1.8.3), so the
     *   time of 6 - as of any other status - it does not tell.
     * @param array<array-key, mixed>           $state   index => entry, the record's dm_state
     * @param iterable<StateChange|SentMessage> $events
     * @param array<array-key, int>             $indexOf indexOf()
     * @param int                               $started when the run started: it sent no message sooner
     * @return array{state: array<array-key, mixed>, changed: list<int>} the map, and the indexes whose
     *                                                                    entry changed, in order
     */
    public static function merge(array $state, iterable $events, array $indexOf, int $started, int $now): array
    {
        [$from, $to] = [max(1, $started - Lookup::SKEW), min(self::LATEST, $now + Lookup::SKEW)];
        $sane   = static fn(?int $time): int => (!is_null($time) && ($time >= $from) && ($time <= $to)) ? $time : 0;
        $merged = [];
        foreach ($events as $event) {
            $k = $indexOf[$event->dmId] ?? null;
            if (is_null($k) || !isset(self::ORDER[$event->status])) {
                continue;
            }
            $s = $event->status;
            if ($event instanceof StateChange) {
                $t       = $sane($event->time);
                [$d, $a] = [($s === 4) ? $t : 0, in_array($s, [5, 6], true) ? $t : 0];
            } else {
                [$d, $a] = [$sane($event->deliveredAt), $sane($event->acceptedAt)];
                $t       = ($s === 4) ? $d : (($s === 5) ? $a : 0);
            }
            $merged[$k] = self::join($merged[$k] ?? self::at($state, $k), ['s' => $s, 't' => $t, 'd' => $d, 'a' => $a]);
        }
        $changed = [];
        foreach ($merged as $k => $e) {
            if ($e !== self::at($state, $k)) {
                $state[$k] = self::encode($e);
                $changed[] = $k;
            }
        }
        sort($changed);
        return ['state' => $state, 'changed' => $changed];
    }

    /** H: where a round's reading ends - the last whole hour at least LAG ago */
    public static function boundary(int $now): int
    {
        return intdiv($now - self::LAG, self::ROUND) * self::ROUND;
    }

    /**
     * The windows a round reads state changes in, oldest first: from OVERLAP before $watchLast to
     * boundary(), in calls as cut(). Every call ends past $watchLast: a step that makes one call only
     * still moves on, also over a local midnight. None when the first would start more than CHANGES_KEPT
     * before now: ISDS keeps no more, and what it no longer gives the list of sent messages tells (due()).
     * @param int $span the longest call, the overlap included: MAX_SPAN, or less after ISDS answered one
     *                  too large (halve()) - an hour it refused must be tried as an hour
     * @param int $max  the windows to give at most: a step reads no more, and a $watchLast long past
     *                  would make a list of thousands
     * @return list<array{int, int}> from, to; none until boundary() is past $watchLast
     */
    public static function windows(int $watchLast, int $now, int $span, int $max = self::MAX_CALLS): array
    {
        return self::lost($watchLast, $now) ? [] : self::cut($watchLast, self::boundary($now), $span, $max, self::OVERLAP);
    }

    /**
     * The next call of a step, and where the reading stands once ISDS answers it; null = nothing to read
     * now. The window of changes first (windows()), then the first day to read whole (days()) from where
     * a step got in it: in short calls a day takes more of them than a step makes.
     * @param array{last: int, day: string, at: int, span: int} $read where the reading stands: watch_last,
     *        watch_day, how far the first day of days() is read (0 = not begun; a time out of that day
     *        reads it from its start) and the longest call
     * @return ?array{from: int, to: int, read: array{last: int, day: string, at: int, span: int}}
     */
    public static function nextCall(array $read, int $now): ?array
    {
        $window = self::windows($read['last'], $now, $read['span'], 1)[0] ?? null;
        if (!is_null($window)) {
            return ['from' => $window[0], 'to' => $window[1], 'read' => array_replace($read, ['last' => $window[1]])];
        }
        $day = self::days($read['day'], $now)[0] ?? null;
        if (is_null($day)) {
            return null;
        }
        $start         = self::day($day) ?? throw new \LogicException('days() gives days only');
        [$begin, $end] = [$start->getTimestamp(), $start->add(new \DateInterval('P1D'))->getTimestamp()];
        [$from, $to]   = self::cut((($read['at'] > $begin) && ($read['at'] < $end)) ? $read['at'] : $begin, $end, $read['span'], 1)[0];
        return ['from' => $from, 'to' => $to, 'read' => array_replace($read, ($to === $end) ? ['day' => $day, 'at' => 0] : ['at' => $to])];
    }

    /**
     * The span to read with after ISDS answered a call as too large; null = an hour was too large
     * @param int $span the length of the call ISDS answered so - not the span asked for, which may have
     *                  been longer than the call
     */
    public static function halve(int $span): ?int
    {
        return ($span <= self::ROUND) ? null : max(self::ROUND, intdiv($span, 2));
    }

    /**
     * The local days to read whole now, oldest first: from the day after $watchDay to yesterday, none
     * that starts more than CHANGES_KEPT before now, and none before MORNING. A $watchDay that is no day
     * before today - edited by hand, or a clock set back - gives yesterday, so that the record has a day
     * again.
     * @param string $watchDay the last day read whole, Y-m-d
     * @return list<string> Y-m-d; each is read from its local midnight to the next one, 23 to 25 h - an
     *                      acceptance after 10 days, dated 23:59:59.999 of the day, lies inside
     */
    public static function days(string $watchDay, int $now): array
    {
        if (!self::isMorning($now)) {
            return [];
        }
        $yesterday = self::yesterday($now);
        $days      = [];
        for ($day = self::firstDay($watchDay, $yesterday, $now); $day <= $yesterday; $day = $day->add(new \DateInterval('P1D'))) {
            $days[] = $day->format('Y-m-d');
        }
        return $days;
    }

    /**
     * The last day counted as read whole when following starts: yesterday, before MORNING the day before
     * yesterday. The first round reads the state changes from the start of the run, and by then ISDS has
     * recorded the acceptances of every day before yesterday - of yesterday only from MORNING on.
     */
    public static function firstWatchDay(int $now): string
    {
        return self::local($now)->setTime(0, 0)->sub(new \DateInterval(self::isMorning($now) ? 'P1D' : 'P2D'))->format('Y-m-d');
    }

    public static function isFinal(int $s): bool
    {
        return in_array($s, self::FINAL, true);
    }

    /** when following that started at $watchFrom may end without every message in a final status - after
     *  the list of sent messages was read once more (due()) */
    public static function deadline(int $watchFrom): int
    {
        return $watchFrom + self::WATCH_DAYS * 86400;
    }

    /** Following that started at $watchFrom calls ISDS no more and ends: ISDS no longer keeps the state
     *  changes up to its deadline(). Else an ISDS down for good, or a datovka_conf.php never readable
     *  again, would put the end off for ever. */
    public static function over(int $watchFrom, int $now): bool
    {
        return $now >= self::deadline($watchFrom) + self::CHANGES_KEPT;
    }

    /**
     * Why the list of sent messages is to be read now - what the state changes never tell: 'a' a message
     * the antivirus check may have refused (ISDS records no change 2 -> 3), once boundary() is GRACE past
     * the start of following; 'b' state changes ISDS no longer keeps (windows()); 'c' once at the
     * deadline() while a message is not final. '' = none, and none past over().
     * @param array<string, mixed> $w watch_from and watch_last (times), watch_rec (the time the list was read
     *                                last, null none), dm_state (index => entry) and indexOf of the messages sent
     * @return ''|'a'|'b'|'c'
     */
    public static function due(array $w, int $now): string
    {
        $since = self::time($w['watch_from'] ?? null);
        $last  = self::time($w['watch_last'] ?? null);
        if (is_null($since) || is_null($last) || self::over($since, $now)) {
            return '';
        }
        $rec     = self::time($w['watch_rec'] ?? null);
        $state   = is_array($w['dm_state'] ?? null) ? $w['dm_state'] : [];
        $indexOf = is_array($w['indexOf'] ?? null) ? $w['indexOf'] : [];
        $end     = self::deadline($since);
        if (self::lost($last, $now)) {
            return 'b';
        }
        if (($now >= $end) && (is_null($rec) || ($rec < $end))) {
            return self::allFinal($state, $indexOf) ? '' : 'c';
        }
        return ((self::boundary($now) >= $since + Lookup::GRACE) && is_null($rec) && self::unchecked($state, $indexOf)) ? 'a' : '';
    }

    /**
     * The window a reading of the list of sent messages reads, the same for every reason and over all its
     * steps: from the start of the run to GRACE past the start of following - each message was delivered
     * by then -, no further than $h, the boundary() when the reading started. Nothing is read past over(),
     * so the window lies within Lookup::KEPT, the time ISDS surely keeps sent messages.
     * @return array{int, int}
     */
    public static function listWindow(int $started, int $watchFrom, int $h): array
    {
        $from = max(0, $started - Lookup::SKEW);
        return [$from, max($from, min($h, $watchFrom + Lookup::GRACE))];
    }

    /**
     * The reading of the list a step goes on with: $l of a step before when SentList takes it, in the
     * window listWindow() gives its h, that h no later than boundary() - edited by hand, it would skip what
     * it never read -; else one started now when due(). null = none to read. Past changes ISDS no longer
     * keeps, watch_last stands till the reading is done and h is no sooner. Else the windows move
     * watch_last past h, and a reading ISDS breaks off again and again would start anew each hour; such
     * a reading started GRACE past the start of following at the soonest, so its window is whole.
     * @param mixed                $l as the position keeps it
     * @param array<string, mixed> $w as due(), with started
     * @return ?array<string, mixed> SentList's cursor with h
     */
    public static function listCursor(mixed $l, array $w, int $now): ?array
    {
        $started = self::time($w['started'] ?? null) ?? 0;
        $since   = self::time($w['watch_from'] ?? null);
        $last    = self::time($w['watch_last'] ?? null);
        $h       = is_array($l) ? ($l['h'] ?? null) : null;
        if (is_array($l) && is_int($h) && !is_null($since) && !is_null($last) && ($h <= self::boundary($now))
            && (self::lost($last, $now) ? ($last <= $h) : ($h >= $since + Lookup::GRACE))) {
            [$f, $t] = self::listWindow($started, $since, $h);
            if (SentList::fits($l, $f, $t) && ($l['t'] === $t)) {
                return $l;
            }
        }
        if (is_null($since) || (self::due($w, $now) === '')) {
            return null;
        }
        $h = self::boundary($now);
        return SentList::fresh(...self::listWindow($started, $since, $h)) + ['h' => $h];
    }

    /** watch_last once a reading that started at $h is done: past changes ISDS no longer keeps (windows())
     *  the list told what they would have, so the changes are read on from $h. The changes of the last
     *  14 days are not read then: the list gives the times of delivery and acceptance, not of a login or
     *  of a box made inaccessible, which stay 0. */
    public static function afterList(int $watchLast, int $h, int $now): int
    {
        return self::lost($watchLast, $now) ? max($watchLast, $h) : $watchLast;
    }

    /**
     * Every message sent is in a final status: nothing ISDS may still tell changes what became of it
     * @param array<array-key, mixed> $state   index => entry, the record's dm_state
     * @param array<array-key, int>   $indexOf indexOf() of the messages sent
     */
    public static function allFinal(array $state, array $indexOf): bool
    {
        foreach ($indexOf as $k) {
            $entry = self::at($state, $k);
            if (is_null($entry) || !self::isFinal($entry['s'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Following ends: no work a step can do now (pending(), the list due() too), and every message sent in
     * a final status or the deadline() past - only from MORNING on, though: in the night yesterday is not
     * read whole yet, and an end then would lose the time of an acceptance after 10 days that only that
     * reading tells. Past over() it ends once the items are written, whatever is unread.
     * @param array<string, mixed>    $w       as pending()
     * @param array<array-key, mixed> $state   as allFinal()
     * @param array<array-key, int>   $indexOf as allFinal()
     * @throws \JsonException as pending()
     */
    public static function ends(array $w, array $state, array $indexOf, int $watchFrom, int $now): bool
    {
        $w = array_replace($w, ['watch_from' => $watchFrom, 'dm_state' => $state, 'indexOf' => $indexOf]);
        if (self::over($watchFrom, $now)) {
            return !self::dirty($w['dm_dirty'] ?? null);
        }
        return self::isMorning($now) && !self::pending($w, $now) && (($now >= self::deadline($watchFrom)) || self::allFinal($state, $indexOf));
    }

    /**
     * Is there work a step can do now, rather than wait for the next round: items still to write, the
     * list of sent messages read in part or due(), a window of changes or a day not read. In the night
     * days() has none, so a night with yesterday unread waits for the next hour. Whatever the values, it
     * throws no TypeError and builds no list of windows - due() goes over the messages sent once: a
     * watch_last that is no time means windows from the start.
     * @param array<string, mixed> $w watch_last, watch_day and dm_dirty as the record keeps them (text,
     *                                JSON) or as read from it (a number, the decoded map), l of the position,
     *                                and what due() reads
     * @throws \JsonException dm_dirty edited by hand into no JSON
     */
    public static function pending(array $w, int $now): bool
    {
        $last      = self::time($w['watch_last'] ?? null);
        $day       = is_string($w['watch_day'] ?? null) ? $w['watch_day'] : '';
        $yesterday = self::yesterday($now);
        return self::dirty($w['dm_dirty'] ?? null) || (is_array($w['l'] ?? null) && ($w['l'] !== []))
            || is_null($last) || (self::boundary($now) > $last)
            || (self::isMorning($now) && (self::firstDay($day, $yesterday, $now) <= $yesterday))
            || (self::due($w, $now) !== '');
    }

    /**
     * [$from - $back, $to] in calls of $span at most - less than ROUND counts as ROUND, a position edited
     * to 0 would never end - and cut at each local midnight after $from, so that no call spans more than
     * a local day and the first ends past $from
     * @return list<array{int, int}> the first $max calls
     */
    private static function cut(int $from, int $to, int $span, int $max, int $back = 0): array
    {
        $span  = max(self::ROUND, $span);
        $calls = [];
        for ($start = $from - $back; ($from < $to) && (count($calls) < $max); $start = $from = $end) {
            $end     = min($to, $start + $span, self::local($from)->setTime(0, 0)->add(new \DateInterval('P1D'))->getTimestamp());
            $calls[] = [$start, $end];
        }
        return $calls;
    }

    /** the window of changes from OVERLAP before $watchLast starts sooner than ISDS keeps them */
    private static function lost(int $watchLast, int $now): bool
    {
        return $watchLast - self::OVERLAP < $now - self::CHANGES_KEPT;
    }

    /**
     * A message sent has no status the antivirus check gives or follows: none known, submitted or
     * timestamped only
     * @param array<array-key, mixed> $state
     * @param array<array-key, int>   $indexOf
     */
    private static function unchecked(array $state, array $indexOf): bool
    {
        foreach ($indexOf as $k) {
            if ((self::at($state, $k)['s'] ?? 0) < 3) {
                return true;
            }
        }
        return false;
    }

    /** a time as the record keeps it, text or number of up to 10 digits; null = none */
    private static function time(mixed $value): ?int
    {
        return ((is_int($value) || is_string($value)) && (preg_match('/^\d{1,10}\z/', (string) $value) === 1)) ? (int) $value : null;
    }

    /**
     * The first day to read whole: the day after $watchDay, but none that starts more than CHANGES_KEPT
     * before now - ISDS would refuse the call. A $watchDay that is no day before today gives yesterday.
     */
    private static function firstDay(string $watchDay, \DateTimeImmutable $yesterday, int $now): \DateTimeImmutable
    {
        $day = self::day($watchDay);
        if (is_null($day) || ($day > $yesterday)) {
            return $yesterday;
        }
        $day    = $day->add(new \DateInterval('P1D'));
        $oldest = self::local($now - self::CHANGES_KEPT)->setTime(0, 0)->add(new \DateInterval('P1D'));
        return ($day < $oldest) ? $oldest : $day;
    }

    /**
     * Does dm_dirty, its text or the decoded map, list an item: a key that is an index, as in indexOf().
     * A scalar edited in - "x", 1, false - or a key like "007" or "-1" lists none, or every step would go
     * on at once for ever.
     * @throws \JsonException
     */
    private static function dirty(mixed $map): bool
    {
        $items = is_array($map) ? $map : ((is_string($map) && ($map !== '')) ? json_decode($map, true, 2, JSON_THROW_ON_ERROR) : null);
        foreach (array_keys(is_array($items) ? $items : []) as $k) {
            if (is_int($k) && ($k >= 0)) {
                return true;
            }
        }
        return false;
    }

    /** from MORNING on, yesterday can be read whole: ISDS has recorded its acceptances after 10 days */
    private static function isMorning(int $now): bool
    {
        return (int) self::local($now)->format('G') >= self::MORNING;
    }

    /** the local midnight that starts yesterday */
    private static function yesterday(int $now): \DateTimeImmutable
    {
        return self::local($now)->setTime(0, 0)->sub(new \DateInterval('P1D'));
    }

    private static function local(int $time): \DateTimeImmutable
    {
        return (new \DateTimeImmutable("@$time"))->setTimezone(new \DateTimeZone(self::ZONE));
    }

    /** the local midnight that starts $day, null when $day is not Y-m-d of a day that exists */
    private static function day(string $day): ?\DateTimeImmutable
    {
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone(self::ZONE));
        return (($at !== false) && ($at->format('Y-m-d') === $day)) ? $at : null;
    }

    /**
     * @param array<array-key, mixed> $state
     * @return ?array{s: int, t: int, d: int, a: int}
     */
    private static function at(array $state, int $k): ?array
    {
        return is_string($state[$k] ?? null) ? self::entry($state[$k]) : null;
    }

    /**
     * Two entries of one message as one; $x null = none known yet
     * @param ?array{s: int, t: int, d: int, a: int} $x
     * @param array{s: int, t: int, d: int, a: int}  $y
     * @return array{s: int, t: int, d: int, a: int}
     */
    private static function join(?array $x, array $y): array
    {
        if (is_null($x)) {
            return $y;
        }
        [$s, $t] = match (self::ORDER[$x['s']] <=> self::ORDER[$y['s']]) {
            1       => [$x['s'], $x['t']],
            -1      => [$y['s'], $y['t']],
            default => [$x['s'], self::earliest($x['t'], $y['t'])],
        };
        return ['s' => $s, 't' => $t, 'd' => self::earliest($x['d'], $y['d']), 'a' => self::earliest($x['a'], $y['a'])];
    }

    /** the earlier of two times, 0 = not known */
    private static function earliest(int $a, int $b): int
    {
        return (($a === 0) || (($b !== 0) && ($b < $a))) ? $b : $a;
    }
}
