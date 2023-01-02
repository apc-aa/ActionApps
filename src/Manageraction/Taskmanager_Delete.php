<?php

namespace AA\Manageraction;

use AA\Manager;
use Cvarset;
use zids;

/** Handler for Delete tsk - Deletes specified tasks */
class Taskmanager_Delete extends AbstractManageraction {

    /** Name of this Manager's action */
    function getName() {
        return _m('Remove (cancel task)');
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {

        if (!IfSlPerm(PS_EDIT)) {    // permission to delete items?
            /** @todo Should be changed to different permission */
            return _m("You have not permissions to remove tasks");
        }

        if ($item_zids->count() < 1) {
            return false;     // OK
        }

        // $event->comes('ITEMS_BEFORE_DELETE', $slice_id, 'S', $items_to_delete);

        $varset = new Cvarset;
        $varset->doDeleteWhere('toexecute', $item_zids->sqlin('id'));
        return false;     // OK
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
        /** @todo Should be changed to different permission */
        return IfSlPerm(PS_EDIT);
    }
}