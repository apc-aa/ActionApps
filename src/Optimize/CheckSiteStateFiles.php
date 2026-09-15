<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA_Module_Site;

/** Site modules of the legacy kind (flag <> 1) include modules/site/sites/site_<state_file>
 *  on every public page. A missing or unreadable file ends every page of that site with
 *  HTTP 500 - which only a visitor sees, hence this check (modules/site/site.php).
 */
class CheckSiteStateFiles extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Site state files');
    }

    public static function description(): string
    {
        return _m('Checks that every site module using a state file (the legacy control file) has that file present and readable in modules/site/sites/.');
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
        // one row per site module - the whole table is smaller than any index dance over it.
        // LEFT JOIN: a site row whose module row is gone is exactly the broken state worth reporting.
        $rows     = DB_AA::select([], 'SELECT LOWER(HEX(s.id)) AS id, s.flag, s.state_file, m.name, m.deleted FROM `site` s LEFT JOIN `module` m ON m.id = s.id');
        $findings = [];
        $checked  = 0;

        foreach ($rows as $row) {
            // flag 1 = the router reads state_file as a path, not as a file. AA counts a module as live
            // when deleted = 0 (getModules(), getIdFromUrl()) - anything else is not served.
            if ((int) $row['flag'] === 1 || (int) ($row['deleted'] ?? 0) !== 0) {
                continue;
            }
            if (!($name = AA_Module_Site::stateFileName((string) $row['state_file']))) {
                continue;
            }
            $checked++;
            $path = AA_Module_Site::stateFilePath($name);
            if (is_file($path) && is_readable($path)) {
                continue;
            }
            $findings[] = new Finding(
                Finding::ERROR,
                // the title is escaped by the renderer (admin/aa_statecheck.php); only evidence rows are escaped here
                _m('Site module "%1" has no state file', [(string) ($row['name'] ?? '') ?: $row['id']]),
                evidence: [[
                    _m('Missing file')        => safe('modules/site/sites/' . $name),
                    _m('State file setting')  => safe((string) $row['state_file']),
                ]],
                advice: _m('Every public page of that site answers HTTP 500 (logged as SITE_CONF). Add the file, or fix the "State file" setting of the site module.'),
                links: [_m('Site settings') => AA_Module_Site::getModuleUrl((string) $row['id'], 'modules/site/modedit.php')],
                key: (string) $row['id'],
            );
        }

        return $findings ?: [new Finding(Finding::OK, $checked ? _m('All %1 site state files are in place', [$checked]) : _m('No site module uses a state file'))];
    }
}
