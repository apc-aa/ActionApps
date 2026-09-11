<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\ConfigTimeline;
use AA\Util\FatalLogger;
use AA_Log;

/** PHP fatal errors of the last 7 days, one finding per file:line (the PHP_FATAL
 *  log selector), and whether they started after the last recorded deployment.
 *  Logging them at all is opt-in (AA_LOG_PHP_FATALS), so the first finding on
 *  most installs is that they are blind.
 */
class CheckPhpFatals extends AbstractOptimize
{
    private const WINDOW_SECONDS = 7 * 86400;
    private const GROUPS = 20;

    public static function name(): string
    {
        return _m('PHP fatal errors');
    }

    public static function description(): string
    {
        return _m('Lists PHP fatal errors logged in the last 7 days by file and line, with counts and whether they appeared after the last deployment. Warns when fatal errors are not logged at all.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'errors';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        if (!defined('AA_LOG_PHP_FATALS') || !constant('AA_LOG_PHP_FATALS')) {
            return [new Finding(Finding::WARNING, _m('PHP fatal errors are not logged'), advice: _m("Add define('AA_LOG_PHP_FATALS', true); to include/config.php. Until then a page that dies leaves no trace here, only in the web server error log."))];
        }

        $now  = now();
        $rows = DB_AA::select([], 'SELECT `selector`, COUNT(*) AS cnt, MIN(`time`) AS first, MAX(`time`) AS last, MAX(`params`) AS msg FROM `log`', [['type', FatalLogger::EVENT], ['time', $now - self::WINDOW_SECONDS, '>=']], ['cnt-'], ['selector'], self::GROUPS);
        if (!$rows) {
            return [new Finding(Finding::OK, _m('No PHP fatal errors in the last 7 days'))];
        }

        $deployed = self::lastDeployment(ConfigTimeline::getChanges());
        $findings = [];
        foreach ($rows as $row) {
            $first    = (int) $row['first'];
            $evidence = [[
                _m('Message')                       => safe((string) $row['msg']),
                _m('Last')                          => date('Y-m-d H:i', (int) $row['last']),
                _m('Started after last deployment') => self::isNewSince($first, $deployed) ? _m('yes') . ' (' . date('Y-m-d', $deployed) . ')' : _m('no'),
            ]];
            $links = [_m('Log rows') => AA_Log::getAdminUrl(FatalLogger::EVENT, (string) $row['selector'])];
            if ($page = self::pageOf((string) $row['selector'])) {
                $links[_m('Open page')] = constant('AA_INSTAL_URL') . $page;
            }
            $findings[] = new Finding(Finding::ERROR, _m('PHP fatal error in %1', [(string) $row['selector']]), count: (int) $row['cnt'], since: $first, evidence: $evidence, advice: _m('Open the file at that line. Every occurrence is a page the visitor never got.'), links: $links, key: (string) $row['selector'], last: (int) $row['last']);
        }
        return $findings;
    }

    /** The file of a `file:line` selector when it is a page one can open (a .php
     *  script outside src/, include/, vendor/), '' otherwise. The real URI of the
     *  request is not in the log yet (DESIGN.md § 4). */
    public static function pageOf(string $selector): string
    {
        $file = (string) substr($selector, 0, (int) strrpos($selector, ':'));
        if (!str_ends_with($file, '.php') || str_contains($file, '..') || str_starts_with($file, '/')) {
            return '';
        }
        return preg_match('#^(src|include|vendor|tests|dev)/#', $file) ? '' : $file;
    }

    /** unixtime of the latest recorded configuration change, 0 when none
     * @param list<array{time:int}> $changes  ConfigTimeline::getChanges(), oldest first */
    public static function lastDeployment(array $changes): int
    {
        $last = end($changes);
        return $last ? (int) $last['time'] : 0;
    }

    public static function isNewSince(int $first, int $deployed): bool
    {
        return $deployed > 0 && $first >= $deployed;
    }
}
