<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use AA_Log;
use AA_Logentry;
use AA_Logentry_Ne;
use AA_Logentry_Nf;
use AA_Logentry_Nran;
use AA_Logentry_Nrun;
use RuntimeException;

/** The nightly test's findings in the AA log (type NIGHTLY), as State Check shows them.
 *
 *  Every push writes the whole current state as one set of rows sharing one `time`, so a
 *  reader needs only the newest set - CheckNightly runs on every load of State Check.
 *  The state is kept per check type and spec: per pair the newest run that ran it wins, and
 *  a one-spec rerun replaces only the pairs it ran - the others are carried over as they were.
 *  A finding carries when it was first seen and for how many runs in a row, so the older sets
 *  are not needed: LogCleanup deletes them after 40 days, and a pair that has not run for
 *  that long is no longer carried over.
 *
 *  @phpstan-type Found array{page: string, url: string, severity: string, known: bool, count: int, first: int, runs: int, evidence: string}
 *  @phpstan-type Pair array{check: string, spec: string, run: string, ran: int, reason: string, findings: array<string, Found>}
 *  @phpstan-type State array{run: array{run: string, mode: string, started: int, finished: int}, full: array{run: string, started: int}, pairs: array<string, Pair>}
 *  @phpstan-type Push array{run: string, mode: string, started: int, finished: int, ran: array<string, array{check: string, spec: string}>, findings: list<array{check: string, spec: string, severity: string, page: string, url: string, evidence: string, known: bool, reason: string}>}
 */
final class NightlyLog
{
    public const EVENT = 'NIGHTLY';
    /** the age at which LogCleanup (AA\Later\ScheduleAaTasks) deletes log rows */
    public const RETENTION = 40 * 86400;

    /** what the state carries at most - a stream of pushes with ever new check ids must not grow
     *  the set every push writes without end; a push alone fits (NightlyImport caps it) */
    public const MAX_PAIRS    = 500;
    public const MAX_FINDINGS = 2000;
    /** MySQL named lock that makes concurrent pushes wait for each other */
    private const LOCK = 'aa_nightly';

    /** The newest state, null when the nightly test has not pushed in the last 40 days.
     *  Once per request - CheckNightly and the page model both read it.
     *  @return ?State */
    public static function load(): ?array
    {
        return AA::Contentcache()->get_result([self::class, 'loadFromDb']);
    }

    /** @return ?State */
    public static function loadFromDb(): ?array
    {
        return self::newestSet()[1];
    }

    /** Stores a push checked by NightlyImport::validate().
     *  @param Push $push
     *  @return int  findings of the push stored; 0 when newer runs already ran all its checks
     *  @throws RuntimeException when the rows could not be written */
    public static function import(array $push, int $now): int
    {
        // two pushes at once would both merge into the same old set, and the later one would
        // drop the pairs of the other; the lock belongs to the connection, which DB_AA reuses
        if (!DB_AA::select1('l', "SELECT GET_LOCK('" . self::LOCK . "', 10) AS l")) {
            throw new RuntimeException('another push of the nightly test is being stored');
        }
        try {
            [$time, $prev] = self::newestSet();
            [$state, $stored, $taken] = self::merge($prev, $push, $now);
            if (!$taken) {
                return 0;
            }
            // one time per set: a second push within the same second must not mix into the first
            if (!AA_Log::writeSet(self::EVENT, self::rows($state), max($now, $time + 1))) {
                throw new RuntimeException('the nightly findings could not be written to the log');
            }
            return $stored;
        } finally {
            DB_AA::select1('l', "SELECT RELEASE_LOCK('" . self::LOCK . "') AS l");
        }
    }

    /** selector of a pair row - check and spec ids never hold ':' */
    public static function pairKey(string $check, string $spec): string
    {
        return "$check:$spec";
    }

