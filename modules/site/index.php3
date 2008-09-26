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
// APC AA site Module main administration page

// used in initpage.php3 script to include config.php3 from the right directory

require_once "../../include/init_page.php3";
require_once AA_INC_PATH ."varset.php3";
require_once AA_INC_PATH ."formutil.php3";
require_once AA_INC_PATH ."pagecache.php3";
require_once AA_BASE_PATH."modules/site/util.php3";   // module specific utils
require_once AA_BASE_PATH."modules/site/sitetree.php3";   // module specific utils

// ----------------- function definition end -----------------------------------

$module_id   = $slice_id;
$p_module_id = q_pack_id($module_id);
$site_info   = GetModuleInfo($module_id,'W');   // W is identifier of "site" module
                                                //    - see /include/constants.php3
// r_spot_id holds current position in the tree
if (!isset($r_spot_id)) {
    $r_spot_id = 1;
    //  $sess->register(r_spot_id);   // Don't use a session variable, its page dependent
}

if ( !IfSlPerm(PS_MODW_EDIT_CODE) ) {
    MsgPage($sess->url(self_base())."index.php3", _m("You do not have permission to edit items in this slice"));
    exit;
}

// form send us spot id (prevents 'Back' browser problems, ...)
if (isset($spot_id)) {
    $r_spot_id = $spot_id;
}

// switch to another spot, if the spot is in this site
if (isset($go_sid)) {
    $r_spot_id = $go_sid;
}

$tree = new sitetree();

if ($site_info['structure'] != "") {
    $tree = unserialize($site_info['structure']);
} else {
    // get information about start spot (=start of the tree)
    $SQL = "SELECT * FROM site_spot WHERE site_id = '$p_module_id' AND spot_id='1'";
    $db->query($SQL);
    if ($db->next_record()) {
        $tree = new sitetree($db->Record);
    } else {
        MsgErr(_m("Starting spot not found"));
        page_close();
        exit;
    }
}

//  try
/*
$tree->addInSequence( 0, 'second' );
$tree->addVariable( 1, 'x' );
$tree->addChoice( 1, 'choice 1' );
$tree->addInSequence( 0, 'third' );
$tree->addInSequence( 2, 'quatro' );
*/

if ($debug) print("<p>Action=$akce; r_spot_id=$r_spot_id</p>");

switch( $akce ) {
    case 's': $tree->addInSequence( $r_spot_id, 'spot' ); break;  // Add Spot
    case 'c': $tree->addChoice( $r_spot_id, 'option' );   break;  // Add Choice
    case 'r': $parent = $tree->get( 'parent', $r_spot_id );       // Remove
              if ($priorsib = $tree->removeSpot($r_spot_id)) {
                  $r_spot_id = $priorsib; // was set to $parent;
              }
              break;
    case 'u': $tree->move(          $r_spot_id, 'moveUp' );    break;  // Up
    case 'd': $tree->move(          $r_spot_id, 'moveDown' );  break;  // Down
    case 'l': $tree->moveLeftRight( $r_spot_id, 'moveLeft' );  break;  // Left
    case 'a': $tree->moveLeftRight( $r_spot_id, 'moveRight' ); break;  // Right

    case 'h': $tree->setFlag($r_spot_id, MODW_FLAG_DISABLE);   break;  // Hide
    case 'e': $tree->clearFlag($r_spot_id, MODW_FLAG_DISABLE); break;  // Disable

    case 'p': $tree->setFlag($r_spot_id, MODW_FLAG_COLLAPSE);   break;  // Collapse
    case 'm': $tree->clearFlag($r_spot_id, MODW_FLAG_COLLAPSE); break;  // Expand
}

if ($addcond) {
    $tree->addCondition($r_spot_id, $addcondvar, $addcond);
} elseif($addvar) {
    $tree->addVariable($r_spot_id, $addvar);
} elseif($delvar) {
    $tree->removeVariable($r_spot_id, $delvar);
} elseif($delcond) {
    $tree->removeCondition($r_spot_id, $delcond);
} elseif($content OR $name) {
    $SQL = "SELECT id FROM site_spot WHERE site_id='$p_module_id' AND spot_id='$r_spot_id'";
    $db->query($SQL);
    $SQL = ($db->next_record() ?
           "UPDATE site_spot SET content='$content' WHERE id='". $db->f('id') ."'" :
           "INSERT INTO site_spot (site_id, spot_id, content) VALUES ('$p_module_id', '$r_spot_id', '$content')");
    $db->query($SQL);

    if ($name) {  // do not change to empty
        $tree->set('name', $r_spot_id, $name);
    }
}

