<?php
/**
 * File contains definitions of functions which corresponds with actions
 * on Item Manager page (admin/index.php3) - manipulates with polls
 *
 * Should be included to other scripts (admin/index.php3)
 *
 *   Move polls to app/hold/trash based on param
 *  @param $status    static function parameter defined in manager action
 *                   in this case it holds bin number, where the central_confs should go
 *  @param $item_arr array, where keys are unpacked ids of items prefixed by
 *                   'x' character (javascript purposes only)
 *  @param $akce_param additional parameter for the action - not used here
 *
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

/** AA_Manageraction - Item manager actions. Just create new class and assign
 *  it to your manager
 */
class AA_Manageraction_Polls_MoveItem extends AA_Manageraction {

    /** specifies, to which bin the move should be performed */
    var $to_bin;

    /** Constructor - fills the information about the target bin */
    function __construct($id, $to_bin) {
        $this->to_bin = $to_bin;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    function getName() {
        switch($this->to_bin) {
            case 1: return _m('Move to Active');
            case 2: return _m('Move to Holding bin');
            case 3: return _m('Move to Trash');
        }
        return "";
    }

    /** main executive function
     * @param $manager
     * @param $item_zids - array of id of AA records to check
     * @param $action_param - not used
     * @return bool
     */
    function perform($manager, $item_zids, $action_param) {
        $SQL = "UPDATE polls SET status_code = '".$this->to_bin."' WHERE ". $item_zids->sqlin('id', true);
        DB_AA::sql($SQL);
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        $current_bin     =  $manager->getBin();

        switch($this->to_bin) {
            case 1: return IfSlPerm(PS_ITEMS2ACT) AND
                           ($current_bin != 'app' ) AND
                           ($current_bin != 'appb') AND
                           ($current_bin != 'appc');
                    // Folder2 is Holding bin - prepared for more than three bins
            case 2: return IfSlPerm(PS_ITEMS2HOLD) AND
                           ($current_bin != 'folder2');
                    // Folder3 is Trash
            case 3: return IfSlPerm(PS_ITEMS2TRASH) AND
                           ($current_bin != 'folder3');
        }
    }
}

/** AA_Manageraction_Polls_DeleteTrash - Handler for DeleteTrash switch
 *  Delete all AAs in the trash bin
 */
class AA_Manageraction_Polls_DeleteTrash extends AA_Manageraction {

    /** specifies, if we have to delete only items specified in $item_arr
     *  otherwise delete all items in Trash
     *  With $selected=true  it is used as "action" of manager
     *  With $selected=false it is used as "switch" of manager (left menu)
     */
    var $selected;

    /** Constructor - fills the information about the target bin */
    function __construct($id, $selected=false) {
        $this->selected = $selected;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Remove (delete from database)');
    }

    /** main executive function
     * @param $manager
     * @param $item_zids    Items to delete (if 'selected' is $param)
     * @param $action_param  Not used
     * @return if|mixed|void
     */
    function perform($manager, $item_zids, $action_param) {
        if ( !IfSlPerm(PS_DELETE_ITEMS) ) {    // permission to delete items?
            return _m("You have not permissions to remove polls");
        }

        $wherein = '';

        // restrict the deletion only to selected items
        if ($this->selected) {
            $wherein = ' AND '. $item_zids->sqlin('id', true);
        }

        // now we ask, which items we have to delete. We are checking the items even
        // it is specified in $item_arr - for security reasons - we can delete only
        // items in current slice and in trash

        $items_to_delete = GetTable2Array("SELECT id FROM polls WHERE status_code=3 $wherein", '', 'id');
        if (count($items_to_delete) < 1) {
            return;
        }

        // delete content of all fields
        // don't worry about fed fields - content is copied
        $wherein = "IN ('".join_and_quote("','", $items_to_delete)."')";

        DB_AA::sql("DELETE FROM polls WHERE id ".$wherein);
    }

    /** Checks if the user have enough permission to perform the action
     * @param $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        // if we want to use it as "action" (not "switch"), then we should be in trash bin
        return (IfSlPerm(PS_DELETE_ITEMS) AND (!$this->selected OR ($manager->getBin() == 'folder3')));
    }
}


