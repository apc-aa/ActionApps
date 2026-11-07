<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Util\LogOnce;
use AA\Util\SiteFile;
use AA_Log;

/** Files a template or a mail asked for in the last 30 days ({include:<file>:fileman}, a local
 *  attachment) that AA refused to read: outside the site's own files, in the AA tree, a dotfile, a
 *  script or a file of settings or keys - and the slices and sites whose templates use
 *  {include:<file>:readfile} or {include:<file>:site}, which read nothing. The template printed nothing
 *  there, so the page may miss a part. Also the local files mails still took from outside the upload
 *  directories. Logged once a day per file, readfile and site per slice or site (LogOnce). Index-only,
 *  hence basic.
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
        return _m('Lists the files that templates or e-mails asked for in the last 30 days and AA refused to read, as they are not files of the site, the templates that still use {include:…:readfile} or {include:…:site}, which read no file, and the files e-mails took from outside the upload directories.');
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
        $since = now() - self::WINDOW_SECONDS;
        return self::findingsFor(LogOnce::recent(SiteFile::EVENT, $since, self::GROUPS), LogOnce::recent(SiteFile::EVENT_OUTER, $since, self::GROUPS));
    }

    /**
     * @param list<array<string, ?string>> $rows  LogOnce::recent() rows - selector "<purpose>:<file>", params "<reason>:<slice id>"; readfile and site: selector "<type>:<slice or site id>", params the file
     * @param list<array<string, ?string>> $outer LogOnce::recent() rows of the files mails took from outside the upload directories
     * @return Finding[]
     */
    public static function findingsFor(array $rows, array $outer = []): array
    {
        $findings = [];
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            [$purpose, $file] = explode(':', $selector, 2) + [1 => ''];
            if (in_array($purpose, SiteFile::REMOVED_TYPES, true)) {
                $findings[] = self::removedTypeFinding($row, $purpose, $file);
                continue;
            }
            [$reason, $slice] = ParamExplode((string) $row['params']) + [1 => ''];
            $findings[] = new Finding(
                Finding::WARNING,
                _m('The file %1 was not read (%2)', [$file, $purpose]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('File')      => safe($file),
                    _m('Reason')    => safe(self::reason((string) $reason)),
                    _m('Slice')     => safe((string) $slice),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('AA reads for templates and e-mails only the files of the site: its File Manager directory or the upload directories - never from the directory AA is installed in, never a dotfile, a script or a file of settings or keys. Move the file among the files of the site, or remove it from the template.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(SiteFile::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
            );
        }
        foreach ($outer as $row) {
            $selector = (string) $row['selector'];
            $file     = explode(':', $selector, 2)[1] ?? '';
            $findings[] = new Finding(
                Finding::INFO,
                _m('An e-mail took the file %1 from outside the upload directories', [$file]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('File')      => safe($file),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('The file was sent. AA is to send local files only from the upload directories of the slices and the File Manager, so attach a file uploaded to the slice instead, or tell the AA developers the directory this site keeps its attachments in.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(SiteFile::EVENT_OUTER, $selector)],
                key: "outer:$selector",
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No file read was refused in the last 30 days'))];
    }

    /**
     * A template of the slice or site $where using {include:<file>:<type>} of a removed type - params one file name it used (a row a day keeps the first);
     * $where is '' for a static view or cron, so the name is what leads to the template
     * @param array<string, ?string>             $row
     * @param value-of<SiteFile::REMOVED_TYPES> $type
     */
    private static function removedTypeFinding(array $row, string $type, string $where): Finding
    {
        $file = (string) $row['params'];
        // no default: a type added to SiteFile::REMOVED_TYPES needs its own reason
        $why  = match ($type) {
            'readfile' => _m('readfile read any file of the document root, past the access rules of the web server, so it reads nothing now.'),
            'site'     => _m('site read from a directory that a visitor could choose by a parameter of the address, so it reads nothing now.'),
        };
        return new Finding(
            Finding::WARNING,
            _m('A template uses {include:%1:%2}, which prints nothing', [$file, $type]),
            count: (int) $row['days'],
            since: (int) $row['first'],
            evidence: [[
                _m('File')          => safe($file),
                _m('Slice or site') => safe($where),
                _m('Days seen')     => (string) (int) $row['days'],
                _m('Last')          => date('Y-m-d', (int) $row['last']),
            ]],
            advice: $why . ' ' . _m('In a slice put the file into its File Manager directory and use {include:<file>:fileman}; elsewhere include the file by its full web address, {include:https#://<domain>/<file>}.'),
            links: [_m('Log rows') => AA_Log::getAdminUrl(SiteFile::EVENT, (string) $row['selector'])],
            key: (string) $row['selector'],
            last: (int) $row['last'],
        );
    }

    /** the reason SiteFile logged, in words */
    public static function reason(string $code): string
    {
        return match ($code) {
            SiteFile::REASON_OUTSIDE => _m('outside the files of the site'),
            SiteFile::REASON_AA_TREE => _m('in the directory of an AA installation'),
            SiteFile::REASON_DOT     => _m('a name starting with a dot (.., .htaccess, .git)'),
            SiteFile::REASON_SECRET  => _m('a script, a settings or key file, a dump or a backup'),
            SiteFile::REASON_TYPE    => _m('not a regular file, or a file without an extension'),
            SiteFile::REASON_CHANGED => _m('the file changed while it was read'),
            default                  => $code,
        };
    }
}
