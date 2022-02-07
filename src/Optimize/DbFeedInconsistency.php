<?php

namespace AA\Optimize;

use AA_Slice;
use function _m;
use function freeDB;
use function getDB;
use function GetTable2Array;
use function q_pack_id;

/** Testing if feeds table do not contain relations to non existant slices
 */
class DbFeedInconsistency extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Feeds table inconsistent records");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Testing if feeds table do not contain relations to non existant slices (after slice deletion)");
    }

    /** Test function
     * tests for duplicate entries
     * @return bool
     */
    public function test(): bool {
        $ret = true;

        // test wrong destination slices
        $SQL = "SELECT from_id,to_id FROM feeds LEFT JOIN slice ON feeds.to_id=slice.id
                WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, "unpack:from_id", 'unpack:to_id');
        if (is_array($err) and count($err) > 0) {
            foreach ($err as $from_id => $to_id) {
                $this->message(_m('Wrong destination slice id: %1 -> %2', [AA_Slice::getModuleName($from_id), $to_id]));
            }
            $ret = false;
        }

        // test wrong source slices
        $SQL = "SELECT from_id,to_id FROM feeds LEFT JOIN slice ON feeds.from_id=slice.id
                WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, "unpack:from_id", 'unpack:to_id');
        if (is_array($err) and count($err) > 0) {
            foreach ($err as $from_id => $to_id) {
                $this->message(_m('Wrong source slice id: %1 -> %2', [$from_id, AA_Slice::getModuleName($to_id)]));
            }
            $ret = false;
        }
        if ($ret) {
            $this->message(_m('No wrong references found, hurray!'));
        }
        return $ret;
    }

    /** Name function
     * @return bool
     */
    public function repair(): bool {
        $db = getDb();

        // test wrong destination slices
        $SQL = "SELECT to_id FROM feeds LEFT JOIN slice ON feeds.to_id=slice.id WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, '', 'unpack:to_id');

        if (is_array($err) and count($err) > 0) {
            foreach ($err as $wrong_slice_id) {
                $SQL = 'DELETE FROM `feeds` WHERE `to_id`=\'' . q_pack_id($wrong_slice_id) . '\'';
                $db->query($SQL);
            }
        }

        // test wrong source slices
        $SQL = "SELECT from_id FROM feeds LEFT JOIN slice ON feeds.from_id=slice.id WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, '', 'unpack:from_id');

        if (is_array($err) and count($err) > 0) {
            foreach ($err as $wrong_slice_id) {
                $SQL = 'DELETE FROM `feeds` WHERE `from_id`=\'' . q_pack_id($wrong_slice_id) . '\'';
                $db->query($SQL);
            }
        }

        freeDb($db);
        return true;
    }
}