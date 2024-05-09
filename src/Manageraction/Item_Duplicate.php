<?php

namespace AA\Manageraction;

use AA;
use AA\Manager;
use AA_Set;
use zids;

/** Manager action Item_Duplicate - Duplicate selected item in the slice.
 */
class Item_Duplicate extends AbstractManageraction {

    /** Constructor - fills the information about the target bin */
    function __construct($id) {
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    public function getName(): string {
        return _m('Duplicate Item');
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        $sid = $manager->getModuleId();

        $grabber = new AA\IO\Grabber\Slice(new AA_Set([$sid], null, null, AA_BIN_ALL), $item_zids->itemFilter([$sid]));
        // insert_if_new is the same as insert, (but just make sure the item is not in DB which is not important here)
        $saver = new AA\IO\Saver($grabber, null, null, 'insert_if_new', 'new');
        $saver->run();
        //SendOkPage( array("report" => $saver->report() ), $saver->changedIds());
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
        return IfSlPerm(PS_EDIT_ALL_ITEMS, $manager->getModuleId());
    }
}