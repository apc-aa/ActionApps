<?php

namespace AA\Manageraction;

use AA\IO\DB\DB_AA;
use AA\Manager;
use zids;

/** Manager action */
class Central_MoveItem extends AbstractManageraction {

    /** specifies, to which bin the move should be performed */
    var $to_bin;

    /** Constructor - fills the information about the target bin */
    function __construct($id, $to_bin) {
        $this->to_bin = $to_bin;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    public function getName(): string {
        switch ($this->to_bin) {
            case 1:
                return _m('Move to Active');
            case 2:
                return _m('Move to Holding bin');
            case 3:
                return _m('Move to Trash');
        }
        return "";
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        $item_ids = $item_zids->shortids();

        if ($item_ids) {
            $SQL = "UPDATE central_conf SET status_code = '" . $this->to_bin . "' WHERE id IN ('" . join_and_quote("','", $item_ids) . "')";
            DB_AA::sql($SQL);
        }
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        $current_bin = $manager->getBin();

        /** for acces to Central you have to be superadmin */
        if (!IsSuperadmin()) {
            return false;
        }

        switch ($this->to_bin) {
            case 1:
                return ($current_bin != 'app') and
                    ($current_bin != 'appb') and
                    ($current_bin != 'appc');
            // Folder2 is Holding bin - prepared for more than three bins
            case 2:
                return ($current_bin != 'hold');
            // Folder3 is Trash
            case 3:
                return ($current_bin != 'trash');
        }

        // should not be reached
        return false;
    }
}