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
# expected $slice_id for edit slice, Add_slice=1 for adding slice

# new slice - template as slice 
$set_template_id = (($template_slice_sel=="slice") ? $template_id2 : $template_id);
  
if( $set_template_id ) {
  $foo = explode("{", $set_template_id);
  $set_template_id = $foo[0];
  $change_lang_file = $foo[1];
}  

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."date.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if($slice_id) {  // edit slice
  if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT)) {
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_EDIT, "standalone");
    exit;
  }
} else {          // add slice
  if(!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD)) {
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_ADD, "standalone");
    exit;
  }
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$superadmin = IsSuperadmin();

if( $add || $update ) {
  do {
    if( !$owner ) {  # insert new owner
      ValidateInput("new_owner", L_NEW_OWNER, &$new_owner, &$err, true, "text");
      ValidateInput("new_owner_email", L_NEW_OWNER_EMAIL, &$new_owner_email, &$err, true, "email");

      if( count($err) > 1)
        break;
        
      $owner = new_id();
      $varset->set("id", $owner, "unpacked");
      $varset->set("name", $new_owner, "text");
      $varset->set("email", $new_owner_email, "text");
       
        # create new owner
      if( !$db->query("INSERT INTO slice_owner " . $varset->makeINSERT() )) {
        $err["DB"] .= MsgErr("Can't add slice");
        break;
      }
      
      $varset->clear();
    }  

    ValidateInput("name", L_SLICE_NAME, &$name, &$err, true, "text");
    ValidateInput("owner", L_OWNER, &$owner, &$err, false, "id");
    ValidateInput("slice_url", L_SLICE_URL, &$slice_url, &$err, false, "url");
    ValidateInput("d_listlen", L_D_LISTLEN, $d_listlen, &$err, true, "number");
    ValidateInput("permit_anonymous_post", L_PERMIT_ANONYMOUS_POST, $permit_anonymous_post, &$err, false, "number");
    ValidateInput("permit_offline_fill", L_PERMIT_OFFLINE_FILL, $permit_offline_fill, &$err, false, "number");
    ValidateInput("lang_file", L_LANG_FILE, $lang_file, &$err, true, "text");

    if( count($err) > 1)
      break;
    if(!$d_expiry_limit)   // default value for limit
      $d_expiry_limit = 2000;
    $template = ( $template ? 1 : 0 );
    $deleted  = ( $deleted  ? 1 : 0 );
    
    if( $update )
    {
      $varset->clear();
      $varset->add("name", "quoted", $name);
      $varset->add("owner", "unpacked", $owner);
      $varset->add("slice_url", "quoted", $slice_url);
      if( $superadmin ) 
        $varset->add("deleted", "number", $deleted);
      $varset->add("lang_file", "quoted", $lang_file);

      $SQL = "UPDATE module SET ". $varset->makeUPDATE() . " WHERE id='$p_slice_id'";
      if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
        $err["DB"] = MsgErr("Can't change slice");
        break;
      }

      $varset->add("d_listlen", "number", $d_listlen);
      if( $superadmin ) 
        $varset->add("template", "number", $template);
      $varset->add("permit_anonymous_post", "number", $permit_anonymous_post);
      $varset->add("permit_offline_fill", "number", $permit_offline_fill);

      $SQL = "UPDATE slice SET ". $varset->makeUPDATE() . " WHERE id='$p_slice_id'";
      if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
        $err["DB"] = MsgErr("Can't change slice");
        break;
      }
      $r_slice_headline = stripslashes($name);
      $r_config_file[$slice_id] = stripslashes($lang_file);
      $r_slice_view_url = ($slice_url=="" ? $sess->url("../slice.php3"). "&slice_id=$slice_id&encap=false"
                                      : stripslashes($slice_url));
    }
    else  // insert (add)
    {
      $slice_id = new_id();
      $varset->set("id", $slice_id, "unpacked");
      $varset->set("created_by", $auth->auth["uid"], "text");
      $varset->set("created_at", now(), "text");
      $varset->set("name", $name, "quoted");
      $varset->set("owner", $owner, "unpacked");
      $varset->set("slice_url", $slice_url, "quoted");
      $varset->set("deleted", $deleted, "number");
      $varset->set("lang_file", $lang_file, "quoted");
      $varset->set("type","S","quoted");
	  
      if( !$db->query("INSERT INTO module" . $varset->makeINSERT() )) {
        $err["DB"] .= MsgErr("Can't add slice");
        break;
      }
	  
      $varset->clear();

        # get template data
      $varset->addArray( $SLICE_FIELDS_TEXT, $SLICE_FIELDS_NUM );
      $SQL = "SELECT * FROM slice WHERE id='". q_pack_id($set_template_id) ."'";
      $db->query($SQL);
      if( !$db->next_record() ) {
        $err["DB"] = MsgErr("Bad template id");
        break;
      }
      $varset->setFromArray($db->Record);
      $varset->set("id", $slice_id, "unpacked");
      $varset->set("created_by", $auth->auth["uid"], "text");
      $varset->set("created_at", now(), "text");
      $varset->set("name", $name, "quoted");
      $varset->set("owner", $owner, "unpacked");
      $varset->set("slice_url", $slice_url, "quoted");
      $varset->set("deleted", $deleted, "number");
      $varset->set("lang_file", $lang_file, "quoted");
      $varset->set("d_listlen", $d_listlen, "number");
      $varset->set("template", $template, "number");
      $varset->set("permit_anonymous_post", $permit_anonymous_post, "number");
      $varset->set("permit_offline_fill", $permit_offline_fill, "number");

         # create new slice
      if( !$db->query("INSERT INTO slice" . $varset->makeINSERT() )) {
        $err["DB"] .= MsgErr("Can't add slice");
        break;
      }

         # copy fields
      $db2  = new DB_AA;         
      $SQL = "SELECT * FROM field WHERE slice_id='". q_pack_id($set_template_id) ."'";
      $db->query($SQL);
      while( $db->next_record() ) {
        $varset->clear();
        $varset->addArray( $FIELD_FIELDS_TEXT, $FIELD_FIELDS_NUM );
        $varset->setFromArray($db->Record);
        $varset->set("slice_id", $slice_id, "unpacked" );
        $SQL = "INSERT INTO field " . $varset->makeINSERT();
        if( !$db2->query($SQL)) {
          $err["DB"] .= MsgErr("Can't copy fields");
          break;
        }
      }  

      $r_config_file[$slice_id] = $lang_file;
      $sess->register(slice_id);

      AddPermObject($slice_id, "slice");    // no special permission added - only superuser can access
    }
    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
    $cache->invalidate();  # invalidate old cached values - all
  }while(false);
  if( count($err) <= 1 )
  {
    page_close();                                // to save session variables
    $netscape = (($r=="") ? "r=1" : "r=".++$r);   // special parameter for Natscape to reload page
    // added by Setu, 2002-0227
    if ($return_url)   // after work for action, if return_url is there, we go to the page.
        go_url(urldecode($return_url));
    go_url($sess->url(self_base() . "slicedit.php3?$netscape"));
  }
}

