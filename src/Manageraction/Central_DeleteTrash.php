<?php

namespace AA\Manageraction;

use AA\IO\DB\DB_AA;
use AA\Manager;
use zids;

/** Manager action Central_DeleteTrash - Handler for DeleteTrash switch
 *  Delete all AAs in the trash bin
 */
class Central_DeleteTrash extends AbstractManageraction
{
    /** specifies, if we have to delete only items specified in $item_arr
     *  otherwise delete all items in Trash
     *  With $selected=true  it is used as "action" of manager
     *  With $selected=false it is used as "switch" of manager (left menu)
     */
    public $selected;

    /** Constructor - fills the information about the target bin */
    public function __construct($id, $selected = false)
    {
        $this->selected = $selected;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    public function getName(): string
    {
        return _m('Remove (delete from database)');
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        if (!IsSuperadmin()) {    // permission to delete items?
            return _m("You do not have permissions to remove items");
        }

        $where           = [['status_code', 3, 'i']];
        $items_to_delete = $item_zids->shortids();

        // restrict the deletion only to selected items
        if ($this->selected) {
            if (count($items_to_delete) < 1) {
                return false;
            }
            $where[] = ['id', $items_to_delete];
        }

        // now we ask, which items we have to delete. We are checking the items even
        // it is specified in $item_arr - for security reasons - we can delete only
        // items in current slice and in trash
        $items_to_delete = DB_AA::select('id', 'SELECT id FROM central_conf', $where);
        if (count($items_to_delete) < 1) {
            return false;     // OK - no errors
        }

        // delete content of all fields
        // don't worry about fed fields - content is copied
        $wherein = "IN ('" . join_and_quote("','", $items_to_delete) . "')";
        $db = getDB();
        $db->query("DELETE FROM central_conf WHERE id " . $wherein);
        freeDB($db);
        return false;     // OK - no errors
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
        return (IsSuperadmin() && (!$this->selected || ($manager->getBin() === 'trash')));
    }
}
