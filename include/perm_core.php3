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

// perm_core.php3
// Definitions and functions used no matter which one perm_*.php3 backend
// is used.
//

define("MAX_GROUPS_DEEP", 16);   // Maximum number of nested groups (user belongs to group1, group1 to group2 ...)
define("MAX_ENTRIES_SHOWN",10);   // Maximum number of shown users in search for users/groups

// permission letter definition
//---------- Slice -----------------
// author        - possibly letters 'abcdefg'
define("PS_EDIT_SELF_ITEMS",      "a");   // slice | change self-written items
define("PS_EDIT_SELF_USER_DATA",  "b");   // slice | change data for current userself-written items
// editor        - possibly letters 'hijklmnopqrs'
define("PS_ITEMS2ACT",            "h");   // slice | move item to approved bin
define("PS_ITEMS2HOLD",           "i");   // slice | move item to holding bin
define("PS_ITEMS2TRASH",          "j");   // slice | move item to trash bin
define("PS_EDIT_ALL_ITEMS",       "k");   // slice | change all items
define("PS_DELETE_ITEMS",         "l");   // slice | delete items
// administrator - possibly letters 'ABCDEFGHIJKLMNOP'
define("PS_EDIT",                 "A");   // slice | set slice properties
define("PS_CATEGORYxxx",          "B");   // slice | change slice categories - now free (not used - you can rename it - honza 2009-11-3)
define("PS_FIELDS",               "C");   // slice | edit fields defauts
define("PS_BOOKMARK",             "D");   // slice | change search form settings
define("PS_USERS",                "E");   // slice | manage users (change perms
                                          //         to slice, set profile)
define("PS_COMPACT",              "F");   // slice | change slice compact view
define("PS_FULLTEXT",             "G");   // slice | change item fulltext view
define("PS_FEEDING",              "H");   // slice | change properties
define("PS_ADD_USER",             "I");   // slice | add existing user to slice
define("PS_CONFIG",               "J");   // slice | configure slice (show/hide columns in admin interface)
define("PS_FORMS",                "K");   // slice | edit forms

// super         - possibly letters 'QRSTUVW'
define("PS_ADD",                  "Q");   // aa    | add slice
define("PS_NEW_USER",             "R");   // aa    | create new user
define("PS_MANAGE_ALL_SLICES",    "S");   // aa    | edit all slices (this
                                          //         permission is useable, when
                                          //         you want credit some rights
define("PS_HISTORY",              "T");   // slice | access history log

//---------- Polls -----------------
// author        - possibly letters 'abcdefg'
define("PS_MODP_ADD_POLL",        "a");   // slice | add poll
// editor        - possibly letters 'hijklmnopqrs'
define("PS_MODP_POLLS2ACT",       "h");   // slice | move poll to approved bin
define("PS_MODP_POLLS2HOLD",      "i");   // slice | move poll to holding bin
define("PS_MODP_POLLS2TRASH",     "j");   // slice | move poll to trash bin
define("PS_MODP_EDIT_POLLS",      "k");   // slice |
define("PS_MODP_DELETE_POLLS",    "l");   // slice | delete poll
// administrator - possibly letters 'ABCDEFGHIJKLMNOP'
define("PS_MODP_SETTINGS",        "A");   // slice | set polls properties
define("PS_MODP_EDIT_DESIGN",     "B");   // slice | change polls design
// super         - possibly letters 'QRSTUVW'

//---------- Links -----------------
// author        - possibly letters 'abcdefg'
define("PS_LINKS_INHERIT",        "a");   // slice | user have also the rights to subcategories
// editor        - possibly letters 'hijklmnopqrs'
define("PS_LINKS_CHECK_LINK",     "h");   // slice | check link
define("PS_LINKS_HIGHLIGHT_LINK", "i");   // slice | highlight/dehighlight link
define("PS_LINKS_DELETE_LINK",    "j");   // slice | move link to trash bin
define("PS_LINKS_EDIT_CATEGORY",  "k");   // slice |
define("PS_LINKS_EDIT_LINKS",     "l");   // slice | edit links
define("PS_LINKS_ADD_SUBCATEGORY","m");   // slice | add subcategory to category
define("PS_LINKS_DEL_SUBCATEGORY","n");   // slice | delete subcategory from category
define("PS_LINKS_ADD_LINK",       "o");   // slice | add new link
define("PS_LINKS_LINK2FOLDER",    "p");
define("PS_LINKS_LINK2ACT",       "q");
// administrator - possibly letters 'ABCDEFGHIJKLMNOP'
define("PS_LINKS_SETTINGS",       "A");
define("PS_LINKS_EDIT_DESIGN",    "B");
// super         - possibly letters 'QRSTUVW'

