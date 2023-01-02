<?php

namespace AA\Manageraction;

use AA\Later\Toexecute;
use AA\Manager;
use zids;

/** Manager action Item_DeleteTrash - Handler for DeleteTrash switch
 *  Delete all items in the trash bin
 */
class Taskmanager_Execute extends AbstractManageraction {

    /** Name of this Manager's action */
    function getName() {
        return _m('Execute');
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {

        if (!IfSlPerm(PS_EDIT)) {    // permission to delete items?
            /** @todo Should be changed to different permission */
            return _m("You have not permissions to remove items");
        }

        if ($item_zids->count() < 1) {
            return false;     // OK
        }

        $toexecute = new Toexecute;
        $toexecute->executeTask($item_zids->shortids());
        return $toexecute->report();
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
        /** @todo Should be changed to different permission */
        // if we want to use it as "action" (not "switch"), then we should be in trash bin
        return IfSlPerm(PS_EDIT);
    }
}