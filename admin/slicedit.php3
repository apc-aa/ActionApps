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

if($template_slice_sel=="slice")          # new slice - template as slice 
  $template_id = $template_id2;
if( $template_id ) {
  $foo = explode("{", $template_id);
  $template_id = $foo[0];
  $slice_lang_file = $foo[1];
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
      $varset->add("name", "quoted", $name);
      $varset->add("owner", "unpacked", $owner);
      $varset->add("slice_url", "quoted", $slice_url);
      $varset->add("d_listlen", "number", $d_listlen);
      if( $superadmin ) {
        $varset->add("deleted", "number", $deleted);
        $varset->add("template", "number", $template);
      }  
      $varset->add("permit_anonymous_post", "number", $permit_anonymous_post);
      $varset->add("permit_offline_fill", "number", $permit_offline_fill);
      $varset->add("lang_file", "quoted", $lang_file);

      $SQL = "UPDATE slice SET ". $varset->makeUPDATE() . "WHERE id='$p_slice_id'";
      if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
        $err["DB"] = MsgErr("Can't change slice");
        break;
      }
    }
    else  // insert (add)
    {
        # get template data
      $varset->addArray( $SLICE_FIELDS_TEXT, $SLICE_FIELDS_NUM );
      $SQL = "SELECT * FROM slice WHERE id='". q_pack_id($template_id) ."'";
      $db->query($SQL);
      if( !$db->next_record() ) {
        $err["DB"] = MsgErr("Bad template id");
        break;
      }
      $varset->setFromArray($db->Record);
      $slice_id = new_id();
      $varset->set("id", $slice_id, "unpacked");
      $varset->set("created_by", $auth->auth["uid"], "text");
      $varset->set("created_at", now(), "text");
      $varset->set("name", $name, "quoted");
      $varset->set("owner", $owner, "unpacked");
      $varset->set("slice_url", $slice_url, "quoted");
      $varset->set("d_listlen", $d_listlen, "number");
      $varset->set("deleted", $deleted, "number");
      $varset->set("template", $template, "number");
      $varset->set("permit_anonymous_post", $permit_anonymous_post, "number");
      $varset->set("permit_offline_fill", $permit_offline_fill, "number");
      $varset->set("lang_file", $lang_file, "quoted");

         # create new slice
      if( !$db->query("INSERT INTO slice" . $varset->makeINSERT() )) {
        $err["DB"] .= MsgErr("Can't add slice");
        break;
      }

         # copy fields
      $db2  = new DB_AA;         
      $SQL = "SELECT * FROM field WHERE slice_id='". q_pack_id($template_id) ."'";
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

        # create categories

        # find name for category group_id
      $SQL = "SELECT input_show_func FROM field 
               WHERE slice_id='". q_pack_id($template_id) ."' 
                 AND input_show_func LIKE '%SliceCateg-%'";
      $db->query($SQL);
      $max=0;
      while( $db->next_record() ) {
        $foo = $db->f(input_show_func);    
          # get 15 from sel:SliceCateg-00015 
        $num = (int) substr($foo, strpos($foo, ":") + 12, 5);
        $max = max($max,$num);
      }  
      
      $max++;
      $new_group_name = "SliceCateg-". substr("00000$max", -5);

        
        # create new constant group and assign
      $SQL = "UPDATE field SET input_show_func='sel:$new_group_name'
               WHERE slice_id='". q_pack_id($slice_id) ."' 
                 AND id LIKE 'category%'";
      $db->query($SQL);

        # insert three default categories
      $db->query("INSERT INTO constant 
                  VALUES( '" . q_pack_id(new_id()) ."',
                          '$new_group_name', 
                          '". L_SOME_CATEGORY ."',
                          '". L_SOME_CATEGORY ."',
                          'AA-predefined054',
                          '1000')" );
      $db->query("INSERT INTO constant 
                  VALUES( '" . q_pack_id(new_id()) ."',
                          '$new_group_name', 
                          '". L_SOME_CATEGORY ."',
                          '". L_SOME_CATEGORY ."',
                          'AA-predefined054',
                          '1000')" );
      $db->query("INSERT INTO constant 
                  VALUES( '" . q_pack_id(new_id()) ."',
                          '$new_group_name', 
                          '". L_SOME_CATEGORY ."',
                          '". L_SOME_CATEGORY ."',
                          'AA-predefined054',
                          '1000')" );

         # insert constant group name
      $db->query("INSERT INTO constant 
                  VALUES( '" . q_pack_id(new_id()) ."',
                          'lt_groupNames', 
                          '". L_SOME_CATEGORY ."',
                          '$new_group_name',
                          '". quote(substr($name,0,50)) ."',
                          '1000')" );
        
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
    go_url($sess->url(self_base() . "slicedit.php3?$netscape"));
  }
}

$foo_source = ( ( $slice_id=="" ) ? $template_id : $slice_id);
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
$SQL= " SELECT id, name FROM slice_owner";
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
  $xx = ($slice_id!="");
  $show = Array("main"=>false, "slicedel"=>$xx, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx,
                "views"=>$xx, "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx, "mapping"=>$xx);
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
  echo "<input type=hidden name=template_id value=\"". $template_id .'">';
  echo "<input type=submit name=insert value=\"". L_INSERT .'">';
}else{
  echo "<input type=hidden name=\"update\" value=1>";
  echo '<input type=submit name=update value="'. L_UPDATE .'">&nbsp;&nbsp;';
  echo '<input type=reset value="'. L_RESET .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. L_CANCEL .'">';
}

/*
$Log$
Revision 1.21  2001/05/21 13:52:32  honzam
New "Field mapping" feature for internal slice to slice feeding

Revision 1.20  2001/05/18 13:50:09  honzam
better Message Page handling (not so much)

Revision 1.19  2001/05/10 10:01:43  honzam
New spanish language files, removed <form enctype parameter where not needed, better number validation

Revision 1.18  2001/03/20 16:01:13  honzam
HTML / Plain text selection implemented
Standardized content management for items - filler, itemedit, offline, feeding

Revision 1.17  2001/02/26 17:26:08  honzam
color profiles

Revision 1.16  2001/02/26 12:22:30  madebeer
moved hint on .shtml to slicedit
changed default item manager design

Revision 1.15  2001/01/23 23:58:03  honzam
Aliases setings support, bug in permissions fixed (can't login not super user), help texts for aliases page

Revision 1.13  2001/01/08 13:31:58  honzam
Small bugfixes

Revision 1.12  2000/12/23 19:56:02  honzam
Multiple fulltext item view on one page, bugfixes from merge v1.2.3 to v1.5.2

Revision 1.11  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.10  2000/10/10 10:06:54  honzam
Database operations result checking. Messages abstraction via MsgOK(), MsgErr()

Revision 1.9  2000/08/17 15:14:32  honzam
new possibility to redirect item displaying (for database changes see CHANGES)

Revision 1.8  2000/08/03 12:49:22  kzajicek
English editing

Revision 1.7  2000/08/03 12:34:27  honzam
Default values for new slice defined.

Revision 1.6  2000/07/26 14:36:59  honzam
default WDDX value is set to config field for new slices

Revision 1.5  2000/07/14 16:11:29  kzajicek
Just better comment

Revision 1.4  2000/07/13 09:19:01  kzajicek
Variables $created_by and $created_at are initialized later, so
the actual effect was that Updates zeroized the database values! In fact
the database fields created_by and created_at should remain constant.
Do we need changed_by and changed_at?

Revision 1.3  2000/07/07 21:37:45  honzam
Slice ID is displayed

Revision 1.1.1.1  2000/06/21 18:40:05  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:56  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.17  2000/06/12 19:58:25  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.16  2000/06/09 15:14:10  honzama
New configurable admin interface

Revision 1.15  2000/04/24 16:45:03  honzama
New usermanagement interface.

Revision 1.14  2000/03/22 09:36:44  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/
?>
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()?>