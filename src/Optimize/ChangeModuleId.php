<?php

namespace AA\Optimize;

use AA;
use AA_Metabase;
use function _m;
use function new_id;
use function xpack_id;

/** Change module ID to new one
 *  Used sometimes if you clone database or copy slices to another database
 *  for another client and do not want to use the same ids )
 */
class ChangeModuleId extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Change current module ID to new one");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Used sometimes if you clone database or copy slices to another database for another client and do not want to use the same slice/site ids. You probably need to do it for all slices and site module.<br>IT CHANGES CURRENT SLICE ID!");
    }

    /** implemented actions within this class */
    public static function actions(): array { return ['repair']; }

    /** Test function
     * @return true
     */
    public function test(): bool {
        $this->message(_m('Nothing to test here. Just be careful - IT CHANGES CURRENT SLICE ID without asking!'));
        return true;
    }

    /** Repair function
     * repairs tables
     * @return true
     */
    public function repair(): bool {
        $module_fields = AA_Metabase::getModuleFields();

        // additional fields to change
        $module_fields['object_text'] = 'value';

        $current_id = AA::$module_id;
        $p_current_id = xpack_id($current_id);

        $new_id = new_id();
        $p_new_id = xpack_id($new_id);

        $this->message(_m('Going to change module_id %1 -> %2 (%3 -> %4)', [
            $current_id,
            $new_id,
            $p_current_id,
            $p_new_id,
        ]));
        $converted = 0;
        foreach ($module_fields as $table => $field) {
            if (AA_Metabase::isPacked($table, $field)) {
                $SQL = "UPDATE `$table` SET $field=$p_new_id WHERE $field=$p_current_id";
            } else {
                $SQL = "UPDATE `$table` SET $field='$new_id' WHERE $field='$current_id'";
            }
            $this->message(_m('Changing ID for table %1 (%2)', [$table, $field]));
            $this->query($SQL);
            $converted++;
        }
        //$this->message(_m('Changing ID for table %1 (%2)', array($table, $field)));
        //$SQL = "UPDATE `object_text` SET value='$new_id' WHERE value='$current_id'";
        //$this->query($SQL);
        //$converted++;

        $this->message(_m('Converted %1 tables', [$converted]));
        return true;
    }
}