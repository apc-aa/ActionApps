<?php

namespace AA\Manageraction;

use AA\Manager;
use zids;

/**  */
class Links_GoCateg extends AbstractManageraction {

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        global $r_state;
        // TODO - refresh permission for new category!!!
        $cpath = GetCategoryPath((int)$action_param);
        if (IsCatPerm(PS_LINKS_EDIT_LINKS, $cpath)) {
            $r_state["show_subtree"] = false;
            $r_state['cat_id']       = $action_param;
            $r_state['cat_path']     = $cpath;
        }
        return false;     // OK - no errors
    }
}