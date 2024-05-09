<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA_Slice;
use function _m;
use function freeDB;
use function getDB;
use function q_pack_id;

/** Set Expiry date to maximum value for items in slices, where expiry_date.....
 *  field is not shown. It also sets the field's default to that value.
 */
class SetExpirydate extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Set Expiry date to maximum value");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Set Expiry date to maximum value for items in slices, where expiry_date..... field is not shown. It also sets the field's default to that value.<br><b>Is it really what you want?</b>");
    }

    /** Test function
     * @return bool
     */
    public function test(): bool {

//      $slices   = GetTable2Array("SELECT slice_id, input_default FROM `field` WHERE id='expiry_date.....' AND input_show=0", "unpack:slice_id", 'input_default');
        $slices = DB_AA::select(['sid' => 'input_default'], 'SELECT LOWER(HEX(`slice_id`)) AS sid, input_default FROM `field`', [
            ['id', 'expiry_date.....',],
            ['input_show', 0, 'i',],
        ]);
        foreach ($slices as $sid => $default) {
            $this->message([
                _m('Slice ID')                           => $sid,
                _m('Slice Name')                         => safe(AA_Slice::getModuleName($sid)),
                _m('default value for expiry_date.....') => $default,
            ]);
        }
        $this->message(_m('We found %1 hidden exipry_date..... fields', [count($slices)]));
        // $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', array($wrong_count, $row_count)));
        return !count($slices);
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    public function repair(): bool {
        $db = getDb();
//        $slices   = GetTable2Array("SELECT slice_id, input_default FROM `field` WHERE id='expiry_date.....' AND input_show=0", "unpack:slice_id", 'input_default');
        $slices = DB_AA::select(['sid' => 'input_default'], 'SELECT LOWER(HEX(`slice_id`)) AS sid, input_default FROM `field`', [
            ['id', 'expiry_date.....',],
            ['input_show', 0, 'i',],
        ]);
        foreach ($slices as $sid => $default) {
            $db->query("UPDATE item SET expiry_date=2145826800 WHERE slice_id='" . q_pack_id($sid) . "'");
            //huhl("UPDATE item SET expiry_date=2145826800 WHERE slice_id='".q_pack_id($sid)."'");
            $this->message(_m('items changed: %1 in slice: %2 (%3)', [
                $db->affected_rows(),
                $sid,
                AA_Slice::getModuleName($sid),
            ]));

            $db->query("UPDATE field SET input_default='never:' WHERE id='expiry_date.....' AND slice_id='" . q_pack_id($sid) . "'");
            //huhl("UPDATE field SET input_default='txt:2145826800' WHERE id='expiry_date.....' AND slice_id='".q_pack_id($sid)."'");
            $this->message(_m('field default changed for expiry_date..... in %1 (%2)', [
                $sid,
                AA_Slice::getModuleName($sid),
            ]));
        }
        freeDb($db);
        return true;
    }
}