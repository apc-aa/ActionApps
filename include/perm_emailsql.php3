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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

//php_sqlemail - functions for working with permissions with SQL - with e-mail hook

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

/* TODO/NOTES:
     AddUser() AddGroup() : unique-problem
*/

// ----------------------------- QUERY -----------------------------------

/** PopAuthenticate function
 *  returns uid if user is authenticated, else false.
 * @param $username
 * @param $password
 * @param $popserver
 */

function PopAuthenticate($username, $password, $popserver) {
  $old_error_reporting = error_reporting(E_ALL & ~(E_WARNING | E_NOTICE));
  $mailbox = '{'.$popserver.'/pop3:110}'; $mbox = imap_open ($mailbox,
  $username, $password,OP_READONLY); error_reporting($old_error_reporting);
  if ($mbox) {
      imap_close($mbox);
  }
  return $mbox;
}
/** AuthenticateUsername function
 * @param $username
 * @param $password
 * @param $flags
 */
function AuthenticateUsernameCurrent($username, $password, $flags = 0) {

  $db  = new DB_AA;
  $id  = false; $i = 0;
  // build and execute a query for $username

  // match by uid if it is like 'toolkit' , by email if like 'madebeer@igc.org'
  // in the future, if it is like @igc.org, it should query an external
  // authentication source, like an LDAP server for @igc.org
  if ( $emailauth = strstr($username, "@") ){
    $sql=sprintf("SELECT id, uid, password FROM users WHERE mail ='%s'", $username);
  } else {
    $sql=sprintf("SELECT id, uid, password FROM users WHERE uid ='%s'", $username);
  }
  $sth = $db->query( $sql );
  $row = mysql_fetch_array($sth);
  $id  = $row['id'];
  $uid = $row['uid'];

// if the password is * or @web.ca username - use pop.web.ca for authentication
  if ((substr($row['password'],0,1) == '*') || eregi("@web\.ca$",$username) ){
      if (PopAuthenticate($username, $password, "pop.web.net")) {
          return $id;
      } else {
        return false;
      }
  } else {

  if (defined(CRYPT_SALT_LENGTH)) {                      // set by PHP
     $slength = CRYPT_SALT_LENGTH;
  } else if (substr($row['password'], 0, 3) == '$1$') {    // MD5
     $slength = 12;
  } else if (substr($row['password'], 0, 3) == '$2$') {    // Extended DES (16)
     $slength = 16;
  } else {
     $slength = 2;                                      // Standard DES
  }

  $cryptpw = crypt($password, substr($row['password'], 0, $slength));

  // if the passwords match, return the authenticated userid, otherwise false

  // echo "$password (given)<br>";
  // echo "$cryptpw (given crypted, ", strlen($cryptpw), ")<br>";
  // echo "$row[password] (stored crypted, ", strlen($row[password]), ")<br>";
  // echo "$slength (salt length)<br>";

  // The next substr looks odd, but $cryptpw is under
  // certain circumstances 4 chars longer than $row[password]
  // (on zulle.pair.com, FreeBSD 2.2.7, PHP 3.0.16, crypt uses MD5
  // and salt is 12 chars long).

  if ($row['password'] == substr($cryptpw,0,strlen($row['password']))) {
     return $id;
  } else {
     return false;
  }

  }
}

/** GetGroup function
 * @param $user_id
 * @param $flags
 * @return array(uid, name, description, owner)
 */
