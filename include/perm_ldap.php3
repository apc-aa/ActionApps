<?php  # perm_ldap - functions for working with permissions with LDAP
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


// default ldap server for all searches
$aa_default_ldap = array("host"=>LDAP_HOST, 
                         "binddn"=>LDAP_BINDDN,
                         "bindpw"=>LDAP_BINDPW,
                         "basedn"=>LDAP_BASEDN,
                         "people"=>LDAP_PEOPLE,
                         "groups"=>LDAP_GROUPS,
                         "acls"=>LDAP_ACLS,
                         "port"=>LDAP_PORT);

// define special ldap servers for another-node authentification
// if not found - use $aa_default_ldap
$aa_ldap_servers = array(
   DEFAULT_ORG_ID=>$aa_default_ldap,
   "other.org"=>array("host"=>"ldap.other.org", 
                      "binddn"=>"cn=root,dc=other,dc=apc,dc=org",
                      "bindpw"=>"passwordx",
                      "basedn"=>"dc=other,dc=apc,dc=org",
                      "people"=>"ou=People,dc=other,dc=apc,dc=org",
                      "groups"=>"ou=Groups,dc=other,dc=apc,dc=org",
                      "acls"=>"ou=ACLs,dc=other,dc=apc,dc=org"),
   "another.org"=>array("host"=>"ldap.another.org",
                        "binddn"=>"cn=root,dc=another,dc=apc,dc=org",
                        "bindpw"=>"password2", 
                        "basedn"=>"dc=another,dc=apc,dc=org",
                        "people"=>"ou=People,dc=another,dc=apc,dc=org",
                        "groups"=>"ou=Groups,dc=another,dc=apc,dc=org",
                        "acls"=>"ou=ACLs,dc=another,dc=apc,dc=org"));
   
##############################################################################
# API functions
##############################################################################

// returns uid if user is authentificied, else false.
function AuthenticateUsername($username, $password, $flags = 0) {
  global $aa_ldap_servers, $aa_default_ldap;
  if (!$username or !$password) {         // no password => anonymous in LDAP
     return false;
  }

  $return_val=false;
  if($org = strstr($username, "@"))       // user tries to auth. via e-mail  
    $LDAPserver = WhereToSearch( substr($org,"@"));  // get ldap server for this address
   else 
    $LDAPserver = $aa_default_ldap;

  $ds = LDAP_Connect($LDAPserver[host], $LDAPserver[port]);	// connect LDAP server
  if (!$ds)                  			// not connected
    return false;	

  if($org = strstr($username, "@")) { // user typed e-mail -> search to get DN
    $search = "(&(objectclass=inetOrgPerson)(mail=$username))";
    if (@LDAP_Bind($ds, $LDAPserver[binddn], $LDAPserver[bindpw] )) {
      $r = LDAP_search($ds, $LDAPserver[people], $search, array(""));
      $arr = LDAP_get_entries($ds,$r);
      if ( $arr["count"] > 0 )
        $userdn = $arr[0]["dn"];
       else {
        @LDAP_Close($ds);
        return false; 
      }  
    }    
  } else {                                    // build DN
    $userdn = "uid=$username,".$LDAPserver[people];
  }

  if (@LDAP_Bind($ds, $userdn, $password)) {  // try to authenticate
      $return_val = $userdn;
  }
  @LDAP_Close($ds);
  return $return_val;  
}

################ User functions ##############################################

// creates new person in LDAP permission system
function AddUser($user, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;

  $record["objectclass"][0]   = "top";
  $record["objectclass"][1]   = "person";
  $record["objectclass"][2]   = "organizationalperson";
  $record["objectclass"][3]   = "inetorgperson";
  $record["cn"] = $user["givenname"] . "  " . $user["sn"];
  $record["sn"] = $user["sn"];
  $record["givenname"] = $user["givenname"];
  if ($user["mail"]) { $record["mail"] = $user["mail"]; }  // can be an array
  $record["uid"] = $user["uid"];
  $record["userPassword"] = "{md5}" 
                  . base64_encode(pack("H*",md5($user["userpassword"])));
  if ($user["phone"]) { $record["telephoneNumber"] = $user["phone"]; };

  // add data to directory
  $user_dn = "uid=$user[uid]," . $aa_default_ldap[people];  
  $r=@ldap_add($ds, $user_dn, $record);
  ldap_close($ds);
  if ($r) {
     return $user_dn;
  } else {
     return false;
  }
}

