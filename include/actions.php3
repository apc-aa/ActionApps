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
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;
use AA\Later\Toexecute;


/** AA_Manageraction - Item manager actions. Just create new class and assign
 *  it to your manager
 */
class AA_Manageraction_Item_MoveItem extends AA_Manageraction {

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


    /**
     * @param $zids    zids
     * @param $to_bin  int
     */
    function doMove($zids, $to_bin) {
        $ids = $zids->longids();
        foreach ($ids as $id) {
            UpdateField($id, 'status_code.....', new AA_Value($to_bin), [true, true, true]);   // invalidate cache, feed, throw events
        }
    }

    /** main executive function
     * @param AA_Manager $manager
     * @param zids $item_zids - item ids for action
     * @param $action_param - not used
     * @return bool
     */
    function perform($manager, $item_zids, $action_param) {
        $sid = $manager->getModuleId();
        AA_Manageraction_Item_MoveItem::doMove($item_zids->itemFilter($sid), $this->to_bin);
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        $current_bin = $manager->getBin();
        $module_id   = $manager->getModuleId();

        switch($this->to_bin) {
            case 1: return IfSlPerm(PS_ITEMS2ACT, $module_id) AND !in_array($current_bin, ['app', 'appb', 'appc']);
                    // Folder2 is Holding bin - prepared for more than three bins
            case 2: return IfSlPerm(PS_ITEMS2HOLD, $module_id) AND  ($current_bin != 'hold');
                    // Folder3 is Trash
            case 3: return IfSlPerm(PS_ITEMS2TRASH, $module_id) AND ($current_bin != 'trash');
        }
        return false;
    }
}


/** AA_Manageraction_Item_Duplicate - Duplicate selected item in the slice.
 */
class AA_Manageraction_Item_Duplicate extends AA_Manageraction {

    /** Constructor - fills the information about the target bin */
    function __construct($id) {
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Duplicate Item');
    }

