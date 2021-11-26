<?php

namespace AA\Optimize;

use function _m;
use function freeDB;
use function getDB;
use function GetTable2Array;
use function quote;

/** Testing if relation table contain records, where values in both columns are
 *  identical (which was bug fixed in Jan 2006)
 */
class CategorySort2groupBy extends AbstractOptimize {

    /** Name function
     * @return string - name
     */
    public static function name(): string {
        return _m("Convert slice.category_sort to slice.group_by");
    }

    /** Description function
     * @return string - description
     */
    public static function description(): string {
        return _m("In older version of AA we used just category fields for grouping items. Now it is universal, so boolean category_sort is not enough. We use newer group_by field for quite long time s most probably all your slices are already conevrted.");
    }

    /** Test function
     * @return bool
     */
    public function test(): bool {
        $SQL = "SELECT name FROM slice WHERE category_sort>0 AND ((group_by IS NULL) OR (group_by=''))";
        $slice_names = GetTable2Array($SQL, '', 'name');
        if ($slice_names and (count($slice_names) > 0)) {
            $this->message(_m('%1 slices are not converted', [count($slice_names)]) . '<br> &nbsp; ' . join('<br> &nbsp; ', $slice_names));
            return false;
        }
        $this->message(_m('All slices are already converted'));
        return true;
    }

    /** Repair function
     * runs a series of SQL commands
     * @return bool
     */
    public function repair(): bool {
        $db = getDb();
        $SQL = "SELECT id FROM slice WHERE category_sort>0 AND ((group_by IS NULL) OR (group_by=''))";
        $slices = GetTable2Array($SQL, '', 'id');
        foreach ($slices as $p_slice_id) {
            $q_slice_id = quote($p_slice_id);
            $SQL = "SELECT id FROM field WHERE id LIKE 'category.......%' AND slice_id='$q_slice_id'";
            $cat_field = GetTable2Array($SQL, "aa_first", 'id');
            if ($cat_field) {
                // number 2 represents 'a' - ascending (because gb_direction in number)
                $SQL = "UPDATE slice SET group_by='" . quote($cat_field) . "', gb_direction=2, gb_header=0 WHERE id='$q_slice_id'";
            } else {
                $SQL = "UPDATE slice SET group_by='', gb_direction=0, gb_header=0 WHERE id='$q_slice_id'";
            }
            $db->query($SQL);   // correct it
        }
        // fix all category_sort
        $SQL = "UPDATE slice SET category_sort=0";
        $db->query($SQL);
        freeDb($db);
        return true;
    }
}