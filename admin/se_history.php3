<?php
/** se_fulltext.php3 - assigns html format for fulltext view
 *   expected $slice_id for edit slice
 *   optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)
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

const AA_REGISTER_GLOBALS = true;
require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../include/varset.php3";
require_once __DIR__."/../include/item.php3";     // GetAliasesFromField funct def
require_once __DIR__."/../include/msgpage.php3";

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_HISTORY)) {
    MsgPageMenu(StateUrl(self_base())."index.php3", _m("You have not permissions to view history"), "admin");
    exit;
}

//------------------ Actions for History Manager --------------------------------

/** AA_Manageraction_Item_DeleteTrash - Handler for DeleteTrash switch
 *  Delete all items in the trash bin
 */
class AA_Manageraction_History_Apply extends AA_Manageraction {

    /** Name of this Manager's action */
    function getName() {
        return _m('Set again this old values');
    }

    /** @inheritDoc */
    function perform(AA_Manager $manager, zids $item_zids, $action_param) {

        if ( !IfSlPerm(PS_EDIT) ) {    // permission to delete items?
            /** @todo Should be changed to different permission */
            return _m("You have not permissions to change items");
        }

        if ($item_zids->count()< 1) {
            return false;     // OK - no errors
        }

        // AA::$debug = 255;

        $sid = $manager->getModuleId();
        $changes_to_apply = DB_AA::select('id', 'SELECT change.id FROM `change`, `item`', [['item.id', 'UNHEX(change.resource_id)', 'j'], ['change.id', $item_zids->longids()], ['slice_id', $sid, 'l']]);

        $changes = GetHistoryContent(new zids($changes_to_apply,'l'));

        $updated_items = 0;
        foreach ($changes as $chid => $fields) {
            $newcontent4id = new ItemContent();
            $newcontent4id->setItemID($fields['resource_id'][0]['value']);
            $newcontent4id->setSliceID($sid);
            $doit = false;

            foreach ($fields as $fid => $val_arr) {
                if ((strlen($fid)==16) AND ($fid != 'last_edit.......')) {
                    $newcontent4id->setAaValue($fid, new AA_Value($val_arr, FLAG_HTML));   // , $content4id->getFlag($field_id));
                    $doit = true;
                }
            }
            // huhl($newcontent4id);
            if ($doit AND $newcontent4id->storeItem( 'update', [false, false, true])) {
                $updated_items = 1;
            }
        }
        AA::Pagecache()->invalidateFor($sid);   // invalidate old cached values

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

/** GetHistoryFields function
 * List of fields, which will be listed in searchbar in Links Manager (search)
 * (modules/links/index.php3)
 * @return AA\Util\Searchfields
 */
function GetHistoryFields() {  // function - we need trnslate _m() on use (not at include time)
    $searchfields = new AA\Util\Searchfields();
    //   id                     $name,                        $field,                 operators='text', $table=false, $search_pri=false, $order_pri=false
    $searchfields->add('id'                  , _m('Id'),                    'links_links.id',                'numeric', false,          0,    0);

    $searchfields->add('change_id'   , _m('Id'),          'change.id',                 'text', false,           10, 10);
    $searchfields->add('resource_id' , _m('Resource ID'), 'change.resource_id',        'text', false,           20, 20);
    $searchfields->add('type'        , _m('Type'),        'change.type',               'text', false,           30, 30);
    $searchfields->add('user'        , _m('User'),        'change.user',               'text', false,           40, 40);
    $searchfields->add('time'        , _m('Time'),        'change.time',               'date', false,           50, 50);
    // $searchfields->add('selector'    , _m('Selector'),    'change_record.selector',    'text',    'change_record', 60, 60);
    // $searchfields->add('priority'    , _m('Priority'),    'change_record.priority',    'numeric', 'change_record', 70, 70);
    // $searchfields->add('_value'      , _m('Value'),       'change_record.value',       'text',    'change_record', 80, 80);   // we do not use change_record, yet. I added underscore to _value, because it makes MakeSQLConditions to return wrong conditions (condition[value] badly detected)
    // $searchfields->add('vtype'       , _m('Value Type'),  'change_record.type',        'text',    'change_record', 90, 90);
    return $searchfields;
}

/** GetHistoryAliases function
 * Predefined aliases for links. For another aliases use 'inline' aliases.
 * @return array
 */
function GetHistoryAliases() {  // function - we need trnslate _m() on use (not at include time)
    return [
    "_#HI_CH_ID" => GetAliasDef( "f_1", "id",  _m('Change ID')),
//    "_#HI_FIELD" => GetAliasDef( "f_t", "selector",   _m('Field selector')),
//    "_#HI_VALUE" => GetAliasDef( "f_t", "value",      _m('Value')),
//    "_#HI_TYPE_" => GetAliasDef( "f_t", "vtype",       _m('Type of value')),
    "_#HI_TIME_" => GetAliasDef( "f_d:j.n.Y H:i:s", "time", _m('Time of change')),
    "_#HI_USER_" => GetAliasDef( "f_1", "user",        _m('User')),
    "_#HI_UNAME" => GetAliasDef( "f_t:{userinfo:{_#HI_USER_}}", "user",        _m('User')),
    "_#HI_ITEM_" => GetAliasDef( "f_1", "resource_id", _m('Item ID')),
    ];
}

/**
 * Loads data from database for given link ids (called in itemview class)
 * and stores it in the 'Abstract Data Structure' for use with 'item' class
 *
 * @see GetItemContent(), itemview class, item class
 * @param zids $zids if ids to get from database
 * @return array - Abstract Data Structure containing the links data
 *                 {@link http://apc-aa.sourceforge.net/faq/#1337}
 */
function GetHistoryContent($zids) {
    if (!$zids OR $zids->count()<1) {
        return [];
    }

    // construct WHERE clausule
    $sel_in = $zids->sqlin( false, true );

    // get history data
    $SQL = "SELECT * FROM `change` WHERE id $sel_in";

    $content = [];
    StoreTable2Content($content, $SQL, '', 'id');

    $additional = DB_AA::select([], 'SELECT * FROM `change_record`', [['change_id', $zids->longids()]]);
    foreach ($additional as $chrec) {
        $content[$chrec['change_id']][$chrec['selector']][$chrec['priority']] = ['value' => $chrec['value']];
    }
    return $content;
}


/** Links_QueryCatZIDs - Finds category IDs according to given conditions
 * @param        $slice_id
 * @param array  $conds - search conditions (see FAQ)
 * @param string $sort - sort fields (see FAQ)
 * @return zids
 */
function QueryHistoryZIDs($slice_id, $conds, $sort="") {

    $HISTORY_FIELDS = GetHistoryFields()->getArrayDeprecated();

    $where_sql      = MakeSQLConditions($HISTORY_FIELDS, $conds, 'LIKE', $foo);
    $order_by_sql   = MakeSQLOrderBy(   $HISTORY_FIELDS, $sort,  $foo);

    $SQL  = "SELECT DISTINCT `change`.id FROM `change`, `item`  WHERE item.id = UNHEX(change.resource_id) AND item.slice_id = ". xpack_id($slice_id)." AND change.type = 'h'";

    //    $SQL  = "SELECT DISTINCT change.id FROM `change`, change_record WHERE change.id = change_record.change_id AND change.resource_id = '$slice_id' ";

    $SQL .=  $where_sql . $order_by_sql;

    // get result --------------------------
    return GetZidsFromSQL($SQL, 'id', 'l');
}


// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id = $slice_id;
// module_id is the same as slice_id (slice_id was used before AA introduced
// modules. Now it is better to use module_id, because in other modules
// (like Links, ...) it is not so confusing

$p_module_id = q_pack_id($module_id); // packed to 16-digit as stored in database
$slice       = AA_Slice::getModule($module_id);

$actions   = new AA_Manageractions;
$actions->addAction(new AA_Manageraction_History_Apply('ApplyHistory'));


$manager_settings = [
     'module_id' => $slice_id,
     'show'      =>  MGR_ALL, //  & ~MGR_ACTIONS,    // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS | MGR_SB_ALLTEXT | MGR_SB_ALLNUM
     'searchbar' => [
         'fields'               => GetHistoryFields(),
         'search_row_count_min' => 1,
         'order_row_count_min'  => 1,
         'add_empty_search_row' => true,
         'function'             => false,  // name of function for aditional action hooked on standard filter action
         'default_sort'         =>  [0 => ['time' => 'd']]
     ],
     'scroller'  => [
         'listlen'              => ($listlen ?: EDIT_ITEM_COUNT)
     ],
     'itemview'  => [
         'manager_vid'          => false,    // $slice_info['manager_vid'],      // id of view which controls the design
         'format'               => [                           // optionaly to manager_vid you can set format array
             'compact_top'      => '<table border=0 cellspacing=0 cellpadding=5>
                                      <tr class=tabtit"><th width="30"></th><th>Item</th><th>Item ID</th><th>User</th><th>Change time</th></tr>',
             'category_sort'    => false,
             'category_format'  => "",
             'category_top'     => "",
             'category_bottom'  => "",
             'even_odd_differ'  => false,
             'even_row_format'  => "",
//           'odd_row_format'   => '<tr class="tabtxt"><td width="30"><input type="checkbox" name="chb[_#HI_CH_ID]" value=""></td><td class="tabtxt"><a href="_#EDITLINK">{switch({_#L_NAME__}).:_#L_NAME__:???}</a> (_#L_O_NAME)<div class="tabsmall">_#L_DESCRI<br>(_#CATEG_GO)<br><a href="_#L_URL___" target="_blank">_#L_URL___</a></div></td><td class="tabsmall">{alias:checked:f_d:j.n.Y}<br>{alias:created_by:f_e:username}<br>{alias:edited_by:f_e:username}<br><span style="background:#_#L_VCOLOR;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;_#L_VALID_&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span></td></tr>
             'odd_row_format'   => '<tr class="tabtxt"><td width="30"><input type="checkbox" name="chb[x_#HI_CH_ID]" value=""></td><td><strong>{item:{_#HI_ITEM_}:_#HEADLINE}</strong></td><td>_#HI_ITEM_</td><td>_#HI_UNAME <small>(_#HI_USER_)</small></td><td>_#HI_TIME_</td></tr>
             ',
             'compact_remove'   => '',
             'compact_bottom'   => "</table>",
             'id'               => $slice_id
         ],
         'fields'               => '',
         'aliases'              => GetHistoryAliases(),
         'get_content_funct'    => 'GetHistoryContent'
     ],
     'actions'   => $actions,
     'switches'  => [],
     'messages'  => [
         'title'       => _m('ActionApps - History Manager')
     ]
];

$manager = new AA_Manager('history'.$slice_id, $manager_settings);
$manager->performActions();

$conds = $manager->getConds();
$sort  = $manager->getSort();
$zids=QueryHistoryZIDs($slice_id, $conds, $sort);

$manager->displayPage($zids, 'sliceadmin', 'history');

page_close();


