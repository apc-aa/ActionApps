<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Central\OptimizeRun;

/** An Optimize run of Central asks its AAs in the task queue (OptimizeRun), so a queue that does not run
 *  or a failed step leaves AAs nobody asked - and the page may have been closed long ago. Warns about runs
 *  that hang (BatchRun::isStuck()) and lists those stopped by an error. */
class CheckOptimizeRuns extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Optimize runs of Central');
    }

    public static function description(): string
    {
        return _m('Warns about Optimize runs on several AAs that hang and lists those stopped by an error.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'db';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        return self::findingsAt(time());
    }

    /** @return Finding[] */
    public static function findingsAt(int $now): array
    {
        $findings = [];
        foreach (OptimizeRun::unfinished() as $run) {
            $error = $run->state() === OptimizeRun::ERROR;
            if (!$error && !$run->isStuck($now)) {
                continue;               // the queue moves it
            }
            $evidence = [
                _m('Started')   => safe(date('Y-m-d H:i', $run->started())),
                _m('Task')      => safe(OptimizeRun::taskName($run->task()) . ' - ' . $run->method()),
                _m('User')      => safe($run->user()),
                _m('AAs done')  => $run->done() . ' / ' . count($run->aas()),
            ];
            if ($error) {
                $evidence[_m('Error')] = safe((string) ($run->lastFailure()['code'] ?? ''));
            }
            $findings[] = new Finding(
                $error ? Finding::ERROR : Finding::WARNING,
                $error ? _m('An Optimize run on several AAs stopped by an error') : _m('An Optimize run on several AAs has not moved on for %1 hours', [intdiv($now - $run->idleSince(), 3600)]),
                since: $run->started(),
                evidence: [$evidence],
                advice: _m('Some AAs of the run were not asked. Check that the task queue of this AA runs; on the page of the run Continue goes on, End keeps the results so far.'),
                links: [_m('The run') => AA_INSTAL_PATH . 'central/optimize.php?run=' . $run->getId()],
                key: $run->getId(),
            );
        }
        return $findings ?: [new Finding(Finding::OK, _m('No Optimize run of Central is stuck'))];
    }
}
