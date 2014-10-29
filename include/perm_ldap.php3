<?php
/**
 *  perm_ldap - functions for working with permissions with LDAP
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


//#############################################################################
// API functions
//#############################################################################


//############### User functions //#############################################

/** AddUser function
 *  Creates new person in LDAP permission system
 * @param $user
 * @param $flags
 */
function AddUser($user, $flags = 0) {
    if (! AA::$perm->isUsernameFree($user["uid"])) {
        return false;
    }

    $aa_default_ldap = AA_Permsystem_Ldap::getLdap();
    if ( !($ds=InitLDAP()) ) {
        return false;
    }

    $record = array();
    $record["objectclass"][0]   = "top";
    $record["objectclass"][1]   = "person";
    $record["objectclass"][2]   = "organizationalperson";
    $record["objectclass"][3]   = "inetorgperson";
    $record["cn"]               = $user["givenname"]. "  ". $user["sn"];
    $record["sn"]               = $user["sn"];
    $record["givenname"]        = $user["givenname"];
    if ($user["mail"]) {
        $record["mail"]         = $user["mail"];   // can be an array
    }
    $record["uid"]              = $user["uid"];
    $record["userPassword"]     = "{md5}". base64_encode(pack("H*",md5($user["userpassword"])));
    if ($user["phone"]) {
        $record["telephoneNumber"] = $user["phone"];
    }

    // add data to directory
    $user_dn = "uid=".$user['uid']."," . $aa_default_ldap['people'];
    $r       = @ldap_add($ds, $user_dn, $record);
    ldap_close($ds);
    return ($r ? $user_dn : false);
}

/** DelUser function
 *  Deletes an user in LDAP permission system
 *  @param $user_id is DN
 * @param $flags
 */
function DelUser($user_id, $flags = 3) {
    $aa_default_ldap = AA_Permsystem_Ldap::getLdap();
    if ( !($ds=InitLDAP()) ) {
        return false;
    }

    // To keep integrity of LDAP DB, we should also delete all references
    // to this user in other LDAP entries (e.g. member=.., apcaci=..).
    // But this requires explicit knowledge of the schema!
    if ($flags & 1) {            // cancel membership in groups
        $filter = "(&(objectclass=groupOfNames)(member=$user_id))";
        $r      = ldap_search($ds, $aa_default_ldap['groups'], $filter, array(""));
        $arr    = ldap_get_entries($ds,$r);
        for ($i=0; $i < $arr["count"]; ++$i) {
            DelGroupMember($arr[$i]["dn"], $user_id);
        }
        ldap_free_result($r);
    }

    if ($flags & 2) {            // cancel assigned permissions
        $filter = "(&(objectclass=apcacl)(apcaci=$user_id:*))";
        $r      = ldap_search($ds, $aa_default_ldap['acls'], $filter, array("apcObjectType","apcaci","apcObjectID"));
        $arr    = ldap_get_entries($ds,$r);
        for ($i=0; $i < $arr["count"]; ++$i) {
            // indexes in lowercase !!!
            DelPerm($user_id, $arr[$i]["apcobjectid"][0], $arr[$i]["apcobjecttype"][0]);
        }
        ldap_free_result($r);
    }

    $r = @ldap_delete($ds, $user_id);

    ldap_close($ds);
    return $r;
}

/** ChangeUser function
 *  Changes user entry in LDAP permission system
 * @param $user
 * @param $flags
 */
function ChangeUser($user, $flags = 0) {
    if ( !($ds = InitLDAP()) ) {
        return false;
    }

    $record = array();
    $record["cn"]        = $user["givenname"]." ".$user["sn"];
    $record["sn"]        = $user["sn"];
    $record["givenname"] = $user["givenname"];
    $record["mail"]      = $user["mail"];                // can be an array
    if ($user["userpassword"]) {
        $record["userPassword"]    = "{md5}". base64_encode(pack("H*",md5($user["userpassword"])));
    }
    if ($user["phone"]) {
        $record["telephoneNumber"] = $user["phone"];
    }

    // add data to directory
    $r = @ldap_mod_replace($ds, $user['uid'], $record);
    ldap_close($ds);
    return $r;
}

//############### Group functions //############################################

/** AddGroup function
 *  Creates new group in LDAP permission system
 *  @param $group array ("name", "description", ...)
 * @param $flags
 */