// deletes an user in LDAP permission system
// $user_id is DN
function DelUser ($user_id, $flags = 3) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;

  // To keep integrity of LDAP DB, we should also delete all references
  // to this user in other LDAP entries (e.g. member=.., apcaci=..).
  // But this requires explicit knowledge of the schema!
  
  if ($flags & 1) {            // cancel membership in groups
     $filter = "(&(objectclass=groupOfNames)(member=$user_id))";
     $r = ldap_search($ds, $aa_default_ldap[groups], $filter, array(""));
     $arr = ldap_get_entries($ds,$r);
     for($i=0; $i < $arr["count"]; $i++) {
        DelGroupMember($arr[$i]["dn"], $user_id);
     }
     ldap_free_result($r);
  }
  
  if ($flags & 2) {            // cancel asssigned permissions
     $filter = "(&(objectclass=apcacl)(apcaci=$user_id:*))";
     $r = ldap_search($ds, $aa_default_ldap[acls], $filter,
                      array("apcObjectType","apcaci","apcObjectID"));
     $arr = ldap_get_entries($ds,$r);
     for($i=0; $i < $arr["count"]; $i++) {
        DelPerm($user_id, $arr[$i]["apcobjectid"][0], 
                $arr[$i]["apcobjecttype"][0]);   // indexes in lowercase !!!
     }
     ldap_free_result($r);
  }    
  
  $r=@ldap_delete($ds, $user_id);
    
  ldap_close($ds);
  return $r; 
}

// changes user entry in LDAP permission system
function ChangeUser ($user, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;
    
  $record["cn"] = $user["givenname"]." ".$user["sn"];
  $record["sn"] = $user["sn"];
  $record["givenname"] = $user["givenname"];
  $record["mail"] = $user["mail"];                // can be an array
  if ($user["userpassword"])
    $record["userPassword"] = "{md5}" 
                  . base64_encode(pack("H*",md5($user["userpassword"])));
  if ($user["phone"]) 
    $record["telephoneNumber"] = $user["phone"]; 

  // add data to directory
//p_arr_m($record);
//huh("Uid:".$user[uid].":");
  $r=@ldap_mod_replace($ds, $user[uid], $record);
  ldap_close($ds);
  return $r; 
}

// returns array(cn, sn, givenname, array(mail), array(phone))
function GetUser ($user_id, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;

  $filter = "objectclass=inetOrgPerson";
  $result = @ldap_read($ds, $user_id, $filter, 
               array("uid","cn","sn","givenname","mail","telephonenumber"));
  if (!$result) return false; 
  $entry = ldap_first_entry ($ds, $result);
  $arr = ldap_get_attributes($ds, $entry);

  $res["uid"] = $user_id;
  $res["login"] = $arr["uid"][0];
  if( is_array($arr["givenname"]) )
    $res["givenname"] = $arr["givenname"][0];
  if( is_array($arr["sn"]) )
    $res["sn"] = $arr["sn"][0];
  if( is_array($arr["cn"]) )
    $res["cn"] = $arr["cn"][0];
  if( is_array($arr["mail"]) )
    for($i=0; $i < $arr["mail"]["count"]; $i++) 
      $res["mail"][$i] = $arr["mail"][$i];
  if( is_array($arr["telephonenumber"]) ) 
    for($i=0; $i < $arr["telephonenumber"]["count"]; $i++) 
      $res["phone"][$i] = $arr["telephonenumber"][$i];
        
  ldap_close($ds);
  return $res;
}

################ Group functions #############################################

// creates new group in LDAP permission system
// $group is an array ("name", "description", ...)
function AddGroup ($group, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;

  $record["objectclass"][0]   = "top";
  $record["objectclass"][1]   = "groupOfNames";
  $record["cn"] = $group["name"];
  $record["member"] = "";
  
  if ($group["description"]) $record["description"] = $group["description"];

  // add data to directory
  $group_dn = "cn=$group[name]," . $aa_default_ldap[groups];
  $r=@ldap_add($ds, $group_dn, $record);
  ldap_close($ds);
  if ($r) { 
     return $group_dn;
  } else {
     return false;
  }
}