$foo_source = ( ( $slice_id=="" ) ? $set_template_id : $slice_id);
  # set variables from database - allways
$SQL= " SELECT * FROM slice WHERE id='".q_pack_id($foo_source)."'";
$db->query($SQL);
if ($db->next_record())
  while (list($key,$val,,) = each($db->Record)) {
    if( EReg("^[0-9]*$", $key))
      continue;
    $$key = $val; // variables and database fields have identical names
  }
$id = unpack_id($db->f("id"));  // correct ids
$owner = unpack_id($db->f("owner"));  // correct ids

if( $slice_id == "" ) {         // load default values for new slice
  $name = "";
  $owner = "";
  $template = "";
  $slice_url = "";
}

# lookup owners
$slice_owners[0] = L_SELECT_OWNER;
$SQL= " SELECT id, name FROM slice_owner ORDER BY name";
$db->query($SQL);
while ($db->next_record()) {
  $slice_owners[unpack_id($db->f(id))] = $db->f(name);
}

$PERMS_STATE = array( "0" => L_PROHIBITED,
                      "1" => L_ACTIVE_BIN,
                      "2" => L_HOLDING_BIN );

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_SLICE_TIT;?></TITLE>
</HEAD>
<?php
  $show ["main"] = false;
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

  echo "<H1><B>" . ( $slice_id=="" ? L_A_SLICE_ADD : L_A_SLICE_EDT) . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_SLICES_HDR?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
  FrmStaticText(L_ID, $slice_id);
  FrmInputText("name", L_SLICE_NAME, $name, 99, 25, true);
  FrmInputText("slice_url", L_SLICE_URL, $slice_url, 254, 25, false);
  $ssiuri = ereg_replace("/admin/.*", "/slice.php3", $PHP_SELF);
  echo "<TR><TD colspan=2>" . L_SLICE_HINT . "<BR><pre>" . 
       "&lt;!--#include virtual=&quot;" . $ssiuri .
     "?slice_id=" . $slice_id . "&quot;--&gt;</pre></TD></TR>";

  FrmInputSelect("owner", L_OWNER, $slice_owners, $owner, false);
  if( !$owner ) {
    FrmInputText("new_owner", L_NEW_OWNER, $new_owner, 99, 25, false);
    FrmInputText("new_owner_email", L_NEW_OWNER_EMAIL, $new_owner_email, 99, 25, false);
  }  
  FrmInputText("d_listlen", L_D_LISTLEN, $d_listlen, 5, 5, true);
  if( $superadmin ) {
    FrmInputChBox("template", L_TEMPLATE, $template);
    FrmInputChBox("deleted", L_DELETED, $deleted);
  }  
  FrmInputSelect("permit_anonymous_post", L_PERMIT_ANONYMOUS_POST, $PERMS_STATE, $permit_anonymous_post, false);
  FrmInputSelect("permit_offline_fill", L_PERMIT_OFFLINE_FILL, $PERMS_STATE, $permit_offline_fill, false);
  FrmInputSelect("lang_file", L_LANG_FILE, $LANGUAGE_FILES, $lang_file, false);
?>
</table>
<tr><td align="center">
<?php
if($slice_id=="") {
  echo "<input type=hidden name=\"add\" value=1>";        // action
  echo "<input type=hidden name=\"Add_slice\" value=1>";  // detects new slice
  echo "<input type=hidden name=template_id value=\"". $set_template_id .'">';
  echo "<input type=submit name=insert value=\"". L_INSERT .'">';
}else{
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