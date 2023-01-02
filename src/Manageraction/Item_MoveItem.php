<?php

namespace AA\Manageraction;

use AA\Manager;
use AA_Value;
use zids;

/**
 */
class Item_MoveItem extends AbstractManageraction {

    /** specifies, to which bin the move should be performed */
    var $to_bin;

    /** Constructor - fills the information about the target bin */
    function __construct($id, $to_bin) {
        $this->to_bin = $to_bin;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    function getName() {
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


    /**
     * @param $zids    zids
     * @param $to_bin  int
     */
    function doMove($zids, $to_bin) {
        $ids = $zids->longids();
        foreach ($ids as $id) {
            UpdateField($id, 'status_code.....', new AA_Value($to_bin), [
                true,
                true,
                true,
            ]);   // invalidate cache, feed, throw events
        }
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        $sid = $manager->getModuleId();
        Item_MoveItem::doMove($item_zids->itemFilter($sid), $this->to_bin);
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        $current_bin = $manager->getBin();
        $module_id   = $manager->getModuleId();

        switch ($this->to_bin) {
            case 1:
                return IfSlPerm(PS_ITEMS2ACT, $module_id) and !in_array($current_bin, ['app', 'appb', 'appc']);
            // Folder2 is Holding bin - prepared for more than three bins
            case 2:
                return IfSlPerm(PS_ITEMS2HOLD, $module_id) and ($current_bin != 'hold');
            // Folder3 is Trash
            case 3:
                return IfSlPerm(PS_ITEMS2TRASH, $module_id) and ($current_bin != 'trash');
        }
        return false;
    }
}