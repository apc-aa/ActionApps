<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Later\Toexecute;
use AA\Util\LogOnce;
use AA_Log;

/** Queued tasks needing a class this AA does not have, in the last 30 days: Toexecute leaves them in
 *  the queue at their priority, due again in a minute, older tasks in up to an hour (a class may be
 *  missing only during a deploy, or only in this one of two AA trees over the same database), and
 *  logs it once a day per class. Index-only, hence basic.
 */
class CheckQueuedClassMissing extends AbstractOptimize
{
    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Queued tasks of a missing class');
    }

    public static function description(): string
    {
        return _m('Lists the classes of queued tasks that could not run in the last 30 days because this AA does not have the class.');
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
        return self::findingsFor(LogOnce::recent(Toexecute::LOG_CLASS_MISSING, now() - self::WINDOW_SECONDS, self::GROUPS));
    }

    /**
     * @param list<array<string, ?string>> $rows LogOnce::recent() rows - selector the class, params the task selector
     * @return Finding[]
     */
    public static function findingsFor(array $rows): array
    {
        $findings = [];
        foreach ($rows as $row) {
            $class      = (string) $row['selector'];
            $findings[] = new Finding(
                Finding::WARNING,
                _m('Queued tasks of class %1 cannot run - the class is missing', [safe($class)]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Class')     => safe($class),
                    _m('Selector')  => safe((string) $row['params']),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('The tasks stay in the queue and are tried again, every minute at first and then less often, up to once an hour; an AA that has the class runs them. If the class is missing after a deploy (files not all copied, an old composer classmap) or this AA is older than another one using the same database, update it. If the feature was removed, delete the tasks in the Task Manager.'),
                links: [_m('Task Manager') => get_admin_url('aa_toexecute.php'), _m('Log rows') => AA_Log::getAdminUrl(Toexecute::LOG_CLASS_MISSING, $class)],
                key: $class,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No queued task with a missing class in the last 30 days'))];
    }
}