    /** The state after a push.
     *  @param ?State $prev
     *  @param Push   $push
     *  @return array{0: State, 1: int, 2: int}  the state, findings of the push stored, pairs taken from the push */
    public static function merge(?array $prev, array $push, int $now): array
    {
        $by_pair = [];
        foreach ($push['findings'] as $finding) {
            $by_pair[self::pairKey($finding['check'], $finding['spec'])][] = $finding;
        }

        $old_pairs = $prev['pairs'] ?? [];
        $pairs     = [];
        $stored    = 0;
        foreach ($push['ran'] as $key => $ran) {
            $old = $old_pairs[$key] ?? null;
            if ($old && $old['ran'] > $push['started']) {
                continue;                       // a newer run already ran it - carried over below
            }
            // the same run pushed again replaces its result, but is not one more run in a row
            $again = $old && ($old['ran'] === $push['started']);
            $pair  = ['check' => $ran['check'], 'spec' => $ran['spec'], 'run' => $push['run'], 'ran' => $push['started'], 'reason' => '', 'findings' => []];
            foreach ($by_pair[$key] ?? [] as $finding) {
                ++$stored;
                $page = $finding['page'];
                if ($finding['known'] && ($pair['reason'] === '')) {
                    $pair['reason'] = $finding['reason'];
                }
                if ($found = $pair['findings'][$page] ?? null) {    // more of the same on one page
                    $found['count']++;
                    $found['known'] = $found['known'] && $finding['known'];
                    $found['url']   = ($found['url'] !== '') ? $found['url'] : $finding['url'];
                    if (Finding::rank($finding['severity']) < Finding::rank($found['severity'])) {
                        $found['severity'] = $finding['severity'];
                    }
                    $pair['findings'][$page] = $found;
                    continue;
                }
                $before = $old['findings'][$page] ?? null;
                $pair['findings'][$page] = [
                    'page'     => $page,
                    'url'      => $finding['url'],
                    'severity' => $finding['severity'],
                    'known'    => $finding['known'],
                    'count'    => 1,
                    'first'    => $before ? $before['first'] : $push['started'],
                    'runs'     => $before ? $before['runs'] + ($again ? 0 : 1) : 1,
                    'evidence' => $finding['evidence'],
                ];
            }
            $pairs[$key] = $pair;
        }
        $taken = count($pairs);
        foreach ($old_pairs as $key => $old) {
            if (!isset($pairs[$key]) && ($old['ran'] >= $now - self::RETENTION)) {
                $pairs[$key] = $old;
            }
        }
        $pairs = self::cap($pairs);
        ksort($pairs);

        $run = ['run' => $push['run'], 'mode' => $push['mode'], 'started' => $push['started'], 'finished' => $push['finished']];
        if ($prev && ($prev['run']['started'] > $push['started'])) {
            $run = $prev['run'];
        }
        $full = $prev['full'] ?? ['run' => '', 'started' => 0];
        if (($push['mode'] === 'full') && ($push['started'] >= $full['started'])) {
            $full = ['run' => $push['run'], 'started' => $push['started']];
        }
        return [['run' => $run, 'full' => $full, 'pairs' => $pairs], $stored, $taken];
    }

    /** The pairs that fit MAX_PAIRS and MAX_FINDINGS, those run longest ago dropped first.
     *  @param array<string, Pair> $pairs
     *  @return array<string, Pair> */
    private static function cap(array $pairs): array
    {
        uasort($pairs, static fn(array $a, array $b): int => $b['ran'] <=> $a['ran']);
        $kept  = [];
        $found = 0;
        foreach ($pairs as $key => $pair) {
            $found += count($pair['findings']);
            if ((count($kept) >= self::MAX_PAIRS) || ($found > self::MAX_FINDINGS)) {
                break;
            }
            $kept[$key] = $pair;
        }
        return $kept;
    }

