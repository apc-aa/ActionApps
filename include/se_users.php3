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

# expected $slice_id for edit slice
# optionaly $Msg to show under <h1>Headline</h1>
# (typicaly: Category update successful)

$editor_perms = GetSlicePerms($auth->auth["uid"], $slice_id);

// Function decides whether current user can change role
// of specified user. Only allowed when $editor_perm (current user) is greater 
// than $perm (user's role) and $perm_role (new user's role)
function CanChangeRole ($user_perm, $editor_perm, $role_perm) {
  if ((ComparePerms($editor_perm, $user_perm)=="G") &&
      (ComparePerms($editor_perm, $role_perm)=="G")) {
    return true;
  } else {
    return false;
  }
}    
  
function ChangeRole () {
  global $UsrAdd, $UsrDel, $slice_id, $editor_perms, $role, $perms_roles, $db;   
  $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
  
  if( $UsrAdd ) {
    if( CanChangeRole( GetSlicePerms($UsrAdd, $slice_id, false),
                       $editor_perms,
                       $perms_roles[$role]['perm']) ) {
      echo serialize (array ($UsrAdd,$slice_id,$perms_roles[$role]['id'],$role));
      AddPerm($UsrAdd, $slice_id, "slice", $perms_roles[$role]['id']);
      $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
    }
  } elseif( $UsrDel ) {
    if( CanChangeRole(GetSlicePerms($UsrDel, $slice_id, false),
                      $editor_perms,
                      $perms_roles["AUTHOR"]['perm']) )  // smallest permission
      DelPerm($UsrDel, $slice_id, "slice");
      $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
  }
}
