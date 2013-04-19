<?php
/**
 * File contains definitions of functions which corresponds with actions
 * on Item Manager page (admin/index.php3) - manipulates with items
 *
 * Should be included to other scripts (admin/index.php3)
 *
 *   Move item to app/hold/trash based on param
 *  @param $status    static function parameter defined in manager action
 *                   in this case it holds bin number, where the items should go
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


require_once AA_INC_PATH."manager.class.php3";

/** AA_Manageraction - Item manager actions. Just create new class and assign
 *  it to your manager
 */
class AA_Manageraction_Item_MoveItem extends AA_Manageraction {

    /** specifies, to which bin the move should be performed */
    var $to_bin;

    /** Constructor - fills the information about the target bin */
    function AA_Manageraction_Item_MoveItem($id, $to_bin) {
        $this->to_bin = $to_bin;
        parent::AA_Manageraction($id);
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
    * @param $param       - not used
    * @param $item_arr    - array of id of AA records to check
    * @param $akce_param  - not used
    */
    function doMove($zids, $to_bin) {
        global $auth, $event, $pagecache;
        $PERMS = array(1 => PS_ITEMS2ACT, 2 => PS_ITEMS2HOLD, 3 => PS_ITEMS2TRASH);

        $to_bin = (int)$to_bin;
        if ($zids->count() > 0) {
            $now  = now();

            $SQL = "SELECT id, slice_id FROM item WHERE (status_code<>$to_bin) AND ". $zids->sqlin('id');
            $ids = GetTable2Array($SQL, 'id', 'unpack:slice_id');

            if (empty($ids)) {
                return;
            }

            $items2move = array();
            foreach($ids as $p_id => $sid) {
                if (IfSlPerm($PERMS[$to_bin], $sid)) {
                    if (!is_array($items2move[$sid])) {
                        $items2move[$sid] = array();
                    }
                    $items2move[$sid][] = $p_id;
                }
            }

            foreach ($items2move as $sid => $p_ids) {

                $xzids = new zids($p_ids, 'p');

                $SQL = "UPDATE item SET
                   status_code = '". $to_bin ."',
                   last_edit   = '$now',
                   edited_by   = '". quote(isset($auth) ? $auth->auth["uid"] : "9999999999")."'";

                // E-mail Alerts
                $moved2active = ( ($to_bin == 1) ? $now : 0 );
                $SQL         .= ", moved2active = $moved2active";
                $SQL         .= " WHERE ". $xzids->sqlin('id');

                tryQuery($SQL);

                $item_ids = $xzids->longids();
                if ($to_bin == 1) {
                    foreach ($item_ids as $iid) {
                        FeedItem($iid);
                    }
                }
                $event->comes('ITEMS_MOVED', $sid, 'S', $item_ids, $to_bin );
                $pagecache->invalidateFor("slice_id=$sid");  // invalidate old cached values
            }
        }
    }

    /** main executive function
    * @param $param       - not used
    * @param $item_arr    - array of id of AA records to check
    * @param $akce_param  - not used
    */
    function perform(&$manager, &$state, $item_arr, $akce_param) {
        $zids = new zids;
        $zids->setFromItemArr($item_arr);
        AA_Manageraction_Item_MoveItem::doMove($zids, $this->to_bin);
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action */
    function isPerm(&$manager) {
        $current_bin     =  $manager->getBin();

        switch($this->to_bin) {
            case 1: return IfSlPerm(PS_ITEMS2ACT) AND
                           ($current_bin != 'app' ) AND
                           ($current_bin != 'appb') AND
                           ($current_bin != 'appc');
                    // Folder2 is Holding bin - prepared for more than three bins
            case 2: return IfSlPerm(PS_ITEMS2HOLD) AND
                           ($current_bin != 'hold');
                    // Folder3 is Trash
            case 3: return IfSlPerm(PS_ITEMS2TRASH) AND
                           ($current_bin != 'trash');
        }
    }
}


/** AA_Manageraction_Item_Duplicate - Duplicate selected item in the slice.
 */
class AA_Manageraction_Item_Duplicate extends AA_Manageraction {

    /** Constructor - fills the information about the target bin */
    function __construct($id) {
        parent::AA_Manageraction($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Duplicate Item');
    }

    /** main executive function
    * @param $param       - not used
    * @param $item_arr    - array of id of AA records to check
    * @param $akce_param  - not used
    */
    function perform(&$manager, &$state, $item_arr, $akce_param) {

        $zids = AA_Manageraction::getZidsSanitized($item_arr, $manager->getModuleId());
        $grabber = new AA_Grabber_Slice(null, $zids);
        // insert_if_new is the same as insert, (but just make sure the item is not in DB which is not important here)
        $saver   = new AA_Saver($grabber, null, null, 'insert_if_new', 'new');
        $saver->run();
        //SendOkPage( array("report" => $saver->report() ), $saver->changedIds());
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action */
    function isPerm(&$manager) {
        return IfSlPerm(PS_EDIT_ALL_ITEMS, $manager->getModuleId());
    }
}


/** AA_Manageraction_Item_Feed
 *  Export (Copy) items to another slice
 *  @param $slice      slice object - slice, from which we export
 *  @param $item_arr   array, where keys are unpacked ids of items prefixed by
 *                     'x' character (javascript purposes only)
 *  @param $akce_param Special string, where destination slices are coded.
 *                     The format is "<status>-<unpacked_slice_id>,<status>-.."
 * @return false or error message
 */
class AA_Manageraction_Item_Feed extends AA_Manageraction {

    /** specifies, to which bin the move should be performed */
    var $slice_id;

    /** Constructor - fills the information about the target bin */
    function AA_Manageraction_Item_Feed($id, $slice_id) {
        $this->slice_id = $slice_id;
        parent::AA_Manageraction($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Export to slice');
    }

    /** main executive function
    * @param $param       - not used
    * @param $item_arr    - array of id of AA records to check
    * @param $akce_param  - not used
    * @return false or error message
    */
    function perform(&$manager, &$state, $item_arr, $akce_param) {
        if (strlen($akce_param) < 1) {
            return _m('No slice selected');
        }
        $export_to = explode(",", $akce_param);          // <status>-<slice_id> pairs

        foreach ( $item_arr as $it_id => $foo ) {
            $it_id = substr($it_id,1);                 // remove initial 'x'
            foreach ( $export_to as $exp_slice_pair ) {
                list($status,$sid) = explode("-", $exp_slice_pair);
                FeedItemTo($it_id, $this->slice_id, $sid, ($status=='1' ? 'y':'n'), 0);
            }
        }
        return false;                                  // OK - no error
    }

    /** Checks if the user have enough permission to perform the action */
    function isPerm(&$manager) {
        return true;
    }
}


/** AA_Manageraction_Item_Move2slice
 *  Move items to another slice
 *  @param $slice      slice object - slice, from which we export
 *  @param $item_arr   array, where keys are unpacked ids of items prefixed by
 *                     'x' character (javascript purposes only)
 *  @param $akce_param unpacked id of slice, where items should be moved
 */
class AA_Manageraction_Item_Move2slice extends AA_Manageraction {

    /** specifies, to which bin the move should be performed */
    var $slice_id;

    /** Constructor - fills the information about the target bin
     *  We use default empty parameters, since we need to construct this
     *  class from state by setFromState() method
     */
    function AA_Manageraction_Item_Move2slice($id='', $slice_id='') {
        $this->slice_id = $slice_id;
        parent::AA_Manageraction($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Move to another slice');
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     *
     *  We extending AA_Storable, because we want to get the state form some
     *  actions. Action selectbox is able to display settings by AJAX call, where
     *  we need to pass all parameters of the object
     */
    function getClassProperties() {
        $properties = parent::getClassProperties();
        //                                          id             name                              type    multi  persistent - validator, required, help, morehelp, example
        $properties['slice_id'] = new AA_Property( 'slice_id',  _m('Slice ID'),                    'text', false, true);
        return $properties;
    }

    /** Defines the HTNML for parameters. All parameters stored into akce_param[]
     *  array will be passed to perform() method for action execution
     */
    function htmlSettings() {
        global $g_modules;

        $options = array();
        if ( is_array($g_modules) AND (count($g_modules) > 1) ) {
            foreach ( $g_modules as $sid => $v) {
                //  we can feed just between slices ('S')                                                 // we must have autor or editor perms in destination slices
                if ( ($v['type'] == 'S') AND ((string)$this->slice_id != (string)$sid) AND IfSlPerm( PS_ITEMS2ACT, $sid) ) {
                    $options[$sid] = $v['name'];
                }
            }
        }

        ob_start();
        FrmTabCaption();
        FrmInputSelect('akce_param[dest_slice_id]', _m('Move to slice'), $options);
        FrmTabEnd();
        return ob_get_clean();
    }

    /** main executive function
    * @param  $param       - not used
    * @param  $item_arr    - array of id of AA records to check
    * @param  $akce_param  - not used
    * @return false or error message
    */
    function perform(&$manager, &$state, $item_arr, $akce_param) {
        global $event, $auth, $pagecache;
        if (strlen($akce_param['dest_slice_id']) < 1) {
            return _m('No slice selected');
        }

        $dest_slice_id = $akce_param['dest_slice_id'];

        if ( !IfSlPerm(PS_DELETE_ITEMS, $this->slice_id) OR !IfSlPerm(PS_ITEMS2ACT, $dest_slice_id) ) {    // permission to delete items and add items in destination slice?
            return _m("You have not permissions to move items");
        }

        $zids = new zids;
        $zids->setFromItemArr($item_arr);

        if ($zids->count() < 1) {
            return;
        }

        // check if there are no ids from bad slice (attack???)
        $wherein = " AND ". $zids->sqlin('id');
        $SQL = "SELECT id FROM item WHERE slice_id = '". q_pack_id($this->slice_id) ."' $wherein";

        $zids_to_move = new zids(GetTable2Array($SQL, '', 'id'), 'p');

        if ($zids_to_move->count() < 1) {
            return;
        }

        tryQuery("UPDATE item SET slice_id = '". q_pack_id($dest_slice_id) ."' WHERE ". $zids_to_move->sqlin('id'));

        $pagecache->invalidateFor("slice_id=". $this->slice_id);  // invalidate old cached values
        $pagecache->invalidateFor("slice_id=". $dest_slice_id);   // invalidate old cached values

        return false;                                  // OK - no error
    }

    /** Checks if the user have enough permission to perform the action */
    function isPerm(&$manager) {
        return IfSlPerm(PS_DELETE_ITEMS, $this->slice_id);
    }
}


/** AA_Manageraction_Item_DeleteTrash - Handler for DeleteTrash switch
 *  Delete all items in the trash bin
 */
class AA_Manageraction_Item_DeleteTrash extends AA_Manageraction {

    /** specifies, if we have to delete only items specified in $item_arr
     *  otherwise delete all items in Trash
     *  With $selected=true  it is used as "action" of manager
     *  With $selected=false it is used as "switch" of manager (left menu)
     */
    var $selected;

    /** Constructor - fills the information about the target bin */
    function AA_Manageraction_Item_DeleteTrash($id, $selected=false) {
        $this->selected = $selected;
        parent::AA_Manageraction($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Remove (delete from database)');
    }

    /** main executive function
     *  @param $param       'selected' if we have to delete only items specified
     *                      in $item_arr - otherwise delete all items in Trash
     *  @param $item_arr    Items to delete (if 'selected' is $param)
     *  @param $akce_param  Not used
     *  @return false or error message
     */
    function perform(&$manager, &$state, $item_arr, $akce_param) {
        global $pagecache, $slice_id, $event;

        if ( !IfSlPerm(PS_DELETE_ITEMS) ) {    // permission to delete items?
            return _m("You have not permissions to remove items");
        }

        $wherein = '';

        // restrict the deletion only to selected items
        if ($this->selected == 'selected') {
            $zids = new zids;
            $zids->setFromItemArr($item_arr);

            if ($zids->count() < 1) {
                return;
            }

            $wherein = " AND ". $zids->sqlin('id');
        }

        $db = getDB();
        // now we ask, which items we have to delete. We are checking the items even
        // it is specified in $item_arr - for security reasons - we can delete only
        // items in current slice and in trash
        $db->query("SELECT id FROM item
                   WHERE status_code=3 AND slice_id = '". q_pack_id($slice_id) ."' $wherein");
        $items_to_delete = array();
        while ( $db->next_record() ) {
            $items_to_delete[] = $db->f("id");
        }
        if (count($items_to_delete) < 1) {
            freeDB($db);
            return;
        }

        // mimo enabled -- problem?
        $event->comes('ITEMS_BEFORE_DELETE', $slice_id, 'S', $items_to_delete);

        // delete content of all fields
        // don't worry about fed fields - content is copied
        $wherein = "IN ('".join_and_quote("','", $items_to_delete)."')";
        $db->query("DELETE FROM discussion WHERE item_id ".$wherein);
        $db->query("DELETE FROM content WHERE item_id ".$wherein);
        $db->query("DELETE FROM item WHERE id ".$wherein);

        $pagecache->invalidateFor("slice_id=$slice_id");
        freeDB($db);

        return false;
    }

    /** Checks if the user have enough permission to perform the action */
    function isPerm(&$manager) {
        // if we want to use it as "action" (not "switch"), then we should be in trash bin
        return (IfSlPerm(PS_DELETE_ITEMS) AND (!$this->selected OR ($manager->getBin() == 'trash')));
    }
}


/** AA_Manageraction - Item manager actions. Just create new class and assign
 *  it to your manager
 */
class AA_Manageraction_Item_Preview extends AA_Manageraction {

    /** Name of this Manager's action */
    function getName() {
        return _m('Preview');
    }

    // uses setOpenUrl() method to open preview window
    // perm are always true, so no need to rewrite it
}

/** AA_Manageraction - Item manager actions. Just create new class and assign
 *  it to your manager
 */
class AA_Manageraction_Item_Modifycontent extends AA_Manageraction {

    /** Name of this Manager's action */
    function getName() {
        return _m('Modify content');
    }

    // uses setOpenUrl() method to open search_replace.php3 window
    /** Checks if the user have enough permission to perform the action */
    function isPerm(&$manager) {
        return IfSlPerm(PS_EDIT_ALL_ITEMS);
    }
}


/** AA_Manageraction - Item manager actions. Just create new class and assign
 *  it to your manager
 */
class AA_Manageraction_Item_Email extends AA_Manageraction {

    /** Name of this Manager's action */
    function getName() {
        return _m('Send email');
    }

    // uses setOpenUrl() method to open search_replace.php3 window
    /** Checks if the user have enough permission to perform the action */
    function isPerm(&$manager) {
        // $slice = AA_Slices::getSlice($manager->getModuleId());
        // return ($slice->type() == 'ReaderManagement');
        return true; // allow in all slices
    }
}


/** AA_Manageraction_Export - Exports selected items to Excel file */
class AA_Manageraction_Item_Export extends AA_Manageraction {

    function AA_Manageraction_Item_Export($id) {
        parent::AA_Manageraction($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Export to file');
    }

    /** main executive function
    * @param $param       - not used
    * @param $item_arr    - array of id of AA records to check
    * @param $akce_param  - not used
    */
    function perform(&$manager, &$state, $item_arr, $akce_param) {
        $zids = new zids;
        $zids->setFromItemArr($item_arr);

        if ($zids->count() > 0) {
            $exportset = AA_Object::factory('AA_Exportsetings', array('grabber_type'=>'AA_Grabber_Slice', 'format'=>'AA_Exporter_Excel', 'type' => 'human'));     
            $exportset->setOwnerId($manager->getModuleId());
            $exportset->export($zids);
        }
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action */
    function isPerm(&$manager) {
        return IfSlPerm(PS_EDIT_ALL_ITEMS);
    }
}

/** AA_Manageraction_Item_Tab - Swith to another bin in Manager */
class AA_Manageraction_Item_Tab extends AA_Manageraction {

    /** specifies, to which bin we want to switch */
    var $to_bin;

    /** Constructor - fills the information about the target bin */
    function AA_Manageraction_Item_Tab($id, $to_bin) {
        $this->to_bin = $to_bin;
        parent::AA_Manageraction($id);
    }

    /** main executive function - Handler for Tab switch - switch between bins
     * @return false or error message
     */
    function perform(&$manager, &$state, $item_arr, $akce_param) {
        $manager->setBin($this->to_bin);
        $manager->go2page(1);
        return false;        // OK
    }
}

/** AA_Manageraction_Item_Tab - Swith to another bin in Manager */
class AA_Manageraction_Item_Gobookmark extends AA_Manageraction {

    /** specifies, to which bin we want to switch */
    //var $to_bin;

    /** Constructor - fills the information about the target bin */
    function AA_Manageraction_Item_Gobookmark($id) {
        parent::AA_Manageraction($id);
    }

    /** main executive function - Handler for Tab switch - switch between bins
     * @return false or error message
     */
    function perform(&$manager, &$state, $item_arr, $akce_param) {
        $manager->setFromBookmark($_GET['GoBookmark']);
        $manager->go2page(1);
        return false;        // OK
    }
}


//------------------ Actions for Task Manager --------------------------------

/** AA_Manageraction_Item_DeleteTrash - Handler for DeleteTrash switch
 *  Delete all items in the trash bin
 */
class AA_Manageraction_Taskmanager_Delete extends AA_Manageraction {

    /** Name of this Manager's action */
    function getName() {
        return _m('Remove (cancel task)');
    }

    /** main executive function
     *  @param $param       'selected' if we have to delete only items specified
     *                      in $item_arr - otherwise delete all items in Trash
     *  @param $item_arr    Items to delete (if 'selected' is $param)
     *  @param $akce_param  Not used
     *  @return false or error message
     */
    function perform(&$manager, &$state, $item_arr, $akce_param) {

        if ( !IfSlPerm(PS_EDIT) ) {    // permission to delete items?
            /** @todo Should be changed to different permission */
            return _m("You have not permissions to remove tasks");
        }

        // restrict the deletion only to selected items
        $zids = new zids;
        $zids->setFromItemArr($item_arr, 's');

        if ($zids->count() < 1) {
            return;
        }

        // $event->comes('ITEMS_BEFORE_DELETE', $slice_id, 'S', $items_to_delete);

        $varset = new Cvarset;
        $varset->doDeleteWhere('toexecute', $zids->sqlin('id'));
        return false;     // OK
    }

    /** Checks if the user have enough permission to perform the action */
    function isPerm(&$manager) {
        /** @todo Should be changed to different permission */
        return IfSlPerm(PS_EDIT);
    }
}

/** AA_Manageraction_Item_DeleteTrash - Handler for DeleteTrash switch
 *  Delete all items in the trash bin
 */
class AA_Manageraction_Taskmanager_Execute extends AA_Manageraction {

    /** Name of this Manager's action */
    function getName() {
        return _m('Execute');
    }

    /** main executive function
     *  @param $param       'selected' if we have to delete only items specified
     *                      in $item_arr - otherwise delete all items in Trash
     *  @param $item_arr    Items to delete (if 'selected' is $param)
     *  @param $akce_param  Not used
     *  @return false or error message
     */
    function perform(&$manager, &$state, $item_arr, $akce_param) {
        global $pagecache, $slice_id, $event;

        if ( !IfSlPerm(PS_EDIT) ) {    // permission to delete items?
            /** @todo Should be changed to different permission */
            return _m("You have not permissions to remove items");
        }

        // restrict the deletion only to selected items
        $zids = new zids;
        $zids->setFromItemArr($item_arr, 's');

        if ($zids->count() < 1) {
            return;
        }

        $toexecute = new AA_Toexecute;
        $toexecute->executeTask($zids->shortids());
        return $toexecute->report();
    }

    /** Checks if the user have enough permission to perform the action */
    function isPerm(&$manager) {
        /** @todo Should be changed to different permission */
        // if we want to use it as "action" (not "switch"), then we should be in trash bin
        return IfSlPerm(PS_EDIT);
    }
}


?>
