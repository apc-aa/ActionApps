<?php  //slice_id expected
/**
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

use AA\Manager;

const AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/view.php";
require_once __DIR__ . "/../include/item.php";
require_once __DIR__ . "/../include/feeding.php";
require_once __DIR__ . "/../include/itemfunc.php";
require_once __DIR__ . "/../include/notify.php";
require_once __DIR__ . "/../include/searchlib.php";
require_once __DIR__ . "/../include/formutil.php";

require_once __DIR__ . "/../include/actions.php";

// new manager class approach ------------------------------------------------

// Allow edit current slice without slice_pwd
AA_Credentials::singleton()->loadFromSlice(AA::$module_id);

// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id = AA::$module_id;
// module_id is the same as slice_id (slice_id was used before AA introduced
// modules. Now it is better to use module_id, because in other modules
// (like Links, ...) it is not so confusing

$slice       = AA_Slice::getModule($module_id);

if ( !IfSlPerm(PS_EDIT_ALL_ITEMS)) {
    MsgPage(_m("You do not have permission see the users in this slice:") . AA_Slice::getModuleName(AA::$module_id));
    exit;
}

if ( !$_GET['type'] AND $r_userstate['type'] ) {
    $type = $r_userstate['type'];
}

switch( $type ) {
    case 'users':
           $row =  '<td>{ifset:{_#LASTNAME}:_#1:_#HEADLINE}</td><td>{ifset:{_#FIRSTNAM}:_#1}</td><td>{ifset:{_#EMAIL___}:_#1}<input type="hidden" name="items[x_#ITEM_ID#]" value=""></td>';
           $default_sort_field = "con_email.......";
           $format = [
              'compact_top'      => '<table class="aa-table"><thead><tr><th colspan="2">'._m('User').'</th><th>'._m('Email').'</th></tr></thead><tbody>',
              'odd_row_format'   => '<tr>'.$row.'</tr>',
              'compact_bottom'   => "</tbody></table>"
           ];
    // add your type here
    default:  // no format used - taken default format from manager class
}

$manager_settings = [
     'module_id' => $module_id,
     'show'      => MGR_SB_ORDERROWS,      //     // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS
     'searchbar' => [
         'fields'               => $slice->getSearchfields(),
         'search_row_count_min' => 1,
         'order_row_count_min'  => 1,
         'add_empty_search_row' => true,
         'function'             => false,  // name of function for aditional action hooked on standard filter action
         'default_bookmark'     => $group
     ],
     'scroller'  => [
         'listlen'              => ($listlen ?: EDIT_ITEM_COUNT)
     ],
     'itemview'  => [
         'manager_vid'          => false,    // $slice_info['manager_vid'],      // id of view which controls the design
         'format'               => $format,
         'fields'               => $slice->getFields()->getRecordArray(),
         'aliases'              => new AA_Aliases(new AA_Aliasgenerator_Slice($module_id)),
         'get_content_funct'    => 'GetItemContent'
     ]
];

$manager = new Manager('users'.AA::$module_id, (new \AA\Managed())->setSettings($manager_settings)->setTitle(_m("Show selected users")));

// r_userstate array holds all configuration of Manager
// the configuration then could be Bookmarked


if ( !isset($r_userstate) OR isset($group) OR isset($items)) {
    // we are here for the first time
    unset($r_userstate);
    // set default admin interface settings from user's profile
    $sess->register('r_userstate');
    if ( $items ) {   // store items to sessions
        $r_userstate['items'] = $items;
    }
    $r_userstate['type'] = $type;
}

//echo "<pre>"; print_r($r_userstate['usershowmanager']); echo "</pre>";
//echo "<pre>"; print_r($manager); echo "</pre>";


if ( $r_userstate['usershowmanager'] ) {        // do not set state for the first time calling
    $manager->setFromState($r_userstate['usershowmanager']);
}

$manager->performActions();
$manager->printHtmlPageBegin(true);  // html, head, css, title, javascripts

$conds = $manager->getConds();
$sort  = $manager->getSort();

if (!$sort AND $default_sort_field) {
    $sort[0][$default_sort_field]="a";
}

$zids = new zids(null, 'l');
if ( is_array($r_userstate['items']) ) {
    $zids->setFromItemArr($r_userstate['items']);
}

$zids = QueryZids( [AA::$module_id], $conds, $sort, 'ACTIVE', 0, $r_userstate['items'] ? $zids : false);

echo $manager->getSearchbarBegin();
echo $manager->getSearchbarEnd();   // close the searchbar form

echo $manager->getAndClearMessages();
echo GetPrintArray($r_err);
echo GetPrintArray($r_msg);
unset($r_err);
unset($r_msg);

echo $manager->getItems($zids);   // print links and actions
$r_userstate['usershowmanager'] = $manager->getState();

echo "<table>
 <tr>
  <td>
   <input type=\"button\" value='". _m("Close") ."' onclick='window.close()'>
   <input type=\"hidden\" name=\"group\" value=\"$group\">
  </td>
 </tr>
</table>";

HtmlPageEnd();
page_close();

