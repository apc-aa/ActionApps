<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\Adminlink;

/** Constant groups no code uses - Repair deletes them
 */
class DbInconsistencyConstants extends AbstractOptimize
{
    private ?string $custom_code = null;

    /** shown on State Check - see AbstractOptimize::CHECK_*; LIKE scans over all code, so not basic */
    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
    }

    /** unused constant groups are clutter, not a malfunction */
    public static function failSeverity(): string
    {
        return Finding::INFO;
    }

    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Check for unused constants");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("Test constant table for never used constants groups");
    }

    /** Groups no code names - Repair deletes them, so any mention of the name anywhere counts as a use:
     *  all template code (CodePoints - views, fields, settings, spots, objects, e-mails, code items) and the
     *  custom PHP of this install. The code is read once and all the groups looked for in it.
     *  @return string[] */
    protected function getNotUsedGroups(): array
    {
        $candidates = [];
        foreach (DB_AA::select('group_id', 'SELECT DISTINCT group_id FROM `constant`') as $grp) {
            $grp = (string) $grp;
            // a name with spaces around is another group than the trimmed one - getUntrimmedGroups() reports it
            if (($grp !== '') && ($grp === trim($grp)) && !in_array($grp, RedefineCoreConstants::CORE_GROUPS, true)) {
                $candidates[$grp] = true;
            }
        }
        foreach ($this->codeTexts() as $txt) {
            foreach (array_keys($candidates) as $grp) {
                if (str_contains($txt, (string) $grp)) {
                    unset($candidates[$grp]);
                }
            }
            if (!$candidates) {
                break;
            }
        }
        return array_map('strval', array_keys($candidates));
    }

    /** @return iterable<string> */
    protected function codeTexts(): iterable
    {
        yield from CodePoints::texts(true);   // a constant used only in an Alerts e-mail is used
        yield $this->customCode();
    }

    /** groups stored with spaces around the name - no code can name them reliably, so Repair leaves them
     *  @return string[] */
    protected function getUntrimmedGroups(): array
    {
        return array_values(array_filter(array_map('strval', DB_AA::select('group_id', 'SELECT DISTINCT group_id FROM `constant`')), static fn(string $grp): bool => ($grp !== trim($grp))));
    }

    /** the PHP of this install AA does not ship: include/usr_aliasfnc.php, include/custom/<AA_CUSTOM_DIR>/ and
     *  the control files of the sites modules/site/sites/site_*.php */
    protected function customCode(): string
    {
        if ($this->custom_code === null) {
            $include = dirname(__DIR__, 2) . '/include/';
            $files   = array_merge(glob($include . 'usr_aliasfnc.php*') ?: [], glob(dirname(__DIR__, 2) . '/modules/site/sites/site_*.php') ?: []);
            if (defined('AA_CUSTOM_DIR') && is_dir($dir = $include . 'custom/' . constant('AA_CUSTOM_DIR'))) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if (preg_match('/\.(php\d?|inc)$/', $file->getFilename())) {
                        $files[] = $file->getPathname();
                    }
                }
            }
            $this->custom_code = implode("\n", array_map(static fn($file) => (string) file_get_contents($file), $files));
        }
        return $this->custom_code;
    }

    /**  */
    protected function getNonAsciiGroups(): array
    {
        $ret = [];
        $all_groups = DB_AA::select('group_id', "SELECT DISTINCT group_id FROM `constant`");
        foreach ($all_groups as $grp) {
            $len = strlen($grp);
            for ($i = 0; $i < $len; $i++) {
                $ch = $grp[$i];
                if (ord($ch) > 127) {
                    $ret[] = $grp;
                    break;
                }
            }
        }
        return array_filter(array_map('trim', $ret), 'strlen');
    }

    /** Test function
     * tests for never used constants
     * @return bool
     */
    public function test(): bool
    {
        $ret = true;

        $non_ascii = $this->getNonAsciiGroups();

        foreach ($non_ascii as $grp) {
            $this->message(['problem' => _m('Non ASCII chars in group_id'),
                'group'   => Adminlink::searchLink($grp, safe($grp)),
                'values'  => safe(implode(', ', DB_AA::select('c', "SELECT CONCAT(`name`,'->',`value`) as c FROM `constant`", [['group_id', $grp]])))]);
            $ret = false;
        }
        $this->message(_m('Found %1 Non ASCII', [count($non_ascii)]));

        foreach ($this->getUntrimmedGroups() as $grp) {
            $this->message(['problem' => _m('Spaces around the group name - rename the group'),
                'group'   => Adminlink::searchLink($grp, safe("'$grp'")),
                'values'  => safe(implode(', ', DB_AA::select('c', "SELECT CONCAT(`name`,'->',`value`) as c FROM `constant`", [['group_id', $grp]])))]);
            $ret = false;
        }

        $not_used = $this->getNotUsedGroups();

        foreach ($not_used as $grp) {
            $this->message(['problem' => _m('Not used constant group'),
                'group'   => Adminlink::searchLink($grp, safe($grp)),
                'values'  => safe(implode(', ', DB_AA::select('c', "SELECT CONCAT(`name`,'->',`value`) as c FROM `constant`", [['group_id', $grp]])))]);
            $ret = false;
        }
        $this->message(_m('Found %1 unused constant groups', [count($not_used)]));
        return $ret;
    }

    /** Name function
     * @return bool
     */
    public function repair(): bool
    {
        // exactly the stored names: a trimmed one could be another group, even a core one
        $not_used = $this->getNotUsedGroups();
        if (!$not_used) {
            $this->message(_m('Deleted %1 constants in %2 (%3) unused constant groups', [0, 0, 0]));
            return true;
        }
        $deleted  = DB_AA::delete("constant", [['group_id',$not_used]]);
        $deleted2 = DB_AA::delete("constant", [['group_id','lt_groupNames'],['value',$not_used]]);

        $this->message(_m('Deleted %1 constants in %2 (%3) unused constant groups', [$deleted, count($not_used), $deleted2]));
        return true;
    }
}
