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

// permission letter definition
//---------- Slice -----------------
// author        - possibly letters 'abcdefg'
define("PS_EDIT_SELF_ITEMS",      "a");   # slice # change self-written items
// editor        - possibly letters 'hijklmnopqrs'
define("PS_ITEMS2ACT",            "h");   # slice # move item to approved bin
define("PS_ITEMS2HOLD",           "i");   # slice # move item to holding bin
define("PS_ITEMS2TRASH",          "j");   # slice # move item to trash bin
define("PS_EDIT_ALL_ITEMS",       "k");   # slice # change all items
define("PS_DELETE_ITEMS",         "l");   # slice # delete items
// administrator - possibly letters 'ABCDEFGHIJKLMNOP'
define("PS_EDIT",                 "A");   # slice # set slice properties
define("PS_CATEGORY",             "B");   # slice # change slice categories
define("PS_FIELDS",               "C");   # slice # edit fields defauts
define("PS_SEARCH",               "D");   # slice # change search form settings
define("PS_USERS",                "E");   # slice # manage users
define("PS_COMPACT",              "F");   # slice # change slice compact view
define("PS_FULLTEXT",             "G");   # slice # change item fulltext view
define("PS_FEEDING",              "H");   # slice # change properties
define("PS_ADD_USER",             "I");   # slice # add existing user to slice
define("PS_CONFIG",               "J");   # slice # configure slice (show/hide
                                                  #  columns in admin interface)
// super         - possibly letters 'QRSTUVW'
define("PS_ADD",                  "Q");   # aa    # add slice
define("PS_NEW_USER",             "R");   # aa    # create new user
define("PS_MANAGE_ALL_SLICES",    "S");   # aa    # edit all slices (this
                                                  # permission is useable, when
                                                  # you want credit some rights

//---------- Polls -----------------
// author        - possibly letters 'abcdefg'
define("PS_MODP_ADD_POLL",        "a");   # slice # add poll
// editor        - possibly letters 'hijklmnopqrs'
define("PS_MODP_POLLS2ACT",       "h");   # slice # move poll to approved bin
define("PS_MODP_POLLS2HOLD",      "i");   # slice # move poll to holding bin
define("PS_MODP_POLLS2TRASH",     "j");   # slice # move poll to trash bin
define("PS_MODP_EDIT_POLLS",      "k");   # slice #
define("PS_MODP_DELETE_POLLS",    "l");   # slice # delete poll
// administrator - possibly letters 'ABCDEFGHIJKLMNOP'
define("PS_MODP_SETTINGS",        "A");   # slice # set polls properties
define("PS_MODP_EDIT_DESIGN",     "B");   # slice # change polls design
// super         - possibly letters 'QRSTUVW'

//---------- Links -----------------
// author        - possibly letters 'abcdefg'
define("PS_LINKS_INHERIT",        "a");   # slice # user have also the rights to subcategories
// editor        - possibly letters 'hijklmnopqrs'
define("PS_LINKS_CHECK_LINK",     "h");   # slice # check link
define("PS_LINKS_HIGHLIGHT_LINK", "i");   # slice # highlight/dehighlight link
define("PS_LINKS_DELETE_LINK",    "j");   # slice # move link to trash bin
define("PS_LINKS_EDIT_CATEGORY",  "k");   # slice #
define("PS_LINKS_EDIT_LINKS",     "l");   # slice # edit links
define("PS_LINKS_ADD_SUBCATEGORY","m");   # slice # add subcategory to category
define("PS_LINKS_DEL_SUBCATEGORY","n");   # slice # delete subcategory from category
define("PS_LINKS_ADD_LINK",       "o");   # slice # add new link
define("PS_LINKS_LINK2FOLDER",    "p");   # slice # add new link
define("PS_LINKS_LINK2ACT",       "q");   # slice # add new link
// administrator - possibly letters 'ABCDEFGHIJKLMNOP'
// super         - possibly letters 'QRSTUVW'
define("PS_LINKS_SETTINGS",       "T");   # slice # add new link

