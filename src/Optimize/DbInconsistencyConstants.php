<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use function _m;

/** Testing if feeds table do not contain relations to non-existent slices
 */
class DbInconsistencyConstants extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Check for unused constants");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Test constant table for never used constants groups");
    }

    /**  */
    protected function getNotUsedGroups(): array {
        $not_used = [];
        $all_groups = DB_AA::select('group_id', "SELECT DISTINCT group_id FROM `constant`");
        foreach ($all_groups as $grp) {
            if ($grp AND ($grp!='lt_groupNames') AND !DB_AA::test('field', [['input_show_func',"%:$grp",'LIKE']]) AND !DB_AA::test('field', [['input_show_func',"%:$grp:%",'LIKE']])) {
                $not_used[] = $grp;
            }
        }
        return array_filter(array_map('trim', $not_used), 'strlen');
    }

    /** Test function
     * tests for never used constants
     * @return bool
     */
    public function test(): bool {
        $ret = true;

        $not_used = $this->getNotUsedGroups();

        foreach ($not_used as $grp) {
            $this->message(['problem'=>_m('Not used constant group'),'group'=>$grp, 'values'=>join(', ', DB_AA::select('c', "SELECT CONCAT(`name`,'->',`value`) as c FROM `constant`", [['group_id',$grp]]))]);
            $ret = false;
        }
        return $ret;
    }

    /** Name function
     * @return bool
     */
    public function repair(): bool {
        $not_used = $this->getNotUsedGroups();
        $deleted  = DB_AA::delete("constant", [['group_id',$not_used]]);
        $deleted2 = DB_AA::delete("constant", [['group_id','lt_groupNames'],['value',$not_used]]);

        $this->message(_m('Deleted %1 constants in %2 (%3) unused constant groups', [$deleted, count($not_used), $deleted2]));
        return true;
    }
}