// deletes a group in LDAP permission system
// $group_id is DN
function DelGroup ($group_id, $flags = 3) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;

  // To keep integrity of LDAP DB, we should also delete all references
  // to this group in other LDAP entries (e.g. member=.., apcaci=..).
  // But this requires explicit knowledge of the schema.
  
  if ($flags & 1) {            // cancel membership in other groups
     $filter = "(&(objectclass=groupOfNames)(member=$group_id))";
     $r = ldap_search($ds, $aa_default_ldap[groups], $filter, array(""));
     $arr = ldap_get_entries($ds,$r);
     for($i=0; $i < $arr["count"]; $i++) {
        DelGroupMember($arr[$i]["dn"], $group_id);
     }
     ldap_free_result($r);
  }    

  if ($flags & 2) {            // cancel asssigned permissions
     $filter = "(&(objectclass=apcacl)(apcaci=$group_id:*))";
     $r = ldap_search($ds, $aa_default_ldap[acls], $filter,
                      array("apcObjectType","apcaci","apcObjectID"));
     $arr = ldap_get_entries($ds,$r);
     for($i=0; $i < $arr["count"]; $i++) {
        DelPerm($group_id, $arr[$i]["apcobjectid"][0], 
                $arr[$i]["apcobjecttype"][0]);   // indexes in lowercase !!!
     }
     ldap_free_result($r);
  }    
  
  $r=@ldap_delete($ds, $group_id);
    
  ldap_close($ds);
  return $r; 
}

// changes group entry in LDAP permission system
function ChangeGroup ($group, $flags = 0) {
  global $aa_default_ldap;
 
  if( !($ds=InitLDAP()) )
    return false;
    
  $record["description"] = $group["description"];
  if ($group["name"]) 
    $record["cn"] = $group["name"];

  // add data to directory
//p_arr_m($record);
//huh("Uid:".$user[uid].":");
  $r=@ldap_mod_replace($ds, $group[uid], $record);
  ldap_close($ds);
  return $r; 
}

// returns array(uid, name, description)
function GetGroup ($user_id, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;

  $filter = "objectclass=groupofnames";
  $result = @ldap_read($ds, $user_id, $filter, array("cn","description"));
  if (!$result) return false; 
  $entry = ldap_first_entry ($ds, $result);
  $arr = ldap_get_attributes($ds, $entry);

  $res["uid"] = $user_id;
  if( is_array($arr["cn"]) )
    $res["name"] = $arr["cn"][0];
  if( is_array($arr["description"]) )
    $res["description"] = $arr["description"][0];
        
  ldap_close($ds);
  return $res;
}

// function returns list of groups which corresponds to mask $pattern
function FindGroups ($pattern, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;

  $filter = "(&(objectclass=groupofnames)(cn=$pattern*))";
  $res = @ldap_search($ds,$aa_default_ldap[groups],$filter,array("cn"));
  if (!$res) {
    if(ldap_errno($ds)==4)    // LDAP sizelimit exceed
      return "too much";
     else 
      return false;
  }
  $arr = LDAP_get_entries($ds,$res);

  for($i=0; $i<$arr[count]; $i++) 
    $result[$arr[$i][dn]] = array("name"=>$arr[$i][cn][0]);
  
  ldap_close($ds);
  return $result;
}

// function returns list of users which corresponds to mask $pattern
function FindUsers ($pattern, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;

  $filter = "(&(objectclass=inetOrgPerson)(|(uid=$pattern*)(cn=$pattern*)(mail=$pattern*)))";
  $res = @ldap_search($ds,$aa_default_ldap[people],$filter,array("mail","cn"));
  if (!$res) {
    if(ldap_errno($ds)==4)    // LDAP sizelimit exceed
      return "too much";
     else 
      return false;
  }
  $arr = LDAP_get_entries($ds,$res);

  for($i=0; $i<$arr[count]; $i++) 
    $result[$arr[$i][dn]] = array("name"=>$arr[$i][cn][0], "mail"=>$arr[$i][mail][0]);
  
  ldap_close($ds);
  return $result;
}

