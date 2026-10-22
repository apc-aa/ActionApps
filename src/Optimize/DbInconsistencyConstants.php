<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\Adminlink;

/** Testing if feeds table do not contain relations to non-existent slices
 */
class DbInconsistencyConstants extends AbstractOptimize
{
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

    /**  */
    protected function getNotUsedGroups(): array
    {
        $not_used = [];
        $all_groups = DB_AA::select('group_id', "SELECT DISTINCT group_id FROM `constant`");
        foreach ($all_groups as $grp) {
            // the usual places first: they settle most groups in a few probes (1 s on aadev, 20 s without).
            // Repair deletes what this misses, so then any mention of the name in any code counts as a use.
            if ($grp
                and ($grp !== 'lt_groupNames')
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
                and !CodePoints::contains((string) $grp)
            ) {
                $not_used[] = $grp;
            }
        }
        return array_filter(array_map('trim', $not_used), 'strlen');
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
        $not_used = $this->getNotUsedGroups();
        $deleted  = DB_AA::delete("constant", [['group_id',$not_used]]);
        $deleted2 = DB_AA::delete("constant", [['group_id','lt_groupNames'],['value',$not_used]]);

        $this->message(_m('Deleted %1 constants in %2 (%3) unused constant groups', [$deleted, count($not_used), $deleted2]));
        return true;
    }
}
