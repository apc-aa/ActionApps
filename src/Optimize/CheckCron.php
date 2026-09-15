<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;

/** The `cron` table as cron.php reads it: rows it cannot parse, scripts scheduled
 *  twice (.php3 next to .php), rows that stopped running, and AA's own jobs
 *  left there although they run from the queue (RemoveAaCronEntries).
 */
class CheckCron extends AbstractOptimize
{
    public const SCHEDULE_FIELDS = ['minutes', 'hours', 'mday', 'mon', 'wday'];
    /** what cron.php's parser accepts: numbers, lists, ranges, steps, star */
    private const SCHEDULE_RE = '/^[\d,\-*\/]+$/';
    /** a row is stale after twice its period, but never sooner than this - the
     *  minute-level view belongs to CheckQueue */
    private const MIN_STALE_SECONDS = 3600;

    public static function name(): string
    {
        return _m('Cron table');
    }

    public static function description(): string
    {
        return _m('Checks the AA cron table: unreadable schedules, scripts scheduled twice, rows that have not run for twice their period, and AA jobs that belong to the queue.');
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
        $now      = now();
        $rows     = DB_AA::select([], 'SELECT * FROM `cron`', null, ['id']);
        $findings = [];
        $link     = [_m('Cron') => get_admin_url('aa_cron.php')];

        foreach ($rows as $row) {
            if ($bad = self::invalidFields($row)) {
                $fields = implode(', ', array_map(static fn(string $f) => "$f='" . $row[$f] . "'", $bad));
                $findings[] = new Finding(Finding::ERROR, _m('Cron row %1 (%2) has an unreadable schedule: %3', [(int) $row['id'], (string) $row['script'], $fields]), advice: _m('cron.php skips a row it cannot parse, so the script never runs. Allowed are digits, commas, ranges and */step; fix the fields or delete the row.'), links: $link, key: 'invalid:' . (int) $row['id']);
            }
        }

        foreach (self::duplicateScripts($rows) as $script => $count) {
            $findings[] = new Finding(Finding::WARNING, _m('Job %1 is scheduled %2 times', [$script, $count]), count: $count, advice: _m('Usually an old .php3 row next to the .php one, so the script runs twice. Delete the older row.'), links: $link, key: 'duplicate:' . $script);
        }

        $stale = array_values(array_filter($rows, static fn(array $row) => self::isStale($row, $now)));
        if ($stale) {
            $evidence = array_map(static fn(array $row) => [
                _m('Script')         => safe((string) $row['script'] . ' ' . $row['params']),
                _m('Last run')       => date('Y-m-d H:i', (int) $row['last_run']),
                _m('Expected every') => self::expectedPeriod($row) . ' s',
            ], $stale);
            $findings[] = new Finding(Finding::WARNING, _m('%1 cron rows have not run for longer than twice their period', [count($stale)]), count: count($stale), since: min(array_map(static fn(array $row) => (int) $row['last_run'], $stale)), evidence: $evidence, advice: _m('cron.php runs (see the task queue) but skipped these rows, or their schedule means something else than you expect.'), links: $link, key: 'stale');
        }

        $aa_jobs = new RemoveAaCronEntries();
        if (!$aa_jobs->test()) {
            $findings[] = new Finding(Finding::WARNING, _m('AA jobs are still in the cron table and run twice'), evidence: array_map(static fn(array $row) => array_map('safe', $row), $aa_jobs->reportRows()), advice: RemoveAaCronEntries::description() . ' ' . _m('Run service/sql_update.php.'), links: [_m('Optimize') => get_admin_url('aa_optimize.php')], key: 'aajobs');
        }

        if (!$findings) {
            $findings[] = new Finding(Finding::OK, _m('%1 cron rows, all readable and running on schedule', [count($rows)]));
        }
        return $findings;
    }

    /** @param array<string,mixed> $row  a `cron` row
     *  @return string[] schedule fields cron.php cannot parse */
    public static function invalidFields(array $row): array
    {
        return array_values(array_filter(self::SCHEDULE_FIELDS, static fn(string $f) => !preg_match(self::SCHEDULE_RE, (string) ($row[$f] ?? ''))));
    }

    /** @param list<array<string,mixed>> $rows  `cron` rows
     *  @return array<string,int> "script params" => rows, only those scheduled more than once;
     *          .php3 is the same script as .php, different params (alerts.php howoften=...) are different jobs */
    public static function duplicateScripts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $script = (string) preg_replace('/\.php3$/', '.php', trim((string) $row['script']));
            $job    = trim($script . ' ' . trim((string) ($row['params'] ?? '')));
            $counts[$job] = ($counts[$job] ?? 0) + 1;
        }
        return array_filter($counts, static fn(int $n) => $n > 1);
    }

    /** Seconds between two runs as the schedule reads. The coarsest restricted
     *  field decides; a list of fixed values counts as its whole cycle, so the
     *  result errs on the long side and never flags a row too early.
     * @param array<string,mixed> $row  a `cron` row
     */
    public static function expectedPeriod(array $row): int
    {
        if (($row['mon'] ?? '*') !== '*') {
            return 366 * 86400;
        }
        if (($row['mday'] ?? '*') !== '*') {
            return 31 * 86400;
        }
        if (($row['wday'] ?? '*') !== '*') {
            return 7 * 86400;
        }
        $hours   = (string) ($row['hours'] ?? '*');
        $minutes = (string) ($row['minutes'] ?? '*');
        if ($hours !== '*') {
            return str_contains($hours, '/') ? self::step($hours) * 3600 : 86400;
        }
        if ($minutes === '*') {
            return 60;
        }
        return str_contains($minutes, '/') ? self::step($minutes) * 60 : 3600;
    }

    /** @param array<string,mixed> $row  a `cron` row */
    public static function isStale(array $row, int $now): bool
    {
        $last = (int) ($row['last_run'] ?? 0);
        return $last > 0 && ($now - $last) > max(2 * self::expectedPeriod($row), self::MIN_STALE_SECONDS);
    }

    /** the N after the slash in a step schedule (star-slash-N, 0-60/N); a zero step would mean "always", read it as 1 */
    private static function step(string $field): int
    {
        return max(1, (int) substr($field, strrpos($field, '/') + 1));
    }
}