function AddGroup($group, $flags = 0) {
    $aa_default_ldap = AA_Permsystem_Ldap::getLdap();
    if ( !($ds=InitLDAP()) ) {
        return false;
    }

    $record = array();
    $record["objectclass"][0] = "top";
    $record["objectclass"][1] = "groupOfNames";
    $record["cn"]             = $group["name"];
    $record["member"]         = LDAP_BINDDN;  // in order to be compatible with LDAP
                                              // schema where member is required

    if ($group["description"]) {
        $record["description"] = $group["description"];
    }

    // add data to directory
    $group_dn = "cn=".$group['name']."," . $aa_default_ldap['groups'];
    $r = @ldap_add($ds, $group_dn, $record);
    ldap_close($ds);

    return ($r ? $group_dn : false);
}

/** DelGroup function
 *  Deletes a group in LDAP permission system
 *  @param $group_id is DN
 * @param $flags
 */
 function DelGroup($group_id, $flags = 3) {
     $aa_default_ldap = AA_Permsystem_Ldap::getLdap();
     if ( !($ds=InitLDAP()) ) {
         return false;
     }

     // To keep integrity of LDAP DB, we should also delete all references
     // to this group in other LDAP entries (e.g. member=.., apcaci=..).
     // But this requires explicit knowledge of the schema.

     if ($flags & 1) {            // cancel membership in other groups
         $filter = "(&(objectclass=groupOfNames)(member=$group_id))";
         $r      = ldap_search($ds, $aa_default_ldap['groups'], $filter, array(""));
         $arr    = ldap_get_entries($ds,$r);
         for ($i=0; $i < $arr["count"]; ++$i) {
             DelGroupMember($arr[$i]["dn"], $group_id);
         }
         ldap_free_result($r);
     }

     if ($flags & 2) {            // cancel assigned permissions
         $filter = "(&(objectclass=apcacl)(apcaci=$group_id:*))";
         $r      = ldap_search($ds, $aa_default_ldap['acls'], $filter, array("apcObjectType","apcaci","apcObjectID"));
         $arr    = ldap_get_entries($ds,$r);
         for ($i=0; $i < $arr["count"]; ++$i) {
             // indexes in lowercase !!!
             DelPerm($group_id, $arr[$i]["apcobjectid"][0],  $arr[$i]["apcobjecttype"][0]);
         }
         ldap_free_result($r);
     }

     $r = @ldap_delete($ds, $group_id);

     ldap_close($ds);
     return $r;
}

/** ChangeGroup function
 *  changes group entry in LDAP permission system
 * @param $group
 * @param $flags
 */
function ChangeGroup($group, $flags = 0) {
    if ( !($ds=InitLDAP()) ) {
        return false;
    }

    $record = array();
    $record["description"] = $group["description"];
    if ($group["name"]) {
        $record["cn"] = $group["name"];
    }

    // add data to directory
    $r = @ldap_mod_replace($ds, $group['uid'], $record);
    ldap_close($ds);
    return $r;
}

/** AddGroupMember function
 * @param $group_id
 * @param $id
 * @param $flags
 */
function AddGroupMember($group_id, $id, $flags = 0) {
    if ( !($ds=InitLDAP()) ) {
        return false;
    }

    $r = @ldap_mod_add($ds, $group_id, array("member" => "$id"));
    ldap_close($ds);
    return $r;
}
/** DelGroupMember function
 * @param $group_id
 * @param $id
 * @param $flags
 */
function DelGroupMember($group_id, $id, $flags = 0) {
    if ( !($ds=InitLDAP()) ) {
        return false;
    }

    // immediate ldap_mod_del fails, if there is only one member attribute (=$id)
    $filter = "objectclass=groupOfNames";
    $result = @ldap_read($ds, $group_id, $filter, array("member"));
    if (!$result) {
        return false;
    }
    $entry  = ldap_first_entry ($ds, $result);
    $arr    = ldap_get_attributes($ds, $entry);

    $new    = array();
    for ($i=0; $i < $arr["member"]["count"]; ++$i) {
        if (!stristr($arr["member"][$i], $id)) {
            $new["member"][] = $arr["member"][$i];
        }
    }

    if (sizeof($new["member"]) == 0) {
        $new["member"][] = LDAP_BINDDN;   // in order to be compatible with LDAP
    }                                     // schema where member is required

    $r = ldap_mod_replace($ds, $group_id, $new);
    ldap_close($ds);
    return $r;
}


//############### Permission functions //#######################################

/** AddPermObject function
 *  Creates a new object in LDAP
 * @param $objectID
 * @param $objectType
 * @param $flags
 */