//---------- Site -----------------
// author        - possibly letters 'abcdefg'
// editor        - possibly letters 'hijklmnopqrs'
define("PS_MODW_EDIT_CODE", "h");         #
// administrator - possibly letters 'ABCDEFGHIJKLMNOP'
define("PS_MODW_SETTINGS",  "A");         #       # set site module properties
// super         - possibly letters 'QRSTUVW'


# $perms_roles[role]['id'] is number stored to permission system for specified
# role. On usege time the number is replaced by set of letters defined in
# $perms_roles[role]['perm']. However, it is possible to store the permission
# letters into perm system directly (in case you want user with specific rights)

$perms_roles = array(
  "AUTHOR" => array(         # AUTHOR can write items and edit his items (is true for 'slice' module)
     'id' => '1',
     'perm' => 'abcdefg'),                # author
  "EDITOR" => array(         # EDITOR = AUTHOR + can edit and manage all items (is true for 'slice' module)
     'id' => '2',
     'perm' => 'abcdefg'.                 # author
               'hijklmnopqrs'),           # editor
  "ADMINISTRATOR" => array(  # ADMINISTRATOR = EDITOR + can change slice properties (is true for 'slice' module)
     'id' => '3',
     'perm' => 'abcdefg'.                 # author
               'hijklmnopqrs'.            # editor
               'ABCDEFGHIJKLMNOP'),       # administrator
  "SUPER" => array(          # SUPER = ADMINISTRATOR + can set any properties for any slice (is true for 'slice' module)
     'id' => '4',
     'perm' => 'abcdefg'.                 # author
               'hijklmnopqrs'.            # editor
               'ABCDEFGHIJKLMNOP'.        # administrator
               'QRSTUVW'));               # super
# reserve: tuvwxyzXYZ and special characters like +-/*@... (but no numbers!!!)


# defines, which roles youcan use with each module
$perms_roles_modules = array(
  'S'     => array("AUTHOR","EDITOR","ADMINISTRATOR"),  # S - slice
      # There is not listed SUPER, because SUPER is permission for 'aa' object
      # and not 'slice' object. 'aa' object is parent of all modules - setting
      # perm to 'aa' object is the same as setting it for all the modules
      # (specific setting of 'slice' module for the user is stronger than
      # the 'aa' seting)
  'W'     => array("ADMINISTRATOR"),                    # site module
  'A'     => array("ADMINISTRATOR"),                    # MySQL Auth module
  'J'     => array("ADMINISTRATOR"),                    # jump module
      # There is no specific roles in 'W', 'A', 'J' modules.
      # See include/constants.php3 for module definitions
  'P'     => array("EDITOR","ADMINISTRATOR"),           # polls module
  'Links' => array("AUTHOR","EDITOR","ADMINISTRATOR")); # Links module
      # AUTHOR in Links module is public - probably identified by free/freepwd
      # user. (S)he can just add links



// replaces roles with apropriate perms
// substitute role identifiers (1,2,3,4) with his permissions (E,A,R ...)
function ResolvePerms($perms) {
  global $perms_roles;

  reset($perms_roles);
  while( list(, $arr) = each($perms_roles))
    $perms = str_replace($arr['id'], $arr['perm'], $perms);
  return $perms;
}

