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
        AA::$perm->cache($user_id);
    }

    switch($objType) {
        case "aa":
            $ret = IsPerm($permission_to["aa"][$objID], $perm);
            return($ret);
        case "slice":
            $ret = IsPerm(AA_Perm::joinSliceAndAAPerm($permission_to["slice"][$objID], $permission_to["aa"][AA_ID]), $perm);
            return($ret);
        default: return false;
    }
}

/** GetUserSlices function
 * @param $user_id
 */
function GetUserSlices( $user_id = "current") {
    global $permission_uid, $permission_to, $auth;
    if ($user_id == "current") {
        $user_id = $auth->auth["uid"];
    }

    if ($permission_uid != $user_id) {
        AA::$perm->cache($user_id);
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
    global $auth, $slice_id;
    return CheckPerms( $auth->auth["uid"], "slice", get_if($slice,$slice_id), $perm);
}

/** IsSuperadmin function
 *  Checks if logged user is superadmin
 */
function IsSuperadmin() {
    global $auth, $r_superuser, $permission_uid;
    // check all superadmin's global permissions
    if ($permission_uid != $auth->auth["uid"]) {
        AA::$perm->cache($auth->auth["uid"]);
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
        AA::$perm->cache($auth->auth["uid"]);
    }

    // check for current category permissions
    $parents  = explode(",",$cat_path);
    $myIndex  = count($parents)-1;  // index of this category

    $perm2cat = $permission_to["slice"][Links_Category2SliceID($parents[$myIndex])];
    $perm2aa  = $permission_to["aa"][AA_ID];

    if ( $perm2cat ) {              // specific perms are set
        return IsPerm(AA_Perm::joinSliceAndAAPerm($perm2cat,$perm2aa), $perm);
    }

    // check for inherited permissions

    // go from leaves to root and check, if some permisions are defined
    // if defined on some level - stop and check
    for ( $i=$myIndex-1; $i>=0; $i--) {
        $perm2cat = $permission_to["slice"][Links_Category2SliceID($parents[$i])];

        if ( $perm2cat ) {      // specific perms are set
            if ( strrchr($perm2cat, PS_LINKS_INHERIT) ) { // inherited
                return IsPerm(AA_Perm::joinSliceAndAAPerm($perm2cat,$perm2aa),$perm);
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
    $newPerms = AA::$perm->getObjectsPerms($template_perm_id, 'slice');
    $oldPerms = AA::$perm->getObjectsPerms($category_perm_id, 'slice');

    // Delete all old perms
    if ( isset($oldPerms) AND is_array($oldPerms)) {
        foreach ($oldPerms as  $uid => $perm ) {
            DelPerm($uid, $category_perm_id, 'slice');
        }
    }

    // Copy template's permissions
    if ( isset($newPerms) AND is_array($newPerms)) {
        foreach ($newPerms as  $uid => $perm) {
            AddPerm($uid, $category_perm_id, 'slice', $perm);
        }
    }
}

/** FilemanPerms function
 *  Permissions for the on-line file manager
 * (c) Jakub Adamek, Econnect, +-July 2002
 * @param $auth
 * @param $slice_id
 */
function FilemanPerms($slice_id) {
    global $sess, $errcheck;
    // Sets the fileman_dir var:
    global $fileman_dir;
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
    } elseif (!is_array($slices) || count ($slices) == 0) {
        return array();
    } else {
        $slice_ids = array();
        foreach ($slices as $slice => $foo) {
            $slice_ids[] = q_pack_id($slice);
        }
        $where .= " AND owner_module_id IN ('".join ("','", $slice_ids)."')";
    }
    return GetTable2Array("SELECT id, description FROM email $where", 'id', 'description');
}

/** Grabs User name from LDAP/SQL/AA
 *  @param $user_id (uid=peterf,ou=People,ou=AA for LDAP, 24 for SQL, c7626ea.. for AA Reader )
 *  @return name of the user ('Peter Fiala' in our example)
 *  @todo   we should propaply provide realy the username (like peterf) here
 */
function perm_username( $user_id ) {
    if ( $user_id == '9999999999' ) {
        return "anonym";
    }
    $userinfo = AA::$perm->getIDsInfo($user_id);
    return empty($userinfo) ? $user_id : $userinfo['name'];
}

require_once AA_INC_PATH ."util.php3";          // for getDB()
require_once AA_INC_PATH ."searchlib.php3";     // for queryzids()
require_once AA_INC_PATH ."item_content.php3";  // for ItemContent class


/** main AA permissions */
class AA_Perm {

    var $perm_systems;

    function __construct($systems) {
        $this->perm_systems = array();
        foreach ($systems as $system_name) {
            $system_name = 'AA_Permsystem_' . ucfirst($system_name);
            $this->perm_systems[] = new $system_name;
        }
    }


    /**
     * ussage: AA::$perm->isUsernameFree($var)
     */
    public function authenticateUsername($username, $password) {
        foreach ($this->perm_systems as $perm_sys) {
            if ($user_id = $perm_sys->authenticateUsername($username, $password)) {
                return $user_id;
            }
        }
        return false;
    }


    /**
     * ussage: AA::$perm->isUsernameFree($var)
     */
    public function isUsernameFree($username) {
        foreach ($this->perm_systems as $perm_sys) {
            if (!$perm_sys->isUsernameFree($username)) {
                return false;
            }
        }
        return true;
    }

    public function findUsernames($pattern) {
        $users = array();
        foreach ($this->perm_systems as $perm_sys) {
            $new_users = $perm_sys->findUsernames($pattern);
            if (is_array($new_users) AND count($new_users)) {
                // + operator preserves the numeric keys (used in SQL perm), which is crucial
                $users = $users + $new_users;
            }
        }
        return $users;
    }

    public function findUserByLogin($user_login) {
        $users = array();
        foreach ($this->perm_systems as $perm_sys) {
            if ($user = $perm_sys->findUserByLogin($user_login)) {
                return $user;
            }
        }
        return false;
    }

    public function findGroups($pattern) {
        $groups = array();
        foreach ($this->perm_systems as $perm_sys) {
            $new_groups = $perm_sys->findGroups($pattern);
            if (is_array($new_groups) AND count($new_groups)) {
                // + operator preserves the numeric keys (used in SQL perm), which is crucial
                $groups = $groups + $new_groups;
            }
        }
        return $groups;
    }

    /**
     *  @param $group_id
     *  @return array(uid, name, description) - info about the group
     *  ussage: AA::$perm->getGroup($var)
     */
    public function getGroup($group_id) {
        foreach ($this->perm_systems as $perm_sys) {
            if ($groupinfo = $perm_sys->getGroup($group_id)) {
                return $groupinfo;
            }
        }
        return false;
    }

    /** getIDsInfo function
     * @param $uid
     * @return an array containing basic information on $uid
     * or false if ID does not exist
     * array("mail => $mail", "name => $cn", "type => "User" : "Group"")
     */
    public function getIDsInfo($uid) {
        if ( $uid AND ($ps = $this->_whichUsersystem($uid))) {
            return $ps->getIDsInfo($uid);
        }
        return false;
    }

    function getGroupMembers($group_id) {
        if ( $group_id AND ($ps = $this->_whichUsersystem($group_id))) {
            return $ps->getGroupMembers($group_id);
        }
        return array();
    }

    /** getMembership function
     * @param $uid
     * @param $flags - use to obey group in groups?
     * @return return array of group_ids, where id (group or user) is a member
     */
    function getMembership($uid, $flags = 0) {
        $groups = array();
        foreach ($this->perm_systems as $perm_sys) {
            $groups = array_merge($groups, $perm_sys->getMembership($uid, $flags));
        }
        return $groups;
    }

    /** getUserPerms function
     * @param $uid
     * @param $objectType
     * @param $flags
     * @return an array of sliceids and their permissions (for user $userid).
     * granted on all objects of type $objectType
     * flags & 1 -> do not involve membership in groups
     */
    function getUserPerms($uid, $objectType, $flags = 0) {
        if ( $ps = $this->_whichPermstorage()) {
            return $ps->getJoinedPerms($uid, $objectType, ($flags & 1) ? array() : $this->getMembership($uid));
        }
        return array();
    }

    function getObjectsPerms($objectID, $objectType) {
        if ( $ps = $this->_whichPermstorage()) {
            return $ps->getObjectsPerms($objectID, $objectType);
        }
        return array();
    }

    /** $this->_whichUsersystem($uid) function
     *  returns the permission system for the user id
     *  @param $uid
     */
    private function _whichUsersystem($uid) {
        foreach ($this->perm_systems as $perm_sys) {
            if ($perm_sys->userIdFromatMatches($uid)) {
                return $perm_sys;
            }
        }
        return false;
    }

    /** $this->_whichUsersystem($uid) function
     *  returns the permission system for the user id
     *  @param $uid
     */
    private function _whichPermstorage() {
        foreach ($this->perm_systems as $perm_sys) {
            if ($perm_sys->storesGeneralPerms()) {
                return $perm_sys;
            }
        }
        return false;
    }

    public static function cryptPwd($password) {
        $seed = '$2y$14$'.gensalt(22);
        $ret  = crypt($password, $seed);
        return (strlen($ret) == 60) ? $ret : crypt($password);  // len should be 60 for blowfish
    }

    public static function comparePwds($password, $hash) {
        // looks ugly for the first_child look, but it is really how the crypt
        // with salt works - see php documentation

        $crypted = crypt($password, $hash);

        // passwords in SQL perms in AA 2.x are 30 characers long (in user table),
        // so we have to compare it with shortened hash
        return $hash == ((strlen($hash) == 30) ?  substr($crypted,0,30) : $crypted);
    }

    /** AA::$perm->cache function
     *  Save all permissions for specified user to session variable
     * @param $user_id
     */
    function cache($user_id) {
        global $permission_uid, $permission_to, $sess, $perms_roles, $r_superuser;

        if (is_object($sess)) {
            $sess->register('permission_uid');
            $sess->register('permission_to');
            $sess->register('r_superuser');
        }

        $permission_uid         = $user_id;
        $permission_to["slice"] = $this->getUserPerms($permission_uid, "slice");
        $permission_to["aa"]    = $this->getUserPerms($permission_uid, "aa");     // aa is parent of all slices

        // Resolve all permission (convert roles into perms)
        foreach ($permission_to["slice"] as $key => $val) {
            $permission_to["slice"][$key] = AA_Perm::_resolve($val);
        }

        foreach ($permission_to["aa"] as $key => $val) {
            if ( IsPerm($val, $perms_roles['SUPER']['id']) ) {
                $r_superuser[$key] = true;
            }
            $permission_to["aa"][$key] = AA_Perm::_resolve($val);
        }
    }

    /** AA_Perm::compare() function
     *  Returns "E" if both permission are equal, "G" if perms1
     *  are more powerfull than perm2, "L" if perm2 are more powerful than perm1
     * @param $perms1
     * @param $perms2
     */
    public static function compare($perms1, $perms2) {
        $perms1 = AA_Perm::_resolve($perms1);
        $perms2 = AA_Perm::_resolve($perms2);

        if (strlen($perms1) == strspn($perms1, $perms2)) {
            // perms are equal ?
            return (strlen($perms2) == strspn($perms2, $perms1)) ? 'E' : 'L';
        }
        return 'G';
    }


    /** getModulePerms function
     *  Returns users's permissions to specified slice
     *  if $whole is true, then consider membership in groups
     * @param $user_id
     * @param $objID
     * @param $whole
     */
    function getModulePerms( $user_id, $objID, $whole=true) {
        $slice_perms = $this->getUserPerms($user_id, "slice", ($whole ? 0 : 1));
        $aa_perms    = $this->getUserPerms($user_id, "aa",    ($whole ? 0 : 1));
        return AA_Perm::joinSliceAndAAPerm($slice_perms[$objID], $aa_perms[AA_ID]);
    }

    /** AA_Perm::joinSliceAndAAPerm function
     * Resolves precedence issues between slice-specific permissions
     * and global access rigths (rights to object aa).
     * Slice-specific perms take precedence except the SUPER access level
     * @param $slice_perm
     * @param $aa_perm
     */
    public static function joinSliceAndAAPerm($slice_perm, $aa_perm) {
        global $perms_roles;
        if (AA_Perm::compare($aa_perm, $perms_roles["SUPER"]['perm']) == "E") {
            return $aa_perm;
        } else {
            return ($slice_perm ? $slice_perm : $aa_perm);
        }
    }

    /** AA_Perm::_resolve($perms) function
     *  Replaces roles with apropriate perms
     *  substitute role identifiers (1,2,3,4) with his permissions (E,A,R ...)
     *  @param $perms
     */
    private static function _resolve($perms) {
        global $perms_roles;

        foreach ($perms_roles as $arr) {
            $perms = str_replace($arr['id'], $arr['perm'], $perms);
        }
        return $perms;
    }
}

AA::$perm = new AA_Perm(array(PERM_LIB, 'Reader'));

class AA_Permsystem {
    /** userIdFromatMatches - is user id in correct format?
     *  we MUST use specific UIDs for every single Permission Type
     *  (it MUST be clear, which perm system is used just from the format of UID)
     */
    function userIdFromatMatches($uid)                          {}
    function authenticateUsername($username, $password)         {}
    function isUsernameFree($username)                          {}
    function findUsernames($pattern)                            {}
    function findUserByLogin($user_login)                       {}
    function findGroups($pattern)                               {}
    function getGroup($group_id)                                {}
    function getIDsInfo($uid)                                   {}
    function getGroupMembers($group_id)                         {}
    function getMembership($id, $flags=0)                       { return array(); }
    function getObjectsPerms()                                  { return array(); }
    function getJoinedPerms($uid, $objectType, $groups=array()) { return array(); }

    /** true, if the system is able to store permissins for groups and users (even foreign users and groups)
     *  SQL and LDAP is able to store it, Reader not. */
    function storesGeneralPerms()                               { return false; }

    /** getUserPerms function
     * @param $uid
     * @param $objectType
     * @param $flags
     * @return an array of sliceids and their permissions (for user $userid).
     * granted on all objects of type $objectType
     * flags & 1 -> do not involve membership in groups
     */
    function getUserPerms($uid, $objectType, $flags = 0) {
        return $this->getJoinedPerms($uid, $objectType, ($flags & 1) ? array() : $this->getMembership($uid));
    }
}


class AA_Permsystem_Reader extends AA_Permsystem {

    /** userIdFromatMatches - is user id in correct format?
     *  we MUST use specific UIDs for every single Permission Type
     *  (it MUST be clear, which perm system is used just from the format of UID)
     */
    function userIdFromatMatches($uid) {
        // Reader perms - long ID (32 hexa)
        return (guesstype($uid) == 'l');
    }

    /** AuthenticateReaderUsername function
     *  Search all Reader slices for $username and check if tha password is correct
     *  Returns ID of the user (item ID of the user in Reader slice, in this case)
     * @param $username
     * @param $password
     */
    function authenticateUsername($username, $password) {
        if ( !$username ) {
            return false;
        }

        $user_id   = AA_Reader::name2Id($username);
        $user_info = GetAuthData( $user_id );

        if ( !$user_info->is_empty() AND AA_Perm::comparePwds($password, $user_info->getValue(FIELDID_PASSWORD)) ) {
            // user id is the id of the item in the Reader Management slice
            return $user_id;
        }
        return false;
    }


    /** isUsernameFree function
     *  Looks into reader management slices whether the reader name is not yet used.
     *   This function is used in perm_ldap and perm_sql in IsUsernameFree().
     * @param $username
     */
    function isUsernameFree($username) {
        // search not only Active bin, but also Holding bin, Pending, ...
        return AA_Reader::name2Id($username, null, AA_BIN_ALL) ? false : true;
    }


    public function findUsernames($pattern) {
        return $this->_findUserPattern('%'. quote($pattern) .'%');
    }

    public function findUserByLogin($user_login) {
        return $this->_findUserPattern(quote($user_login));
    }

    /** findUsernames function
     *  return list of RM users which matches the pattern
     * @param $pattern - already quoted!!!
     */
    private function _findUserPattern($pattern) {
        global $db;
        $db->tquery("SELECT content.text AS name, content.item_id AS id
                       FROM slice
                 INNER JOIN item ON slice.id = item.slice_id
                 INNER JOIN content ON item.id=content.item_id
                      WHERE slice.type = 'ReaderManagement'
                        AND content.field_id = '".FIELDID_USERNAME."'
                        AND content.text LIKE '%". quote($pattern) ."%'");
        $users = array();
        while ($db->next_record()) {
            $users[unpack_id($db->f('id'))] = array('name' => $db->f('name'));
        }
        return $users;
    }

    public function findGroups($pattern) {
        global $db;
        $db->tquery("SELECT module.id,module.name FROM slice,module
                      WHERE slice.type = 'ReaderManagement'
                        AND slice.id   = module.id
                        AND module.deleted < '1'
                        AND module.name LIKE '%". quote($pattern) ."%'");
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

    /** getIDsInfo function
     * @param $uid
     * @return an array containing basic information on $id
     * or false if ID does not exist
     * array("mail => $mail", "name => $cn", "type => "User" : "Group"")
     */
    public function getIDsInfo($uid) {
        if ( !$uid )                          { return false;  }
        if ( $this->_isGroupReader($uid) )    { return $this->_readerGroupIDsInfo($uid); }
        if ( $this->_isGroupReaderSet($uid) ) { return $this->_readerSetIDsInfo($uid); }
        if ( $this->_isUserReader($uid) )     { return $this->_readerIDsInfo($uid);  }
        return false;
    }

    /** _isUserReader, _isGroupReader, _isGroupReaderSetfunction
     * @param $user_id
     */
    private function _isUserReader($user_id)      { return (guesstype($user_id) == 'l');  }
    private function _isGroupReader($group_id)    { return ((guesstype($group_id) == 'l') AND (AA_Slices::getSliceProperty($group_id, 'type')=='ReaderManagement')); }
    private function _isGroupReaderSet($group_id) { return is_marked_by($group_id, 1); }

    /** GetReaderIDsInfo function
     *  returns basic information on user grabed from any Reader Management slice
     * @param $user_id
     */
    private function _readerIDsInfo($user_id) {
        $user_info = GetAuthData($user_id);
        if ($user_info->is_empty()) {
            return false;
        }
        $res              = array();
        $res['id']        = $user_id;
        $res['name']      = $user_info->getValue(FIELDID_USERNAME);
        $res['mail']      = $user_info->getValue(FIELDID_EMAIL);
        $res['mails']     = array($res['mail']);
        $res['login']     = $user_info->getValue(FIELDID_USERNAME);
        $res['sn']        = '';
        $res['givenname'] = '';
        $res['type']      = 'Reader';
        return $res;
    }

    /** GetReaderGroupIDsInfo function
     *  returns basic information on user grabed from any Reader Management slice
     * @param $rm_id
     */
    private function _readerGroupIDsInfo($rm_id) {
        $slice       = AA_Slices::getSlice($rm_id);
        $res         = array();
        $res['type'] = 'ReaderGroup';
        $res['name'] = $slice->getProperty('name');
        return $res;
    }

    /** GetReaderSetIDsInfo function
     * @param $set_id
     */
    private function _readerSetIDsInfo($set_id) {
        $set  = AA_Object::load($set_id, 'AA_Set');
        if ( empty($set) ) {
            return false;
        }
        $res         = array();
        $res['type'] = 'ReaderSet';
        $res['name'] = $set->getName();
        return $res;
    }

    function getGroupMembers($group_id) {
        // @todo implement it
        return array();
    }

    /** getMembership function
     *  return array of group_ids, where id (group or user) is a member (=Reader Management slice) in which is the user member
     * @param $user_id
     */
    function getMembership($user_id, $flags=0) {
        if (!$user_id) {
            return array();
        }
        $user_info = GetAuthData( $user_id );

        if ($user_info->is_empty()) {
            return array();
        }

        $reader_slice_id = $user_info->getSliceID();
        $ret             = array($reader_slice_id);

        $restrict_zids   = new zids($user_id, 'l');
        // groups could be definned also by subset of readers - defined by AA_Set
        $set_ids         = AA_Object::querySet('AA_Set', new AA_Set(array($reader_slice_id)));
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


/** GetAuthData function
 *  Fills content array for current loged user or specified user
 * @param $user_id
 */
function GetAuthData( $user_id = false ) {
    global $auth;
    if ( !$user_id ) {
        if ( $_SERVER['PHP_AUTH_USER'] ) {
           $user_id = AA_Reader::name2Id($_SERVER['PHP_AUTH_USER']);
        }
        elseif ( $_SERVER['REMOTE_USER'] ) {
           $user_id = AA_Reader::name2Id($_SERVER['REMOTE_USER']);
        }
        else {
           $user_id = $auth->auth["uid"];
        }
    }
    return new ItemContent((guesstype($user_id) == 'l') ? $user_id : false);
}


class AA_Reader {
    function _find($field, $value, $slices, $bin ) {
        if (!$slices) {
            $slices  = getReaderSlices();
        }
        $aa_set = new AA_Set($slices, new AA_Condition($field, '=', $value), null, $bin);

        // get item id of current user
        $zid = $aa_set->query();
        return $zid->longids(0);
    }

    /** name2Id function
     *  Tries to find item id for the username in the Reader slices
     *  You can search all users or just the active (default)
     * @param $username
     * @param $bin
     */
    function name2Id($username, $slices=null, $bin=null ) {
        return AA_Reader::_find(FIELDID_USERNAME, $username, $slices, $bin );
    }

    function email2Id($email, $slices=null, $bin=null) {
        return AA_Reader::_find(FIELDID_EMAIL, $email, $slices, $bin);
    }
}

?>
