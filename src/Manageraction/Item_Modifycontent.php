<?php

namespace AA\Manageraction;

use AA\Manager;

/** Manager action */
class Item_Modifycontent extends AbstractManageraction
{
    /** Name of this Manager's action */
    public function getName(): string
    {
        return _m('Modify content');
    }

    // uses setOpenUrl() method to open search_replace.php window

    /** Checks if the user have enough permission to perform the action
     *
     * @param Manager  $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        return IfSlPerm(PS_EDIT_ALL_ITEMS);
    }
}
