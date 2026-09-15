<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA_Slice;

/** Set Expiry date to maximum value for items in slices, where expiry_date.....
 *  field is not shown. It also sets the field's default to that value.
 */
class SetExpirydate extends AbstractOptimizeById
{
    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Set Expiry date to maximum value");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("Set Expiry date to maximum value for items in slices, where expiry_date..... field is not shown. It also sets the field's default to that value.<br><b>Is it really what you want?</b>");
    }

    /** Find bad record and return their ids with the description
     * get ['id' => ['some'=>'', 'fields'=>'', 'to_be_shown'=>'']] - id could be number, string or compound value
     *                                                                but must describe the row to fix/delete
     */
    protected function get_bad_ids(): array
    {
        $bad_ids = [];

        $potential_slices = DB_AA::select(['sid' => []], 'SELECT LOWER(HEX(`slice_id`)) AS sid, input_default FROM `field`', [
            ['id', 'expiry_date.....',],
            ['input_show', 0, 'i',],
        ]);

        foreach ($potential_slices as $sid => $row) {
            if (DB_AA::test('item', [['slice_id', $sid, 'l'], ['expiry_date', 2145826800, 'i<>']]) or DB_AA::test('field', [['slice_id', $sid, 'l'], ['id', 'expiry_date.....',], ['input_default','never:', 's<>']])) {
                $bad_ids[$sid] = ['name' => AA_Slice::getModule($sid)?->getName() ?? '', ...$row];   // raw name - AbstractOptimizeById::test() safe()s the row
            }
        }

        return $bad_ids;
    }

    public function repair4ids(array $ids): false|string
    {

        $ids = $this->filter_valid_ids($ids); // security check to not repair invalid ids

        $items = DB_AA::update('item', [['expiry_date',2145826800,'i']], [['slice_id', $ids, 'l'], ['expiry_date', 2145826800, 'i<>']]);
        $this->message(_m('items changed: %1 / %2 slices: %3', [$items, count($ids), implode(', ', $ids)]));            // AA_Slice::getModuleName($sid),

        $fields = DB_AA::update('field', [['input_default','never:']], [['id','expiry_date.....'],['slice_id',$ids, 'l']]);
        $this->message(_m('field default changed for expiry_date..... in %1 /%2 slices: %3)', [$fields, count($ids), implode(', ', $ids)]));            // AA_Slice::getModuleName($sid),

        return "items $items, fields $fields";
    }
}
