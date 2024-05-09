<?php

namespace AA\Manageraction;

use AA\Manager;

/** Manager action */
class Item_Email extends AbstractManageraction {

    /** Name of this Manager's action */
    public function getName(): string {
        return _m('Send email');
    }

    // uses setOpenUrl() method to open search_replace.php window

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        // $slice = AA_Slice::getModule($manager->getModuleId());
        // return ($slice->type() == 'ReaderManagement');
        return true; // allow in all slices
    }
}