<?php

namespace AA\Manageraction;

use AA\Manager;
use zids;

/** Manager action Central_DeleteTrash - Handler for DeleteTrash switch
 *  Delete all AAs in the trash bin
 */
class Central_DeleteTrash extends AbstractManageraction {

    /** specifies, if we have to delete only items specified in $item_arr
     *  otherwise delete all items in Trash
     *  With $selected=true  it is used as "action" of manager
     *  With $selected=false it is used as "switch" of manager (left menu)
     */
    var $selected;

    /** Constructor - fills the information about the target bin */
    function __construct($id, $selected = false) {
        $this->selected = $selected;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Remove (delete from database)');
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        if (!IsSuperadmin()) {    // permission to delete items?
            return _m("You have not permissions to remove items");
        }

        $wherein         = '';
        $items_to_delete = $item_zids->shortids();

        // restrict the deletion only to selected items
        if ($this->selected) {
            if (count($items_to_delete) < 1) {
                return false;
            }
            $wherein = " AND id IN ('" . join_and_quote("','", $items_to_delete) . "')";
        }

        $db = getDB();

        // now we ask, which items we have to delete. We are checking the items even
        // it is specified in $item_arr - for security reasons - we can delete only
        // items in current slice and in trash
        $db->query("SELECT id FROM central_conf WHERE status_code=3 $wherein");
        $items_to_delete = [];
        while ($db->next_record()) {
            $items_to_delete[] = $db->f("id");
        }
        if (count($items_to_delete) < 1) {
            freeDB($db);
            return false;     // OK - no errors
        }

        // delete content of all fields
        // don't worry about fed fields - content is copied
        $wherein = "IN ('" . join_and_quote("','", $items_to_delete) . "')";
        $db->query("DELETE FROM central_conf WHERE id " . $wherein);
        freeDB($db);
        return false;     // OK - no errors
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        // if we want to use it as "action" (not "switch"), then we should be in trash bin
        return (IsSuperadmin() and (!$this->selected or ($manager->getBin() == 'trash')));
    }
}