function AddGroupMember ($group_id, $id, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;
    
  $r=@ldap_mod_add($ds, $group_id, array("member" => "$id"));
  ldap_close($ds);
  return $r;
}

function DelGroupMember ($group_id, $id, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;
    
  // immediate ldap_mod_del fails, if there is only one member attribute (=$id)
  $filter = "objectclass=groupOfNames";
  $result = @ldap_read($ds, $group_id, $filter, array("member"));
  if (!$result) return false; 
  $entry = ldap_first_entry ($ds, $result);
  $arr = ldap_get_attributes($ds, $entry);

  for($i=0; $i < $arr["member"]["count"]; $i++) {
    if(!stristr($arr["member"][$i], $id)) {
      $new["member"][] = $arr["member"][$i];
    }
  }
  
  if (sizeof($new["member"]) == 0) {
     $new["member"][] = "";
  }

  $r=ldap_mod_replace($ds, $group_id, $new);
  ldap_close($ds);
  return $r;
}

function GetGroupMembers ($group_id, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;

  $filter = "objectclass=groupOfNames";
  $result = @ldap_read($ds, $group_id, $filter, array("member"));
  if (!$result) return false; 
  $entry = ldap_first_entry ($ds, $result);
  $arr = ldap_get_attributes($ds, $entry);

  for($i=0; $i < $arr["member"]["count"]; $i++) {
    if ($info = GetIDsInfo ($arr["member"][$i], $ds)) {
       $res[$arr["member"][$i]] = $info;
    }
  }
  
  ldap_close($ds);
  return $res;
}

// returns list of group_ids, where id (group or user) is a member
// $flags - use to obey group in groups?
function GetMembership ($id, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;
  $last_groups[] = $id;
  $deep_counter = 0;
  do{
    if($deep_counter++ > MAX_GROUPS_DEEP)
      break;
    $search = "(&(objectclass=groupofnames)(|";
    reset($last_groups); // make search string
    while( list(,$member) = each($last_groups))
      $search .= "(member=$member)";
    $search .= "))";
    $res = @ldap_search($ds,$aa_default_ldap[groups],$search,array("member"));
    if (!$res) {
      if(ldap_errno($ds)==4)    // LDAP sizelimit exceed
        return "too much";
       else 
        return false;
    }
    $array = ldap_get_entries($ds,$res);
    unset($last_groups);  //get deeper groups to last_groups and groups
    for($i=0; $i<$array["count"]; $i++) {
      $last_groups[] = $array[$i]["dn"];
      $groups[$array[$i]["dn"]] = TRUE;
    }  
  } while( is_array($last_groups) AND ($flags==0) );
  
  ldap_close($ds);  
  if (is_array($groups)) {
     while (list($key,) = each($groups)) {
        $result[] = $key;                   // transform to a numbered array
     }
  }
  return $result;
}

################ Permission functions ########################################

// creates a new object in LDAP
function AddPermObject ($objectID, $objectType, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;
    
  $record["objectclass"][0]   = "top";
  $record["objectclass"][1]   = "apcacl";
  $record["apcobjectid"]   = $objectID;
  $record["apcobjecttype"] = $objectType;

  // add data to directory
  $r=ldap_add($ds, "apcobjectid=$objectID,". $aa_default_ldap[acls], $record);
  ldap_close($ds);
  return $r; 
}

// deletes an ACL object in LDAP permission system
function DelPermObject ($objectID, $objectType, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;

  $r=@ldap_delete($ds, "apcobjectid=$objectID,". $aa_default_ldap[acls]);
    
  ldap_close($ds);
  return $r; 
}

