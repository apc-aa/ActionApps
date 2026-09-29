<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Central\InstallRun;

/** A transfer of an app moves its items only while its progress page is open (central/installapp.php),
 *  so a closed tab or a failed batch leaves a half-moved app nobody may notice: the new site lacks items.
 *  Warns about transfers that have not moved on for an hour and lists those stopped by an error. */
class CheckInstallRuns extends AbstractOptimize
{
    /** seconds without progress before a transfer counts as stuck */
    public const STUCK_AFTER = 3600;

    public static function name(): string
    {
        return _m('App transfers');
    }

    public static function description(): string
    {
        return _m('Warns about app transfers that have not moved on for an hour and lists those stopped by an error.');
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
        foreach (InstallRun::unfinished() as $run) {
            $error = $run->state() === InstallRun::ERROR;
            if (!$error && ($run->moved() > $now - self::STUCK_AFTER)) {
                continue;
            }
            $evidence = [
                _m('Started')       => safe(date('Y-m-d H:i', $run->started())),
                _m('Last progress') => safe(date('Y-m-d H:i', $run->moved())),
                _m('User')          => safe($run->user()),
                _m('Slices')        => (string) count($run->slices()),
                _m('Items written') => (string) array_sum($run->written()),
            ];
            if ($error) {
                [$code, $path] = $run->error() ?? ['', ''];
                $evidence[_m('Error')] = safe("$code $path");
            }
            $findings[] = new Finding(
                $error ? Finding::ERROR : Finding::WARNING,
                $error ? _m('An app transfer stopped by an error') : _m('An app transfer has not moved on for %1 hours', [intdiv($now - $run->moved(), 3600)]),
                since: $run->started(),
                evidence: [$evidence],
                advice: _m('The new modules hold only part of the items. The user who started the transfer opens its progress page and chooses Continue - or End, which keeps what is written.'),
                links: [_m('Progress page') => AA_INSTAL_PATH . 'central/installapp.php?run=' . $run->getId()],
                key: $run->getId(),
            );
        }
        return $findings ?: [new Finding(Finding::OK, _m('No app transfer is stuck'))];
    }
}
