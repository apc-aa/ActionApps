<?php

namespace AA\Manageraction;

use AA;
use AA\IO\DB\DB_AA;
use AA\Manager;
use AA_Property;
use zids;

/** Manager action Item_Move2slice
 *  Move items to another slice
 * @param $slice       - slice object - slice, from which we export
 * @param $item_arr    - array, where keys are unpacked ids of items prefixed by
 *                     'x' character (javascript purposes only)
 * @param $akce_param  - unpacked id of slice, where items should be moved
 */
class Item_Move2Slice extends AbstractManageraction {

    /** specifies, to which bin the move should be performed */
    var $slice_id;

    /** Constructor - fills the information about the target bin
     *  We use default empty parameters, since we need to construct this
     *  class from state by setFromState() method
     */
    function __construct($id = '', $slice_id = '') {
        $this->slice_id = $slice_id;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Move to another slice');
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     *
     *  We extending AA_Storable, because we want to get the state form some
     *  actions. Action selectbox is able to display settings by AJAX call, where
     *  we need to pass all parameters of the object
     * @return array
     */
    public static function getClassProperties(): array {
        $properties = parent::getClassProperties();
        //                                          id             name                              type    multi  persistent - validator, required, help, morehelp, example
        $properties['slice_id'] = new AA_Property('slice_id', _m('Slice ID'), 'text', false, true);
        return $properties;
    }

    /** Defines the HTNML for parameters. All parameters stored into akce_param[]
     *  array will be passed to perform() method for action execution
     */
    function htmlSettings() {
        global $g_modules;

        $options = [];
        if (is_array($g_modules) and (count($g_modules) > 1)) {
            foreach ($g_modules as $sid => $v) {
                //  we can feed just between slices ('S')                                                 // we must have autor or editor perms in destination slices
                if (($v['type'] == 'S') and ((string)$this->slice_id != (string)$sid) and IfSlPerm(PS_ITEMS2ACT, $sid)) {
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
    function perform(Manager $manager, zids $item_zids, $action_param) {
        if (strlen($action_param['dest_slice_id']) < 1) {
            return _m('No slice selected');
        }

        $dest_slice_id = $action_param['dest_slice_id'];

        if (!IfSlPerm(PS_DELETE_ITEMS, $this->slice_id) or !IfSlPerm(PS_ITEMS2ACT, $dest_slice_id)) {    // permission to delete items and add items in destination slice?
            return _m("You have not permissions to move items");
        }

        if ($item_zids->count() < 1) {
            return false;     // OK
        }

        // check if there are no ids from bad slice (attack???)
        $wherein = " AND " . $item_zids->sqlin('id');
        $SQL     = "SELECT id FROM item WHERE slice_id = '" . q_pack_id($this->slice_id) . "' $wherein";

        $zids_to_move = new zids(GetTable2Array($SQL, '', 'id'), 'p');

        if ($zids_to_move->count() < 1) {
            return false;     // OK
        }

        DB_AA::sql("UPDATE item SET slice_id = '" . q_pack_id($dest_slice_id) . "' WHERE " . $zids_to_move->sqlin('id'));

        AA::Pagecache()->invalidateFor([$this->slice_id, $dest_slice_id]);  // invalidate old cached values

        return false;                                  // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
        return IfSlPerm(PS_DELETE_ITEMS, $this->slice_id);
    }
}