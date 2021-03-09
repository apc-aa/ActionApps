<?php
/**
 *
 * PHP version 7.2+
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
 * @author    Michael de Beer, Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;

require_once __DIR__."/perm_core.php3";

//php_sql - functions for working with permissions with SQL

/* INSTALL notes
   to come -- basically createa a database with these tables/fields:

users        membership     perms
  id            groupid        object_type
  type          memberid       objectid
  password                     userid
  mail                         perm
  name
  givenname
  sn

*/

// ----------------------------- USERS --------------------------------------

// users and groups are really the same thing, except
//   groups have null for the attributes  password & mail
//   (also marked by  'type'

/** AddUser function
 * @param $user
 * @param $flags
 * creates new person in permission system
 * @return bool
 */
function AddUser($user, $flags = 0) {
    if (! AA::$perm->isUsernameFree($user["uid"])) {
        return false;
    }
    $varset = new CVarset([
        ['type'       , "User"],
                                ['uid'        , $user["uid"]],
                                ['mail'       , (is_array($user['mail']) ? $user['mail'][0] : $user['mail'])],
                                ['name'       , $user["givenname"]." ".$user["sn"]],
                                ['sn'         , $user["sn"]],
                                ['givenname'  , $user["givenname"]],
                                ['description', ''],
                                ['password'   , AA_Permsystem_Sql::generatePasswordHash($user["userpassword"])]
    ]);
    $varset->doInsert('users');
    return $varset->last_insert_id();
}

/** DelUser function
 * @param $user_id
 * @param $flags
 *  deletes an user in permission system
 *  $user_id is DN
 * @return bool
 */
function DelUser($user_id, $flags = 3) {
     // To keep integrity of AA we should also delete all references
     // to this group
     if ($flags & 1) {
         // cancel this group's membership in other groups
         DB_AA::delete('membership', [['memberid', $user_id]]);
     }
     if ($flags & 2) {
         // cancel direct permissions
         DB_AA::delete('perms', [['userid', $user_id]]);
     }
     // cancel the user
     DB_AA::delete('users', [['id', $user_id]]);
     return true;
}

/** ChangeUser function
 * @param $user
 * @param $flags
 *  changes user entry in permission system
 * @return bool
 */
function ChangeUser($user, $flags = 0) {
    $varset = new CVarset([
                                ['mail'     , (is_array($user['mail']) ? $user['mail'][0] : $user['mail'])],
                                ['name'     , $user["givenname"]." ".$user["sn"]],
                                ['sn'       , $user["sn"]],
                                ['givenname', $user["givenname"]]
        ]
                          );
    if ($user["userpassword"]) {
        $varset->add("password", "text", AA_Permsystem_Sql::generatePasswordHash($user["userpassword"]));
    }
    $varset->addkey("id", "text", $user["uid"]);
    $varset->doUpdate('users', null, $user["uid"]);
    return true;
}

// ----------------------------- GROUPS -----------------------------------

/** AddGroup function
 * creates new group in permission system
 * @param $group is an array ("name", "description", ...)
 * @param $flags
 * @return
 */
function AddGroup($group, $flags = 0) {
    // creates new person in permission system
    $varset = new CVarset([
        ['type'       , 'Group'],
                                ['uid'        , ''],
                                ['mail'       , ''],
                                ['name'       , $group['name']],
                                ['sn'         , ''],
                                ['givenname'  , ''],
                                ['description', $group['description']],
                                ['password'   , 'crypt will never return this']
    ]);
    $varset->doInsert('users');
    return $varset->last_insert_id();
}

/** DelGroup function
 *  deletes an group in permission system
 * @param $group_id is DN
 * @param $flags
 * @return bool
 */
function DelGroup($group_id, $flags = 3) {
     // cancel other people's membership in this group
     DB_AA::delete('membership', [['groupid', $group_id]]);

     // To keep integrity of AA we should also delete all references
     // to this group
     if ($flags & 1) {
         // cancel this group's membership in other groups
         DB_AA::delete('membership', [['memberid', $group_id]]);
     }
     if ($flags & 2) {
         // cancel direct permissions
         DB_AA::delete('perms', [['userid', $group_id]]);
     }
     // cancel the group
     DB_AA::delete('users', [['id', $group_id]]);
     return true;
}

/** ChangeGroup function
 *  changes fields about group
 * @param $group is an array ("name", "description", ...)
 * @param $flags
 * @return bool
 */
function ChangeGroup($group, $flags = 0) {
    $varset = new CVarset([
                                ['name'       , $group["name"]],
                                ['description', $group["description"]]
        ]
                          );
    $varset->addkey("id", "text", $group["uid"]);
    $varset->doUpdate('users', null, $group["uid"]);
    return true;
}

