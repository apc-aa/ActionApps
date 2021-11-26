<?php

namespace AA\Optimize;

use AA_Permsystem_Reader;
use function _m;
use function getDB;
use function GetTable2Array;

/** There was change in Reader management functionality in AA v2.8.1 */
class ReadersLogin2id extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Convert Readers login to reader id");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("There was change in Reader management functionality in AA v2.8.1, so readers are not internally identified by its login, but by reader ID (item ID of reader in Reader slice). This is much more powerful - you can create relations just as in normal slice. It works well without any change. The only problem is, if you set any slice to be editable by users from Reader slice. In that case the fields edited_by........ and posted_by........ are filled by readers login instead of reader id. You can fix it by \"Repair\".");
    }

    /** Test function
     * @return bool
     */
    public function test(): bool {
        $this->clear_report();
        $ret = true;  // which means OK

        // get all readers in array: id => arrary( name => ...)
        $perm_reader = new AA_Permsystem_Reader();
        $readers = $perm_reader->findUsernames('');
        $posted_by_found = $this->_test_field($readers, 'posted_by');
        if (count($posted_by_found) > 0) {
            $this->message(_m('%1 login names from reader slice found as records in item.posted_by which is wrong (There should be reader ID from AA v2.8.1). "Repair" will correct it.', [count($posted_by_found)]));
            $ret = false;
        }
        $edited_by_found = $this->_test_field($readers, 'edited_by');
        if (count($edited_by_found) > 0) {
            $this->message(_m('%1 login names from reader slice found as records in item.edited_by which is wrong (There should be reader ID from AA v2.8.1). "Repair" will correct it.', [count($edited_by_found)]));
            $ret = false;
        }
        return $ret;
    }

    /** test if we can find an item which was edited by reader and is identified
     *  by login name (instead of item_id)
     * @return array of such users
     */
    function _test_field($readers, $item_field) {
        // get posted_by, edit_by, ... array:  posted_by => 1
        $SQL = "SELECT DISTINCT $item_field FROM item";
        $editors = GetTable2Array($SQL, $item_field, 'aa_mark');
        $ret = [];
        foreach ($readers as $r_id => $reader) {
            if ($reader['name'] and isset($editors[$reader['name']])) {
                $ret[$r_id] = $reader['name'];
            }
        }
        return $ret;
    }

    /** Repair function
     * @return bool
     */
    public function repair(): bool {
        $this->clear_report();

        // get all readers in array: id => arrary( name => ...)
        $perm_reader = new AA_Permsystem_Reader();
        $readers = $perm_reader->findUsernames('');
        $posted_by_found = $this->_test_field($readers, 'posted_by');
        $edited_by_found = $this->_test_field($readers, 'edited_by');
        $db = getDb();
        if (count($posted_by_found) > 0) {
            foreach ($posted_by_found as $r_id => $r_login) {
                $SQL = "UPDATE item SET posted_by = '$r_id' WHERE posted_by = '$r_login'";
                $db->query($SQL);
                $this->message(_m('Column item.posted_by updated for %1 (id: %2).', [$r_login, $r_id]));
            }
        }
        if (count($edited_by_found) > 0) {
            foreach ($edited_by_found as $r_id => $r_login) {
                $SQL = "UPDATE item SET edited_by = '$r_id' WHERE edited_by = '$r_login'";
                $db->query($SQL);
                $this->message(_m('Column item.edited_by updated for %1 (id: %2).', [$r_login, $r_id]));
            }
        }
        return true;
    }
}