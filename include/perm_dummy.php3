<?php  // perm_dummy - pure permission functions - anyone can do anything
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


//## API functions //##

function AuthenticateUsername($username, $password, $flags = 0) {
  return "foobar";
}

function AddUser($user, $flags = 0) {
  return "foobar";
}

function DelUser ($user_id, $flags = 0) {
  return true;
}

function GetUser ($user_id, $flags = 0) {
  return array("");
}

function ChangeUser ($user_id, $flags = 0) {
  return true;
}

function FindUsers ($pattern, $flags = 0) {
  return array("");
}

function GetMembership ($id, $flags = 0) {
  return array("");
}

function AddGroup ($group, $flags = 0) {
  return "foobar";
}

function DelGroup ($group_id, $flags = 0) {
  return true;
}

function GetGroup ($group_id, $flags = 0) {
  return array("");
}

function ChangeGroup ($group_id, $flags = 0) {
  return true;
}


function FindGroups ($pattern, $flags = 0) {
  return array("");
}

function AddGroupMember ($group_id, $id, $flags = 0) {
  return true;
}

function DelGroupMember ($group_id, $id, $flags = 0) {
  return true;
}

function GetGroupMembers ($group_id, $flags = 0) {
  return array("");
}

function AddPermObject ($objectID, $objectType, $flags = 0) {
  return true;
}

function DelPermObject ($objectID, $objectType, $flags = 0) {
  return true;
}

function AddPerm($id, $objectID, $objectType, $perm, $flags = 0) {
  return true;
}

function DelPerm ($id, $objectID, $objectType, $flags = 0) {
  return true;
}

function ChangePerm ($id, $objectID, $objectType, $perm, $flags = 0) {
  return true;
}

function GetObjectPerms ($objectID, $objectType, $flags = 0) {
  return array("");
}

function GetIDPerms ($id, $objectType, $flags = 0) {
  return array("");
}
?>