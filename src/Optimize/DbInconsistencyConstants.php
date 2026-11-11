<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\Adminlink;

/** Constant groups no code uses - Repair deletes them
 */
class DbInconsistencyConstants extends AbstractOptimize
{
    /** @var array<string, string[]>|null slice id => its fields holding code */
    private ?array $code_fields = null;

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
     *  fields and views first (they settle most groups in a few probes - 1 s on aadev, 20 s without),
     *  then all template code, the Alerts e-mails, the items of fields expanded as code (f_y, cod)
     *  and the custom PHP of this install.
     *  @return string[] */
    protected function getNotUsedGroups(): array
    {
        $not_used = [];
        $all_groups = DB_AA::select('group_id', "SELECT DISTINCT group_id FROM `constant`");
        foreach ($all_groups as $grp) {
            $grp = (string) $grp;
            // a name with spaces around is another group than the trimmed one - getUntrimmedGroups() reports it
            if (($grp !== '') && ($grp === trim($grp))
                and !in_array($grp, RedefineCoreConstants::CORE_GROUPS, true)
                and !DB_AA::test('field', [['input_show_func',":$grp",'CONTAIN']])
                and !DB_AA::test('field', [['alias1_func',":$grp",'CONTAIN']])
                and !DB_AA::test('field', [['alias2_func',":$grp",'CONTAIN']])
                and !DB_AA::test('field', [['alias3_func',":$grp",'CONTAIN']])
                and !DB_AA::test('view', [['parameter',$grp]])
                and !DB_AA::test('view', [['odd',":$grp",'CONTAIN']])
                and !DB_AA::test('view', [['even',":$grp",'CONTAIN']])
                and !DB_AA::test('view', [['`before`',":$grp",'CONTAIN']])
                and !DB_AA::test('view', [['after',":$grp",'CONTAIN']])
                and !DB_AA::test('view', [['group_title',":$grp",'CONTAIN']])
                and !CodePoints::contains($grp)
                and !$this->inEmails($grp)
                and !$this->inCodeFields($grp)
                and !str_contains($this->customCode(), $grp)
            ) {
                $not_used[] = $grp;
            }
        }
        return $not_used;
    }

    /** groups stored with spaces around the name - no code can name them reliably, so Repair leaves them
     *  @return string[] */
    protected function getUntrimmedGroups(): array
    {
        return array_values(array_filter(array_map('strval', DB_AA::select('group_id', 'SELECT DISTINCT group_id FROM `constant`')), static fn(string $grp): bool => ($grp !== trim($grp))));
    }

    /** the Alerts e-mail templates are expanded like any template */
    private function inEmails(string $grp): bool
    {
        foreach (['subject', 'body', 'header_from', 'reply_to', 'errors_to', 'sender'] as $column) {
            if (DB_AA::test('email', [[$column, $grp, 'CONTAIN']])) {
                return true;
            }
        }
        return false;
    }

    /** an item field shown by the alias function f_y or edited by the code widget holds template code
     *  in any slice, not only in Code slices (CodePoints). The fields of all such slices are searched in all of
     *  them - a group is rather kept than deleted. */
    private function inCodeFields(string $grp): bool
    {
        $this->code_fields ??= $this->codeFields();
        if (!$this->code_fields) {
            return false;
        }
        return DB_AA::test(['content', 'item'], [['content.text', $grp, 'CONTAIN'], ['content.item_id', 'item.id', 'j'], ['item.slice_id', array_keys($this->code_fields), 'l'], ['content.field_id', array_unique(array_merge(...array_values($this->code_fields)))]]);
    }

    /** @return array<string, string[]> slice id => its fields holding code */
    protected function codeFields(): array
    {
        $fields = [];
        foreach ([['alias1_func', 'f_y'], ['alias2_func', 'f_y'], ['alias3_func', 'f_y'], ['input_show_func', 'cod']] as [$column, $prefix]) {
            foreach (DB_AA::select([], 'SELECT LOWER(HEX(`slice_id`)) AS sid, `id` FROM `field`', [[$column, $prefix, 'BEGIN']]) as $row) {
                $fields[(string) $row['sid']][] = (string) $row['id'];
            }
        }
        return $fields;
    }

    /** the PHP of this install AA does not ship: include/usr_aliasfnc.php and include/custom/<AA_CUSTOM_DIR>/ */
    protected function customCode(): string
    {
        if ($this->custom_code === null) {
            $include = dirname(__DIR__, 2) . '/include/';
            $files   = glob($include . 'usr_aliasfnc.php*') ?: [];
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
