<?php
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
 * @author    Michael de Beer, Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once AA_INC_PATH."perm_core.php3";

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

// ----------------------------- QUERY -----------------------------------

/** AuthenticateSqlUsername function
 * @param $username
 * @param $password
 * @param $flags
 * @return uid if user is authenticated, else false.
 */
function AuthenticateUsernameCurrent($username, $password) {
    $db = new DB_AA;
    $id = false; $i = 0;
    // build and execute a query for $username

    // match by uid if it is like 'toolkit' , by email if like 'madebeer@igc.org'
    // in the future, if it is like @igc.org, it should query an external
    // authentication source, like an LDAP server for @igc.org
    if ( $num = strstr($username, "@") ){
        $SQL = sprintf("SELECT id, password FROM users WHERE mail ='%s'", $username);
    } else {
        $SQL = sprintf("SELECT id, password FROM users WHERE uid ='%s'", $username);
    }
    $db->query( $SQL );
    $db->next_record();
    $db_id  = $db->f('id');
    $db_pwd = $db->f('password');

    return AA_Perm::comparePwds($password, $db_pwd) ? $db_id : false;
}

/** GetGroup function
 * @param $user_id
 * @param $flags
 *  @return array(uid, name, description, owner)
 */
function GetGroup($user_id, $flags = 0) {
    $db  = new DB_AA;
    $SQL = sprintf( "SELECT id, name, description FROM users WHERE id = '%s'", $user_id);
    $db->query( $SQL );

    // TODO: something about a sizelimit??
    if ($db->next_record()) {
        $res['uid']         = $user_id;
        $res['name']        = $db->f("name");
        $res['description'] = $db->f("description");
    }
    return $res;
}

/** FindGroups function
 * @param $pattern
 * @param $flags
 * @return list of groups which corresponds to mask $pattern
 */
function FindGroups($pattern, $flags = 0) {

    $db    = new DB_AA;
    $by_id = FindReaderGroups($pattern);

    // older code uses _m("Group"), the new one uses 'Group' or 'User' as keyword
    $SQL = sprintf( "SELECT id, name
                       FROM users
                      WHERE name LIKE '%s%%' AND (type = '%s' OR type = '%s')",
                    addslashes($pattern), _m("Group"), "Group");

    $db->query( $SQL );
    // TODO: something about a sizelimit??
    $db->query($SQL);
    while ($db->next_record()) {
        $by_id[$db->f("id")] = array("name"=>$db->f("name"));
    }
    return $by_id;
}
/** find_user_by_login function
 * @param $login
 */
function find_user_by_login($login) {
    $db = new DB_AA;
    $db->query("SELECT * FROM users WHERE uid='$login'");
    while ($db->next_record()) {
        $by_id[$db->f("id")] = array("name"=>($db->f("givenname")." ".$db->f("sn")),
                                     "mail"=>$db->f("mail"));
    }
    return $by_id;
}

/** FindUsers function
 * @param $pattern
 * @param $flags
 * @return list of users which corresponds to mask $pattern
 */
function FindUsers($pattern, $flags = 0) {

    $db  = new DB_AA;
    $by_id = FindReaderUsers($pattern);
    $pattern = addslashes($pattern);

    $SQL = sprintf( "
       SELECT id, mail, givenname, sn
         FROM users
        WHERE ( name  LIKE '%s%%' OR mail LIKE '%s%%' OR uid LIKE '%s%%') AND
              ( type = '%s' OR type = '%s')",
              $pattern, $pattern, $pattern, _m("User"), "User");
    $db->query( $SQL );
    // TODO: something about a sizelimit??
    $db->query($SQL);
    while ($db->next_record()) {
        $by_id[$db->f("id")] = array("name"=>($db->f("givenname")." ".$db->f("sn")),
                                     "mail"=>$db->f("mail"));
    }
    return $by_id;
};

// TODO : make this recursive friendly?
/** GetGroupMembers function
 * @param $group_id
 * @param $flags
 */
