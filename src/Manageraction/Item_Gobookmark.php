<?php

namespace AA\Manageraction;

use AA\Manager;
use zids;

/** Manager action Item_Tab - Swith to another bin in Manager */
class Item_Gobookmark extends AbstractManageraction {

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        $manager->setFromBookmark($_GET['GoBookmark']);
        $manager->go2page(1);
        return false;        // OK
    }
}