<?php

namespace AA\Manageraction;

use AA;
use AA\IO\DB\DB_AA;
use AA\Manager;
use AA_Slice;
use AA_Property;
use zids;

/** Manager action Item_Move2slice
 *  Move items to another slice
 * @param $slice       - slice object - slice, from which we export
 * @param $item_arr    - array, where keys are unpacked ids of items prefixed by
 *                     'x' character (javascript purposes only)
 * @param $akce_param  - unpacked id of slice, where items should be moved
 */
class Item_Move2Slice extends AbstractManageraction
{
    /** specifies, to which bin the move should be performed */
    public $slice_id;

    /** Constructor - fills the information about the target bin
     *  We use default empty parameters, since we need to construct this
     *  class from state by setFromState() method
     */
    public function __construct($id = '', $slice_id = '')
    {
        $this->slice_id = $slice_id;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    public function getName(): string
    {
        return _m('Move to another slice');
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     *
     *  We are extending AA_Storable, because we want to get the state form some
     *  actions. Action selectbox is able to display settings by AJAX call, where
     *  we need to pass all parameters of the object
     * @return array
     */
    public static function getClassProperties(): array
    {
        $properties = parent::getClassProperties();
        //                                          id             name                              type    multi  persistent - validator, required, help, morehelp, example
        $properties['slice_id'] = new AA_Property('slice_id', _m('Slice ID'), 'text', false, true);
        return $properties;
    }

    /** Defines the HTNML for parameters. All parameters stored into akce_param[]
     *  array will be passed to perform() method for action execution
     */
    public function htmlSettings(): false|string
    {
        global $g_modules;

        $options = [];
        if (is_array($g_modules) && count($g_modules) > 1) {
            foreach ($g_modules as $sid => $v) {
                //  we can feed just between slices ('S')                                                 // we must have autor or editor perms in destination slices
                if ($v['type'] === 'S' && (string) $this->slice_id !== (string) $sid && IfSlPerm(PS_ITEMS2ACT, $sid) && AA_Slice::isStoredSlice((string) $sid)) {
                    $options[$sid] = $v['name'];
                }
            }
        }

        ob_start();
        FrmTabCaption();
        FrmInputSelect('akce_param[dest_slice_id]', _m('Move to slice'), $options);
        FrmTabEnd();
        return ob_get_clean();
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        $dest_slice_id = is_array($action_param) ? ($action_param['dest_slice_id'] ?? '') : '';
        if (!is_string($dest_slice_id) || !is_long_id($dest_slice_id)) {
            return _m('No slice selected');
        }
        $dest_slice_id = strtolower($dest_slice_id);
        if (!AA_Slice::isStoredSlice($dest_slice_id)) {
            return _m('Items can be moved only to a slice.');
        }

        if (!IfSlPerm(PS_DELETE_ITEMS, $this->slice_id) || !IfSlPerm(PS_ITEMS2ACT, $dest_slice_id)) {    // permission to delete items and add items in destination slice?
            return _m("You do not have permissions to move items");
        }

        if ($item_zids->isEmpty()) {
            return false;     // OK
        }

        // check if there are no ids from bad slice (attack???)
        $zids_to_move = new zids(DB_AA::select('id', 'SELECT id FROM item', [['slice_id', $this->slice_id, 'l'], $item_zids->whereIn('id')]), 'p');

        if ($zids_to_move->count() < 1) {
            return false;     // OK
        }

        DB_AA::sql("UPDATE item SET slice_id = " . xpack_id($dest_slice_id) . " WHERE " . $zids_to_move->sqlin('id'));

        AA::Pagecache()->invalidateFor([$this->slice_id, $dest_slice_id]);  // invalidate old cached values

        return false;                                  // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param \AA\Manager  $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        return IfSlPerm(PS_DELETE_ITEMS, $this->slice_id);
    }
}
