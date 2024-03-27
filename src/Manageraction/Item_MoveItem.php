<?php

namespace AA\Manageraction;

use AA;
use AA\Manager;
use AA_Value;
use ItemContent;
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
     * @param zids   $zids
     * @param int    $to_bin
     * @param string $slice_id
     * @return int
     */
    protected static function doMove($zids, $to_bin, string $slice_id): int {
        $ids = $zids->longids();

        $newcontent4id = new ItemContent();
        $newcontent4id->setAaValue('status_code.....', new AA_Value($to_bin));
        $newcontent4id->setSliceID($slice_id);

        $updated_items = 0;
        foreach ($ids as $id) {
            // older and slower approach - needs GetItemContent() ...
            // UpdateField($id, 'status_code.....', $val, [false, false, false]);   // invalidate cache, feed, throw events
            $newcontent4id->setItemID($id);
            if ($newcontent4id->storeItem( 'update', [false, true, true] )) {    // !invalidatecache, feed, throw_events
                ++$updated_items;
            }
        }
        return $updated_items;
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        $sid = $manager->getModuleId();
        Item_MoveItem::doMove($item_zids->itemFilter([$sid]), $this->to_bin, $sid);
        AA::Pagecache()->invalidateFor($sid);
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