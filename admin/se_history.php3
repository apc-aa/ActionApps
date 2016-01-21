<?php
/** se_fulltext.php3 - assigns html format for fulltext view
 *   expected $slice_id for edit slice
 *   optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)
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

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."item.php3";     // GetAliasesFromField funct def
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."msgpage.php3";
require_once AA_INC_PATH . "manager.class.php3";

if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_HISTORY)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to view history"), "admin");
    exit;
}
/** IsHistoryActionPerm function
 * @param $action
 * @return false
 */
function IsHistoryActionPerm($action) {
    return false;
}

/** GetHistoryFields function
 * List of fields, which will be listed in searchbar in Links Manager (search)
 * (modules/links/index.php3)
 * @return array
 */
function GetHistoryFields() {  // function - we need trnslate _m() on use (not at include time)
    return array(
     'change_id'   => GetFieldDef( _m('Id'),          'change.id',          'text', false,           10, 10),
     'resource_id' => GetFieldDef( _m('Resource ID'), 'change.resource_id', 'text',    false,           20, 20),
     'type'        => GetFieldDef( _m('Type'),        'change.type',        'text',    false,           30, 30),
     'user'        => GetFieldDef( _m('User'),        'change.user',        'text', false,           40, 40),
     'time'        => GetFieldDef( _m('Time'),        'change.time',        'date',    false,           50, 50),
     'selector'    => GetFieldDef( _m('Selector'),    'change_record.selector',    'text',    'change_record',           60, 60),
     'priority'    => GetFieldDef( _m('Priority'),    'change_record.priority',    'numeric', 'change_record', 70, 70),
     'value'       => GetFieldDef( _m('Value'),       'change_record.value',       'text',    'change_record', 80, 80),
     'vtype'       => GetFieldDef( _m('Value Type'),  'change_record.type',        'text',    'change_record', 90, 90)
               );
}

/** GetHistoryAliases function
 * Predefined aliases for links. For another aliases use 'inline' aliases.
 * @return array
 */
function GetHistoryAliases() {  // function - we need trnslate _m() on use (not at include time)
    return array(
    "_#HI_CH_ID" => GetAliasDef( "f_t", "change_id",  _m('Change ID')),
    "_#HI_FIELD" => GetAliasDef( "f_t", "selector",   _m('Field selector')),
    "_#HI_VALUE" => GetAliasDef( "f_t", "value",      _m('Value')),
    "_#HI_TYPE_" => GetAliasDef( "f_t", "vtype",       _m('Type of value')),
    "_#HI_TIME_" => GetAliasDef( "f_d:j.n.Y", "time", _m('Time of change')),
    "_#HI_USER_" => GetAliasDef( "f_t", "user",        _m('User')),
    "_#HI_ITEM_" => GetAliasDef( "f_t", "resource_id", _m('Item ID')),
    );
}

/**
 * Loads data from database for given link ids (called in itemview class)
 * and stores it in the 'Abstract Data Structure' for use with 'item' class
 *
 * @see GetItemContent(), itemview class, item class
 * @param array $zids array if ids to get from database
 * @return array - Abstract Data Structure containing the links data
 *                 {@link http://apc-aa.sourceforge.net/faq/#1337}
 */
function GetHistoryContent($zids) {
    if (!$zids OR $zids->count()<1) {
        return false;
    }

    // construct WHERE clausule
    $sel_in = $zids->sqlin( false );

    // get history data
    $SQL = "SELECT * FROM `change`, change_record
              WHERE change.id = change_record.change_id
              AND change_id $sel_in";

    $content = array();
    StoreTable2Content($content, $SQL, '', 'change_id');
    return $content;
}


/** Links_QueryCatZIDs - Finds category IDs according to given conditions
 *  @param string $cat_path - path to category (like '1,4,78' for category 78)
 *  @param array  $conds    - search conditions (see FAQ)
 *  @param array  $sort     - sort fields (see FAQ)
 *  @param bool   $subcat   - search in the specified category only, or search
 *                            also in all subcategories
 *  @param string $type     - type is something like bins as known from items
 *                            type is one of the following:
 *                            'app'       - approved (normal shown categories)
 *                            'all'       - all categories in any folder
 *  @global int  $QueryIDsCount - set to the count of IDs returned
 *  @global bool $debug=1       - many debug messages
 */