//---------- Site -----------------
// author        - possibly letters 'abcdefg'
// editor        - possibly letters 'hijklmnopqrs'
define("PS_MODW_EDIT_CODE", "h");         //
// administrator - possibly letters 'ABCDEFGHIJKLMNOP'
define("PS_MODW_SETTINGS",  "A");         //       | set site module properties
// super         - possibly letters 'QRSTUVW'


// $perms_roles[role]['id'] is number stored to permission system for specified
// role. On usege time the number is replaced by set of letters defined in
// $perms_roles[role]['perm']. However, it is possible to store the permission
// letters into perm system directly (in case you want user with specific rights)

$perms_roles = array(
  "AUTHOR" => array(         // AUTHOR can write items and edit his items (is true for 'slice' module)
     'id' => '1',
     'perm' => 'abcdefg'),                // author
  "EDITOR" => array(         // EDITOR = AUTHOR + can edit and manage all items (is true for 'slice' module)
     'id' => '2',
     'perm' => 'abcdefg'.                 // author
               'hijklmnopqrs'),           // editor
  "ADMINISTRATOR" => array(  // ADMINISTRATOR = EDITOR + can change slice properties (is true for 'slice' module)
     'id' => '3',
     'perm' => 'abcdefg'.                 // author
               'hijklmnopqrs'.            // editor
               'ABCDEFGHIJKLMNOP'),       // administrator
  "SUPER" => array(          // SUPER = ADMINISTRATOR + can set any properties for any slice (is true for 'slice' module)
     'id' => '4',
     'perm' => 'abcdefg'.                 // author
               'hijklmnopqrs'.            // editor
               'ABCDEFGHIJKLMNOP'.        // administrator
               'QRSTUVW'));               // super
// reserve: tuvwxyzXYZ and special characters like +-/*@... (but no numbers!!!)


// defines, which roles youcan use with each module
$perms_roles_modules = array(
  'S'     => array("AUTHOR","EDITOR","ADMINISTRATOR"),  // S - slice
      // There is not listed SUPER, because SUPER is permission for 'aa' object
      // and not 'slice' object. 'aa' object is parent of all modules - setting
      // perm to 'aa' object is the same as setting it for all the modules
      // (specific setting of 'slice' module for the user is stronger than
      // the 'aa' seting)
  'W'     => array("ADMINISTRATOR"),                    // site module
  'A'     => array("ADMINISTRATOR"),                    // MySQL Auth module
  'J'     => array("ADMINISTRATOR"),                    // jump module
      // There is no specific roles in 'W', 'A', 'J' modules.
      // See include/constants.php3 for module definitions
  'Alerts'=> array("ADMINISTRATOR"),                    // Alerts module
  'P'     => array("EDITOR","ADMINISTRATOR"),           // polls module
  'Links' => array("AUTHOR","EDITOR","ADMINISTRATOR")); // Links module
      // AUTHOR in Links module is public - probably identified by free/freepwd
      // user. (S)he can just add links


/**
 *
 */
class AA_Perm_Resource {
    /** Array which defines permission path
     *  Each member of this array is class-id pair - like
     *  AA_Item-7fe5b5646b08af4c3b5295a0186629cc
     *  The first is always AA_Actionapps class, second is of AA_Module class
     *
     *  Example:
     *    $path[0] - array( AA_Actionapps, '37a4b5646b08af4c3b5295a018662e6e' )
     *    $path[1] - array( AA_Module,     '6ba4b366690bac2d3b9295607866654a' ) // slice_id
     *    $path[2] - array( AA_Item,       '7fe5b5646b08af4e3d58d5a3596629cc' ) // or 'AA_View', 'AA_Field', ...
     *    $path[3] - array( AA_Field,      '4356782eab08af4cce5295a018662563' )
     *    $path[4] - ..
     */
    var $path;

    // permstring - something like 'item-63353633636373737/slice-62525525/62524234233232/[default aa]'
    function AA_Perm_Resource($perm_string) {

    }

}



/** ResolvePerms function
 *  Replaces roles with apropriate perms
 *  substitute role identifiers (1,2,3,4) with his permissions (E,A,R ...)
 *  @param $perms
 */
function ResolvePerms($perms) {
    global $perms_roles;

    foreach ($perms_roles as $arr) {
        $perms = str_replace($arr['id'], $arr['perm'], $perms);
    }
    return $perms;
}