// ----------------------------- MEMBERSHIP ---------------------------------
/** AddGroupMember function
 * @param $group_id
 * @param $id
 * @param $flags
 */
function AddGroupMember($group_id, $id, $flags = 0) {
    if ($group_id AND $id) {
        DelGroupMember($group_id, $id);
        $varset = new CVarset([['groupid',$group_id], ['memberid',$id]]);
        $varset->doInsert('membership');
    }
}

/** DelGroupMember function
 * @param $group_id
 * @param $id
 * @param $flags
 */
function DelGroupMember($group_id, $id, $flags = 0) {
    DB_AA::delete('membership', [['groupid', $group_id], ['memberid', $id]]);
}

// ----------------------------- PERMS -----------------------------------

/** AddPermObject function
 *  creates a new object
 * @param $objectID
 * @param $objectType
 * @param $flags
 * @return bool
 */
function AddPermObject($objectID, $objectType, $flags = 0) {
    // we don't need to do that in mysql
    return true;
}

/** DelPermObject function
 *  deletes an ACL object in permission system
 * @param $objectID
 * @param $objectType
 * @param $flags
 * @return bool
 */
function DelPermObject($objectID, $objectType, $flags = 0) {
    // we don't need to do that in mysql
    return true;
}

/** AddPerm function
 * append permission to existing object
 * @param $id
 * @param $objectID
 * @param $objectType
 * @param $perm
 * @param $flags
 * @return bool
 */
function AddPerm($id, $objectID, $object_type, $perm, $flags = 0) {
    DelPerm($id, $objectID, $object_type);
    $varset = new CVarset([
        ['object_type',$object_type],
                                ['objectid',$objectID],
                                ['userid',$id],
                                ['perm',$perm]
    ]);
    return $varset->doInsert('perms');
}

/** DelPerm function
 * @param $id
 * @param $objectID
 * @param $objectType
 * @param $flags
 */
function DelPerm($id, $objectID, $object_type, $flags = 0) {
    DB_AA::delete('perms', [['object_type', $object_type], ['objectid', $objectID], ['userid', $id]]);
}

/** ChangePerm function
 * @param $id
 * @param $objectID
 * @param $objectType
 * @param $perm
 * @param $flags
 */
function ChangePerm($id, $objectID, $objectType, $perm, $flags = 0) {
    return AddPerm($id, $objectID, $objectType, $perm);
}


//#############################################################################

class AA_Permsystem_Sql extends AA_Permsystem {

    /** true, if the system is able to store permissins for groups and users (even foreign users and groups)
     *  SQL and LDAP is able to store it, Reader not. */
    function storesGeneralPerms()                               { return true; }

    /** true, if the User data (name, mail, ..) could be edited on AA Permission page */
    function isUserEditable()                                   { return true; }


    /** userIdFormatMatches - is user id in correct format?
     *  we MUST use specific UIDs for every single Permission Type
     *  (it MUST be clear, which perm system is used just from the format of UID)
     */
    function userIdFormatMatches($uid) {
        // SQL perms - we are using numbers
        return ctype_digit((string) $uid);
    }

    /** authenticateUsername function
     * @param        $username
     * @param        $password
     * @param string $code2fa
     * @return false|string uid if user is authenticated, else false.
     */
    public function authenticateUsername($username, $password, $code2fa = '') {
        if ($user = DB_AA::select1('', 'SELECT id, password FROM `users`', [[strpos($username, "@") ? 'mail' : 'uid', $username]])) {
            return AA_Perm::comparePwds($password, $user['password']) ? $user['id'] : false;
        }
        return false;
    }

    /** isUsernameFree function
     *  Looks whether the username name is not yet used.
     * @param $username
     * @return bool
     */
    function isUsernameFree($username) {
        return !(DB_AA::select1('', 'SELECT uid FROM `users`', [['uid', $username]]));
    }

    /** findUsernames function
     * @param $pattern
     * @return array - list of users which corresponds to mask $pattern
     */
    function findUsernames($pattern) {
        $pattern = quote($pattern);
        return DB_AA::select(['id'=> ['name','mail']], "SELECT id, CONCAT(givenname, ' ', sn) AS name, mail FROM `users` WHERE ( name  LIKE '%$pattern%' OR mail LIKE '%$pattern%' OR uid LIKE '%$pattern%') AND ( type = '".quote(_m("User"))."' OR type = 'User')");
    }

    /** findUserByLogin function
     * @param $user_login
     * @return array|bool
     */
    function findUserByLogin($user_login) {
        return DB_AA::select1(['id' => ['name', 'mail']], "SELECT id, CONCAT(givenname, ' ', sn) AS name, mail FROM `users`", [['uid', $user_login]]);
    }


