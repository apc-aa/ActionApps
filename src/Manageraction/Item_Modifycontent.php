<?php

namespace AA\Manageraction;

/** Manager action */
class Item_Modifycontent extends AbstractManageraction {

    /** Name of this Manager's action */
    public function getName(): string {
        return _m('Modify content');
    }

    // uses setOpenUrl() method to open search_replace.php window

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
        return IfSlPerm(PS_EDIT_ALL_ITEMS);
    }
}