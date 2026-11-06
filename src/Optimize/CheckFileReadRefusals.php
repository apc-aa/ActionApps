<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Util\LogOnce;
use AA\Util\SiteFile;
use AA_Log;

/** Files a template asked for in the last 30 days ({include:<file>:readfile|fileman}, a local mail
 *  attachment) that AA refused to read: outside the site's own files, in the AA tree, a dotfile, a
 *  script or a file of settings or keys. The template printed nothing there, so the page may miss a
 *  part. Logged once a day per file (LogOnce). Index-only, hence basic.
 */
class CheckFileReadRefusals extends AbstractOptimize
{
    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Refused file reads');
    }

    public static function description(): string
    {
        return _m('Lists the files that templates asked for in the last 30 days and AA refused to read, as they are not files of the site.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'security';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        return self::findingsFor(LogOnce::recent(SiteFile::EVENT, now() - self::WINDOW_SECONDS, self::GROUPS));
    }

    /**
     * @param list<array<string, ?string>> $rows LogOnce::recent() rows - selector "<purpose>:<file>", params "<reason>:<slice id>"
     * @return Finding[]
     */
    public static function findingsFor(array $rows): array
    {
        $findings = [];
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            [$purpose, $file] = explode(':', $selector, 2) + [1 => ''];
            [$reason, $slice] = ParamExplode((string) $row['params']) + [1 => ''];
            $findings[] = new Finding(
                Finding::WARNING,
                _m('The file %1 was not read (%2)', [$file, $purpose]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('File')      => safe($file),
                    _m('Reason')    => safe((string) $reason),
                    _m('Slice')     => safe((string) $slice),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('AA reads for templates and e-mails only the files of the site: below its document root, its File Manager directory or the upload directories - never from the directory AA is installed in, never a dotfile, a script or a file of settings or keys. Move the file among the files of the site, or remove it from the template.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(SiteFile::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No file read was refused in the last 30 days'))];
    }
}
