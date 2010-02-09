<?php
/**
 *
 *   sid expected - slice_id where to search
 *   var_id expected - id of variable in calling form, which should be filled
 *   mode expected - which buttons to show ([A][M][B] - 'add' 'add mutual' 'add backward'
 *   design expected - boolean - use standard or admin design
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


$save_hidden = true;   // do not delete r_hidden session variable in init_page!

require_once "../include/init_page.php3";
require_once AA_INC_PATH . "varset.php3";
require_once AA_INC_PATH . "view.php3";
require_once AA_INC_PATH . "pagecache.php3";
require_once AA_INC_PATH . "item.php3";
require_once AA_INC_PATH . "feeding.php3";
require_once AA_INC_PATH . "itemfunc.php3";
require_once AA_INC_PATH . "notify.php3";
require_once AA_INC_PATH . "searchlib.php3";
require_once AA_INC_PATH . "formutil.php3";
require_once AA_INC_PATH . "util.php3";

require_once AA_INC_PATH . "manager.class.php3";

/** RELATED SELECTION WINDOW - managed by MANAGER CLASS */

/* use stored values from session, if we call related window
   for secnd time (eg. after search or filtering data) */
if ( isset($r_state) && !$sid)  {
//  $sid      = $r_state['related']['sid'];
    $mode     = $r_state['related']['mode'];
    $var_id   = $r_state['related']['var_id'];
    $design   = $r_state['related']['design'];
    $frombins = $r_state['related']['frombins'];
//  $conds_ro = $r_state['related']['conds_ro'];
//  $conds_rw = $r_state['related']['conds_rw'];
}

// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id = get_if( $sid, $r_state['related']['sid'] );

// module_id is the same as slice_id (slice_id was used before AA introduced
// modules. Now it is better to use module_id, because in other modules
// (like Links, ...) it is not so confusing

$p_module_id = q_pack_id($module_id); // packed to 16-digit as stored in database
$slice       = AA_Slices::getSlice($module_id);

/* prepare view format for manager class */
if (!$mode ) {
    $mode='AMB';
}

for ( $i=0; $i<strlen($mode); $i++) {
    $m1 = substr($mode,$i,1);
    $mode_string .= "&nbsp;<a href=\"javascript:SelectRelations('$var_id','".$tps['AMB'][$m1]['tag']."','".$tps['AMB'][$m1]['prefix']."','".$tps['AMB'][$m1]['tag']."_#ITEM_ID_','_#JS_HEAD_')\">". $tps['AMB'][$m1]['str'] ."</a>&nbsp;";
}

$aliases = $slice->aliases();
// special alias - will be automaticaly added as last column in manager view
$aliases["_#AA_ACTIO"] = GetAliasDef( "f_t:$mode_string", "id..............");

$manager_vid = null;
$format      = null;   //default manager format will be used (and _#AA_ACTIO alias expanded)

if ($design) {
    if (is_numeric($design)) {
        if ($design==1) {
            $format  = $slice->get_format_strings();
            // replace the checkbox with "action selection links"

            // remove links
            $format["odd_row_format"] = preg_replace('~</?a[^>]*>~is', '', $format['odd_row_format']);
            // add action buttons (links)
            $format["odd_row_format"] = preg_replace('~<input[^>]*checkbox[^>]*chb\[x_#ITEM_ID[^>]*>~i', $mode_string, $format['odd_row_format']);
        } else {
            $manager_vid = $design;
        }
    } else {
        $format['odd_row_format']  = '<tr class="tabtxt"><td>'.$design.'</td></tr>';
        $format['compact_top']     = '<table border="0" cellspacing="0" cellpadding="0" bgcolor="#F5F0E7" width="100%">';
        $format['compact_bottom']  = '</table>';
    }
}

$conds_ro = String2Conds( rawurldecode($showcondsro) );
$conds_rw = String2Conds( rawurldecode($showcondsrw) );

$manager_settings = array(
     'module_id' => $module_id,
     'show'       =>  MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS,
     'searchbar'  => array(
         'fields'               => $slice->fields('search'),
         'search_row_count_min' => 1,
         'order_row_count_min'  => 1,
         'add_empty_search_row' => true,
         'function'             => false  // name of function for aditional action hooked on standard filter action
                         ),
     'scroller'  => array(
         'listlen'              => ($listlen ? $listlen : EDIT_ITEM_COUNT)
                         ),
     'itemview'  => array(
         'manager_vid'          => $manager_vid,    // $slice_info['manager_vid'],      // id of view which controls the design
         'format'               => $format,
         'fields'               => $slice->fields('record'),
         'aliases'              => $aliases,
         'get_content_funct'    => 'GetItemContent'
                         ),
     'messages'  => array(
         'title'       => _m("Editor window - item manager, related selection window")
                         )
         );


$manager = new AA_Manager('related'. $module_id, $manager_settings);
if ((isset($conds_ro)) && (isset($showcondsro))) {
    $manager->searchbar->addSearch($conds_ro, true);
}
if ((isset($conds_rw)) && (isset($showcondsrw))) {
    $manager->searchbar->addSearch($conds_rw);
}

// r_state array holds all configuration of Links Manager
// the configuration then could be Bookmarked
if ( !isset($r_state['related']) OR $sid OR ($r_state['related']['sid'] != $module_id)) {
//    huhl(" 1:", $r_state['related'], " sid:",$sid , " m:",$r_state['related']['module_id'], " mi:",$module_id);
    // we are here for the first time or we are switching to another slice
    unset($r_state['related']);
    // set default admin interface settings from user's profile
    // $r_state["module_id"]         = $module_id;
    // $r_state['bin']               = 'app';
    $frombins = get_if( $frombins, AA_BIN_ACTIVE | AA_BIN_PENDING ) ;
    $r_state['related']['sid']       = $module_id;
    $r_state['related']['mode']      = $mode;
    $r_state['related']['var_id']    = $var_id;
    $r_state['related']['design']    = $design;
    $r_state['related']['frombins']  = $frombins;
//    $r_state['related']['conds_ro'] = $conds_ro;
//    $r_state['related']['conds_rw'] = $conds_rw;

}

if ($r_state['related']['manager'] ) {        // do not set state for the first time calling
    $manager->setFromState($r_state['related']['manager']);
}

$manager->performActions();

$manager->printHtmlPageBegin(true);  // html, head, css, title, javascripts
FrmJavascriptFile('javascript/js_lib.js');
FrmJavascriptFile('javascript/inputform.js');  // for SelectRelations() function
FrmJavascript("
  var maxcount = ". MAX_RELATED_COUNT .";
  var relmessage = \""._m("There are too many related items. The number of related items is limited.") ."\";
  ");

$conds = $manager->getConds();
$sort  = $manager->getSort();

$zids = QueryZIDs(array($module_id), $conds, $sort, $frombins);

$manager->printSearchbarBegin();
$manager->printSearchbarEnd();   // close the searchbar form

$manager->printAndClearMessages();
PrintArray($r_err);
PrintArray($r_msg);
unset($r_err);
unset($r_msg);

$manager->printItems($zids);   // print links and actions
$r_state['related']['manager'] = $manager->getState();

echo '<table width="100%" border="0" cellspacing="0" cellpadding="1" bgcolor="'. COLOR_TABTITBG ."\" align=\"center\">
<tr><td align=\"center\"><input type=\"button\" value='". _m("Back") ."' onclick='window.close()'></td></tr></table>";

HtmlPageEnd();
page_close();

?>