    /** The log rows of a state: the run, then each pair followed by its pages. A page takes two
     *  rows - the page and the url are up to 255 bytes each, the params only 128.
     *  @param State $state
     *  @return list<array{0: string, 1: AA_Logentry}>  selector and params */
    public static function rows(array $state): array
    {
        $rows = [['run', new AA_Logentry_Nrun([
            'run'          => $state['run']['run'],
            'mode'         => $state['run']['mode'],
            'started'      => (string) $state['run']['started'],
            'finished'     => (string) $state['run']['finished'],
            'full_run'     => $state['full']['run'],
            'full_started' => (string) $state['full']['started'],
        ])]];
        $p = $i = 0;
        foreach ($state['pairs'] as $key => $pair) {
            $rows[] = [$key, AA_Logentry_Nran::make(++$p, $pair['run'], $pair['ran'], $pair['reason'])];
            foreach ($pair['findings'] as $found) {
                $rows[] = [$found['page'], new AA_Logentry_Nf([
                    'finding'  => (string) ++$i,
                    'pair'     => (string) $p,
                    'severity' => $found['severity'],
                    'known'    => $found['known'] ? '1' : '0',
                    'count'    => (string) $found['count'],
                    'first'    => (string) $found['first'],
                    'runs'     => (string) $found['runs'],
                ])];
                $rows[] = [$found['url'], AA_Logentry_Ne::make($i, $found['evidence'])];
            }
        }
        return $rows;
    }

    /** Reads rows() back. Rows of another shape are skipped, so a damaged row costs one page, not the section.
     *  @param list<array{selector: ?string, params: ?string}> $rows  in the order they were written
     *  @return ?State  null without the run row */
    public static function parse(array $rows): ?array
    {
        $state    = null;
        $pairs    = [];   // pair number => key
        $findings = [];   // finding number => [key, page]
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            $entry    = AA_Logentry::factoryByString((string) $row['params']);
            if ($entry instanceof AA_Logentry_Nrun) {
                $state = [
                    'run'   => ['run' => $entry->run, 'mode' => $entry->mode, 'started' => (int) $entry->started, 'finished' => (int) $entry->finished],
                    'full'  => ['run' => $entry->full_run, 'started' => (int) $entry->full_started],
                    'pairs' => [],
                ];
            } elseif ($state && ($entry instanceof AA_Logentry_Nran) && str_contains($selector, ':')) {
                [$check, $spec] = explode(':', $selector, 2);
                $pairs[$entry->pair] = $selector;
                $state['pairs'][$selector] = ['check' => $check, 'spec' => $spec, 'run' => $entry->run, 'ran' => (int) $entry->ran, 'reason' => $entry->reason, 'findings' => []];
            } elseif ($state && ($entry instanceof AA_Logentry_Nf) && isset($pairs[$entry->pair]) && (Finding::rank($entry->severity) < Finding::rank(Finding::OK))) {
                $key = $pairs[$entry->pair];
                $findings[$entry->finding] = [$key, $selector];
                $state['pairs'][$key]['findings'][$selector] = [
                    'page'     => $selector,
                    'url'      => '',
                    'severity' => $entry->severity,
                    'known'    => $entry->known === '1',
                    'count'    => (int) $entry->count,
                    'first'    => (int) $entry->first,
                    'runs'     => (int) $entry->runs,
                    'evidence' => '',
                ];
            } elseif ($state && ($entry instanceof AA_Logentry_Ne) && isset($findings[$entry->finding])) {
                [$key, $page] = $findings[$entry->finding];
                $state['pairs'][$key]['findings'][$page]['url']      = $selector;
                $state['pairs'][$key]['findings'][$page]['evidence'] = $entry->evidence;
            }
        }
        return $state;
    }

    /** @return array{0: int, 1: ?State}  time of the newest set (0 = none) and the set */
    private static function newestSet(): array
    {
        $time = (int) DB_AA::select1('t', 'SELECT MAX(`time`) AS t FROM `log`', [['type', self::EVENT]]);
        if (!$time) {
            return [0, null];
        }
        return [$time, self::parse(DB_AA::select([], 'SELECT `selector`, `params` FROM `log`', [['type', self::EVENT], ['time', $time, 'i']], ['id']))];
    }
}
