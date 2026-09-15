<?php

namespace AA\Manageraction;

use AA;
use AA\IO\DB\DB_AA;
use AA\Manager;
use AA_Items;
use aaevent;
use zids;

/** Manager action Item_DeleteTrash - Handler for DeleteTrash switch
 *  Delete all items in the trash bin
 */
class Item_DeleteTrash extends AbstractManageraction
{
    /** specifies, if we have to delete only items specified in $item_arr
     *  otherwise delete all items in Trash
     *  With $selected=true  it is used as "action" of manager
     *  With $selected=false it is used as "switch" of manager (left menu)
     */
    protected bool $selected;

    /** Constructor - fills the information about the target bin */
    public function __construct($id, bool $selected = false)
    {
        parent::__construct($id);
        $this->selected = $selected;
    }

    /** Name of this Manager's action */
    public function getName(): string
    {
        return _m('Remove (delete from database)');
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        global $slice_id;

        if (!IfSlPerm(PS_DELETE_ITEMS)) {    // permission to delete items?
            return _m("You do not have permissions to remove items");
        }

        // restrict the deletion only to selected items
        if ($this->selected) {
            if ($item_zids->isEmpty()) {
                return false;     // OK
            }
            $items_to_delete = new zids(DB_AA::select('id', 'SELECT id FROM `item`', [
                ['status_code', 3],
                ['slice_id', $slice_id, 'l'],
                ['id', $item_zids->longids(), 'l'],
            ]), 'p');
        } else {
            $items_to_delete = new zids(DB_AA::select('id', 'SELECT id FROM `item`', [
                ['status_code', 3],
                ['slice_id', $slice_id, 'l'],
            ]), 'p');
        }

        if ($items_to_delete->count() < 1) {
            return false;     // OK
        }

        // mimo enabled -- problem?

        //$longids = $items_to_delete->longids(); // we need variable because of pass by reference
        aaevent::singleton()->comes('ITEMS_BEFORE_DELETE', $slice_id, 'S', $items_to_delete);

        // delete content of all fields
        AA_Items::deleteItems($items_to_delete);

        AA::Pagecache()->invalidateFor($slice_id);
        return false;     // OK
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param \AA\Manager  $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        // if we want to use it as "action" (not "switch"), then we should be in trash bin
        return (IfSlPerm(PS_DELETE_ITEMS) && (!$this->selected || ($manager->getBin() === 'trash')));
    }
}
