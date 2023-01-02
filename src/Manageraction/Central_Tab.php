<?php

namespace AA\Manageraction;

use AA\Manager;
use zids;

/** Manager action Central_Tab - Swith to another bin in Manager */
class Central_Tab extends AbstractManageraction {

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
        return false;     // OK - no errors
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        return IsSuperadmin();
    }
}