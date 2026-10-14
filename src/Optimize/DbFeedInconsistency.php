<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA_Slice;

/** Testing if feeds table do not contain relations to non-existent slices
 */
class DbFeedInconsistency extends AbstractOptimize
{
    /** shown on State Check - see AbstractOptimize::CHECK_* */
    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Feeds table inconsistent records");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("Testing if feeds table does not contain relations to non-existent slices (after slice deletion)");
    }

    /** Test function
     * tests for duplicate entries
     * @return bool
     */
    public function test(): bool
    {
        $ret = true;

        // test wrong destination slices
        $SQL = "SELECT from_id,to_id FROM feeds LEFT JOIN slice ON feeds.to_id=slice.id
                WHERE slice.id IS NULL";
        $err = [];
        foreach (DB_AA::select([], $SQL) as $row) {
            $err[unpack_id($row['from_id'])] = unpack_id($row['to_id']);
        }
        if (count($err)) {
            foreach ($err as $from_id => $to_id) {
                $this->message(_m('Wrong destination slice id: %1 -> %2', [AA_Slice::getModuleNameSafe($from_id), $to_id]));
            }
            $ret = false;
        }

        // test wrong source slices
        $SQL = "SELECT from_id,to_id FROM feeds LEFT JOIN slice ON feeds.from_id=slice.id
                WHERE slice.id IS NULL";
        $err = [];
        foreach (DB_AA::select([], $SQL) as $row) {
            $err[unpack_id($row['from_id'])] = unpack_id($row['to_id']);
        }
        if (count($err)) {
            foreach ($err as $from_id => $to_id) {
                $this->message(_m('Wrong source slice id: %1 -> %2', [$from_id, AA_Slice::getModuleNameSafe($to_id)]));
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
    public function repair(): bool
    {
        // test wrong destination slices
        $SQL = "SELECT to_id FROM feeds LEFT JOIN slice ON feeds.to_id=slice.id WHERE slice.id IS NULL";
        $err = array_map('unpack_id', DB_AA::select('to_id', $SQL));
        $db = getDb();

        if (count($err)) {
            foreach ($err as $wrong_slice_id) {
                $SQL = 'DELETE FROM `feeds` WHERE `to_id`=' . xpack_id($wrong_slice_id);
                $db->query($SQL);
            }
        }

        // test wrong source slices
        $SQL = "SELECT from_id FROM feeds LEFT JOIN slice ON feeds.from_id=slice.id WHERE slice.id IS NULL";
        freeDb($db);
        $err = array_map('unpack_id', DB_AA::select('from_id', $SQL));
        $db = getDb();

        if (count($err)) {
            foreach ($err as $wrong_slice_id) {
                $SQL = 'DELETE FROM `feeds` WHERE `from_id`=' . xpack_id($wrong_slice_id);
                $db->query($SQL);
            }
        }

        freeDb($db);
        return true;
    }
}
