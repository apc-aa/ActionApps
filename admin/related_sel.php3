<?php
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

# sid expected - slice_id where to search
# var_id expected - id of variable in calling form, which should be filled
# mode expected - which buttons to show ([A][M][B] - 'add' 'add mutual' 'add backward'
# design expected - boolean - use standard or admin design

$save_hidden = true;   # do not delete r_hidden session variable in init_page!

require_once "../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"] . "varset.php3";
require_once $GLOBALS["AA_INC_PATH"] . "view.php3";
require_once $GLOBALS["AA_INC_PATH"] . "pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"] . "item.php3";
require_once $GLOBALS["AA_INC_PATH"] . "feeding.php3";
require_once $GLOBALS["AA_INC_PATH"] . "itemfunc.php3";
require_once $GLOBALS["AA_INC_PATH"] . "notify.php3";
require_once $GLOBALS["AA_INC_PATH"] . "searchlib.php3";
require_once $GLOBALS["AA_INC_PATH"] . "formutil.php3";
require_once $GLOBALS["AA_INC_PATH"] . "util.php3";

require_once $GLOBALS["AA_INC_PATH"] . "manager.class.php3";

/** RELATED SELECTION WINDOW - managed by MANAGER CLASS */

/* use stored values from session, if we call related window
   for secnd time (eg. after search or filtering data) */
if ((isset($r_state)) && !($sid))  {
//  $sid      = $r_state['related']['sid'];
    $mode     = $r_state['related']['mode'];
    $var_id   = $r_state['related']['var_id'];
    $design   = $r_state['related']['design'];
    $frombins = $r_state['related']['frombins'];
//  $conds_ro = $r_state['related']['conds_ro'];
//  $conds_rw = $r_state['related']['conds_rw'];
}

// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id = ( isset($sid) ? $sid : $r_state['module_id'] );

// module_id is the same as slice_id (slice_id was used before AA introduced
// modules. Now it is better to use module_id, because in other modules
// (like Links, ...) it is not so confusing

$p_module_id = q_pack_id($module_id); # packed to 16-digit as stored in database
$slice = new slice($module_id);

/* prepare view format for manager class */
if( !$mode ) { $mode='AMB'; }

for( $i=0; $i<strlen($mode); $i++) {
    $m1 = substr($mode,$i,1);
    $mode_string .= "&nbsp;<a href=\"javascript:SelectRelations('".$tps['AMB'][$m1]['tag']."','".$tps['AMB'][$m1]['prefix']."','".$tps['AMB'][$m1]['tag']."_#ITEM_ID_','_#JS_HEAD_')\">". $tps['AMB'][$m1]['str'] ."</a>&nbsp;";
}

$format = $slice->get_format_strings();
$aliases = $slice->aliases();

// if it is not 'Admin design', we need just following aliases
if ( !isset($aliases["_#ITEM_ID_"]) ) $aliases["_#ITEM_ID_"] = GetAliasDef( "f_n:id..............", "id..............");
if ( !isset($aliases["_#SITEM_ID"]) ) $aliases["_#SITEM_ID"] = GetAliasDef( "f_h",                  "short_id........");
if ( !isset($aliases["_#HEADLINE"]) ) $aliases["_#HEADLINE"] = GetAliasDef( "f_e:safe",             GetHeadlineFieldID($r_sid));
if ( !isset($aliases["_#JS_HEAD_"]) ) $aliases["_#JS_HEAD_"] = GetAliasDef( "f_e:javascript",       GetHeadlineFieldID($r_sid));

