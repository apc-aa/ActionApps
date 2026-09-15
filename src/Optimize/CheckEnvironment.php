<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Util\ConfigTimeline;
use AA_Log;

/** What this install runs on, from the SYS_STAT rows the queue writes every
 *  10 minutes (AA\Later\LogSystemstatus): always one info finding, so the page
 *  can say "unchanged since" next to the problems.
 */
class CheckEnvironment extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Environment');
    }

    public static function description(): string
    {
        return _m('Shows the AA version, SVN revision, PHP and database versions from the last system status row, and when they last changed.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'environment';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $changes = ConfigTimeline::getChanges();
        if (!$changes) {
            return [new Finding(Finding::INFO, _m('No system status recorded yet; the task queue writes one every 10 minutes'))];
        }
        $last     = end($changes);
        $svn      = trim($last['tree'] . ' (' . $last['rev'] . ')');
        $evidence = [[
            _m('AA')               => safe($last['aa']),
            _m('SVN')              => safe($svn),
            _m('PHP')              => safe($last['php']),
            _m('Database')         => safe($last['db']),
            _m('Memory')           => safe($last['mem']),
            _m('CPU')              => safe($last['cpu']),
            _m('Unchanged since')  => date('Y-m-d H:i', $last['time']),
            _m('Recorded changes') => (string) count($changes),
        ]];
        if ($last['server'] !== '') {
            $settings = [];
            foreach (ConfigTimeline::decodeServer($last['server']) as $name => $value) {
                $settings[] = "$name=$value";
            }
            $evidence[0][_m('Server settings')] = safe(implode(', ', $settings));
        }
        return [new Finding(Finding::INFO, _m('AA %1 (%2), PHP %3, %4; unchanged since %5', [$last['aa'], $svn, $last['php'], $last['db'], date('Y-m-d', $last['time'])]), since: $last['time'], evidence: $evidence, links: [_m('Log rows') => AA_Log::getAdminUrl('SYS_STAT', 'basic')], key: 'environment')];
    }
}
