<?php

namespace AA\Manageraction;

use AA\Manager;
use zids;

/**  */
class Links_GoBookmark extends AbstractManageraction {

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        global $links_info;

        $start_id   = $links_info['start_id'];
        $start_path = GetCategoryPath($start_id);

        switch ((string)$action_param) {
            case '1':             // all links
                $GLOBALS['r_state']['show_subtree']    = 1;
                $GLOBALS['r_state']['cat_id']          = $start_id;
                $GLOBALS['r_state']['cat_path']        = $start_path;
                $GLOBALS['r_state']['start_path']      = $start_path;
                $GLOBALS['r_state']['tree_start_path'] = $links_info['tree_start'];
                $GLOBALS['r_state']['bin']             = 'app';
                $manager->resetSearchBar();
                $manager->addOrderBar([0 => ['name' => 'a']]);
                $manager->addSearchBar([0 => ['name' => 1, 'value' => '', 'operator' => 'RLIKE']]);
                break;
            case '2':             // links to check
                $GLOBALS['r_state']['show_subtree']    = 1;
                $GLOBALS['r_state']['cat_id']          = $start_id;
                $GLOBALS['r_state']['cat_path']        = $start_path;
                $GLOBALS['r_state']['start_path']      = $start_path;
                $GLOBALS['r_state']['tree_start_path'] = $links_info['tree_start'];
                $GLOBALS['r_state']['bin']             = 'app';
                $manager->resetSearchBar();
                $manager->addOrderBar([0 => ['checked' => 'a']]);
                $manager->addSearchBar([0 => ['name' => 1, 'value' => '', 'operator' => 'RLIKE']]);
                break;
            case '3':             // last edited links
                $GLOBALS['r_state']['show_subtree']    = 1;
                $GLOBALS['r_state']['cat_id']          = $start_id;
                $GLOBALS['r_state']['cat_path']        = $start_path;
                $GLOBALS['r_state']['start_path']      = $start_path;
                $GLOBALS['r_state']['tree_start_path'] = $links_info['tree_start'];
                $GLOBALS['r_state']['bin']             = 'app';
                $manager->resetSearchBar();
                $manager->addOrderBar([0 => ['last_edit' => 'd']]);
                $manager->addSearchBar([0 => ['name' => 1, 'value' => '', 'operator' => 'RLIKE']]);
                break;
        }
        return false;     // OK - no errors
    }
}