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


# perm_core.php3 
# Definitions and functions used no matter which one perm_*.php3 backend 
# is used.
#

define("MAX_GROUPS_DEEP", 16);   // Maximum number of nested groups (user belongs to group1, group1 to group2 ...)
define("MAX_ENTRIES_SHOWN",5);   // Maximum number of shown users in search for users/groups

# permission types for aa object
define("PS_ADD", "A");             // permission to add slice
define("PS_MANAGE_ALL_SLICES", "M"); // permission to edit all slices 
                           // (this permissin is useable, when you want credit some rights
                           // to given user for all slices (put him into root - aa object), 
                           // but you don't want him to see all slices.

# permission types for slice object
//define("PS_ADD", "A");           // aa permission (see top)
define("PS_DELETE_ITEMS", "B");    // permission to delete items
define("PS_CATEGORY", "C");        // permission to change slice categories
define("PS_FEEDING", "D");         // permission to change properties
define("PS_EDIT", "E");            // permission to set slice properties
define("PS_FIELDS", "F");          // permission to edit fields defauts
define("PS_CONFIG", "H");          // permission to configure slice (show/hide columns in admin interface ...)
define("PS_ADD_USER", "I");       // permission to add existing user to slice
define("PS_EDIT_SELF_ITEMS", "L"); // permission to change self-written items
//define("PS_MANAGE_ALL_SLICES", "M"); // aa permission (see top)
define("PS_NEW_USER", "N");       // permission to create new user
define("PS_COMPACT", "O");         // permission to change slice compact view
define("PS_ITEMS2ACT", "P");       // permission to move item to approved bin
define("PS_ITEMS2HOLD", "Q");      // permission to move item to holding bin
define("PS_ITEMS2TRASH", "R");     // permission to move item to trash bin
define("PS_SEARCH", "S");          // permission to change search form settings
define("PS_EDIT_ALL_ITEMS", "T");  // permission to change all items
define("PS_USERS", "U");           // permission to manage users
define("PS_FULLTEXT", "X");        // permission to change item fulltext view

// numbers 1,2,... in perms record (objectclass apcacl atribute apcaci in LDAP)
// on resolving permissions are this numbers replaced by real permissions defined in $perms_roles_perms
$perms_roles_id = array("AUTHOR"=>"1",            // can write items and edit his items
                        "EDITOR"=>"2",            // AUTHOR + can edit and manage all items
                        "ADMINISTRATOR"=>"3",     // EDITOR + can change slice properties
                        "SUPER"=>"4");            // ADMINISTRATOR + can set any properties for any slice

$perms_roles_perms = array("AUTHOR"=>PS_EDIT_SELF_ITEMS,
                           "EDITOR"=>PS_EDIT_SELF_ITEMS.
                                     PS_ITEMS2ACT.
                                     PS_ITEMS2HOLD.
                                     PS_ITEMS2TRASH.
                                     PS_EDIT_ALL_ITEMS,           
                           "ADMINISTRATOR"=>PS_EDIT.
                                            PS_CATEGORY.
                                            PS_FIELDS.
                                            PS_SEARCH.
                                            PS_USERS.
                                            PS_COMPACT.
                                            PS_FULLTEXT.
                                            PS_FEEDING.           
                                            PS_ADD_USER.
                                            PS_DELETE_ITEMS.
                                            PS_ITEMS2ACT.
                                            PS_ITEMS2HOLD.
                                            PS_ITEMS2TRASH.
                                            PS_EDIT_SELF_ITEMS.
                                            PS_CONFIG.
                                            PS_EDIT_ALL_ITEMS,
                           "SUPER"=>PS_EDIT.
                                    PS_ADD.
                                    PS_CATEGORY.
                                    PS_FIELDS.
                                    PS_SEARCH.
                                    PS_USERS.
                                    PS_COMPACT.
                                    PS_FULLTEXT.
                                    PS_FEEDING.           
                                    PS_ADD_USER.
                                    PS_DELETE_ITEMS.
                                    PS_ITEMS2ACT.
                                    PS_ITEMS2HOLD.
                                    PS_ITEMS2TRASH.
                                    PS_EDIT_SELF_ITEMS.
                                    PS_EDIT_ALL_ITEMS.
                                    PS_NEW_USER.
                                    PS_CONFIG.
                                    PS_MANAGE_ALL_SLICES);

// replaces roles with apropriate perms
// substitute role identifiers (1,2,3,4) with his permissions (E,A,R ...)
function ResolvePerms($perms) {
  global $perms_roles_id, $perms_roles_perms;

  reset($perms_roles_id);
  while( list($role, $letter) = each($perms_roles_id)) 
    $perms = str_replace($letter, $perms_roles_perms[$role], $perms);
  return $perms;
}  