// append permission to existing object
function AddPerm($id, $objectID, $objectType, $perm, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;
    
  $filter = "objectclass=apcacl";
  $basedn = "apcobjectid=" . $objectID . "," . $aa_default_ldap[acls];
  $result = @ldap_read($ds, $basedn, $filter, array("apcaci"));
  if (!$result) return false;
  $entry = ldap_first_entry ($ds, $result);
  $arr = ldap_get_attributes($ds, $entry);
  
  for($i=0; $i < $arr["apcaci"]["count"]; $i++) { // copy old ApcAci values
    if(!stristr($arr["apcaci"][$i], $id)) {   // except the modified/deleted one
      $new["apcaci"][] = $arr["apcaci"][$i];
    } else {
      $old["apcaci"][] = $arr["apcaci"][$i];
    }
  }

  if($perm) {
    $new["apcaci"][] = "$id:$perm";
  }
  
  if (count($new) > 0) {
    $r=@ldap_mod_replace($ds, $basedn, $new);
  } else {
    $r=@ldap_mod_del($ds, $basedn, $old);
  }
  
  ldap_close($ds);
  return $r;          // true or false
}

function DelPerm ($id, $objectID, $objectType, $flags = 0) {
  return AddPerm ($id, $objectID, $objectType, false);
}

function ChangePerm ($id, $objectID, $objectType, $perm, $flags = 0) {
  return AddPerm ($id, $objectID, $objectType, $perm);
}

// returns an array of user/group identities and their permissions
// granted on specified object $objectID
function GetObjectsPerms ($objectID, $objectType, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;

  $filter = "(&(objectclass=apcacl)(apcobjecttype=$objectType))";
  $basedn = "apcobjectid=$objectID,$aa_default_ldap[acls]";
  
  $result = @ldap_read($ds,$basedn,$filter,array("apcaci"));
  if (!$result) return false;
  
  $entry = ldap_first_entry ($ds, $result);
  $arr = ldap_get_attributes($ds, $entry);

  for($i=0; $i < $arr["apcaci"]["count"]; $i++) {
    $apcaci = ParseApcAci( $arr["apcaci"][$i] );
    if( $apcaci ) {
      $info[$apcaci["dn"]]   = GetIDsInfo($apcaci["dn"]);
      $info[$apcaci["dn"]]["perm"] = $apcaci["perm"];
    }  
  }
  return $info;
}

// returns an array of user/group identities and their permissions
// granted on all objects of type $objectType
// flags & 1 -> do not involve membership in groups
function GetIDPerms ($id, $objectType, $flags = 0) {
  global $aa_default_ldap;
  if( !($ds=InitLDAP()) )
    return false;
    
  $filter = "(&(objectclass=apcacl)(apcobjecttype=$objectType)" . 
            "(|(apcaci=$id:*)";

  if (!($flags & 1)) {
     $groups = GetMembership($id);
     for ($i = 0; $i < sizeof($groups); $i++) {
        $filter .= "(apcaci=$groups[$i]:*)";
     }
  }
  $filter .= "))";
  
  $basedn = $aa_default_ldap[acls];

  $result = ldap_search($ds,$basedn,$filter,array("apcaci","apcobjectid"));
  if (!$result) return false;
  
  $arr = ldap_get_entries($ds,$result);
  
  for($i=0; $i < $arr["count"]; $i++) {
     for($j=0; $j < $arr[$i]["apcaci"]["count"]; $j++) {
        for ($k = 0; $k < sizeof($groups); $k++) {
           if (stristr($arr[$i]["apcaci"][$j],$groups[$k])) {
              $perms[$arr[$i]["apcobjectid"][0]] .= 
                 GetApcAciPerm ($arr[$i]["apcaci"][$j]);
           }
        }
        if (stristr($arr[$i]["apcaci"][$j],$id)) {
           $perms[$arr[$i]["apcobjectid"][0]] =
               GetApcAciPerm ($arr[$i]["apcaci"][$j]);
           break;           // specific ID's perm is stronger 
        }
     }
  }
  return $perms;
}


##############################################################################
# Internal functions
##############################################################################

// decides which LDAP server ask for authentification (acording to org - ecn.cz ..)
function WhereToSearch($org) {
  global $aa_ldap_servers, $aa_default_ldap;
  return ($aa_ldap_servers[$org] ? $aa_ldap_servers[$org] : $aa_default_ldap);
}    