if (!($design)) {
    $format["odd_row_format"] = '<tr><td class="tabtxt">_#PUB_DATE&nbsp;</td><td class="tabtxt">_#HEADLINE</td><td class="tabtxt">'.$mode_string.'</td></tr>';
    $format["even_row_format"] = '<tr><td class="tabtxteven">_#PUB_DATE&nbsp;</td><td class="tabtxteven">_#HEADLINE</td><td class="tabtxteven">'.$mode_string.'</td></tr>';
    $format["even_odd_differ"] = 1;
    $format["compact_top"] = '<table border="0" cellspacing="0" cellpadding="0" bgcolor="#F5F0E7" width="100%">
    <tr><td class="tabtitlight">'._m("Publish date").'</td><td class="tabtitlight">'._m("Headline").'</td><td class="tabtitlight">'._m("Actions").'</td></tr>';
    $aliases["_#JS_HEAD_"] = array("fce" => "f_e:javascript",
                                   "param" => GetHeadlineFieldID($sid),
                                   "hlp" => "");
} else {
  $format["odd_row_format"] = str_replace('<input type=checkbox name="chb[x_#ITEM_ID#]" value="1">',
                                                  $mode_string, $format['odd_row_format']);
}
if (isset($showcondsro)) {
    if (isset($conds)) { unset($conds); }
    $showcondsro = stripslashes(rawurldecode($showcondsro));
    parse_str($showcondsro);
    $conds_ro = $conds;
    ParseMultiSelectConds($conds_ro);
    ParseEasyConds($conds_ro);
}
if (isset($showcondsrw)) {
    if (isset($conds)) { unset($conds); }
    $showcondsrw = stripslashes(rawurldecode($showcondsrw));
    parse_str($showcondsrw);
    $conds_rw = $conds;
    ParseMultiSelectConds($conds_rw);
    ParseEasyConds($conds_rw);
}

$manager_settings = array(
     'show'     =>  MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS,
     'searchbar' => array(
         'fields'               => $slice->fields('search'),
         'search_row_count_min' => 1,
         'order_row_count_min'  => 1,
         'add_empty_search_row' => true,
         'function'             => false  // name of function for aditional action hooked on standard filter action
                         ),
     'scroller'  => array(
         'listlen'              => ($listlen ? $listlen : EDIT_ITEM_COUNT),
         'slice_id'             => $slice_id
                         ),
     'itemview'  => array(
         'manager_vid'          => false,    // $slice_info['manager_vid'],      // id of view which controls the design
         'format'               => $format,
         'fields'               => $slice->fields('record'),
         'aliases'              => $aliases,
         'get_content_funct'    => 'GetItemContent'
                         ),
     'messages'  => array(
         'title'       => _m("Editor window - item manager, related selection window")
                         )
         );


$manager = new manager($manager_settings);
if ((isset($conds_ro)) && (isset($showcondsro))) {
    $manager->searchbar->addSearch($conds_ro, true);
}
if ((isset($conds_rw)) && (isset($showcondsrw))) {
    $manager->searchbar->addSearch($conds_rw);
}

// r_state array holds all configuration of Links Manager
// the configuration then could be Bookmarked
if ( !isset($r_state) OR $sid OR ($r_state["module_id"] != $module_id)) {
    // we are here for the first time or we are switching to another slice
    unset($r_state);
    // set default admin interface settings from user's profile
    $r_state["module_id"]       = $module_id;
    $r_state['bin']             = 'app';
 //   $r_state['related']['sid']  = $sid;
    $r_state['related']['mode']  = $mode;
    $r_state['related']['var_id']  = $var_id;
    $r_state['related']['design']  = $design;
    $r_state['related']['frombins']  = $frombins;
//    $r_state['related']['conds_ro'] = $conds_ro;
//    $r_state['related']['conds_rw'] = $conds_rw;

    $sess->register('r_state');
    $profile = new aaprofile($auth->auth["uid"], $module_id); // current user settings
    $manager->setFromProfile($profile);
}

if( $r_state['manager_related'] )        // do not set state for the first time calling
    $manager->setFromState($r_state['manager_related']);

$manager->performActions();

$manager->printHtmlPageBegin(true);  // html, head, css, title, javascripts

echo "<script type=\"text/javascript\" language=\"javascript\"> <!--
  var maxcount = ". MAX_RELATED_COUNT .";
  var relmessage = \""._m("There are too many related items. The number of related items is limited.") ."\";
  var var_id = \"".$var_id."\";
  //-->
</script>
";

$conds = $manager->getConds();
$sort  = $manager->getSort();

$zids=QueryZIDs($slice->fields('record'), $slice_id, $conds, $sort, "", $frombins);

$manager->printSearchbarBegin();
$manager->printSearchbarEnd();   // close the searchbar form

$manager->printAndClearMessages();
PrintArray($r_err);
PrintArray($r_msg);
unset($r_err);
unset($r_msg);

$manager->printItems($zids);   // print links and actions
$r_state['manager_related'] = $manager->getState();

echo '<table width="100%" border="0" cellspacing="0" cellpadding="1" bgcolor="'. COLOR_TABTITBG ."\" align=\"center\">
<tr><td align=center><input type=button value='". _m("Back") ."' onclick='window.close()'></td></tr></table>";
HtmlPageEnd();
page_close();

?>