    /** main executive function
     * @param AA_Manager $manager
     * @param $item_zids - array of id of AA records to check
     * @param $action_param - not used
     * @return bool
     */
    function perform($manager, $item_zids, $action_param) {
        $sid = $manager->getModuleId();

        $grabber = new AA\IO\Grabber\Slice( new AA_Set([$sid],null,null,AA_BIN_ALL), $item_zids->itemFilter($sid));
        // insert_if_new is the same as insert, (but just make sure the item is not in DB which is not important here)
        $saver   = new AA\IO\Saver($grabber, null, null, 'insert_if_new', 'new');
        $saver->run();
        //SendOkPage( array("report" => $saver->report() ), $saver->changedIds());
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
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
    function __construct($id, $slice_id) {
        $this->slice_id = $slice_id;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Export to slice');
    }

    /** main executive function
     * @param AA_Manager $manager
     * @param $item_zids - array of id of AA records to check
     * @param $action_param - not used
     * @return false or error message
     */
    function perform($manager, $item_zids, $action_param) {
        if (strlen($action_param) < 1) {
            return _m('No slice selected');
        }
        $export_to = explode(",", $action_param);          // <status>-<slice_id> pairs

        foreach ($item_zids as $it_id ) {
            foreach ( $export_to as $exp_slice_pair ) {
                [$status,$sid] = explode("-", $exp_slice_pair);
                FeedItemTo($it_id, $this->slice_id, $sid, ($status=='1' ? 'y':'n'), 0);
            }
        }
        return false;                                  // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
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
    function __construct($id='', $slice_id='') {
        $this->slice_id = $slice_id;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Move to another slice');
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     *
     *  We extending AA_Storable, because we want to get the state form some
     *  actions. Action selectbox is able to display settings by AJAX call, where
     *  we need to pass all parameters of the object
     * @return array
     */
    static function getClassProperties(): array {
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

        $options = [];
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
     * @param  $manager
     * @param  $item_zids - array of id of AA records to check
     * @param  array $action_param
     * @return false or error message
     */
    function perform($manager, $item_zids, $action_param) {
        if (strlen($action_param['dest_slice_id']) < 1) {
            return _m('No slice selected');
        }

        $dest_slice_id = $action_param['dest_slice_id'];

        if ( !IfSlPerm(PS_DELETE_ITEMS, $this->slice_id) OR !IfSlPerm(PS_ITEMS2ACT, $dest_slice_id) ) {    // permission to delete items and add items in destination slice?
            return _m("You have not permissions to move items");
        }

        if ($item_zids->count() < 1) {
            return false;     // OK
        }

        // check if there are no ids from bad slice (attack???)
        $wherein = " AND ". $item_zids->sqlin('id');
        $SQL = "SELECT id FROM item WHERE slice_id = '". q_pack_id($this->slice_id) ."' $wherein";

        $zids_to_move = new zids(GetTable2Array($SQL, '', 'id'), 'p');

        if ($zids_to_move->count() < 1) {
            return false;     // OK
        }

        DB_AA::sql("UPDATE item SET slice_id = '". q_pack_id($dest_slice_id) ."' WHERE ". $zids_to_move->sqlin('id'));

        AA::Pagecache()->invalidateFor( [$this->slice_id, $dest_slice_id]);  // invalidate old cached values

        return false;                                  // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
        return IfSlPerm(PS_DELETE_ITEMS, $this->slice_id);
    }
}


/** AA_Manageraction_Item_Move2slice
 *  Move items to another slice
 *  @param $slice      slice object - slice, from which we export
 *  @param $item_arr   array, where keys are unpacked ids of items prefixed by
 *                     'x' character (javascript purposes only)
 *  @param $akce_param unpacked id of slice, where items should be moved
 */
class AA_Manageraction_View_Move2slice extends AA_Manageraction_Item_Move2slice {

    /** Name of this Manager's action */
    function getName() {
        return _m('Move view to another slice');
    }

    /** main executive function
     * @param  $manager
     * @param  zids $item_zids - array of id of AA records to check
     * @param  $action_param - not used
     * @return false or error message
     */
    function perform($manager, $item_zids, $action_param) {
        if (strlen($action_param['dest_slice_id']) < 1) {
            return _m('No slice selected');
        }

        $dest_slice_id = $action_param['dest_slice_id'];

        if ( !IfSlPerm(PS_FULLTEXT, $this->slice_id) OR !IfSlPerm(PS_FULLTEXT, $dest_slice_id) ) {    // permission to delete items and add items in destination slice?
            return _m("You have not permissions to move views");
        }

        if ($item_zids->count() < 1) {
            return false;     // OK
        }

        $ids = DB_AA::select('id','SELECT id FROM view', [
            ['slice_id',$this->slice_id,'l'],
            ['id',$item_zids->shortids(), 'i']
        ]);

        if (!$ids) {
            return false;     // OK
        }

        DB_AA::update('view', [['slice_id', $dest_slice_id, 'l']], [['id',$ids,'i']]);

        AA::Pagecache()->invalidateFor( [$this->slice_id, $dest_slice_id]);  // invalidate old cached values

        return false;                                  // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
        return IfSlPerm(PS_FULLTEXT, $this->slice_id);
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
    function __construct($id, $selected=false) {
        $this->selected = $selected;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Remove (delete from database)');
    }

    /** main executive function
     * @param AA_Manager $manager
     * @param $item_zids    Items to delete (if 'selected' is $param)
     * @param $action_param  Not used
     * @return false or error message
     */
    function perform($manager, $item_zids, $action_param) {
        global $slice_id, $event;

        if ( !IfSlPerm(PS_DELETE_ITEMS) ) {    // permission to delete items?
            return _m("You have not permissions to remove items");
        }

        // restrict the deletion only to selected items
        if ($this->selected == 'selected') {
            if ($item_zids->count()< 1) {
                return false;     // OK
            }
            $items_to_delete = new zids(DB_AA::select('id', 'SELECT id FROM `item`', [['status_code', 3], ['slice_id', $slice_id, 'l'], ['id', $item_zids->longids(), 'l']]),'p');
        } else {
            $items_to_delete = new zids(DB_AA::select('id', 'SELECT id FROM `item`', [['status_code', 3], ['slice_id', $slice_id, 'l']]),'p');
        }

        if ($items_to_delete->count() < 1) {
            return false;     // OK
        }

        // mimo enabled -- problem?

        $longids = $items_to_delete->longids(); // we need variable because of pass by reference
        $event->comes('ITEMS_BEFORE_DELETE', $slice_id, 'S', $longids);

        // delete content of all fields
        AA_Items::deleteItems($items_to_delete);

        AA::Pagecache()->invalidateFor($slice_id);
        return false;     // OK
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
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

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
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

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        // $slice = AA_Slice::getModule($manager->getModuleId());
        // return ($slice->type() == 'ReaderManagement');
        return true; // allow in all slices
    }
}


/** AA_Manageraction_Export - Exports selected items to Excel file */
class AA_Manageraction_Item_Export extends AA_Manageraction {

    function __construct($id) {
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Export to file');
    }

    /** main executive function
     * @param AA_Manager $manager
     * @param $item_zids - array of id of AA records to check
     * @param $action_param - not used
     * @return bool
     */
    function perform($manager, $item_zids, $action_param) {

        if ($item_zids->count() > 0) {
            $exportset = AA_Object::factory('AA_Exportsetings', ['grabber_type'=> 'AA\IO\Grabber\Slice', 'format'=>'AA_Exporter_Excel', 'type' => 'human', 'bins'=>AA_BIN_ALL]);
            $exportset->setOwnerId($manager->getModuleId());
            $exportset->export($item_zids);
        }
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
        return IfSlPerm(PS_EDIT_ALL_ITEMS);
    }
}

/** AA_Manageraction_Item_Tab - Swith to another bin in Manager */
class AA_Manageraction_Item_Tab extends AA_Manageraction {

    /** specifies, to which bin we want to switch */
    var $to_bin;

    /** Constructor - fills the information about the target bin */
    function __construct($id, $to_bin) {
        $this->to_bin = $to_bin;
        parent::__construct($id);
    }

    /** main executive function - Handler for Tab switch - switch between bins
     * @param AA_Manager $manager
     * @param $item_zids
     * @param $action_param
     * @return false or error message
     */
    function perform($manager, $item_zids, $action_param) {
        $manager->setBin($this->to_bin);
        $manager->go2page(1);
        return false;        // OK
    }
}

/** AA_Manageraction_Item_Tab - Swith to another bin in Manager */
class AA_Manageraction_Item_Gobookmark extends AA_Manageraction {

    /** main executive function - Handler for Tab switch - switch between bins
     * @param AA_Manager $manager
     * @param $item_zids
     * @param $action_param
     * @return false or error message
     */
    function perform($manager, $item_zids, $action_param) {
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
     * @param AA_Manager $manager
     * @param $item_zids    Items to delete (if 'selected' is $param)
     * @param $action_param  Not used
     * @return false or error message
     */
    function perform($manager, $item_zids, $action_param) {

        if ( !IfSlPerm(PS_EDIT) ) {    // permission to delete items?
            /** @todo Should be changed to different permission */
            return _m("You have not permissions to remove tasks");
        }

        if ($item_zids->count() < 1) {
            return false;     // OK
        }

        // $event->comes('ITEMS_BEFORE_DELETE', $slice_id, 'S', $items_to_delete);

        $varset = new Cvarset;
        $varset->doDeleteWhere('toexecute', $item_zids->sqlin('id'));
        return false;     // OK
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
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
     * @param AA_Manager $manager
     * @param $item_zids    Items to delete (if 'selected' is $param)
     * @param $action_param  Not used
     * @return false|string - false or error message
     */
    function perform($manager, $item_zids, $action_param) {

        if ( !IfSlPerm(PS_EDIT) ) {    // permission to delete items?
            /** @todo Should be changed to different permission */
            return _m("You have not permissions to remove items");
        }

        if ($item_zids->count() < 1) {
            return false;     // OK
        }

        $toexecute = new Toexecute;
        $toexecute->executeTask($item_zids->shortids());
        return $toexecute->report();
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
        /** @todo Should be changed to different permission */
        // if we want to use it as "action" (not "switch"), then we should be in trash bin
        return IfSlPerm(PS_EDIT);
    }
}


/** Generates manager settings for aa_toexecute.php3 and se_taskmanager.php3
 * @return array - manager settings
 */
function GetToexecuteManagerSettings() {
    $actions   = new AA_Manageractions;
    $actions->addAction(new AA_Manageraction_Taskmanager_Execute('ExecuteTaskAction'));
    $actions->addAction(new AA_Manageraction_Taskmanager_Delete('DeleteTaskAction'));

    //$switches  = new AA_Manageractions;  // we do not need switches here

    $manager_settings = AA::Metabase()->getManagerConf('toexecute', $actions);
    $manager_settings['itemview']['format']['compact_top'] = '
                                        <div class="aa-table">
                                          <table border="0" cellpadding="5" cellspacing="0">
                                            <tbody><tr>
                                              <th width="30">&nbsp;</th>
                                              <th>id</th>
                                              <th>created</th>
                                              <th>execute after</th>
                                              <th>aa_user</th>
                                              <th>priority</th>
                                              <th>selector</th>
                                              <th>object</th>
                                              <th>params</th>
                                            </tr>';
    $manager_settings['itemview']['format']['odd_row_format'] = '
                                      <tr><td width="30"><input name="chb[x_#ID______]" value="" type="checkbox"></td>
                                        <td>_#ID______</td>
                                        <td>_#CREATED_</td>
                                        <td>_#EXECUTE_</td>
                                        <td>_#AA_USER_</td>
                                        <td>_#PRIORITY</td>
                                        <td>_#SELECTOR</td>
                                        <td>{htmlspecialchars:{_#OBJECT__}}</td>
                                        <td>{htmlspecialchars:{_#PARAMS__}}</td>
                                    </tr>';                      // <td>_#OBJECT__</td><td>_#EXECUTE_</td>

    $manager_settings['searchbar']['default_sort'] =  [0 => ['execute_after' => 'a']];
    return $manager_settings;
}



