<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;

/** Until 2026-09 AA installed its own periodic jobs as rows of the `cron`
 *  table (the Add*CronEntry optimizers) and cron.php fetched them over HTTP.
 *  They are queue tasks planned by AA\Later\ScheduleAaTasks now, so a row
 *  left behind runs the job a second time - or, for the removed scripts, 404s.
 */
class RemoveAaCronEntries extends AbstractOptimize
{
    /** a `cron.script` containing one of these is an AA job; `.php3` rows and the
     *  hand-disabled `norun_`/`no_run_here_` variants found on live installs match too */
    public const SCRIPTS = ['/alerts.php', '/xmlclient.php', '/linkcheck.php', 'toexecute.php', '/optimize.php', 'mysql_auth/suspend.php'];

    public static function name(): string
    {
        return _m('Removes the AA jobs from the cron table');
    }

    public static function description(): string
    {
        return _m('Alerts sending, feeding, link checking, table optimization and the queue run itself are queue tasks since 2026-09 (cron.php runs the queue directly). A cron table row still calling one of those scripts runs the job twice, so it is deleted; your own rows stay.');
    }

    public static function types(): array
    {
        return ['sql_update'];
    }

    public static function matches(string $script): bool
    {
        foreach (self::SCRIPTS as $needle) {
            if (str_contains($script, $needle)) {
                return true;
            }
        }
        return false;
    }

    public function test(): bool
    {
        $rows = $this->aaRows();
        foreach ($rows as $row) {
            $this->message(_m('AA job still in the cron table: %1 %2', [$row['script'], $row['params']]));
        }
        return !$rows;
    }

    public function repair(): bool
    {
        $ids = array_column($this->aaRows(), 'id');
        if ($ids) {
            DB_AA::delete('cron', [['id', $ids, 'i']]);
        }
        $this->message(_m('%1 AA cron rows deleted', [count($ids)]));
        return true;
    }

    /** @return list<array<string,mixed>> the `cron` rows that are AA jobs */
    private function aaRows(): array
    {
        $rows = DB_AA::select([], 'SELECT id, script, params FROM `cron`');
        return array_values(array_filter($rows, static fn(array $row) => self::matches((string) $row['script'])));
    }
}