function QueryHistoryZIDs($slice_id, $conds, $sort="") {

    $HISTORY_FIELDS = GetHistoryFields();

    $where_sql      = MakeSQLConditions($HISTORY_FIELDS, $conds, $HISTORY_FIELDS, $foo);
    $order_by_sql   = MakeSQLOrderBy(   $HISTORY_FIELDS, $sort,  $foo);

    $SQL  = "SELECT DISTINCT change.id FROM `change`, change_record, item
              WHERE change.id = change_record.change_id AND item.id = UNHEX(change.resource_id) AND item.slice_id = '". q_pack_id($slice_id)."'
              AND change.type = 'h'";

//    $SQL  = "SELECT DISTINCT change.id FROM `change`, change_record WHERE change.id = change_record.change_id AND change.resource_id = '$slice_id' ";

    $SQL .=  $where_sql . $order_by_sql;

    // get result --------------------------
    return GetZidsFromSQL($SQL, 'id');
}


// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id = $slice_id;
// module_id is the same as slice_id (slice_id was used before AA introduced
// modules. Now it is better to use module_id, because in other modules
// (like Links, ...) it is not so confusing

$p_module_id = q_pack_id($module_id); // packed to 16-digit as stored in database
$slice       = AA_Slice::getModule($module_id);

$manager_settings = array(
     'module_id' => $slice_id,
     'show'      =>  MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS,    // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS
     'searchbar' => array(
         'fields'               => GetHistoryFields(),
         'search_row_count_min' => 1,
         'order_row_count_min'  => 1,
         'add_empty_search_row' => true,
         'function'             => false  // name of function for aditional action hooked on standard filter action
                         ),
     'scroller'  => array(
         'listlen'              => ($listlen ? $listlen : EDIT_ITEM_COUNT)
                         ),
     'itemview'  => array(
         'manager_vid'          => false,    // $slice_info['manager_vid'],      // id of view which controls the design
         'format'               => array(                           // optionaly to manager_vid you can set format array
             'compact_top'      => "<table border=\"0\" cellspacing=\"0\" cellpadding=\"5\">",
             'category_sort'    => false,
             'category_format'  => "",
             'category_top'     => "",
             'category_bottom'  => "",
             'even_odd_differ'  => false,
             'even_row_format'  => "",
//           'odd_row_format'   => '<tr class="tabtxt"><td width="30"><input type="checkbox" name="chb[_#HI_CH_ID]" value=""></td><td class="tabtxt"><a href="_#EDITLINK">{switch({_#L_NAME__}).:_#L_NAME__:???}</a> (_#L_O_NAME)<div class="tabsmall">_#L_DESCRI<br>(_#CATEG_GO)<br><a href="_#L_URL___" target="_blank">_#L_URL___</a></div></td><td class="tabsmall">{alias:checked:f_d:j.n.Y}<br>{alias:created_by:f_e:username}<br>{alias:edited_by:f_e:username}<br><span style="background:#_#L_VCOLOR;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;_#L_VALID_&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span></td></tr>
             'odd_row_format'   => '<tr class="tabtxt"><td width="30"><input type="checkbox" name="chb[_#HI_CH_ID]" value=""></td><td><strong>{item:{_#HI_ITEM_}:_#HEADLINE} / _#HI_FIELD</strong><br>_#HI_VALUE</td><td>_#HI_USER_ - _#HI_TIME_</td></tr>
             ',
             'compact_remove'   => '',
             'compact_bottom'   => "</table>",
             'id'               => $slice_id ),
         'fields'               => '',
         'aliases'              => GetHistoryAliases(),
         'get_content_funct'    => 'GetHistoryContent'
                         ),
     'actions_perm_function' => 'IsHistoryActionPerm',
     'actions'   => array(),
     'switches'  => array(),
     'messages'  => array(
         'title'       => _m('ActionApps - Reader Manager')
                         )
         );

$manager = new AA_Manager('history'.$slice_id, $manager_settings);
$manager->performActions();

$conds = $manager->getConds();
$sort  = $manager->getSort();
$zids=QueryHistoryZIDs($slice_id, $conds, $sort);

require_once AA_INC_PATH."menu.php3";

$manager->displayPage($zids, 'sliceadmin', 'history');

page_close();

?>
