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
    $template['P'] .. id of site template - new will be based on this one
    $update=1 .. write changes to database
*/

$POLLS_LANGUAGE_FILES = array( "en_polls_lang.php3" => "en_polls_lang.php3",
                               "cz_polls_lang.php3" => "cz_polls_lang.php3");


if ( $template['P'] )
  $Add_slice = true;       // message for init_page.php3

$directory_depth = "../";
require_once "../../include/init_page.php3";
require_once AA_INC_PATH."en_polls_lang.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."date.php3";
require_once AA_INC_PATH."modutils.php3";
require_once AA_INC_PATH."mgettext.php3";

if ($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

$err["Init"] = "";          // error array (Init - just for initializing variable
$db = new DB_AA;
$varset = new CVarset();
$superadmin = IsSuperadmin();
$module_id = $slice_id;

if ($template['P']) {        // add module
  if (!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD)) {
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_ADD, "standalone");
    exit;
  }
} else {                    // edit module
  if (!CheckPerms( $auth->auth["uid"], "slice", $module_id, PS_MODP_SETTINGS)) {
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_EDIT, "standalone");
    exit;
  }
}

if ( $insert || $update ) {
  do {
    if ( !$owner )   // insert new owner
      if ( !( $owner = CreateNewOwner($new_owner, $new_owner_email, $err, $varset, $db)))
        break;

    // validate all fields needed for module table (name, slice_url, lang_file, owner)
    ValidateModuleFields( $name, $slice_url, $lang_file, $owner, $err );
    $deleted  = ( $deleted  ? 1 : 0 );

    // now validate all module specific fields
    ValidateInput("IPLockTimeout", L_IPLOCKTIMEOUT, $IPLockTimeout, $err, false, "text");
    ValidateInput("cookiesPrefix", L_COOKIESPREFIX, $cookiesPrefix, $err, false, "text");
    ValidateInput("params", L_PARAMS, $params, $err, false, "text");
    $Logging     = ( $Logging    ? 1 : 0 );
    $IPLocking   = ( $IPLocking  ? 1 : 0 );
    $setCookies  = ( $setCookies ? 1 : 0 );

    if ( count($err) > 1)
      break;

    // write all fields needed for module table
    $module_id = WriteModuleFields( ($update && $module_id) ? $module_id : false,
                                    $db, $varset, $superadmin, $auth,
                                   'P', $name, $slice_url, $lang_file, $owner, $deleted );
    if ( !$module_id )       // error?
      break;
    $slice_id = $module_id;

    // now set all module specific settings
    if ( $update ) {
      $p_module_id = q_pack_id($module_id);

      $varset->clear();

      $varset->add("Logging", "number", $Logging);
      $varset->add("IPLocking", "number", $IPLocking);
      $varset->add("IPLockTimeout", "number", $IPLockTimeout);
      $varset->add("setCookies", "number", $setCookies);
      $varset->add("cookiesPrefix", "quoted", $cookiesPrefix);

      // defaults - we use the same table for all polls. The setting of defaults to 1
      // flags this poll as default for this poll module
      $SQL = "UPDATE polls SET ". $varset->makeUPDATE() . " WHERE (id='$p_module_id') AND (defaults=1)";
      if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
        $err["DB"] = MsgErr("Can't change site");
        break;
      }
    } else { // insert (add)
      $varset->clear();

      $p_template_id = ( $template['P'] ?
              q_pack_id(substr($template['P'],1)) : 'PollTemplate....' );

      $SQL = "SELECT * FROM polls WHERE (id='$p_template_id' AND defaults='1')";
      $db->query($SQL);
      if ( !$db->next_record() ) {
        $err["DB"] = MsgErr("Bad template id");
        break;
      }
      $varset->setFromArray($db->Record);
      $varset->set("id", $module_id, "unpacked");
      $varset->set("Logging", $Logging, "number");
      $varset->set("IPLocking", $IPLocking, "number");
      $varset->set("IPLockTimeout", $IPLockTimeout, "number");
      $varset->set("setCookies", $setCookies, "number");
      $varset->set("cookiesPrefix", $cookiesPrefix, "quoted");
      $varset->set("defaults", 1, "number");

         // create new poll
      if ( !$db->query("INSERT INTO polls" . $varset->makeINSERT() )) {
        $err["DB"] .= MsgErr("Can't add poll");
        break;
      }

      // copy design themes...
      $db2  = new DB_AA;
      $SQL = "SELECT * FROM polls_designs WHERE pollsModuleID='$p_template_id'";
      $db->query($SQL);
      while ( $db->next_record() ) {
        $varset->clear();
        $varset->addArray( array('name', 'comment', 'resultBarFile', 'top', 'answer', 'bottom', 'params'),
                           array('resultBarWidth', 'resultBarHeight'),
                           $db->Record );   // copy table row to varset
        $varset->set("pollsModuleID", $module_id, "unpacked" );

        $SQL = "INSERT INTO polls_designs " . $varset->makeINSERT();
        if ( !$db2->query($SQL)) {
          $err["DB"] .= MsgErr("Can't copy polls_designs");
          break;
        }
      }
    }
    $cache = new PageCache(CACHE_TTL); // database changed -
    $cache->invalidate();  // invalidate old cached values - all
  }while(false);

  if ( count($err) <= 1 ) {
    page_close();                                // to save session variables
    $netscape = (($r=="") ? "r=1" : "r=".++$r);  // special parameter for Netscape to reload page
        // added by Setu, 2002-0227
    if ($return_url)   // after work for action, if return_url is there, we go to the page.
      go_url(urldecode($return_url));
    go_url($sess->url(self_base() . "modedit.php3?$netscape"));
  }
}