    /** findGroups function
     * @param $pattern
     * @return array - list of groups which corresponds to mask $pattern
     */
    function findGroups($pattern) {
        return DB_AA::select(['id'=> ['name']], "SELECT id, name FROM `users` WHERE name LIKE '%".quote($pattern)."%' AND (type = '".quote(_m("Group"))."' OR type = 'Group')");
    }

    /** getGroup function
     *  @param $user_id
     *  @return array(uid, name, description) or false if not found
     */
    function getGroup($group_id) {
        return DB_AA::select1('', 'SELECT id AS uid, name, description FROM `users`', [['id', $group_id, 'i']]);
    }

    /** getIDsInfo function
     * @param $id
     * @param $ds
     * @return false|array containing basic information on $id (user DN or group DN)
     * or false if ID does not exist
     * array("mail" => $mail, "name" => $cn, "type" => "User" : "Group")
     */
    function getIDsInfo($id) {
        if ($this->userIdFormatMatches($id) AND ($user = DB_AA::select1('', 'SELECT id, name, givenname, uid AS login, sn, mail, type FROM `users`', [['id', $id, 'i']]))) {
            if ($user['type'] == _m("User") OR ($user['type'] == "User")) {
                $user['type'] = 'User';
                $user['name'] = $user['givenname']." ".$user['sn'];
            } else {
                $user['type'] = 'Group';
            }
            $user['mails'] = [$user['mail']];
            return $user;
        }
        return false;
    }

    /** getGroupMembers function
     * @param $group_id
     * @todo  Make this recursive friendly?
     * @return array
     */
    function getGroupMembers($group_id) {
        $ret = [];
        $ids = DB_AA::select('memberid', "SELECT memberid FROM `membership`", [['groupid', $group_id, 'i']]);
        foreach($ids as $id) {
            $ret[$id] = $this->getIDsInfo($id);
        }
        return $ret;
    }

    /** getMembership function
     * @param $id
     * @param $flags - use to obey group in groups?
     * @return array of group_ids, where id (group or user) is a member
     */
    function getMembership($id, $flags = 0) {

        // groups now can contain also Readers
        // if (!ctype_digit((string)$id)) { return []; }

        $all_groups  = [];
        $last_groups = [$id];

        $deep_counter = 0;

        do {
            if ($deep_counter++ > MAX_GROUPS_DEEP) {
                break;
            }
            $groupids = DB_AA::select('groupid', "SELECT groupid FROM `membership`", [['memberid', $last_groups]]);

            $last_groups = [];  //get deeper groups to last_groups and groups
            foreach ($groupids as $gid) {
                // Realize that it has already checked a group and eliminate it.

                if ( $gid AND !in_array($gid, $all_groups) ) {  // eliminate also $gid == 0 - nonsence, but sometimes it is in DB
                    $last_groups[] = $gid;
                    $all_groups[]  = $gid;
                }
            }
        } while (count($last_groups));

        // I _think_ this is a list of groupids.
        return $all_groups;
    }

    /** getJoinedPerms function
     * @param $uid
     * @param $objectType
     * @param $groups
     * @return array of sliceids and their permissions (for user $userid) counting all groups listed in third parameter
     * (the gorups could be also foreign - like from Reader Set, but permissions for such groups are stored here)
     */
    function getJoinedPerms($uid, $objectType, $groups= []) {
        $groups[] = $uid;

        $db_perms = DB_AA::select([], "SELECT userid, objectid, perm FROM `perms`", [['object_type', $objectType], ['userid', $groups]]);

        $by_id      = [];
        $user_perms = [];
        foreach ($db_perms as $perm) {
            if ( $user_perms[$perm['objectid']] ) {    // perms for user defined - stronger
                continue;
            }
            if ( $perm['userid'] == $uid ) {      // user specific permissions defined
                $by_id[$perm['objectid']]      = $perm['perm'];
                $user_perms[$perm['objectid']] = true; // match the object id (to ignore
            } else {                             // group permissions
                $by_id[$perm['objectid']] .= $perm['perm']; // JOIN group permissions !!!
            }
        }
        return $by_id;
    }

    /** getObjectsPerms function
     * @param $obejctID
     * @param $objectType
     * @return array of user/group identities and their permissions
     * granted on specified object $objectID
     *
     *  example: $arr["uid=honzam,dc=ecn,dc=apc,dc=org"] == 2
     */
    function getObjectsPerms($objectID, $objectType) {
        return DB_AA::select(['userid'=>'perm'], "SELECT userid, perm FROM `perms`", [['object_type', $objectType], ['objectid', $objectID]]);
    }
}

