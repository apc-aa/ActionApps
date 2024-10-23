<?php

namespace AA\Manageraction;

use AA\IO\DB\DB_AA;
use AA\Manager;
use zids;

/** AA\Manageraction - Item manager actions. Just create new class and assign
 *  it to your manager
 */
class Polls_MoveItem extends AbstractManageraction {

    /** specifies, to which bin the move should be performed */
    var $to_bin;

    /** Constructor - fills the information about the target bin */
    function __construct($id, $to_bin) {
        $this->to_bin = $to_bin;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    public function getName(): string {
        return match ($this->to_bin) {
                  1 => _m('Move to Active'),
                  2 => _m('Move to Holding bin'),
                  3 => _m('Move to Trash'),
            default => "",
        };
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        $SQL = "UPDATE polls SET status_code = '" . $this->to_bin . "' WHERE " . $item_zids->sqlin('id', true);
        DB_AA::sql($SQL);
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        $current_bin = $manager->getBin();

        switch ($this->to_bin) {
            case 1:
                return IfSlPerm(PS_ITEMS2ACT) and   ($current_bin !== 'app') and
                                                    ($current_bin !== 'appb') and
                                                    ($current_bin !== 'appc');

            case 2:   // Folder2 is Holding bin - prepared for more than three bins
                return IfSlPerm(PS_ITEMS2HOLD) and  ($current_bin !== 'folder2');

            case 3:   // Folder3 is Trash
                return IfSlPerm(PS_ITEMS2TRASH) and ($current_bin !== 'folder3');
        }
        // should not be reached
        return false;
    }
}