function AddPermObject($objectID, $objectType, $flags = 0) {
    $aa_default_ldap = AA_Permsystem_Ldap::getLdap();

    if ( !($ds=InitLDAP()) ) {
        return false;
    }

    $record = array();
    $record["objectclass"][0] = "top";
    $record["objectclass"][1] = "apcacl";
    $record["apcobjectid"]    = $objectID;
    $record["apcobjecttype"]  = $objectType;

    // add data to directory
    $r = ldap_add($ds, "apcobjectid=$objectID,". $aa_default_ldap['acls'], $record);
    ldap_close($ds);
    return $r;
}

/** DelPermObject function
 *  Deletes an ACL object in LDAP permission system
 * @param $objectID
 * @param $objectType
 * @param $flags
 */
function DelPermObject($objectID, $objectType, $flags = 0) {
    $aa_default_ldap = AA_Permsystem_Ldap::getLdap();
    if (!($ds=InitLDAP())) {
        return false;
    }

    $r=@ldap_delete($ds, "apcobjectid=$objectID,". $aa_default_ldap['acls']);

    ldap_close($ds);
    return $r;
}

/** AddPerm function
 * Append permission to existing object
 * @param $id
 * @param $objectID
 * @param $objectType
 * @param $perm
 * @param $flags
 */
function AddPerm($id, $objectID, $objectType, $perm, $flags = 0) {
    $aa_default_ldap = AA_Permsystem_Ldap::getLdap();
    if (!($ds=InitLDAP())) {
        return false;
    }

    $filter = "objectclass=apcacl";
    $basedn = "apcobjectid=" . $objectID . "," . $aa_default_ldap['acls'];
    $result = @ldap_read($ds, $basedn, $filter, array("apcaci"));
    if (!$result) {
        // we have to add the permission object
        AddPermObject($objectID, $objectType);
        $result = @ldap_read($ds, $basedn, $filter, array("apcaci"));

        // does not help - return false
        if (!$result) {
            return false;
        }
    }
    $entry = ldap_first_entry ($ds, $result);
    $arr   = ldap_get_attributes($ds, $entry);

    // some older AAs could have mixed case atributes :-( (apcAci)
    $aci = (is_array($arr["apcaci"]) ? $arr["apcaci"] : $arr["apcAci"]);
    $new = array();
    $old = array();

    for ($i=0; $i < $aci['count']; ++$i) { // copy old apcAci values
        if (!stristr($aci[$i], $id)) {   // except the modified/deleted one
            $new["apcaci"][] = $aci[$i];
        } else {
            $old["apcaci"][] = $aci[$i];
        }
    }

    if ($perm) {
        $new["apcaci"][] = "$id:$perm";
    }

    if (count($new) > 0) {
        $r=ldap_mod_replace($ds, $basedn, $new);
    } else {
        $r=ldap_mod_del($ds, $basedn, $old);
    }

    ldap_close($ds);
    return $r;          // true or false
}
/** DelPerm function
 * @param $id
 * @param $objectID
 * @param $objectType
 * @param $flags
 */