if ($addcond OR $delcond OR $content OR $name OR $akce) {
    $GLOBALS['pagecache']->invalidateFor("slice_id=".$module_id);  // invalidate old cached values
}

// This is only run with a hand-coded URL to clean out the site
// and remove corruption, ideally should fix the cause of the corruption!
// - Mitra
if ($sitefix) {
    $tree->walkTree($apc_state, 1, 'ModW_DoNothing', 'all');
}
ModW_StoreTree($tree, $module_id);

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo '<title>'. _m("Editor window - site code manager") .'</title>';
FrmJavascriptFile('javascript/js_lib.js');
echo '</head>';

// module specific navigation bar
require_once AA_BASE_PATH."modules/site/menu.php3";
showMenu($aamenus, "codemanager");

function Links_PrintActionLink($r_spot_id, $action, $text, $img, $link=null) {
    if (!$link) {
        $link  = SiteAdminPage($r_spot_id, "akce=$action");
    }
    $image = GetModuleImage('site', $img, '', 16, 12);
    return "<a href=\"$link\">$image</a>&nbsp;<a href=\"$link\">$text</a>";
}

echo '<table border=0 cellspacing=0 class=login width="98%"><tr><td id="sitetree">
      <br>
      <table border=0 cellspacing=0 align="center">
        <tr>
          <td>'. Links_PrintActionLink($r_spot_id, 's', _m("Add&nbsp;spot"), 'add_spot.gif') .'</td>
          <td>&nbsp;'. Links_PrintActionLink($r_spot_id, 'c', _m("Add&nbsp;choice"), 'add_choice.gif') .'</td>
        </tr>
        <tr>
          <td>'. Links_PrintActionLink($r_spot_id, 'u', _m("Move&nbsp;up"), 'up.gif') .'</td>
          <td>&nbsp;'. Links_PrintActionLink($r_spot_id, 'd', _m("Move&nbsp;down"), 'down.gif') .'</td>
        </tr>
        <tr>
          <td>'. Links_PrintActionLink($r_spot_id, 'l', _m("Move&nbsp;left"), 'left.gif') .'</td>
          <td>&nbsp;'. Links_PrintActionLink($r_spot_id, 'a', _m("Move&nbsp;right"), 'right.gif') .'</td>
        </tr>
        <tr>
          <td>'. Links_PrintActionLink($r_spot_id, 'r', _m("Delete"), 'delete.gif', 'javascript:GoIfConfirmed(\''. SiteAdminPage($r_spot_id, "akce=r").'\', \''.
                                            _m("Are you sure you want to delete the spot?") .'\')') .'</td>
          <td>&nbsp;'.
          (($tree->get('flag', $r_spot_id) & MODW_FLAG_DISABLE) ?
              Links_PrintActionLink($r_spot_id, 'e', _m("Enable"), 'enabled.gif') :
              Links_PrintActionLink($r_spot_id, 'h', _m("Disable"), 'disabled.gif'))
              .'</td>
        </tr>
      </table>
      <br>';

// show tree
if ($debugsite) huhl("XYZZY:SiteIndex tree=",$tree);

// callback functions
$functions = array ('spot'          => 'ModW_PrintSpotName_Start',
                    'before_choice' => 'ModW_PrintChoice_Start',
                    'after_choice'  => 'ModW_PrintChoice_End');

$tree->walkTree($apc_state, 1, $functions, 'collapsed', 0, 'ModW_PrintSpotName_End');

echo '</td><td valign="top">';

ModW_ShowSpot($tree, $module_id, $r_spot_id);

echo '</td></tr></table>';
if ($debug) print("<p>Action=$akce; r_spot_id=$r_spot_id</p>");
echo ' </body>
</html>';

page_close();
exit;
?>