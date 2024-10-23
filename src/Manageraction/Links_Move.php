<?php

namespace AA\Manageraction;

use AA\IO\DB\DB_AA;
use AA\Manager;
use zids;

/**  */
class Links_Move extends \AA\Manageraction\Links_Check {

    /** specifies, to which bin the move should be performed */
    var $to_bin;

    /** Constructor - fills the information about the target bin */
    function    __construct($id, $to_bin) {
        parent::__construct($id);
        $this->to_bin = $to_bin;
    }

    /** Name of this Manager's action */
    public function getName(): string {
        return match ($this->to_bin) {
            1       => _m('Move to Active'),
            2       => _m('Move to Holding bin'),
            3       => _m('Move to Trash'),
            default => "",
        };
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        $SQL = "UPDATE links_links SET folder = '" . $this->to_bin . "' WHERE " . $item_zids->sqlin('id', true);
        DB_AA::sql($SQL);
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        $cid         = $GLOBALS['r_state']['cat_path'];
        $subtree     = $GLOBALS['r_state']['show_subtree'];
        $current_bin = $GLOBALS['r_state']['bin'];

        return match ($this->to_bin) {
                  1 => !$subtree && IsCatPerm(PS_LINKS_ADD_LINK, $cid),
                  2 => ($current_bin !== 'folder2') && IsCatPerm(PS_LINKS_LINK2FOLDER, $cid),
                  3 => ($current_bin !== 'folder3') && IsCatPerm(PS_LINKS_LINK2FOLDER, $cid),
            default => false,
        };
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @param             $item_zids
     * @param             $action_param
     * @return bool
     */
    function isPerm4Perform($manager, $item_zids, $action_param) {
        $perm_needed = ($manager->getBin() == 1 ? PS_LINKS_LINK2ACT : PS_LINKS_LINK2FOLDER);
        return $this->isPerm4Action($manager) and \AA\Manageraction\Links_Check::permCheck($item_zids, $perm_needed, true);
    }
}