/** CachePermissions function
 *  Save all permissions for specified user to session variable
 * @param $user_id
 */
function CachePermissions($user_id) {
    global $permission_uid, $permission_to, $sess, $perms_roles, $r_superuser;

    $sess->register('permission_uid');
    $sess->register('permission_to');
    $sess->register('r_superuser');

    $permission_uid         = $user_id;
    $permission_to["slice"] = GetIDPerms($permission_uid, "slice");
    $permission_to["aa"]    = GetIDPerms($permission_uid, "aa");     // aa is parent of all slices

    if (!is_array($permission_to["slice"])) { // convert to arrays
        $permission_to["slice"] = array();
    }
    if (!is_array($permission_to["aa"])) {
        $permission_to["aa"] = array();
    }

    // Resolve all permission (convert roles into perms)
    foreach ($permission_to["slice"] as $key => $val) {
        $permission_to["slice"][$key] = ResolvePerms($val);
    }

    foreach ($permission_to["aa"] as $key => $val) {
        if ( IsPerm($val, $perms_roles['SUPER']['id']) ) {
            $r_superuser[$key] = true;
        }
        $permission_to["aa"][$key] = ResolvePerms($val);
    }
}

/** IsPerm function
 *  Check, if specified $perm is in $perms list
 * @param $perms
 * @param $perm
 */
function IsPerm($perms, $perm){
    return ( !$perms || !$perm ) ? false : strstr($perms,$perm);
}

/** CheckPerms function
 *  Check if user has specified permissions
 * @param $user_id
 * @param $objType
 * @param $objID
 * @param $perm
 */
function CheckPerms( $user_id, $objType, $objID, $perm) {
    global $permission_uid, $permission_to;
    if ($permission_uid != $user_id) {
        CachePermissions($user_id);
    }

    switch($objType) {
        case "aa":
            $ret = IsPerm($permission_to["aa"][$objID], $perm);
            return($ret);
        case "slice":
            $ret = IsPerm(JoinAA_SlicePerm($permission_to["slice"][$objID], $permission_to["aa"][AA_ID]), $perm);
            return($ret);
        default: return false;
    }
}

/** GetSlicePerms function
 *  Returns users's permissions to specified slice
 *  if $whole is true, then consider membership in groups
 * @param $user_id
 * @param $objID
 * @param $whole
 */
function GetSlicePerms( $user_id, $objID, $whole=true) {
    $slice_perms = GetIDPerms($user_id, "slice", ($whole ? 0 : 1));
    $aa_perms    = GetIDPerms($user_id, "aa",    ($whole ? 0 : 1));
    return JoinAA_SlicePerm($slice_perms[$objID], $aa_perms[AA_ID]);
}

/** ComparePerms function
 *  Returns "E" if both permission are equal, "G" if perms1
 *  are more powerfull than perm2, "L" if perm2 are more powerful than perm1
 * @param $perms1
 * @param $perms2
 */
function ComparePerms($perms1, $perms2) {
    $perms1 = ResolvePerms($perms1);
    $perms2 = ResolvePerms($perms2);

    if (strlen($perms1) == strspn($perms1, $perms2)) {
        // perms are equal ?
        return (strlen($perms2) == strspn($perms2, $perms1)) ? 'E' : 'L';
    }
    return 'G';
}

/** JoinAA_SlicePerm function
 * Resolves precedence issues between slice-specific permissions
 * and global access rigths (rights to object aa).
 * Slice-specific perms take precedence except the SUPER access level
 * @param $slice_perm
 * @param $aa_perm
 */
function JoinAA_SlicePerm($slice_perm, $aa_perm) {
    global $perms_roles;
    if (ComparePerms($aa_perm, $perms_roles["SUPER"]['perm']) == "E") {
        return $aa_perm;
    } else {
        return ($slice_perm ? $slice_perm : $aa_perm);
    }
}
/** GetUserSlices function
 * @param $user_id
 */
function GetUserSlices( $user_id = "current") {
    global $permission_uid, $permission_to, $auth;
    if ($GLOBALS['debugpermissions']) {
        huhl("GetUserSlices:pu=",$permission_uid," pt=",$permission_to);
    }
    if ($user_id == "current") {
        $user_id = $auth->auth["uid"];
    }

    if ($permission_uid != $user_id) {
        CachePermissions($user_id);
    }
    if ($GLOBALS['debugpermissions'] && !$permission_to["aa"][AA_ID]) {
        huhe("Warning: No global permission on this system",AA_ID);
    }
    if (IsPerm($permission_to["aa"][AA_ID], PS_MANAGE_ALL_SLICES) ) {
        return "all";
    }

    return  $permission_to["slice"];
}

