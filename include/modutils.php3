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

# Misc functions used with modules

# Adds new owner to database
# returns unpacked owner_id or false (on error)
function CreateNewOwner($new_owner, $new_owner_email, &$err, $varset, $db) {
  $varset->clear();
  ValidateInput("new_owner", L_NEW_OWNER, $new_owner, $err, true, "text");
  ValidateInput("new_owner_email", L_NEW_OWNER_EMAIL, $new_owner_email, $err, true, "email");

  if( count($err) > 1)
    return false;

  $owner = new_id();
  $varset->set("id", $owner, "unpacked");
  $varset->set("name", $new_owner, "text");
  $varset->set("email", $new_owner_email, "text");

    # create new owner
  if( !$db->query("INSERT INTO slice_owner " . $varset->makeINSERT() )) {
    $err["DB"] .= MsgErr("Can't add owner");
    return false;
  }
  $varset->clear();
  return $owner;
}

# Validate all fields needed for module table (name, slice_url, lang_file, owner)
function ValidateModuleFields( $name, $slice_url, $lang_file, $owner, &$err ) {
  ValidateInput("name", L_SLICE_NAME, $name, $err, true, "text");
  ValidateInput("owner", L_OWNER, $owner, $err, false, "id");
  ValidateInput("slice_url", L_SLICE_URL, $slice_url, $err, false, "url");
  ValidateInput("lang_file", L_LANG_FILE, $lang_file, $err, true, "text");
}

# Updates or inserts all necessary fields to module table
function WriteModuleFields( $module_id, $db, $varset, $superadmin, $auth,
                            $type, $name, $slice_url, $lang_file, $owner, $deleted ) {
  $varset->clear();
  if( $module_id )  {
    $p_module_id = q_pack_id($module_id);
    $varset->add("name", "quoted", $name);
    $varset->add("slice_url", "quoted", $slice_url);
    $varset->add("lang_file", "quoted", $lang_file);
    $varset->add("owner", "unpacked", $owner);
    if( $superadmin )
      $varset->add("deleted", "number", $deleted);

    $SQL = "UPDATE module SET ". $varset->makeUPDATE() . " WHERE id='$p_module_id'";
    if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
      $err["DB"] = MsgErr("Can't change module");
      return false;
    }

    $GLOBALS['r_slice_headline'] = stripslashes($name);
    $GLOBALS['r_lang_file'][$module_id] = stripslashes($lang_file);
    $GLOBALS['r_slice_view_url'] = ($slice_url=="" ? $sess->url("../slice.php3"). "&slice_id=$slice_id&encap=false"
                                    : stripslashes($slice_url));
  } else {  // insert (add)
    $module_id = new_id();
    $varset->set("id", $module_id, "unpacked");
    $varset->set("created_by", $auth->auth["uid"], "text");
    $varset->set("created_at", now(), "text");
    $varset->set("name", $name, "quoted");
    $varset->set("owner", $owner, "unpacked");
    $varset->set("slice_url", $slice_url, "quoted");
    $varset->set("deleted", $deleted, "number");
    $varset->set("lang_file", $lang_file, "quoted");
    $varset->set("type", $type, "quoted");

    if( !$db->query("INSERT INTO module" . $varset->makeINSERT() )) {
      $err["DB"] .= MsgErr("Can't add module");
      return false;
    }

    $GLOBALS['r_lang_file'][$module_id] = $lang_file;
    AddPermObject($module_id, "slice");    // no special permission added - only superuser can access
  }
  return $module_id;
}

# fills variables from module and owners table
function GetModuleFields( $source_id, $db ) {
  # lookup owners
  $slice_owners[0] = L_SELECT_OWNER;
  $SQL= " SELECT id, name FROM slice_owner ORDER BY name";
  $db->query($SQL);
  while ($db->next_record()) {
    $slice_owners[unpack_id($db->f(id))] = $db->f(name);
  }

  $p_source_id = q_pack_id( $source_id );
  $SQL= "SELECT * FROM module WHERE id='$p_source_id'";
  $db->query($SQL);
  if ($db->next_record())
    return array( $db->f('name'),
                  $db->f('slice_url'),
                  $db->f('lang_file'),
                  unpack_id($db->f('owner')),
                  $db->f('deleted'),
                  $slice_owners );
  return false;
}


# check if module can be deleted
function ExitIfCantDelete( $del, $db ) {
  $p_del = q_pack_id($del);
  $SQL = "SELECT deleted FROM module WHERE id='$p_del'";
  $db->query($SQL);
  if( !$db->next_record() )
    go_url($sess->url(self_base() . "slicedel.php3"), "Msg=". L_NO_SUCH_MODULE);
  if( $db->f(deleted) < 1 )
    go_url($sess->url(self_base() . "slicedel.php3"), "Msg=". L_NO_DELETED_MODULE);
}

# delete module from module table
function DeleteModule( $del, $db ) {
  $p_del = q_pack_id($del);
  $SQL = "DELETE LOW_PRIORITY FROM module WHERE id='$p_del'";
  $db->query($SQL);
}


?>