// And the form -----------------------------------------------------

$source_id = ($template['P'] ? substr($template['P'],1) : $module_id );
$p_source_id = q_pack_id( $source_id );

// load module common data
list( $name, $slice_url, $lang_file, $owner, $deleted, $slice_owners ) =
                                           GetModuleFields( $source_id, $db );
// load module specific data
$SQL= " SELECT * FROM polls WHERE id='$p_source_id'";

$db->query($SQL);
if ($db->next_record()) {
    while (list($key,$val,,) = each($db->Record)) {
        if (!is_numeric($key)) {
            $$key = $val; // variables and database fields have identical names
        }
    }
}
$id = unpack_id($db->f("id"));  // correct ids

if ( $template['P'] )            // set new name for new module
  $name = "";

//print_r($GLOBALS);


HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_POLL_TIT;?></TITLE>
</HEAD>
<?php
  require_once AA_BASE_PATH."modules/polls/menu.php3";
  showMenu($aamenus, "modadmin", "main");

  echo "<H1><B>" . ( $template['P'] ? L_A_POLL_ADD : L_A_POLL_EDT) . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_POLL_HDR?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php

  $include_cmd = "<!--#include virtual=\"${AA_INSTAL_PATH}modules/polls/poll.php3?poll_id=$module_id\"-->";
  FrmStaticText(L_ID, $module_id);
  FrmInputText("name", L_POLLS_NAME, $name, 99, 25, true);
  FrmInputText("slice_url", L_POLLS_URL, $slice_url, 254, 25, false,
               _m("Use following SSI command to include the poll to the page: ". $include_cmd));
  FrmInputSelect("owner", L_OWNER, $slice_owners, $owner, false);
  if ( !$owner ) {
    FrmInputText("new_owner", L_NEW_OWNER, $new_owner, 99, 25, false);
    FrmInputText("new_owner_email", L_NEW_OWNER_EMAIL, $new_owner_email, 99, 25, false);
  }
  if ( $superadmin )
    FrmInputChBox("deleted", L_DELETED, $deleted);
  FrmInputSelect("lang_file", L_LANG_FILE, $POLLS_LANGUAGE_FILES, $lang_file, false);

// module specific...

  FrmInputChBox("Logging", _m("Use logging"), $Logging);
  FrmInputChBox("IPLocking", _m("Use IP locking"), $IPLocking);
  FrmInputText("IPLockTimeout", _m("IP Locking timeout"), $IPLockTimeout);
  FrmInputChBox("setCookies", _m("Use cookies"), $setCookies);
  FrmInputText("cookiesPrefix", _m("Cookies prefix"), $cookiesPrefix);
  FrmInputText("params", _m("Parameters"), $params);     // TODO - add paramwizard
?>
    </table>
<?php
  if ( $template['P'] ) {
    FrmInputButtons( array( 'insert',
                            'template[P]' => array( 'type'=>"hidden",
                                                    'value'=> $template['P'])),
                     $sess, $slice_id);
  } else
    FrmInputButtons( array( 'update', 'reset', 'cancel' ), $sess, $slice_id );
?>
  </table>
</FORM>
</BODY>
</HTML>
<?php page_close()?>