/** IfSlPerm function
 *  shortcut for slice permission checking
 * @param $perm
 * @param $slice
 */
function IfSlPerm($perm, $slice=null) {
    global $auth, $slice_id, $debugpermissions;
    if ($debugpermissions) {
        huhl("Slice_id=",$slice_id," Perm=",$perm);
    }
    return CheckPerms( $auth->auth["uid"], "slice", get_if($slice,$slice_id), $perm);
}

/** IsSuperadmin function
 *  Checks if logged user is superadmin
 */
function IsSuperadmin() {
    global $auth, $r_superuser, $permission_uid;
    // check all superadmin's global permissions
    if ($permission_uid != $auth->auth["uid"]) {
        CachePermissions($auth->auth["uid"]);
    }
    return $r_superuser[AA_ID] ? $r_superuser[AA_ID] : false;
}

/** IsCatPerm function
 *  Check if authenticed user has specified permissions to category
 * (used for Links module)
 *
 * Slice id for each category in Links module is not random - it is predictable:
 * <category_id>'Links'<shorted AA_ID>
 * @param $perm
 * @param $cat_path
 * @return bool true if the user has specific $perm for $category
 */
function IsCatPerm($perm, $cat_path) {
    global $permission_uid, $permission_to, $auth;

    //    if (IsPerm( PS_LINKS_COMMON_PERMS, $perm )) // check perms granted to anybody
    //        return true;
    if ( !$cat_path OR !$perm ) {
        return false;
    }

    if ($permission_uid != $auth->auth["uid"]) {
        CachePermissions($auth->auth["uid"]);
    }

    // check for current category permissions
    $parents  = explode(",",$cat_path);
    $myIndex  = count($parents)-1;  // index of this category

    $perm2cat = $permission_to["slice"][Links_Category2SliceID($parents[$myIndex])];
    $perm2aa  = $permission_to["aa"][AA_ID];

    if ( $perm2cat ) {              // specific perms are set
        return IsPerm(JoinAA_SlicePerm($perm2cat,$perm2aa), $perm);
    }

    // check for inherited permissions

    // go from leaves to root and check, if some permisions are defined
    // if defined on some level - stop and check
    for ( $i=$myIndex-1; $i>=0; $i--) {
        $perm2cat = $permission_to["slice"][Links_Category2SliceID($parents[$i])];

        if ( $perm2cat ) {      // specific perms are set
            if ( strrchr($perm2cat, PS_LINKS_INHERIT) ) { // inherited
                return IsPerm(JoinAA_SlicePerm($perm2cat,$perm2aa),$perm);
            }
            break; // first upper category with permissions found - stop travelling
        }
    }
    return IsPerm($perm2aa, $perm);
}

/** ChangeCatPermAsIn function
 *  Change category permission as in template category
 *   (used for Links module)
 * @param $category
 * @param $template
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
    if ( isset($oldPerms) AND is_array($oldPerms)) {
        foreach ($oldPerms as  $uid => $foo ) {
            DelPerm($uid, $category_perm_id, 'slice');
        }
    }

    // Copy template's permissions
    if ( isset($newPerms) AND is_array($newPerms)) {
        foreach ($newPerms as  $uid => $arr) {
            AddPerm($uid, $category_perm_id, 'slice', $arr['perm']);
        }
    }
}


/** FilemanPerms function
 *  Permissions for the on-line file manager
 * (c) Jakub Adamek, Econnect, +-July 2002
 * @param $auth
 * @param $slice_id
 */
function FilemanPerms($auth, $slice_id) {
    global $sess, $errcheck;
    // Sets the fileman_dir var:
    global $fileman_dir;
    trace("+","FilemanPerms slice_id=".$slice_id);
    $db = getDB();
    if (! $slice_id) {
        if ($errcheck)  huhl("Warning: Calling perm_core without a slice-id defined");
        $perms_ok = false;
    } else {
        $db->query("SELECT fileman_access, fileman_dir FROM slice WHERE id='".q_pack_id($slice_id)."'");

        if ($db->num_rows() != 1) {
            $perms_ok = false;
        } else {
            $db->next_record();
            $fileman_dir = $db->f("fileman_dir");
            if (IsSuperadmin()) {
                $perms_ok = true;
            } else {
                if (!$fileman_dir) {
                    $perms_ok = false;
                } else {
                    $perms_ok = false;
                    if ($db->f("fileman_access") == "EDITOR" && IfSlPerm(PS_EDIT_ALL_ITEMS)) {
                        $perms_ok = true;
                    } elseif ($db->f("fileman_access") == "ADMINISTRATOR" && IfSlPerm(PS_FULLTEXT)) {
                        $perms_ok = true;
                    }
                }
            }
        }
    }
    freeDB($db);
    trace("-");
    return $perms_ok;
}

