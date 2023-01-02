<?php

namespace AA\Manageraction;

use AA\Manager;
use zids;

/** Manager action Item_Tab - Swith to another bin in Manager */
class Item_Tab extends AbstractManageraction {

    /** specifies, to which bin we want to switch */
    var $to_bin;

    /** Constructor - fills the information about the target bin */
    function __construct($id, $to_bin) {
        $this->to_bin = $to_bin;
        parent::__construct($id);
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        $manager->setBin($this->to_bin);
        $manager->go2page(1);
        return false;        // OK
    }
}