function GetGroupMembers($group_id, $flags = 0) {
    $db  = new DB_AA;

    settype($group_id,"integer");
    $SQL = sprintf("SELECT memberid as id
                      FROM membership
                     WHERE groupid = %s", $group_id);
    $db->query( $SQL );
    // TODO: something about a sizelimit??
    while($db->next_record()){
        $id         = $db->f('id');
        $by_id[$id] = GetIDsInfo($id);
    }

    return $by_id;
}

/** GetMembership function
 * @param $id
 * @param $flags - use to obey group in groups?
 * @return list of group_ids, where id (group or user) is a member
 */
function GetMembership($id, $flags = 0) {
    $db  = new DB_AA;

    $all_groups = IsUserReader($id) ? GetReaderMembership($id) : array();

    $last_groups[] = $id;
    //  $all_groups = $last_groups;
    $deep_counter = 0;

    do {
        if ($deep_counter++ > MAX_GROUPS_DEEP) {
            break;
        }

        // generate and execute search query
        $where = "memberid in ('" . join ("','", $last_groups) . "')";
        $SQL   = "select groupid as id from membership WHERE $where";
        $db->query( $SQL );

        unset($last_groups);  //get deeper groups to last_groups and groups
        while($db->next_record()) {
            // Realize that it has already checked a group and eliminate it.
            if ( !in_array($db->f('id'), array($all_groups))) {
                $last_groups[] = $db->f('id');
                $all_groups[]  = $db->f('id');
            }
        }
    } while (is_array($last_groups));

    // I _think_ this is a list of groupids.
    return $all_groups;
}

/** GetObjectsPerms function
 * @param $obejctID
 * @param $objectType
 * @param $flags
 * @return an array of user/group identities and their permissions
 * granted on specified object $objectID
 *
 *  example:
 * $arr["uid=honzam,dc=ecn,dc=apc,dc=org"][type] == User
 * $arr["uid=honzam,dc=ecn,dc=apc,dc=org"][name] == Honza Malik
 * $arr["uid=honzam,dc=ecn,dc=apc,dc=org"][mail] == honzam@ecn.cz
 * $arr["uid=honzam,dc=ecn,dc=apc,dc=org"][perm] == 2
 */

function GetObjectsPerms($objectID, $objectType, $flags = 0) {
    $db  = new DB_AA;

    $SQL = sprintf("SELECT userid, perm FROM perms WHERE object_type = '%s' AND objectid = '%s'", $objectType, $objectID);
    $db->query( $SQL );

    while ( $db->next_record() ) {
        $tmp = GetIDsInfo($db->f('userid'));
        $tmp['id'] = $db->f('userid');
        $tmp['perm'] = $db->f('perm');

        $by_id[$db->f('userid')] = $tmp;

//        $by_id[$db->f('id')] = array( 'id'   => $db->f('id'),
//                                      'type' => $db->f('type'),
//                                      'name' => $db->f('name'),
//                                      'mail' => $db->f('mail'),
//                                      'perm' => $db->f('perm') );
    }

    // I am not sure if I perm should be transformed from role->action here
    // right now it is not (Michael).  (which is correct - Honza)

    return (is_array($by_id) ? $by_id : false);
}


/** GetIDPerms function
 * @param $id
 * @param $objectType
 * @param $flags
 * @return an array of sliceids and their permissions (for user $userid).
 * granted on all objects of type $objectType
 * flags & 1 -> do not involve membership in groups
 */
function GetIDPerms($id, $objectType, $flags = 0) {

    $db  = new DB_AA;

    if (!($flags & 1)) {
        $groups = GetMembership($id);
    }

    for ( $i=0, $ino=count($groups); $i<$ino; ++$i) {
        $gsql .= sprintf("OR userid = '%s' ", $groups[$i]);
    }

    $SQL=sprintf("SELECT objectid as id, perm from perms
                  WHERE object_type = '%s' AND (userid = '%s' %s)",
                  $objectType, $id, $gsql);

    $sth = $db->query( $SQL );
    if (!$sth) {
        return false;
    }

    $user_perms = array();
    while ($db->next_record()) {
        if ( $user_perms[$db->f('id')] ) {    // perms for user defined - stronger
            continue;
        }
        if ( $db->f('userid') == $id ) {      // user specific permissions defined
            $by_id[$db->f('id')]      = $db->f('perm');
            $user_perms[$db->f('id')] = true; // match the object id (to ignore
        } else {                              // group permissions
            $by_id[$db->f('id')] .= $db->f('perm'); // JOIN group permissions !!!
        }
    }
    return $by_id;
}

// ----------------------------- USERS --------------------------------------

// users and groups are really the same thing, except
//   groups have null for the attributes  password & mail
//   (also marked by  'type'

/** AddUser function
 * @param $user
 * @param $flags
 * creates new person in permission system
 */
function AddUser($user, $flags = 0) {
    if (! AA::$perm->isUsernameFree($user["uid"])) {
        return false;
    }

    $db  = new DB_AA;

    // do a little bit of QA on the $user array
    $array["type"]      = "User";
    $array["uid"]       = $user["uid"];
    $array["mail"]      = ((is_array($user['mail'])) ? $user['mail'][0] : $user['mail']);
    $array["name"]      = $user["givenname"]." ".$user["sn"];
    $array["sn"]        = $user["sn"];
    $array["givenname"] = $user["givenname"];
    $cryptpw            = AA_Perm::cryptPwd($user["userpassword"]);   // salt is generated by PHP
    $array["password"]  = $cryptpw;

    // insert into users

    $SQL = A2sql_insert('users',$array);
    $db->query($SQL);
    return $db->last_insert_id();
}

/** DelUser function
 * @param $user_id
 * @param $flags
 *  deletes an user in permission system
 *  $user_id is DN
 */
function DelUser($user_id, $flags = 3) {
    $db  = new DB_AA;

    // To keep integrity of AA we should also delete all references
    // to this user
    if ($flags & 1) {
        // cancel membership in groups
        $db->query("delete from membership where memberid = $user_id");

    }
    if ($flags & 2) {
        // cancel direct permissions
        $db->query("delete from perms where userid = $user_id");

    }
    // cancel the user
    $db->query("delete from users where id = $user_id");

    return $r;
}

/** ChangeUser function
 * @param $user
 * @param $flags
 *  changes user entry in permission system
 */
function ChangeUser($user, $flags = 0) {
    $db  = new DB_AA;
    // do a little bit of QA on the $user array
    $array["id"]        = $user["uid"];
    $array["mail"]      = ((is_array($user['mail'])) ? $user['mail'][0] : $user['mail']);
    $array["name"]      = $user["givenname"]." ".$user["sn"];
    $array["sn"]        = $user["sn"];
    $array["givenname"] = $user["givenname"];

    if ($user["userpassword"]) {
        $array["password"] = AA_Perm::cryptPwd($user["userpassword"]); // salt is generated by PHP
    }

    # alter users
    $SQL = A2sql_update('users','id', $array);
    $db->query($SQL);
    return true;
}

// ----------------------------- GROUPS -----------------------------------

/** AddGroup function
 * creates new group in permission system
 * @param $group is an array ("name", "description", ...)
 * @param $flags
 */
function AddGroup($group, $flags = 0) {
    // creates new person in permission system
    $db  = new DB_AA;
    // do a little bit of QA on the $user array
    $array["type"]        = "Group";
    $array["name"]        = $group["name"];
    $array["description"] = $group["description"];
    $array["password"]    = 'crypt will never return this';

    $SQL = A2sql_insert('users',$array);
    $db->query($SQL);
    return $db->last_insert_id();
}

/** DelGroup function
 *  deletes an group in permission system
 * @param $group_id is DN
 * @param $flags
 */
function DelGroup($group_id, $flags = 3) {
  $db  = new DB_AA;

  // cancel other people's membership in this group
  $db->query("delete from membership where groupid = $group_id");

  // To keep integrity of AA we should also delete all references
  // to this group
  if ($flags & 1) {
     // cancel this group's membership in other groups
     $db->query("delete from membership where memberid = $group_id");
  }
  if ($flags & 2) {
     // cancel direct permissions
     $db->query("delete from perms where userid = $group_id");
  }
  // cancel the group
  $db->query("delete from users where id = $group_id");
  return 1;
}

/** ChangeGroup function
 *  changes fields about group
 * @param $group is an array ("name", "description", ...)
 * @param $flags
 */
function ChangeGroup($group, $flags = 0) {
    $db  = new DB_AA;
    // do a little bit of QA on the $user array
    $array["id"]          = $group["uid"];
    $array["name"]        = $group["name"];
    $array["description"] = $group["description"];

    $SQL = A2sql_update('users','id',$array);
    $db->query($SQL);
    return true;
}

// ----------------------------- MEMBERSHIP ---------------------------------
/** AddGroupMember function
 * @param $group_id
 * @param $id
 * @param $flags
 */
function AddGroupMember($group_id, $id, $flags = 0) {
    $db  = new DB_AA;
    $SQL = sprintf( "DELETE FROM membership WHERE groupid = '%s' AND memberid = '%s'",
                     $group_id, $id);
    $db->query( $SQL );
    $SQL = sprintf( "INSERT INTO membership (groupid, memberid)
                      VALUES ('%s','%s')", $group_id, $id);
    $db->query( $SQL );
}
/** DelGroupMember function
 * @param $group_id
 * @param $id
 * @param $flags
 */
function DelGroupMember($group_id, $id, $flags = 0) {
    $db  = new DB_AA;
    $SQL = sprintf( "DELETE from membership
                      WHERE groupid = '%s' AND
                            memberid = '%s'", $group_id, $id);
    $db->query( $SQL );
}

// ----------------------------- PERMS -----------------------------------

/** AddPermObject function
 *  creates a new object
 * @param $objectID
 * @param $objectType
 * @param $flags
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
 */
function AddPerm($id, $objectID, $object_type, $perm, $flags = 0) {
    $db  = new DB_AA;
    $SQL = sprintf( "DELETE FROM perms WHERE object_type = '%s' AND objectid = '%s' AND userid = '%s'",
                    $object_type, $objectID, $id);
    $db->query( $SQL );
    $SQL = sprintf( "INSERT INTO perms (object_type, objectid, userid, perm)
                      VALUES ('%s','%s','%s','%s')",
                    $object_type, $objectID, $id, $perm);
    $db->query( $SQL );
}
/** DelPerm function
 * @param $id
 * @param $objectID
 * @param $objectType
 * @param $flags
 */
function DelPerm($id, $objectID, $object_type, $flags = 0) {
    $db  = new DB_AA;
    $SQL = sprintf( "DELETE FROM perms
                      WHERE userid = '%s'     AND
                            objectid = '%s'   AND
                            object_type = '%s'",
                    $id, $objectID, $object_type);
    $db->query( $SQL );
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
//# Internal functions
//#############################################################################

/** GetIDsInfoCurrent function
 * @param $id
 * @param $ds
 * @return an array containing basic information on $id (user DN or group DN)
 * or false if ID does not exist
 * array("mail => $mail", "name => $cn", "type => "User" : "Group"")
 */
function GetIDsInfoCurrent($id, $ds = "") {
    $db  = new DB_AA;
    $SQL = sprintf( "SELECT name, givenname, uid, sn, mail, type FROM users WHERE id = '%s'", $id);
    $db->query( $SQL );
    // TODO: something about a sizelimit??
    if ($db->next_record()) {
        $res['id']        = $id;
        $res['login']     = $db->f("uid");
        $res['type']      = $db->f("type");
        $res['name']      = ( ($res['type'] == _m("User") OR ($res['type'] == "User")) ? $db->f("givenname")." ".$db->f("sn") : $db->f("name"));
        $res['sn']        = $db->f("sn");
        $res['givenname'] = $db->f("givenname");
        $res['mail']      = $db->f("mail");
        $res['mails']     = array($res['mail']);
    }
    return $res;
}


/** L2sql_insert function
 * uses a list of variables to import from global namespace
 * @param $table
 * @param $aData
 */
function L2sql_insert($table, $aData) {
    global  $debug_query;

    $i = 0;
    if ($debug_query) {
        print "in simple_Insert<br>";
    }

    while ($i < count($aData)) {
        $var = $aData[$i];
        $val = $GLOBALS[$var];
        $fields[$i] = addslashes( $var ); # in case we don't even trust that
        $values[$i] = "'" . addslashes( $val ) . "'";
        #    print "$fields[$i] : $values[$i]";
        $i++;
    }

    $FieldClause = join(', ', $fields);
    $ValueClause = join(', ', $values);

    return ("INSERT INTO $table ( $FieldClause ) VALUES ( $ValueClause )");
}

/** A2sql_insert function
 * inserts an associative array
 * @param $table
 * @param $aData
 */
function A2sql_insert($table, $aData) {
    global  $debug_query;

    if ($debug_query) {
        print "in simple_Insert<br>";
    }

    while (list($key, $val) = each($aData)) {
        $fields[] = addslashes($key);
        $values[] = "'" . addslashes( $val ) . "'";
    }

    $FieldClause = join(', ', $fields);
    $ValueClause = join(', ', $values);

    return ("INSERT INTO $table ( $FieldClause ) VALUES ( $ValueClause )");
}

/** A2sql_update function
 *  generates update sql statement from array
 * @param $table
 * @param $keyField
 * @param $aData
 */
function A2sql_update($table, $keyField, $aData) {
    global  $debug_query;
    if ($debug_query) {
        print "in simple_Insert<br>";
    }

    while (list($key, $val) = each($aData)) {
        if ($key == $keyField) {
            continue;
        }
        $set_terms[] = addslashes($key). "=" . " '" . addslashes( $val ) . "'";
    }

    $set   = join (", ",  $set_terms);
    $where = "$keyField = '" . addslashes($aData[$keyField]). "'";

    // create the sql
    return " UPDATE $table SET $set WHERE $where";
}


class AA_Permsystem_Sql extends AA_Permsystem {

    /** isUsernameFree function
     *  Looks into reader management slices whether the reader name is not yet used.
     *   This function is used in perm_ldap and perm_sql in IsUsernameFree().
     * @param $username
     */
    function isUsernameFree($username) {
        $db = getDB();
        $db->query("SELECT uid FROM users WHERE uid='".addslashes($username)."'");
        $free = ! $db->next_record();
        freeDB($db);
        return $free;
    }
}


?>
