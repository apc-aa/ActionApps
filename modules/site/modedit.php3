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

/* Params:
    $template['W'] .. id of site template - new will be based on this one
    $update=1 .. write changes to database
*/

if( $template['W'] )
  $Add_slice = true;       # message for init_page.php3

$directory_depth = "../";
require "../../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."en_site_lang.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."date.php3";
require $GLOBALS[AA_INC_PATH]."modutils.php3";
require "./util.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

$err["Init"] = "";          // error array (Init - just for initializing variable
$db = new DB_AA;
$varset = new CVarset();
$superadmin = IsSuperadmin();
$module_id = $slice_id;

if($template['W']) {        // add module
  if(!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD)) {
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_ADD, "standalone");
    exit;
  }
} else {                    // edit module
  if(!CheckPerms( $auth->auth["uid"], "slice", $module_id, PS_MODW_SETTINGS)) {
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_EDIT, "standalone");
    exit;
  }
}

if( $add || $update ) {
  do {
    if( !$owner )   # insert new owner
      if( !( $owner = CreateNewOwner($new_owner, $new_owner_email, $err, $varset, $db)))
        break;
    
    $lang_file = 'en_site_lang.php3';   # we do not use other language in this module, now
    
    # validate all fields needed for module table (name, slice_url, lang_file, owner)
    ValidateModuleFields( $name, $slice_url, $lang_file, $owner, $err );
    $deleted  = ( $deleted  ? 1 : 0 );

    # now validate all module specific fields
    ValidateInput("state_file", L_STATE_FILE, $state_file, $err, true, "text");

    if( count($err) > 1)
      break;

    # write all fields needed for module table        
    $module_id = WriteModuleFields( ($update && $module_id) ? $module_id : false,
                                    $db, $varset, $superadmin, $auth,
                                   'W', $name, $slice_url, $lang_file, $owner, $deleted );
    if( !$module_id )   // error?
      break;
    $slice_id = $module_id;
    
    # now set all module specific settings
    if( $update ) {
      $p_module_id = q_pack_id($module_id);

      $varset->clear();
      $varset->add("state_file", "text", $state_file);
      $SQL = "UPDATE site SET ". $varset->makeUPDATE() . " WHERE id='$p_module_id'";
      if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
        $err["DB"] = MsgErr("Can't change site");
        break;
      }
    } else { // insert (add)
      $varset->clear();
            // prepare varset variables for setFromArray() function
      $varset->addArray(array('id','structure','state_file'), array('flag'));

      $p_template_id = ( $template['W'] ? 
              q_pack_id(substr($template['W'],1)) : 'SiteTemplate....' );
        
      $SQL = "SELECT * FROM site WHERE id='$p_template_id'";
      $db->query($SQL);
      if( !$db->next_record() ) {
        $err["DB"] = MsgErr("Bad template id");
        break;
      }
      $varset->setFromArray($db->Record);
      $varset->set("id", $module_id, "unpacked");
      $varset->set("state_file", $state_file, "quoted");

         # create new site
      if( !$db->query("INSERT INTO site" . $varset->makeINSERT() )) {
        $err["DB"] .= MsgErr("Can't add site");
        break;
      }

         # copy spots
      $db2  = new DB_AA;
      $SQL = "SELECT * FROM site_spot WHERE site_id='$p_template_id'";
      $db->query($SQL);
      while( $db->next_record() ) {
        $varset->clear();
        $varset->set("site_id", $module_id, "unpacked" );
        $varset->set("spot_id", $db->f('spot_id'), "number" );
        $varset->set("content ", $db->f('content'), "text" );
        $varset->set("flag", "", "number" );
        $SQL = "INSERT INTO site_spot " . $varset->makeINSERT();
        if( !$db2->query($SQL)) {
          $err["DB"] .= MsgErr("Can't copy site_spots");
          break;
        }
      }
    }
    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed -
    $cache->invalidate();  # invalidate old cached values - all
  }while(false);

  if( count($err) <= 1 ) {
	go_return_or_url(self_base() . "modedit.php3", 0, 0);
  }
}


# And the form -----------------------------------------------------

$source_id = ($template['W'] ? substr($template['W'],1) : $module_id );
$p_source_id = q_pack_id( $source_id );

# load module common data
list( $name, $slice_url, $lang_file, $owner, $deleted, $slice_owners ) = 
                                           GetModuleFields( $source_id, $db );
# load module specific data
$SQL= " SELECT * FROM site WHERE id='$p_source_id'";
$db->query($SQL);
if ($db->next_record()) {
  while (list($key,$val,,) = each($db->Record)) {
    if( EReg("^[0-9]*$", $key))
      continue;
    $$key = $val; // variables and database fields have identical names
  }
}  
$id = unpack_id($db->f("id"));  // correct ids

if( $template['W'] )           // set new name for new module
  $name = "";


HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_SITE_TIT;?></TITLE>
</HEAD>
<?php
  require "./menu.php3";
  showMenu ($aamenus, "modadmin", "main");

  echo "<H1><B>" . ( $template['W'] ? L_A_SITE_ADD : L_A_SITE_EDT) . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_SITES_HDR?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
  ModW_HiddenRSpotId();
  FrmStaticText(L_ID, $module_id, false);
  FrmInputText("name", L_SITE_NAME, $name, 99, 25, true);
  $include_cmd = "<!--#include virtual=\"${AA_INSTAL_PATH}modules/site/site.php3?site_id=$module_id\"-->";
  FrmInputText("slice_url", L_SITE_URL, $slice_url, 254, 25, false,
               L_SITE_URL_HLP1. "$include_cmd" );
  FrmInputSelect("owner", L_OWNER, $slice_owners, $owner, false);
  if( !$owner ) {
    FrmInputText("new_owner", L_NEW_OWNER, $new_owner, 99, 25, false);
    FrmInputText("new_owner_email", L_NEW_OWNER_EMAIL, $new_owner_email, 99, 25, false);
  }
  if( $superadmin )
    FrmInputChBox("deleted", L_DELETED, $deleted);
//  FrmInputSelect("lang_file", L_LANG_FILE, $LANGUAGE_FILES, $lang_file, false);
  FrmInputText("state_file", L_STATE_FILE, $state_file, 99, 25, false, L_SITE_STATE_FILE_HLP);
?>
</table>
<tr><td align="center">
<?php
if( $template['W'] ) {
  echo "<input type=hidden name=\"add\" value=1>";        // action
//  echo "<input type=hidden name=\"Add_slice\" value=1>";  // detects new slice
  echo "<input type=hidden name=\"template[W]\" value=\"". $template['W'] .'">';
  echo "<input type=submit name=insert value=\"". L_INSERT .'">';
} else {
  echo "<input type=hidden name=\"update\" value=1>";
  echo '<input type=submit name=update value="'. L_UPDATE .'">&nbsp;&nbsp;';
  echo '<input type=reset value="'. L_RESET .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. L_CANCEL .'">';
}
?>
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()?>
