<?php  // perm_dummy - pure permission functions - anyone can do anything
/**
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/



//## API functions //##
/** AuthenticateUsername function
 * @param $username
 * @param $password
 * @param $flags
 */
function AuthenticateUsernameCurrent($username, $password, $flags = 0) {
  return "foobar";
}
/** AddUser function
 * @param $user
 * @param $flags
 */
function AddUser($user, $flags = 0) {
  return "foobar";
}
/** DelUser function
 * @param $user_id
 * @param $flags
 */
function DelUser($user_id, $flags = 0) {
  return true;
}
/** ChangeUser
 * @param $user_id
 * @param $flags
 */
function ChangeUser($user_id, $flags = 0) {
  return true;
}
/** GetMembership function
 * @param $id
 * @param $flags
 */
function GetMembership($id, $flags = 0) {
  return array("");
}
/** AddGroup function
 * @param $group
 * @param $flags
 */
function AddGroup($group, $flags = 0) {
  return "foobar";
}
/** DelGroup function
 * @param $group_id
 * @param $flags
 */
function DelGroup($group_id, $flags = 0) {
  return true;
}
/** GetGroup function
 * @param $group_id
 * @param $flags
 */
function GetGroup($group_id, $flags = 0) {
  return array("");
}
/** ChangeGroup function
 * @param $group_id
 * @param $flags
 */
function ChangeGroup($group_id, $flags = 0) {
  return true;
}
/** FindGroups function
 * @param $pattern
 * @param $flags
 */
function FindGroups($pattern, $flags = 0) {
  return array("");
}
/** AddGroupMember function
 * @param $group_id
 * @param $id
 * @param $flags
 */
function AddGroupMember($group_id, $id, $flags = 0) {
  return true;
}
/** DelGroupMember function
 * @param $group_id
 * @param $id
 * @param $flags
 */
function DelGroupMember($group_id, $id, $flags = 0) {
  return true;
}
/** GetGroupMembers function
 * @param $group_id
 * @param $flags
 */
function GetGroupMembers($group_id, $flags = 0) {
  return array("");
}
/** AddPermObject function
 * @param $objectID
 * @param $objectType
 * @param $flags
 */
function AddPermObject($objectID, $objectType, $flags = 0) {
  return true;
}
/** DelPermObject function
 * @param $objectID
 * @param $objectType
 * @param $flags
 */
function DelPermObject($objectID, $objectType, $flags = 0) {
  return true;
}
/** AddPerm function
 * @param $id
 * @param $objectID
 * @param $objectType
 * @param $perm
 * @param $flags
 */
function AddPerm($id, $objectID, $objectType, $perm, $flags = 0) {
  return true;
}
/** DelPerm function
 * @param $id
 * @param $objectID
 * @param $objectType
 * @param $flags
 */
function DelPerm($id, $objectID, $objectType, $flags = 0) {
  return true;
}
/** ChangePerm function
 * @param $id
 * @param $objectID
 * @param $objectType
 * @param $perm
 * @param $flags
 */
function ChangePerm($id, $objectID, $objectType, $perm, $flags = 0) {
  return true;
}
/** GetObjectPerms function
 * @param $objectID
 * @param $objectType
 * @param $flags
 */
function GetObjectPerms($objectID, $objectType, $flags = 0) {
  return array("");
}
/** GetIDPerms function
 * @param $id
 * @param $objectType
 * @param $flags
 */
function GetIDPerms($id, $objectType, $flags = 0) {
  return array("");
}
?>