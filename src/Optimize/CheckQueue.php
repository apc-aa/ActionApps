<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Later\Toexecute;
use AA_Log;

/** Is the task queue (cron.php / misc/toexecute.php) alive, who drives it and does it keep up.
 *  Reads the TOEXECUTE rows of the log: one `Xrun:done:started:duration:allowed`
 *  row per queue run (AA_Logentry_Xrun), one `X:time:...` row per task; and the
 *  Toexecute::FAILED_EVENT row of every task that threw.
 */
class CheckQueue extends AbstractOptimize
{
    /** the queue is planned every 2 minutes; nothing for 10 = dead */
    private const STALE_SECONDS = 600;
    /** cron.php not called for an hour while the queue still runs = the fallback carries it */
    private const CRON_STALE_SECONDS = 3600;
    /** the OS cron entry points log a TOEXECUTE start row under their name (Toexecute::runQueue()); the fallback logs none */
    private const CRON_CALLERS = ['cron.php', 'toexecute.php'];
    private const WINDOW_SECONDS = 7 * 86400;
    /** share of runs over the time limit that counts as overload */
    private const OVERLOAD_RATIO = 0.10;
    private const SLOW_TASKS = 5;

    public static function name(): string
    {
        return _m('Task queue');
    }

    public static function description(): string
    {
        return _m('Checks that the task queue runs, whether OS cron or only the post-response fallback drives it, how often runs exceed their time limit, and which tasks were given up.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'cron';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $now        = now();
        $findings   = [];
        $queue_link = [_m('Task Manager') => get_admin_url('aa_toexecute.php')];
        $cron_link  = [_m('Cron') => get_admin_url('aa_cron.php')];
        $links      = $queue_link + $cron_link;
        $runs_of    = [['type', 'TOEXECUTE'], ['selector', Toexecute::class]];

        // newest run first: ORDER BY time DESC walks the type_time index from the end and stops at once
        $last_run = (int) DB_AA::select1('time', 'SELECT `time` FROM `log`', $runs_of, ['time-']);
        if ($last_run === 0) {
            $findings[] = new Finding(Finding::ERROR, _m('The task queue has never run'), advice: _m('Mails, alerts and feeds wait in the queue until OS cron calls cron.php or a visit triggers the post-response fallback.'), links: $links, key: 'never');
        } elseif ($now - $last_run > self::STALE_SECONDS) {
            $findings[] = new Finding(Finding::ERROR, _m('The task queue has not run since %1', [date('Y-m-d H:i', $last_run)]), since: $last_run, advice: _m('Mails, alerts and feeds are waiting. Check the system crontab (cron.php every minute) and the post-response fallback (DISABLE_CRON_FALLBACK in config.php).'), links: $links, key: 'stale');
        }

        $cron_last = (int) DB_AA::select1('time', 'SELECT `time` FROM `log`', [['type', 'TOEXECUTE'], ['selector', self::CRON_CALLERS], ['params', 'start']], ['time-']);
        if ($last_run > 0 && ($now - $last_run) <= self::STALE_SECONDS && ($now - $cron_last) > self::CRON_STALE_SECONDS) {
            $findings[] = new Finding(
                Finding::WARNING,
                $cron_last ? _m('OS cron has not called cron.php since %1; the post-response fallback keeps the queue alive', [date('Y-m-d H:i', $cron_last)]) : _m('OS cron has never called cron.php; the post-response fallback keeps the queue alive'),
                since: $cron_last,
                advice: _m('The fallback only runs after a visit, so mails, alerts and feeds arrive late on a quiet site. Let the system crontab call cron.php every minute.'),
                links: $cron_link,
                key: 'oscron',
            );
        }

        $fallback_of = [['type', 'CRON_FALLB'], ['time', $now - self::WINDOW_SECONDS, '>=']];
        $fallback    = DB_AA::select1('', 'SELECT COUNT(*) AS cnt, MIN(`time`) AS first, MAX(`time`) AS last FROM `log`', $fallback_of);
        $failures    = (int) ($fallback['cnt'] ?? 0);
        if ($failures > 0) {
            $rows     = DB_AA::select([], 'SELECT `time`, `params` FROM `log`', $fallback_of, ['time-'], null, 5);
            $evidence = array_map(static fn(array $row) => [_m('Time') => date('Y-m-d H:i', (int) $row['time']), _m('Error') => safe((string) $row['params'])], $rows);
            $findings[] = new Finding(Finding::WARNING, _m('The post-response cron fallback failed %1 times in the last 7 days', [$failures]), count: $failures, since: (int) $fallback['first'], evidence: $evidence, links: [_m('Log rows') => AA_Log::getAdminUrl('CRON_FALLB')], key: 'fallback', last: (int) $fallback['last']);
        }

        // a task that throws no longer ends the run with a PHP fatal - its log row is the only trace
        $failed_of = self::failedTasksOf($now);
        $failed    = DB_AA::select1('', 'SELECT COUNT(*) AS cnt, MIN(`time`) AS first, MAX(`time`) AS last FROM `log`', $failed_of);
        $failed_n  = (int) ($failed['cnt'] ?? 0);
        if ($failed_n > 0) {
            $rows     = DB_AA::select([], 'SELECT `time`, `selector`, `params` FROM `log`', $failed_of, ['time-'], null, 5);
            $evidence = array_map(static fn(array $row) => [_m('Time') => date('Y-m-d H:i', (int) $row['time']), _m('Task') => safe((string) $row['selector']), _m('Error') => safe((string) $row['params'])], $rows);
            $findings[] = new Finding(Finding::WARNING, _m('Queued tasks failed %1 times in the last 7 days', [$failed_n]), count: $failed_n, since: (int) $failed['first'], evidence: $evidence, advice: _m("A failed task runs again after %1 minutes with a lower priority and is given up after repeated failures. A task from a user's queue in the Task Manager is not retried - it waits there until someone runs it again. Only the error class and where it was thrown are logged - the message may hold passwords.", [intdiv(Toexecute::CLAIM_LEASE, 60)]), links: [_m('Log rows') => AA_Log::getAdminUrl(Toexecute::FAILED_EVENT)] + $queue_link, key: 'failed', last: (int) $failed['last']);
        }

        $stats = self::runStats(DB_AA::select('params', 'SELECT `params` FROM `log`', array_merge($runs_of, [['time', $now - self::WINDOW_SECONDS, '>=']])));
        if ($stats['runs'] > 0 && max($stats['over'], $stats['unfinished']) > self::OVERLOAD_RATIO * $stats['runs']) {
            $findings[] = new Finding(
                Finding::WARNING,
                _m('%1 of %2 queue runs in the last 7 days exceeded the allowed %3 s (longest %4 s); %5 runs left tasks waiting', [$stats['over'], $stats['runs'], $stats['allowed'], $stats['max'], $stats['unfinished']]),
                count: $stats['over'],
                evidence: $this->slowTasks($now),
                advice: _m('Long tasks block everything behind them - imports of dead feeds and mass mails most often. Disable or slow down the feeds listed, or raise TOEXECUTE_ALLOWED_TIME in config.php.'),
                links: $queue_link,
                key: 'overload',
            );
        }

        $given_up = (int) DB_AA::select1('cnt', 'SELECT COUNT(*) AS cnt FROM `toexecute`', [['priority', 0, 'i']]);
        if ($given_up > 0) {
            $findings[] = new Finding(Finding::WARNING, _m('%1 queued tasks were given up after repeated failures', [$given_up]), count: $given_up, advice: _m('They never run again on their own. Open the Task Manager, see what they are, and delete them or fix the cause.'), links: $queue_link, key: 'givenup');
        }
        $overdue = (int) DB_AA::select1('cnt', 'SELECT COUNT(*) AS cnt FROM `toexecute`', [['priority', 0, '>'], ['execute_after', $now - 3600, '<']]);
        if ($overdue > 0) {
            $findings[] = new Finding(Finding::INFO, _m('%1 queued tasks have been waiting for more than an hour', [$overdue]), count: $overdue, advice: _m('The queue does not keep up, or these tasks fail and get postponed (their priority sinks with each failure).'), links: $queue_link, key: 'overdue');
        }

        if (!$findings) {
            $findings[] = new Finding(Finding::OK, _m('Queue ran at %1; %2 runs in the last 7 days, %3 over the time limit', [date('Y-m-d H:i', $last_run), $stats['runs'], $stats['over']]));
        }
        return $findings;
    }

    /** The log rows of the queued tasks that threw in the last 7 days. Not the steps of imports: their
     *  run stops in error and is not retried this way - CheckImports lists them. */
    public static function failedTasksOf(int $now): array
    {
        return [['type', Toexecute::FAILED_EVENT], ['time', $now - self::WINDOW_SECONDS, '>='], ['selector', 'Import_', 'NOT BEGIN']];
    }

    /** Counts over the `Xrun:done:started:duration:allowed` rows (AA_Logentry_Xrun);
     *  anything else is skipped.
     * @param iterable<string> $params  log.params of the queue-run rows
     * @return array{runs:int, over:int, unfinished:int, max:float, allowed:float}
     */
    public static function runStats(iterable $params): array
    {
        $stats = ['runs' => 0, 'over' => 0, 'unfinished' => 0, 'max' => 0.0, 'allowed' => 0.0];
        foreach ($params as $param) {
            $parts = explode(':', (string) $param);
            if (($parts[0] !== 'Xrun') || (count($parts) < 5)) {
                continue;
            }
            [, $done, $started, $duration, $allowed] = $parts;
            ++$stats['runs'];
            if ((float) $duration > (float) $allowed) {
                ++$stats['over'];
            }
            if ((int) $done < (int) $started) {
                ++$stats['unfinished'];   // the run stopped with a started task undone - out of time
            }
            $stats['max']     = max($stats['max'], (float) $duration);
            $stats['allowed'] = (float) $allowed;
        }
        return $stats;
    }

    /** the task classes with the longest average run in the last 24 hours, from
     *  the per-task `X:time:...` rows (positional, see AA_Logentry_X) */
    private function slowTasks(int $now): array
    {
        $seconds = "SUBSTRING_INDEX(SUBSTRING_INDEX(`params`, ':', 2), ':', -1) + 0";
        $rows = DB_AA::select(
            [],
            "SELECT SUBSTRING_INDEX(`selector`, ':', 1) AS task, COUNT(*) AS cnt, ROUND(AVG($seconds), 2) AS avg_s, MAX($seconds) AS max_s FROM `log`",
            [['type', 'TOEXECUTE'], ['time', $now - 86400, '>='], ['params', 'X:', 'BEGIN']],
            ['avg_s-'],
            ['task'],
            self::SLOW_TASKS,
        );
        return array_map(static fn(array $row) => [
            _m('Task')        => safe((string) $row['task']),
            _m('Runs (24 h)') => (string) (int) $row['cnt'],
            _m('Average [s]') => (string) $row['avg_s'],
            _m('Longest [s]') => (string) $row['max_s'],
        ], $rows);
    }
}
