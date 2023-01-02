<?php

namespace AA\Manageraction;

use AA\IO\DB\DB_AA;
use AA\Manager;
use zids;

/** Marks given link as checked (means visited and content checked)
 *  - used also as AA\Manageraction\Links_* superclass
 */
class Links_Check extends AbstractManageraction {

    protected $perm;

    /** Constructor - fills the information about the target bin */
    function __construct($id) {
        parent::__construct($id);
        $this->perm = PS_LINKS_CHECK_LINK;
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Check Link');
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        DB_AA::sql("UPDATE links_links SET checked = '" . time() . "', checked_by = '" . AuthGetUid() . "' WHERE " . $item_zids->sqlin('id', true));
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        return IsCatPerm($this->perm, $GLOBALS['r_state']['cat_path']);
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @param             $item_zids
     * @param             $action_param
     * @return bool
     */
    function isPerm4Perform($manager, $item_zids, $action_param) {
        return $this->isPerm4Action($manager) and self::permCheck($item_zids, $this->perm);       // _m('No permission to change state of the link');  // error
    }

    /** function, which checks permissions in for many Links action classes */
    static public function permCheck($item_zids, $perm, $allow_nobase4super = false) {

        $base_categs = [];
        $check_super = false;
        foreach ($item_zids as $lid) {
            // get link's base category
            if ($base_categsory_path = GetBaseCategoryPath($lid)) {
                $base_categs[$base_categsory_path] = true;
            } elseif ($allow_nobase4super) {
                $check_super = true;
            } else {
                return false;  // _m('Can\'t get link data');  // error
            }
        }

        foreach ($base_categs as $cat_path => $foo) {
            if (!IsCatPerm($perm, $cat_path)) {
                return false;  //  _m('No permission to move link');  // error
            }
        }

        if ($check_super) {
            return IsSuperadmin();
        }
        return true;
    }
}