<?php

namespace AA\Manageraction;

use AA\Manager;
use zids;

/**  */
class Links_Delete extends AbstractManageraction {

    /** Constructor - fills the information about the target bin */
    function __construct($id) {
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Remove from category');
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        foreach ($item_zids as $lid) {
            Links_DeleteLink($lid);
        }
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        return !$GLOBALS['r_state']['show_subtree'] && IsCatPerm(PS_LINKS_DELETE_LINK, $GLOBALS['r_state']['cat_path']);
    }
}