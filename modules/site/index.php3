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
# APC AA site Module main administration page

# used in initpage.php3 script to include config.php3 from the right directory
$directory_depth = '../';

require_once "../../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"] . "varset.php3";
require_once $GLOBALS["AA_INC_PATH"] . "formutil.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3"; 
require_once "./util.php3";   # module specific utils
require_once "./sitetree.php3";   # module specific utils

# ----------------- function definition end -----------------------------------

$module_id = $slice_id;
$p_module_id = q_pack_id($module_id);

$site_info = GetModuleInfo($module_id,'W');   # W is identifier of "site" module
                                              #    - see /include/constants.php3
# r_spot_id holds current position in the tree
if( !isset($r_spot_id) ) {
  $r_spot_id = 1;
#  $sess->register(r_spot_id);   # Don't use a session variable, its page dependent
}
       
if( !IfSlPerm(PS_MODW_EDIT_CODE) ) {
  MsgPage($sess->url(self_base())."index.php3", _m("You do not have permission to edit items in this slice"));
  exit;
}  

# form send us spot id (prevents 'Back' browser problems, ...)
if( isset($spot_id) )
  $r_spot_id = $spot_id;

# switch to another spot, if the spot is in this site
if( isset($go_sid) )
  $r_spot_id = $go_sid;

$tree = new sitetree();

if( $site_info['structure'] != "" )
  $tree = unserialize($site_info['structure']);
 else {
  # get information about start spot (=start of the tree)
  $SQL = "SELECT * FROM site_spot WHERE site_id = '$p_module_id' AND spot_id='1'";
  $db->query($SQL);
  if( $db->next_record() )
    $tree = new sitetree($db->Record);
  else {
    MsgErr(_m("Starting spot not found"));
    page_close();
    exit;
  }
}  
 
#  try 
/*
$tree->addInSequence( 0, 'second' );
$tree->addVariable( 1, 'x' );
$tree->addChoice( 1, 'choice 1' );
$tree->addInSequence( 0, 'third' );
$tree->addInSequence( 2, 'quatro' );
*/

if ($debug) print("<p>Action=$akce; r_spot_id=$r_spot_id</p>");

switch( $akce ) {
  case 's': $tree->addInSequence( $r_spot_id, 'spot' ); break;
  case 'c': $tree->addChoice( $r_spot_id, 'option' ); break;
  case 'r': $parent = $tree->get( 'parent', $r_spot_id );
            if( $priorsib = $tree->removeSpot( $r_spot_id ) ) {
              $r_spot_id = $priorsib; // was set to $parent;
            }
            break;
  case 'u': $tree->move( $r_spot_id, 'moveUp' ); break;
  case 'd': $tree->move( $r_spot_id, 'moveDown' ); break;
}  

if( $addcond )
  $tree->addCondition( $r_spot_id, $addcondvar, $addcond );
elseif( $addvar )  
  $tree->addVariable( $r_spot_id, $addvar );
elseif( $delvar )  
  $tree->removeVariable( $r_spot_id, $delvar );
elseif( $delcond )  
  $tree->removeCondition( $r_spot_id, $delcond );
elseif( $content OR $name ) {
  $SQL = "SELECT id FROM site_spot 
           WHERE site_id='$p_module_id' AND spot_id='$r_spot_id'";

  $db->query($SQL);
  $SQL=($db->next_record() ? "UPDATE site_spot SET content='$content' 
                               WHERE id='". $db->f('id') ."'" :
                             "INSERT INTO site_spot (site_id, spot_id, content) 
                              VALUES ('$p_module_id', '$r_spot_id', '$content')");
  $db->query($SQL);

  $GLOBALS[pagecache]->invalidate("slice_id=".site_id);  # invalidate old cached values

  if( $name )  # do not change to empty
    $tree->set( 'name', $r_spot_id, $name );
}  
  
//print_r($tree);
$tree->set( 'flag', $r_spot_id, 0 );  

// This is only run with a hand-coded URL to clean out the site 
// and remove corruption, ideally should fix the cause of the corruption! 
// - Mitra
if ($sitefix) {
    $tree->walkTree($apc_state, 1, 'ModW_DoNothing', 'all');
}
ModW_StoreTree( $tree, $module_id );

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Editor window - site code manager") ?></title>
</head> <?php

# module specific navigation bar
require_once "./menu.php3";
showMenu ($aamenus, "codemanager");

echo '<br>
<table border=0 cellspacing=0 class=login width="95%"><TR><TD width=200>
  <a href="'. AAPage(0,"akce=s").'">'. _m("Add&nbsp;spot") .'</a> 
  <a href="'. AAPage(0,"akce=c").'">'. _m("Add&nbsp;choice") .'</a>
  <a href="'. AAPage(0,"akce=r").'">'. _m("Delete&nbsp;spot") .'</a>
  <a href="'. AAPage(0,"akce=u").'">'. _m("Move&nbsp;up") .'</a>
  <a href="'. AAPage(0,"akce=d").'">'. _m("Move&nbsp;down") .'</a>
  <br>';

# show tree
if ($debugsite) huhl("XYZZY:SiteIndex tree=",$tree);
$tree->walkTree($apc_state, 1, 'ModW_PrintSpotName', 'all');

echo '</td><td valign="top">';

ModW_ShowSpot($tree, $module_id, $r_spot_id);

echo '</td></tr></table>';
if ($debug) print("<p>Action=$akce; r_spot_id=$r_spot_id</p>");
echo ' </body>
</html>';

page_close();
exit;
?>