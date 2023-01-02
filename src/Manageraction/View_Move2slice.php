<?php

namespace AA\Manageraction;

use AA;
use AA\IO\DB\DB_AA;
use AA\Manager;
use zids;

/** Manager action Item_Move2slice
 *  Move items to another slice
 * @param $slice       - slice object - slice, from which we export
 * @param $item_arr    - array, where keys are unpacked ids of items prefixed by
 *                     'x' character (javascript purposes only)
 * @param $akce_param  - unpacked id of slice, where items should be moved
 */
class View_Move2slice extends \AA\Manageraction\Item_Move2Slice {

    /** Name of this Manager's action */
    function getName() {
        return _m('Move view to another slice');
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        if (strlen($action_param['dest_slice_id']) < 1) {
            return _m('No slice selected');
        }

        $dest_slice_id = $action_param['dest_slice_id'];

        if (!IfSlPerm(PS_FULLTEXT, $this->slice_id) or !IfSlPerm(PS_FULLTEXT, $dest_slice_id)) {    // permission to delete items and add items in destination slice?
            return _m("You have not permissions to move views");
        }

        if ($item_zids->count() < 1) {
            return false;     // OK
        }

        $ids = DB_AA::select('id', 'SELECT id FROM view', [
            ['slice_id', $this->slice_id, 'l'],
            ['id', $item_zids->shortids(), 'i'],
        ]);

        if (!$ids) {
            return false;     // OK
        }

        DB_AA::update('view', [['slice_id', $dest_slice_id, 'l']], [['id', $ids, 'i']]);

        AA::Pagecache()->invalidateFor([$this->slice_id, $dest_slice_id]);  // invalidate old cached values

        return false;                                  // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
        return IfSlPerm(PS_FULLTEXT, $this->slice_id);
    }
}