// save all permissions for specified user to session variable
function CachePermissions($user_id) {
  global $permission_uid, $permission_to_slice, $permission_to_aa, $sess, 
         $perms_roles_id, $r_superuser;

  $sess->register(permission_uid);
  $sess->register(permission_to_slice);
  $sess->register(permission_to_aa);
  $sess->register(r_superuser);

  $permission_uid = $user_id;
  $permission_to_slice = GetIDPerms ($permission_uid, "slice");
  $permission_to_aa = GetIDPerms ($permission_uid, "aa");     // aa is parent of all slices
  if( !is_array($permission_to_slice) )  //convert to arrays
    $permission_to_slice = array();
  if( !is_array($permission_to_aa) )
    $permission_to_aa = array();
  
  # Resolve all permission (convert roles into perms) 
  reset($permission_to_slice); 
  while( list($key,$val) = each($permission_to_slice) ) 
    $permission_to_slice[$key] = ResolvePerms($val);       

  reset($permission_to_aa); 
  while( list($key,$val) = each($permission_to_aa) ) {
    if( IsPerm($val, $perms_roles_id[SUPER]) )
      $r_superuser[$key] = true;
    $permission_to_aa[$key] = ResolvePerms($val);       
  }  
}  

// function check, if specified $perm is in $perms list
function IsPerm($perms, $perm){
  if( !$perms || !$perm )
    return false;
  return strstr($perms,$perm);
}

// Check if user has specified permissions
function CheckPerms( $user_id, $objType, $objID, $perm) {
  global $permission_uid, $permission_to_slice, $permission_to_aa;

  if($permission_uid != $user_id) 
    CachePermissions($user_id);
    
  switch($objType) {
    case "aa":  
      return IsPerm($permission_to_aa[$objID], $perm);
    case "slice": 
      return IsPerm(JoinAA_SlicePerm($permission_to_slice[$objID], $permission_to_aa[AA_ID]), $perm);
    default: return false;
  }
}  

// Returns users's permissions to specified slice
// if $whole is true, then consider membership in groups 
function GetSlicePerms( $user_id, $objID, $whole=true) {
  $slice_perms = GetIDPerms ($user_id, "slice", ($whole ? 0 : 1));
  $aa_perms = GetIDPerms ($user_id, "aa", ($whole ? 0 : 1));
  return JoinAA_SlicePerm($slice_perms[$objID], $aa_perms[AA_ID]);
}  
                                    
// function returns "E" if both permission are equal, "G" if perms1 
//  are more powerfull than perm2, "L" if perm2 are more powerful than perm1
function ComparePerms($perms1, $perms2) {
  $perms1 = ResolvePerms($perms1);
  $perms2 = ResolvePerms($perms2);

  if( strlen($perms1) == strspn($perms1, $perms2) ) {
    if( strlen($perms2) == strspn($perms2, $perms1) )
      return "E";       // perms are equal
     else
      return "L";
  } else 
    return "G";    
}    

// Resolves precedence issues between slice-specific permissions
// and global access rigths (rights to object aa).
// Slice-specific perms take precedence except the SUPER access level
function JoinAA_SlicePerm($slice_perm, $aa_perm) {
  global $perms_roles_perms;
  if (ComparePerms($aa_perm, $perms_roles_perms["SUPER"])=="E") {
    return $aa_perm;
  } else {
    return ($slice_perm ? $slice_perm : $aa_perm);
  }
}  

function GetUsersSlices( $user_id ) {
  global $permission_uid, $permission_to_slice, $permission_to_aa;

  if($permission_uid != $user_id) 
    CachePermissions($user_id);

  if( IsPerm($permission_to_aa[AA_ID], PS_MANAGE_ALL_SLICES) )
    return "all";

  return  $permission_to_slice;
}  

// shortcut for slice permission checking
function IfSlPerm($perm) {
  global $auth, $slice_id;
  return CheckPerms( $auth->auth["uid"], "slice", $slice_id, $perm);
}  

// Checks if logged user is superadmin
function IsSuperadmin() {
  global $auth, $r_superuser;
    # check all superadmin's global permissions
  if($permission_uid != $auth->auth["uid"]) 
    CachePermissions($auth->auth["uid"]);
  return $r_superuser[AA_ID];
}

/*
$Log$
Revision 1.8  2001/03/20 16:10:37  honzam
Standardized content management for items - filler, itemedit, offline, feeding
Better feeding support

Revision 1.7  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...

Revision 1.6  2001/02/26 17:22:30  honzam
color profiles, itemmanager interface changes

Revision 1.5  2001/01/22 17:32:49  honzam
pagecache, logs, bugfixes (see CHANGES from v1.5.2 to v1.5.3)

Revision 1.3  2000/08/01 14:32:33  kzajicek
AA-super access level takes precedence

Revision 1.2  2000/07/28 15:11:41  kzajicek
Functions DeleteUserComplete and buggy DeleteGroupComlete are now
obsolete, DelUser and DelGroup do the job.

Revision 1.1.1.1  2000/06/21 18:40:43  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:25  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.9  2000/06/12 19:58:36  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.8  2000/06/09 15:14:12  honzama
New configurable admin interface

Revision 1.7  2000/04/24 16:50:34  honzama
New usermanagement interface.

Revision 1.6  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc
*/
?>