function GetGroup($user_id, $flags = 0) {
  $db  = new DB_AA;
  $sql = sprintf( "SELECT id, name, description
                     FROM users
                    WHERE id = '%s'", $user_id);
  $sth = $db->query( $sql );

  // TODO: something about a sizelimit??
  if ($db->next_record()) {
    $res['uid'] = $user_id;
    $res['name'] = $db->f("name");
    $res['description'] = $db->f("description");
  }
  return $res;
}

/** FindGroups function
 * @param $ pattern
 * @param $flags
 *  @return list of groups which corresponds to mask $pattern
 */
function FindGroups($pattern, $flags = 0) {

  $db  = new DB_AA;

  $sql = sprintf( "SELECT id, name
                     FROM users
                    WHERE name like '%s%%' AND
                          type = '%s'", addslashes($pattern), _m("Group"));
  $sth = $db->query( $sql );

  // TODO: something about a sizelimit??

  $db->query($SQL);
  while ($db->next_record()) {
    $by_id[$db->f("id")] = array("name"=>$db->f("name"));
  }

  return $by_id;
}

/** FindUsers function
 * @param $pattern
 * @param $flags
 *  @return list of users which corresponds to mask $pattern
 */
function FindUsers($pattern, $flags = 0) {

  $db      = new DB_AA;
  $pattern = addslashes($pattern);

  $sql = sprintf( "
     SELECT id, mail, givenname, sn
       FROM users
      WHERE ( name  LIKE '%s%%' OR mail LIKE '%s%%' OR uid LIKE '%s%%') AND
            type = '%s'", $pattern, $pattern, $pattern, 'User');
  $sth = $db->query( $sql );

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
  $sql = sprintf("SELECT memberid as id
                    FROM membership
                   WHERE groupid = %s", $group_id);
  $sth = $db->query( $sql );

  // TODO: something about a sizelimit??

  while ($row = mysql_fetch_array($sth)){
    $id         = $row['id'];
    $info       = GetIDsInfo($id);
    $by_id[$id] = $info;
  }

  return $by_id;
}

/** GetMembership function
 *  @return list of group_ids, where id (group or user) is a member
 * @param $id
 * @param $flags - use to obey group in groups?
 */
function GetMembership($id, $flags = 0) {

  $db  = new DB_AA;

  $last_groups[] = $id;
//  $all_groups = $last_groups;
  $deep_counter = 0;

  do{
      if ($deep_counter++ > MAX_GROUPS_DEEP) {
          break;
      }
    //echo "counter $deep_counter\n";

    // generate and execute search query
    $where = "memberid in (" . join (",", $last_groups) . ")";
    $sql   = "select groupid as id from membership WHERE $where";
    $sth   = $db->query( $sql );

    unset($last_groups);  //get deeper groups to last_groups and groups
    while ($row = mysql_fetch_array($sth)) {
      //echo "row $row[id]\n";
        // Realize that it has already checked a group and eliminate it.
        if (! in_array($row['id'],array($all_groups))) {
          $last_groups[] = $row["id"];
          $all_groups[]  = $row["id"];
     }
    }

  } while ( is_array($last_groups) );

  // I _think_ this is a list of groupids.
  //  echo "FA";
// $return[] = sort( $all_groups );
//  echo "R";
  return $all_groups;
}


// returns an array of user/group identities and their permissions
// granted on specified object $objectID

/* example:
$arr["uid=honzam,dc=ecn,dc=apc,dc=org"][type] == User
$arr["uid=honzam,dc=ecn,dc=apc,dc=org"][name] == Honza Malik
$arr["uid=honzam,dc=ecn,dc=apc,dc=org"][mail] == honzam@ecn.cz
$arr["uid=honzam,dc=ecn,dc=apc,dc=org"][perm] == 2
*/
/** GetObjectsPerms function
 * @param $objectID
 * @param $objectType
 * @param $flags
 */
function GetObjectsPerms($objectID, $objectType, $flags = 0) {

  $db  = new DB_AA;

  $sql= sprintf(
        "SELECT id, type, name, mail, perm
           FROM perms, users
          WHERE object_type = '%s' AND
                objectid    = '%s'   AND
                userid      = id",
         $objectType, $objectID);

//  echo $sql;
  $sth = $db->query( $sql );

  if (!$sth) {
      return false;
  }

  while ($row = mysql_fetch_array($sth)) {
    $by_id[$row['id']] = $row;
  };

  // I am not sure if I perm should be transformed from role->action here
  // right now it is not.

  return $by_id;
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

  for ($i = 0; $i < count($groups); $i++) {
    $gsql .= sprintf("OR userid = '%s' ", $groups[$i]);
  }

  $sql=sprintf("SELECT objectid as id, perm from perms
                WHERE object_type = '%s' AND (userid = '%s' %s)",
                $objectType, $id, $gsql);

  $sth = $db->query( $sql );
  if (!$sth) return false;

  while ($row = mysql_fetch_array($sth)) {
    $by_id[ $row['id'] ] = $row['perm'];
  }
  return $by_id;
}

// ----------------------------- USERS --------------------------------------

// users and groups are really the same thing, except
//   groups have null for the attributes  password & mail
//   (also marked by  'type'

/** AddUser function
 *  creates new person in permission system
 * @param $user
 * @param $flags
 */
function AddUser($user, $flags = 0) {

  $db  = new DB_AA;

// check that it's a unique id ...
  $sql = sprintf( "
     SELECT id
       FROM users
      WHERE ( uid  = '%s') AND
            type = '%s'", $user['uid'], "User");
  $sth = $db->query( $sql );
  if (!$sth) {
      return false;
  }
  if (mysql_num_rows($sth)) {
      return false;
  }

  // do a little bit of QA on the $user array
  //
  $array["type"]      = "User";
  $array["uid"]       = $user['uid'];
  $array["mail"]      = ((is_array($user['mail'])) ? $user['mail'][0] : $user['mail']);
  $array["name"]      = $user["givenname"]." ".$user["sn"];
  $array["sn"]        = $user["sn"];
  $array["givenname"] = $user["givenname"];

  if ($user["userpassword"]) {
    $cryptpw = crypt( $user["userpassword"]);   // salt is generated by PHP
  } else {
    $cryptpw = "*";
  }
  $array["password"] = $cryptpw;

//  print $user[mail];
//  print $array[mail];

  // insert into users
  //

  $sql = A2sql_insert('users',$array);
  //print $sql;
  $db->query($sql);
  $id = mysql_insert_id();

//  echo "id is $id\n<BR>";


/*  OLD -- since salt is now userid, don't need to do this

  // fix the password if that was successful
  //

  if ($id) {
     $cryptpw = crypt( $user["userpassword"], $id );
     $sql=sprintf("UPDATE users SET password = '%s'
                    WHERE id = %s", $cryptpw,$id);
     $db->query($sql);
  } else { echo "NOID"; };
*/

  return $id;
}

/** DelUser function
 *  deletes an user in permission system
 *  $user_id is DN
 * @param $user_id
 * @param $flags
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
 *  changes user entry in permission system
 * @param $user
 * @param $flags
 */
function ChangeUser($user, $flags = 0) {
  $db  = new DB_AA;

  // do a little bit of QA on the $user array
  //
  $array["id"]        = $user["uid"];
  $array["mail"]      = ((is_array($user['mail'])) ? $user['mail'][0] : $user['mail']);
  $array["name"]      = $user["givenname"]." ".$user["sn"];
  $array["sn"]        = $user["sn"];
  $array["givenname"] = $user["givenname"];

  if ($user["userpassword"]) {
    $array["password"] = crypt( $user["userpassword"]); // salt is generated by PHP
  }

  // alter users
  $sql = A2sql_update('users','id', $array);
  $db->query($sql);
  return true;
}

// ----------------------------- GROUPS -----------------------------------

/** AddGroup function
 * creates new group in permission system
 * @param array $group ("name", "description", ...)
 * @param $flags
 */
function AddGroup($group, $flags = 0) {
// creates new person in permission system

  $db  = new DB_AA;
   // do a little bit of QA on the $user array
  $array["type"] = _m("Group");
  $array["name"] = $group["name"];
  $array["description"] = $group["description"];
  $array["password"] = 'crypt will never return this';

  $sql = A2sql_insert('users',$array);
  $db->query($sql);
  $id = mysql_insert_id();

  return $id;
}

/** DelGroup function
 * deletes an group in permission system
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
 * @param array $group ("name", "description", ...)
 * @param $flags
 */
function ChangeGroup($group, $flags = 0) {
  $db  = new DB_AA;
  // do a little bit of QA on the $user array
  $array["id"] = $group["uid"];
  $array["name"] = $group["name"];
  $array["description"] = $group["description"];

  $sql = A2sql_update('users','id',$array);
  $db->query($sql);
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
  $sql = sprintf( "REPLACE into membership (groupid, memberid)
                    VALUES ('%s','%s')", $group_id, $id);
  $db->query( $sql );
}
/** DelGroupMember function
 * @param $group_id
 * @param $id
 * @param $flags
 */
function DelGroupMember($group_id, $id, $flags = 0) {
  $db  = new DB_AA;
  $sql = sprintf( "DELETE from membership
                    WHERE groupid = '%s' AND
                          memberid = '%s'", $group_id, $id);
  $db->query( $sql );
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
 * @param $object_type
 * @param $perm
 * @param $flags
 */
function AddPerm($id, $objectID, $object_type, $perm, $flags = 0) {
  $db  = new DB_AA;
  $sql = sprintf( "REPLACE into perms (object_type, objectid, userid, perm)
                    VALUES ('%s','%s','%s','%s')",
                  $object_type, $objectID, $id, $perm);
  //echo $sql;
  $db->query( $sql );
}
/** DelPerm function
 * @param $id
 * @param $objectID
 * @param $object_type
 * @param $flags
 */
function DelPerm($id, $objectID, $object_type, $flags = 0) {
  $db  = new DB_AA;
  $sql = sprintf( "delete from perms
                    where   userid = '%s'     AND
                            objectid = '%s'   AND
                            object_type = '%s'",
                    $id, $objectID, $object_type);
  //echo $sql;
  $db->query( $sql );
}
/** ChangePerm function
 * @param $id
 * @param $objectID
 * @param $objectType
 * @param $perm
 * @param $flags
 */
function ChangePerm($id, $objectID, $objectType, $perm, $flags = 0) {
  return AddPerm ($id, $objectID, $objectType, $perm);
}


//#############################################################################
// Internal functions
//#############################################################################

/** GetIDsInfo function
 * @param $id
 * @param $ds
 *  @return an array containing basic information on $id (user DN or group DN)
 * or false if ID does not exist
 * array("mail => $mail", "name =>$cn", "type => "User" : "Group")
 */
function GetIDsInfoCurrent($id, $ds = "") {

  $db  = new DB_AA;

  $sql = sprintf( "SELECT name, givenname, sn, mail, type
                     FROM users
                    WHERE id = '%s'", $id);
  $sth = $db->query( $sql );

  // TODO: something about a sizelimit??

  if ($db->next_record()) {
    $res['type'] = $db->f("type");
    $res['name'] = ( ($res['type'] == "User") ? $db->f("givenname")." ".$db->f("sn") : $db->f("name"));
    $res['mail'] = $db->f("mail");
  }

  return $res;
}

/** L2sql_insert function
 *  uses a list of variables to import from global namespace
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
     $fields[$i] = addslashes( $var ); // in case we don't even trust that
     $values[$i] = "'" . addslashes( $val ) . "'";
//    print "$fields[$i] : $values[$i]";
     $i++;
  }

  $FieldClause = join(', ', $fields);
  $ValueClause = join(', ', $values);

  return ("INSERT INTO $table ( $FieldClause )
                  VALUES      ( $ValueClause )");
}


/** A2sql_insert function
 *  inserts an associative array
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
  };

  $FieldClause = join(', ', $fields);
  $ValueClause = join(', ', $values);

  return ("INSERT INTO $table ( $FieldClause )
                  VALUES      ( $ValueClause )");
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
  };

  $set     = join (", ",  $set_terms);
  $where   = "$keyField = '" . addslashes($aData[$keyField]). "'";

  // create the sql
  return " UPDATE $table
                SET $set
              WHERE $where
     ";
}
?>
