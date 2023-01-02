<?php

namespace AA\Manageraction;

use AA\Manager;
use zids;

/**  */
class Links_Add2Cat extends AbstractManageraction {

    /** Constructor - fills the information about the target bin */
    function __construct($id, $url) {
        parent::__construct($id);
        $this->setOpenUrl($url);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Add to category');
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        if (ctype_digit((string)$action_param)) {
            foreach ($item_zids as $lid) {
                Links_Assign2Category($lid, $action_param);
            }
        }
        return false;                                     // OK - no error
    }
}