// connect to LDAP server
function InitLDAP() {
  global $aa_default_ldap;
  
  $ds = LDAP_Connect($aa_default_ldap[host], $aa_default_ldap[port]);	// connect LDAP server
  if (!$ds)   				// not connect
    return false;
    
  if (!LDAP_Bind($ds, $aa_default_ldap[binddn], $aa_default_ldap[bindpw] )) 
    return false;  		// not authentificed
  return $ds;  
}  

// parse apcaci LDAP entry
function ParseApcAci($str) {
  if( ereg("(.*):([^:]*)$", $str, $foo)) 
    return array("dn"=>$foo[1], "perm"=>$foo[2]);
  return false;
}  

function GetApcAciPerm( $str ) {
  ereg("(.*):([^:]*)$", $str, $foo);
  return $foo[2];                          // permission string
}  

// returns an array containing basic information on $id (user DN or group DN)
// or false if ID does not exist
// array("mail => $mail", "name => $cn", "type => L_USER : L_GROUP")
function GetIDsInfo ($id, $ds = "") {
  global $aa_default_ldap;

  if( !$id )
    return false;
   
  if( $ds=="" ) {
    if( !($ds=InitLDAP()) )
      return false;
  }else
    $no_ldap_close=true;    

  $filter = "(|(objectclass=groupOfNames)(objectclass=inetOrgPerson))";
  $result = @ldap_read($ds, $id, $filter, array("objectclass","mail","cn"));
  if (!$result) return false; 
  $entry = ldap_first_entry ($ds, $result);
  $arr = ldap_get_attributes($ds, $entry);
  
  for($i=0; $i < $arr["objectclass"]["count"]; $i++) {
    if(stristr($arr["objectclass"][$i], "groupofnames")) {
       $res["type"] = L_GROUP;
    }
  }
  
  if (!$res["type"])
    $res["type"] = L_USER;
  $res["name"] = $arr["cn"][0];
  $res["mail"] = $arr["mail"][0];  

  if( !$no_ldap_close )    
    ldap_close($ds);
  return $res;
}

/*
$Log$
Revision 1.15  2001/03/30 11:54:35  honzam
offline filling bug and others small bugs fixed

Revision 1.14  2001/02/26 14:41:27  honzam
added port specification for LDAP server

Revision 1.13  2001/01/22 17:32:49  honzam
pagecache, logs, bugfixes (see CHANGES from v1.5.2 to v1.5.3)

Revision 1.11  2000/08/14 09:22:17  kzajicek
Fixed incorrect default for in DelGroup

Revision 1.10  2000/08/11 17:12:48  kzajicek
E-mail is not required

Revision 1.9  2000/08/10 15:37:18  kzajicek
UserAdd now returns user_id on success and false on error.

Revision 1.8  2000/08/09 15:13:24  kzajicek
Fixed incorrect default for  in DelUser

Revision 1.7  2000/08/01 13:04:44  kzajicek
OpenLDAP search did not work well when apcaci was not the last attribute.
ldap_mod_del is now used only when there will be no apcaci value in the entry.

Revision 1.6  2000/07/28 14:46:32  kzajicek
GroupAdd now returns group_id on success and false on error.

Revision 1.5  2000/07/28 14:37:33  kzajicek
Unified behaviour of DelUser, DelGroup in LDAP and SQL. Default is now
delete any links to removed subject in permission system (to keep integrity).

Revision 1.4  2000/07/28 14:25:02  kzajicek
DelUser and DelGroup now able to cancel assigned permissions.

Revision 1.3  2000/07/21 14:45:41  kzajicek
Admin needs to see login names, not IDs (DB specific)

Revision 1.2  2000/07/17 10:31:16  kzajicek
Fixed login without password bug

Revision 1.1.1.1  2000/06/21 18:40:45  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:26  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.25  2000/06/12 19:58:37  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.24  2000/05/30 09:11:39  honzama
MySQL permissions upadted and completed.

Revision 1.23  2000/05/15 18:18:11  karelz
Fixed bug in FindUsers.

Revision 1.22  2000/04/28 09:49:30  honzama
Small bug in user/group search fixed.

Revision 1.21  2000/04/24 16:50:34  honzama
New usermanagement interface.

Revision 1.20  2000/03/29 15:51:17  honzama
Fixed bug in bitwise comparison of $flags argument.

Revision 1.19  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/

?>