/** GetUserEmails function
 *  get email permissions
 * (c) Jakub Adamek, Econnect, December 2002
 *
 * @param $type      OPTIONAL emails type, see get_email_types() in tv_email.php3.
 *                   If not specified, all types are included.
 * @param $user_id   OPTIONAL, default is current user
 * @return array (email id => description)
 */
function GetUserEmails($type = "", $user_id = "current") {
    global $auth;
    if ($user_id == "current") {
        $user_id = $auth->auth["uid"];
    }
    $slices = GetUserSlices($user_id);
    $where  = "WHERE (1=1)";
    if ($type) {
        $where .= " AND type='$type'";
    }
    if ($slices == "all") {
        ;
    } elseif (!is_array($slices) || count ($slices) == 0) {
        return array();
    } else {
        foreach ($slices as $slice => $foo) {
            $slice_ids[] = q_pack_id($slice);
        }
        $where .= " AND owner_module_id IN ('".join ("','", $slice_ids)."')";
    }
    return GetTable2Array("SELECT id, description FROM email $where", 'id', 'description');
}

/** perm_username function
 * Grabs login name from LDAP username (you must use LDAP permission system)
 * @param $username in LDAP form (uid=peterf,ou=People,ou=AA)
 * @return username without additional characters ('peterf' in our example)
 *                  (for SQL permissions it returns username unchanged)
 * (TODO - return the username also for SQL permissions - probably by query
 *  to database)
 */
function perm_username( $username ) {
    if ( $username == '9999999999' ) {
        return "anonym";
    }
    $userinfo = GetIDsInfo($username);
    return empty($userinfo) ? $username : $userinfo['name'];
}

require_once AA_INC_PATH ."util.php3";          // for getDB()
require_once AA_INC_PATH ."searchlib.php3";     // for queryzids()
require_once AA_INC_PATH ."item_content.php3";  // for ItemContent class

/** IsReadernameFree function
 *  Looks into reader management slices whether the reader name is not yet used.
 *   This function is used in perm_ldap and perm_sql in IsUsernameFree().
 * @param $username
 */
function IsReadernameFree($username) {
    // search not only Active bin, but also Holding bin, Pending, ...
    return ReaderName2Id($username, 'ALL') ? false : true;
}

/** AuthenticateReaderUsername function
 *  Search all Reader slices for $username and check if tha password is correct
 *  Returns ID of the user (item ID of the user in Reader slice, in this case)
 * @param $username
 * @param $password
 */
function AuthenticateReaderUsername($username, $password) {
    if ( !$username ) {
        return false;
    }
    $user_id   = ReaderName2Id($username);
    $user_info = GetAuthData( $user_id );
    if ( !$user_info->is_empty() AND ($user_info->getValue(FIELDID_PASSWORD) == crypt($password, 'xx'))) {
        // user id is the id of the item in the Reader Management slice
        return $user_id;
    }
    return false;
}

/** ReaderName2Id function
 *  Tries to find item id for the username in the Reader slices
 *  You can search all users or just the active (default)
 * @param $username
 * @param $restrict
 */
function ReaderName2Id($username, $restrict = 'ACTIVE') {
    // Prepare for calling QueryZIDs()
    $conds[] = array( FIELDID_USERNAME => $username );
    $slices  = getReaderSlices();

    // get item id of current user
    $zid = QueryZIDs( $slices, $conds, '', $restrict, 0, false, '=' );
    return $zid->longids(0);
}

/** getReaderSlices function
 *  Returns array of all - not deleted - Reader slices
 */
function getReaderSlices() {
    $SQL = "SELECT module.id FROM slice, module
             WHERE slice.type = 'ReaderManagement'
               AND slice.id   = module.id
               AND module.deleted < '1'";
    return GetTable2Array($SQL, '', 'unpack:id');
}

/** FindReaderGroupsn function
 *  return list of RM slices which matches the pattern
 */