// save all permissions for specified user to session variable
function CachePermissions($user_id) {
  global $permission_uid, $permission_to, $sess,
         $perms_roles, $r_superuser;

  $sess->register(permission_uid);
  $sess->register(permission_to);
  $sess->register(r_superuser);

  $permission_uid = $user_id;
  $permission_to["slice"] = GetIDPerms ($permission_uid, "slice");
  $permission_to["aa"] = GetIDPerms ($permission_uid, "aa");     // aa is parent of all slices
  if( !is_array($permission_to["slice"]) )  //convert to arrays
    $permission_to["slice"] = array();
  if( !is_array($permission_to["aa"]) )
    $permission_to["aa"] = array();

  # Resolve all permission (convert roles into perms)
  reset($permission_to["slice"]);
  while( list($key,$val) = each($permission_to["slice"]) )
    $permission_to["slice"][$key] = ResolvePerms($val);

  reset($permission_to["aa"]);
  while( list($key,$val) = each($permission_to["aa"]) ) {
    if( IsPerm($val, $perms_roles['SUPER']['id']) )
      $r_superuser[$key] = true;
    $permission_to["aa"][$key] = ResolvePerms($val);
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
  global $permission_uid, $permission_to;

  if($permission_uid != $user_id)
    CachePermissions($user_id);

  switch($objType) {
    case "aa":
      return IsPerm($permission_to["aa"][$objID], $perm);
    case "slice":
      return IsPerm(JoinAA_SlicePerm($permission_to["slice"][$objID], $permission_to["aa"][AA_ID]), $perm);
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
  global $perms_roles;
  if (ComparePerms($aa_perm, $perms_roles["SUPER"]['perm'])=="E") {
    return $aa_perm;
  } else {
    return ($slice_perm ? $slice_perm : $aa_perm);
  }
}

function GetUserSlices( $user_id = "current") {
  global $permission_uid, $permission_to, $auth;

  if ($user_id == "current")
    $user_id = $auth->auth ["uid"];

  if($permission_uid != $user_id)
    CachePermissions($user_id);

  if( IsPerm($permission_to["aa"][AA_ID], PS_MANAGE_ALL_SLICES) )
    return "all";

  return  $permission_to["slice"];
}

// shortcut for slice permission checking
function IfSlPerm($perm) {
  global $auth, $slice_id;
  return CheckPerms( $auth->auth["uid"], "slice", $slice_id, $perm);
}

// Checks if logged user is superadmin
function IsSuperadmin() {
  global $auth, $r_superuser, $permission_uid;
    # check all superadmin's global permissions
  if($permission_uid != $auth->auth["uid"])
    CachePermissions($auth->auth["uid"]);
  return $r_superuser[AA_ID];
}

/** Check if authenticed user has specified permissions to category
* (used for Links module)
*
* Slice id for each category in Links module is not random - it is predictable:
* <category_id>'Links'<shorted AA_ID>
*
* @return bool true if the user has specific $perm for $category
*/
function IsCatPerm($perm, $cat_path) {
    global $permission_uid, $permission_to;
    global $auth;

//    if (IsPerm( PS_LINKS_COMMON_PERMS, $perm )) // check perms granted to anybody
//        return true;

    if($permission_uid != $auth->auth["uid"])
        CachePermissions($auth->auth["uid"]);

    // check for current category permissions
    $parents = explode(",",$cat_path);
    $myIndex = count($parents)-1;  // index of this category

    $perm2cat = $permission_to["slice"][Links_Category2SliceID($parents[$myIndex])];
    $perm2aa  = $permission_to["aa"][AA_ID];

    if( $perm2cat )               // specific perms are set
        return IsPerm(JoinAA_SlicePerm($perm2cat,$perm2aa), $perm);

    // check for inherited permissions

    // go from leaves to root and check, if some permisions are defined
    // if defined on some level - stop and check
    for( $i=$myIndex-1; $i>=0; $i--) {
        $perm2cat = $permission_to["slice"][Links_Category2SliceID($parents[$i])];

        if( $perm2cat ) {      // specific perms are set
            if( strrchr($perm2cat, PS_LINKS_INHERIT) )  // inherited
                return IsPerm(JoinAA_SlicePerm($perm2cat,$perm2aa),$perm);
            break; // first upper category with permissions found - stop travelling
        }
    }
    return IsPerm( $perm2aa, $perm);
}

/** Change category permission as in template category
*   (used for Links module)
*/
function ChangeCatPermAsIn($category, $template) {
    // (Slice id for category in Links module is not random - it is predictable:
    // <category_id>'Links'<shorted AA_ID>
    $template_perm_id = Links_Category2SliceID($template);
    $category_perm_id = Links_Category2SliceID($category);

    // returns an array of user/group identities and their permissions
    // granted on specified object $objectID
    $newPerms = GetObjectsPerms($template_perm_id, 'slice');
    $oldPerms = GetObjectsPerms($category_perm_id, 'slice');

    // Delete all old perms
    if( isset($oldPerms) AND is_array($oldPerms)) {
        reset($oldPerms);
        while( list( $uid, ) = each($oldPerms))
        DelPerm ($uid, $category_perm_id, 'slice');
    }

    // Copy template's permissions
    if( isset($newPerms) AND is_array($newPerms)) {
        reset($newPerms);
        while( list( $uid, $arr) = each($newPerms))
        AddPerm ($uid, $category_perm_id, 'slice', $arr['perm']);
    }
}


/** Permissions for the on-line file manager
* (c) Jakub Adamek, Econnect, +-July 2002
*/
function FilemanPerms ($auth, $slice_id) {
    global $sess;
    // Sets the fileman_dir var:
    global $fileman_dir;

    $db = new DB_AA;
    $db->query("SELECT fileman_access, fileman_dir FROM slice WHERE id='".q_pack_id($slice_id)."'");

    if ($db->num_rows() != 1) return false;

    $db->next_record();
    $fileman_dir = $db->f("fileman_dir");
    if (IsSuperadmin()) return true;
    else if (!$fileman_dir) return false;

    if ($GLOBALS[debug]) echo "FILEMAN ACCESS ".$db->f("fileman_access");
    $perms_ok = false;
    if ($db->f("fileman_access") == "EDITOR"
        && IfSlPerm(PS_EDIT_ALL_ITEMS))
        $perms_ok = true;
    else if ($db->f("fileman_access") == "ADMINISTRATOR"
        && IfSlPerm(PS_FULLTEXT))
        $perms_ok = true;

    return $perms_ok;
}

/** get email permissions
* (c) Jakub Adamek, Econnect, December 2002
*
* @param $type      OPTIONAL emails type, see get_email_types() in util.php3.
*                   If not specified, all types are included.
* @param $user_id   OPTIONAL, default is current user
* @return array (email id => description)
*/
function GetUserEmails ($type = "", $user_id = "current") {
    global $auth, $db;
    if ($user_id == "current")
        $user_id = $auth->auth["uid"];
    $slices = GetUserSlices ($user_id);
    $where = "WHERE 1";
    if ($type) $where .= " AND type='$type'";
    if ($slices == "all")
        ;
    else if (!is_array ($slices) || count ($slices) == 0)
        return array ();
    else {
        reset ($slices);
        while (list ($slice) = each ($slices))
            $slice_ids[] = q_pack_id ($slice);
        $where .= " AND owner_module_id IN ('".join ("','", $slice_ids)."')";
    }
    $db->query("SELECT * FROM email ".$where);
    while ($db->next_record())
        $retval[$db->f("id")] = $db->f("description");
    return $retval;
}

/**
 * Grabs login name from LDAP username (you must use LDAP permission system)
 * @param $username in LDAP form (uid=peterf,ou=People,ou=AA)
 * @return username without additional characters ('peterf' in our example)
 *                  (for SQL permissions it returns username unchanged)
 * (TODO - return the username also for SQL permissions - probably by query
 *  to database)
 */
function perm_username( $username ) {
    if( PERM_LIB != 'ldap' )
        return $username;
    $begin = strpos($username, '=');
    $end   = strpos($username, ',');
    if ( !$begin OR !$end )
        return "anonym";
    return substr($username, $begin+1, $end-$begin-1);
}

require_once $GLOBALS["AA_BASE_PATH"]."modules/alerts/reader_field_ids.php3";

/** Looks into reader management slices whether the reader name is not yet used.
*   This function is used in perm_ldap and perm_sql in IsUsernameFree().
*/
function IsReadernameFree ($username) {
    $db = getDB();
    $SQL = "SELECT content.text FROM content
            INNER JOIN item ON content.item_id = item.id
            INNER JOIN slice ON item.slice_id = slice.id
            WHERE content.field_id='".FIELDID_USERNAME."'
            AND slice.type='ReaderManagement'
            AND content.text='$username'";
    $db->query ($SQL);
    $retval = ! $db->next_record();
    freeDB ($db);
    return $retval;
}

?>
