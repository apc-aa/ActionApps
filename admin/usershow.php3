<?php  //slice_id expected
/**
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

require_once "../include/init_page.php3";
require_once AA_INC_PATH . "varset.php3";
require_once AA_INC_PATH . "view.php3";
require_once AA_INC_PATH . "pagecache.php3";
require_once AA_INC_PATH . "item.php3";
require_once AA_INC_PATH . "feeding.php3";
require_once AA_INC_PATH . "profile.class.php3";
require_once AA_INC_PATH . "itemfunc.php3";
require_once AA_INC_PATH . "notify.php3";
require_once AA_INC_PATH . "searchlib.php3";
require_once AA_INC_PATH . "formutil.php3";
require_once AA_INC_PATH . "sliceobj.php3";
require_once AA_INC_PATH . "msgpage.php3";
require_once AA_INC_PATH . "manager.class.php3";
require_once AA_INC_PATH . "actions.php3";

// new manager class approach ------------------------------------------------

// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id = $slice_id;
// module_id is the same as slice_id (slice_id was used before AA introduced
// modules. Now it is better to use module_id, because in other modules
// (like Links, ...) it is not so confusing

$p_module_id = q_pack_id($module_id); // packed to 16-digit as stored in database
$slice       = AA_Slices::getSlice($module_id);

switch( $type ) {
    case 'users':
           $row =  '<td>_#LASTNAME</td><td>_#FIRSTNAM</td><td>_#EMAIL___<input type="hidden" name="items[x_#ITEM_ID#]" value=""></td>';
           $default_sort_field = "con_email.......";
           $format = array(
              'compact_top'      => '<table border=1 cellspacing=0 cellpadding=3 width=100%><tr align="center" class="tabtit">'.
                                    '<td><b>Last</b></td><td><b>First</b></td><td><b>Email</b></td></tr>',
              'even_odd_differ'  => 1,
              'even_row_format'  => '<tr class="tabtxteven">'.$row.'</tr>',
              'odd_row_format'   => '<tr class="tabtxt">'.$row.'</tr>',
              'compact_bottom'   => "</table>");
    // add your type here
    default:  // no format used - taken default format from manager class
}

$manager_settings = array(
     'module_id' => $slice_id,
     'show'      => MGR_SB_ORDERROWS,      //     // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS
     'searchbar' => array(
         'fields'               => $slice->fields('search'),
         'search_row_count_min' => 1,
         'order_row_count_min'  => 1,
         'add_empty_search_row' => true,
         'function'             => false,  // name of function for aditional action hooked on standard filter action
         'default_bookmark'     => $group
                         ),
     'scroller'  => array(
         'listlen'              => ($listlen ? $listlen : EDIT_ITEM_COUNT)
                         ),
     'itemview'  => array(
         'manager_vid'          => false,    // $slice_info['manager_vid'],      // id of view which controls the design
         'format'               => $format,
         'fields'               => $slice->fields('record'),
         'aliases'              => $slice->aliases(),
         'get_content_funct'    => 'GetItemContent'
                         ),
     'messages' => array (
         'title'                => _m("Show selected users")
                         )
         );

$manager = new AA_Manager($manager_settings);

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
}

//echo "<pre>"; print_r($r_userstate['usershowmanager']); echo "</pre>";

// echo "<pre>"; print_r($manager); echo "</pre>";


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
if ( $r_userstate['items'] ) {
    $zids->setFromItemArr($r_userstate['items']);
}

$zids = QueryZIDs( array($slice_id), $conds, $sort, 'ACTIVE', 0, $r_userstate['items'] ? $zids : false);

$manager->printSearchbarBegin();
$manager->printSearchbarEnd();   // close the searchbar form

$manager->printAndClearMessages();
PrintArray($r_err);
PrintArray($r_msg);
unset($r_err);
unset($r_msg);

$manager->printItems($zids);   // print links and actions
$r_userstate['usershowmanager'] = $manager->getState();

echo '<table width="100%" border="0" cellspacing="0" cellpadding="1" bgcolor="'. COLOR_TABTITBG ."\" align=\"center\">
 <tr>
  <td align=\"center\">
   <input type=\"button\" value='". _m("Close") ."' onclick='window.close()'>
   <input type=\"hidden\" name=\"group\" value=\"".$group."\">
  </td>
 </tr>
</table>";

HtmlPageEnd();
page_close();
?>