function FindReaderGroups($pattern) {
    global $db;
    $db->tquery("SELECT module.id,module.name FROM slice,module
                  WHERE slice.type = 'ReaderManagement'
                    AND slice.id   = module.id
                    AND module.deleted < '1'
                    AND module.name LIKE '". quote($pattern) ."%'");
    $prefix = _m('Reader Slice');
    $groups = array();
    while ($db->next_record()) {
        $groups[unpack_id($db->f('id'))] = array('name' => "$prefix: ". $db->f('name'));
    }

    // get all ReaderSets
    $prefix = _m('Reader Set');
    foreach ( AA_Object::getNameArray('AA_Set', array_keys($groups)) as $set_id => $name ) {
        $groups[$set_id] = array('name' => "$prefix: $name");
    }
    return $groups;


}

/** FindReaderUsers function
 *  return list of RM users which matches the pattern
 * @param $pattern
 */
function FindReaderUsers($pattern) {
    global $db;
    $db->tquery("SELECT content.text AS name, content.item_id AS id
                   FROM slice
             INNER JOIN item ON slice.id = item.slice_id
             INNER JOIN content ON item.id=content.item_id
                  WHERE slice.type = 'ReaderManagement'
                    AND content.field_id = '".FIELDID_USERNAME."'
                    AND content.text LIKE '%". quote($pattern) ."%'");
    while ($db->next_record()) {
        $users[unpack_id($db->f('id'))] = array('name' => $db->f('name'));
    }
    return $users;
}

/** GetAuthData function
 *  Fills content array for current loged user or specified user
 * @param $user_id
 */
function GetAuthData( $user_id = false ) {
    global $auth;
    if ( !$user_id ) {
        if ( $_SERVER['PHP_AUTH_USER'] ) {
           $user_id = ReaderName2Id($_SERVER['PHP_AUTH_USER']);
        }
        elseif ( $_SERVER['REMOTE_USER'] ) {
           $user_id = ReaderName2Id($_SERVER['REMOTE_USER']);
        }
        else {
           $user_id = (guesstype($auth->auth["uid"]) == 'l') ? $auth->auth["uid"] : false;
        }
    }
    return new ItemContent($user_id);
}

/** GetReaderIDsInfo function
 *  returns basic information on user grabed from any Reader Management slice
 * @param $user_id
 */
function GetReaderIDsInfo($user_id) {
    if ( !$user_id ) {
        return false;
    }
    $user_info = GetAuthData($user_id);
    if ($user_info->is_empty()) {
        return false;
    }
    $res['type'] = 'Reader';
    $res['name'] = $user_info->getValue(FIELDID_USERNAME);
    $res['mail'] = $user_info->getValue(FIELDID_EMAIL);
    return $res;
}

/** GetReaderGroupIDsInfo function
 *  returns basic information on user grabed from any Reader Management slice
 * @param $rm_id
 */
function GetReaderGroupIDsInfo($rm_id) {
    if ( !$rm_id ) {
        return false;
    }
    $slice       = AA_Slices::getSlice($rm_id);
    $res['type'] = 'ReaderGroup';
    $res['name'] = $slice->getProperty('name');
    return $res;
}
/** GetReaderSetIDsInfo function
 * @param $set_id
 */
function GetReaderSetIDsInfo($set_id) {
    if ( !$set_id ) {
        return false;
    }
    $set  = AA_Object::load($set_id, 'AA_Set');
    if ( empty($set) ) {
        return false;
    }
    $res['type'] = 'ReaderSet';
    $res['name'] = $set->getName();
    return $res;
}

/** GetReaderMembership function
 *  return id of group (=Reader Management slice) in which is the user member
 * @param $user_id
 */
function GetReaderMembership($user_id) {
    if (!$user_id) {
        return false;
    }
    $user_info = GetAuthData( $user_id );

    if ($user_info->is_empty()) {
        return false;
    }

    $reader_slice_id = $user_info->getSliceID();
    $ret             = array($reader_slice_id);

    $restrict_zids   = new zids($user_id, 'l');
    // groups could be definned also by subset of readers - defined by AA_Set
    $set_ids         = AA_Object::query('AA_Set', array($reader_slice_id));
    foreach( $set_ids as $set_id ) {
        $set  = AA_Object::load($set_id, 'AA_Set');
        $zids = QueryZids(array($reader_slice_id), $set->getConds(), '', 'ACTIVE', 0, $restrict_zids);

        // reader is in this reader set
        if ($zids->count() > 0) {
            $ret[] = $set->getId();
        }
    }

    // we use unpacked slice id as id of group for RM slices
    return $ret;
}

?>