function DelPerm($id, $objectID, $objectType, $flags = 0) {
    return AddPerm($id, $objectID, $objectType, false);
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
// Internal functions
//#############################################################################


/** InitLDAP function
 * Connect to LDAP server
 */
function InitLDAP() {
    $aa_default_ldap = AA_Permsystem_Ldap::getLdap();
    $ds = LDAP_Connect($aa_default_ldap['host'], $aa_default_ldap['port']);	// connect LDAP server
    if (!$ds) {   				// not connect
        return false;
    }

    if (!LDAP_Bind($ds, $aa_default_ldap['binddn'], $aa_default_ldap['bindpw'])) {
        return false;  		// not authentificed
    }
    return $ds;
}

/** ParseApcAci function
 *  Parse apcaci LDAP entry
 * @param $str
 */
function ParseApcAci($str) {
    $foo = explode(':', $str);
    return ((count($foo) < 2) ? false : array("dn"=>$foo[0], "perm"=>$foo[1]));
}
/** GetApcAciPerm function
 * @param $str
 */
function GetApcAciPerm($str) {
    $foo = explode(':', $str);
    return $foo[1];         // permission string
}

/* Not used right now. This would be used if we allow groups in groups
function GetUserType( $user_id ) {
    if (substr($user_id,0,4)      == 'uid=') return 'User';
    if (substr($user_id,0,3)      == 'cn=')  return 'Group';
    if (guesstype($user_id) == 'l')    return 'ReaderGroup';
    return 'Reader';
}
*/

class AA_Permsystem_Ldap extends AA_Permsystem {

    /** getLdap function
     *  Decides which LDAP server ask for authentification
     *  (acording to org - ecn.cz ..)
     * @param $org
     */
    function getLdap($org='') {
        // default ldap server for all searches
        return array( "host"   => LDAP_HOST,
                      "binddn" => LDAP_BINDDN,
                      "bindpw" => LDAP_BINDPW,
                      "basedn" => LDAP_BASEDN,
                      "people" => LDAP_PEOPLE,
                      "groups" => LDAP_GROUPS,
                      "acls"   => LDAP_ACLS,
                      "port"   => LDAP_PORT);
    }

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
        // LDAP perms - starting with uid (Users) od cn (Groups)
        return  ((substr($uid,0,4) == 'uid=') OR (substr($uid,0,3) == 'cn='));
    }

    /** authenticateUsername function
     *  Try to authenticate user from LDAP
     *  @param $username
     *  @param $password
     *  @return uid if user is authentificied, else false.
     */
    function authenticateUsername($username, $password) {
        if (!$username or !$password) {         // no password => anonymous in LDAP
            return false;
        }

        $return_val=false;
        if ($org = strstr($username, "@")) {      // user tries to auth. via e-mail
            $LDAPserver = AA_Permsystem_Ldap::getLdap(substr($org,1)); // get ldap server for this address
        } else {
            $LDAPserver = AA_Permsystem_Ldap::getLdap();
        }

        $ds = LDAP_Connect($LDAPserver['host'], $LDAPserver['port']);	// connect LDAP server
        if (!$ds) {                 			// not connected
            return false;
        }

        if ($org = strstr($username, "@")) { // user typed e-mail -> search to get DN
            $search = "(&(objectclass=inetOrgPerson)(mail=$username))";
            if (@LDAP_Bind($ds, $LDAPserver['binddn'], $LDAPserver['bindpw'] )) {
                $r = LDAP_search($ds, $LDAPserver['people'], $search, array(""));
                $arr = LDAP_get_entries($ds,$r);
                if ( $arr["count"] > 0 ) {
                    $userdn = $arr[0]["dn"];
                } else {
                    @LDAP_Close($ds);
                    return false;
                }
            }
        } else {                                    // build DN
            $userdn = "uid=$username,".$LDAPserver['people'];
        }

        if (@LDAP_Bind($ds, $userdn, $password)) {  // try to authenticate
            $return_val = $userdn;
        }
        @LDAP_Close($ds);
        return $return_val;
    }

    /** isUsernameFree function
     *  is this username free in LDAP system?
     * @param $username
     */
    function isUsernameFree($username) {
        $aa_default_ldap = AA_Permsystem_Ldap::getLdap();
        // search not only Active bin, but also Holding bin, Pending, ...
        return ! $this->getIDsInfo("uid=$username,".$aa_default_ldap['people']);
    }

    /** findUsers function
     *  @param $pattern
     *  @return list of users which corresponds to mask $pattern
     */
    function findUsernames($pattern) {
        $aa_default_ldap = AA_Permsystem_Ldap::getLdap();
        if ( !($ds=InitLDAP()) ) {
            return array();
        }
        $result = array();

        $filter = "(&(objectclass=inetOrgPerson)(|(uid=$pattern*)(cn=$pattern*)(mail=$pattern*)))";
        $res    = @ldap_search($ds,$aa_default_ldap['people'],$filter,array("mail","cn"));
        if (!$res) {
            // LDAP sizelimit exceed
            return ((ldap_errno($ds)==4) ? "too much" : array());
        }
        $arr = LDAP_get_entries($ds,$res);

        for ($i=0; $i<$arr['count']; ++$i) {
            $result[$arr[$i]['dn']] = array("name"=>$arr[$i]['cn'][0], "mail"=>$arr[$i]['mail'][0]);
        }

        ldap_close($ds);
        return $result;
    }

    /** find_user_by_login function
     * @param $login
     */
    function findUserByLogin($user_login) {
        $aa_default_ldap = AA_Permsystem_Ldap::getLdap();
        $user_id         = "uid=$user_login,".$aa_default_ldap['people'];
        $user            = $this->getIDsInfo($user_id);
        return $user ? array($user_id=>$user) : false;
    }

    /** findGroups function
     *  @param $pattern
     *  @return list of groups which corresponds to mask $pattern
     */
    function findGroups($pattern) {
        $aa_default_ldap = AA_Permsystem_Ldap::getLdap();
        if ( !($ds=InitLDAP()) ) {
            return false;
        }

        $filter = "(&(objectclass=groupofnames)(cn=$pattern*))";
        $res    = @ldap_search($ds,$aa_default_ldap['groups'],$filter,array("cn"));
        if (!$res) {
            // LDAP sizelimit exceed
            return ((ldap_errno($ds)==4) ? "too much" : false);
        }
        $arr = LDAP_get_entries($ds,$res);

        $result = array();
        for ($i=0; $i<$arr['count']; ++$i) {
            $result[$arr[$i]['dn']] = array("name"=>$arr[$i]['cn'][0]);
        }

        ldap_close($ds);
        return $result;
    }


    /** getGroup function
     *  @param $group_id
     *  @return array(uid, name, description)
     */
    function getGroup($group_id) {
        if ( !($ds=InitLDAP()) ) {
            return false;
        }

        $filter = "objectclass=groupofnames";
        $result = @ldap_read($ds, $group_id, $filter, array("cn","description"));
        if (!$result) {
            return false;
        }
        $entry  = ldap_first_entry ($ds, $result);
        $arr    = ldap_get_attributes($ds, $entry);

        $res = array();
        $res["uid"] = $group_id;
        if ( is_array($arr["cn"]) ) {
            $res["name"] = $arr["cn"][0];
        }
        if ( is_array($arr["description"]) ) {
            $res["description"] = $arr["description"][0];
        }

        ldap_close($ds);
        return $res;
    }

    /** getIDsInfo function
     * @param $id
     * @return an array containing basic information on $id (user DN or group DN)
     * or false if ID does not exist
     * array("mail" => $mail, "name" => $cn, "type" => "User" : "Group")
     */
    function getIDsInfo($id) {
        if ( !($ds=InitLDAP()) ) {
            return false;
        }

        $filter = "(|(objectclass=groupOfNames)(objectclass=inetOrgPerson))";
        $result = @ldap_read($ds, $id, $filter, array("objectclass","mail","cn","sn","givenname"));
        if (!$result) {
            return false;
        }
        $entry = ldap_first_entry($ds, $result);
        $arr   = $entry ? ldap_get_attributes($ds, $entry) : array();

        if ( !is_array($arr["objectclass"]) ) {  // new LDAP is case sensitive (v3)
            $arr["objectclass"] = $arr["objectClass"];
        }

        $res = array();
        $res['id'] = $id;
        for ($i=0; $i < $arr["objectclass"]["count"]; ++$i) {
            if (stristr($arr["objectclass"][$i], "groupofnames")) {
                $res["type"] = "Group";
            }
        }

        if (!$res["type"]) {
            $res["type"] = "User";
        }

        $res["login"] = $arr["uid"][0];
        $res["name"]  = $arr["cn"][0];
        $gname = ( is_array($arr["givenname"]) ? $arr["givenname"] : $arr["givenName"] );
        if ( is_array($gname) ) {
            $res["givenname"] = $gname[0];
        }

        if ( is_array($arr["sn"]) ) {
            $res["sn"] = $arr["sn"][0];
        }
        $res["mail"]  = $arr["mail"][0];
        $res["mails"] = $arr["mail"];

        ldap_close($ds);
        return $res;
    }


 //-----------------------------------
    /** getGroupMembers function
     * @param $group_id
     */
    function getGroupMembers($group_id) {
        if ( !($ds=InitLDAP()) ) {
            return false;
        }

        $filter = "objectclass=groupOfNames";
        $result = @ldap_read($ds, $group_id, $filter, array("member"));
        if (!$result) {
            return false;
        }
        $entry  = ldap_first_entry ($ds, $result);
        $arr    = ldap_get_attributes($ds, $entry);

        $res    = array();
        for ($i=0; $i < $arr["member"]["count"]; ++$i) {
            if ($info = $this->getIDsInfo($arr["member"][$i])) {
                $res[$arr["member"][$i]] = $info;
            }
        }

        ldap_close($ds);
        return $res;
    }

    /** getMembership function
     * @param $id
     * @param $flags - use to obey group in groups?
     * @return array of group_ids, where id (group or user) is a member
     */
    function getMembership($id, $flags = 0) {
        $aa_default_ldap = AA_Permsystem_Ldap::getLdap();

        if ( !($ds=InitLDAP()) ) {
            return array();
        }
        $result       = array();
        $groups       = array();
        $last_groups  = array($id);
        $deep_counter = 0;
        do {
            if ($deep_counter++ > MAX_GROUPS_DEEP) {
                break;
            }
            $search = "(&(objectclass=groupofnames)(|";
            // make search string
            foreach ($last_groups as $member) {
                $search .= "(member=$member)";
            }
            $search .= "))";
            $res = @ldap_search($ds,$aa_default_ldap['groups'],$search,array('member'));
            if (!$res) {
                // LDAP sizelimit exceed ?
                return (ldap_errno($ds)==4) ? "too much" : false;
            }
            $array = ldap_get_entries($ds,$res);
            unset($last_groups);  //get deeper groups to last_groups and groups
            for ($i=0; $i<$array["count"]; ++$i) {
                $last_groups[] = $array[$i]["dn"];
                $groups[$array[$i]["dn"]] = true;
            }
        } while ( is_array($last_groups) AND ($flags==0) );

        ldap_close($ds);
        if (is_array($groups)) {
            while (list($key,) = each($groups)) {
                $result[] = $key;                   // transform to a numbered array
            }
        }

        return $result;
    }

    /** getJoinedPerms function
     * @param $uid
     * @param $objectType
     * @param $groups
     * @return an array of sliceids and their permissions (for user $userid) counting all groups listed in third parameter
     * (the gorups could be also foreign - like from Reader Set, but permissions for such groups are stored here)
     */
    function getJoinedPerms($uid, $objectType, $groups=array()) {
        $aa_default_ldap = AA_Permsystem_Ldap::getLdap();
        if (!($ds=InitLDAP())) {
            return array();
        }

        $filter = "(&(objectclass=apcacl)(apcobjecttype=$objectType)(|(apcaci=$uid:*)";

        foreach ($groups as $group) {
            $filter .= "(apcaci=$group:*)";
        }
        $filter .= "))";

        $basedn = $aa_default_ldap['acls'];

        $result = ldap_search($ds,$basedn,$filter,array("apcaci","apcobjectid"));
        if (!$result) {
            return array();
        }

        $perms = array();
        $arr   = ldap_get_entries($ds,$result);
        for ($i=0; $i < $arr["count"]; ++$i) {
            // some older AAs could have mixed case atributes :-( (apcAci)
            $aci = (is_array($arr[$i]["apcaci"]) ? $arr[$i]["apcaci"] : $arr[$i]["apcAci"]);
            for ($j=0; $j < $aci["count"]; ++$j) {
                for ( $k=0, $kno=sizeof($groups); $k<$kno; ++$k) {
                    if (stristr($aci[$j],$groups[$k])) {
                        $perms[$arr[$i]["apcobjectid"][0]] .= GetApcAciPerm($aci[$j]);
                    }
                }
                if (stristr($aci[$j],$uid)) {
                    $perms[$arr[$i]["apcobjectid"][0]]     = GetApcAciPerm($aci[$j]);
                    break;           // specific ID's perm is stronger
                }
            }
        }
        return $perms;
    }

    /** getObjectsPerms function
     * @param $objectID
     * @param $ojectType
     * @return an array of user/group identities and their permissions
     *  granted on specified object $objectID
     */
    function getObjectsPerms($objectID, $objectType) {
        $aa_default_ldap = AA_Permsystem_Ldap::getLdap();
        if (!($ds=InitLDAP())) {
            return false;
        }

        $filter = "(&(objectclass=apcacl)(apcobjecttype=$objectType))";
        $basedn = "apcobjectid=$objectID,".$aa_default_ldap['acls'];

        $result = @ldap_read($ds,$basedn,$filter,array("apcaci"));
        if (!$result) {
            return false;
        }

        $entry = ldap_first_entry ($ds, $result);
        $arr   = ldap_get_attributes($ds, $entry);

        // some older AAs could have mixed case atributes :-( (apcAci)
        $aci = (is_array($arr["apcaci"]) ? $arr["apcaci"] : $arr["apcAci"]);

        $info = array();
        for ($i=0; $i < $aci["count"]; ++$i) {
            $apcaci = ParseApcAci( $aci[$i] );
            if ($apcaci) {
                $info[$apcaci["dn"]] = $apcaci["perm"];
            }
        }
        return $info;
     }
}
?>
