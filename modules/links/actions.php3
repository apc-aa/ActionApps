<?php
/**
 * File contains definitions of functions which corresponds with actions
 * on Link Manager page - manipulates with links
 *
 * Should be included to other scripts (module/links/index.php3)
 *
 * @package Links
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

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

use AA\IO\DB\DB_AA;


/** Marks given link as checked (means visited and content checked)
 *  - used also as AA_Manageraction_Links_* superclass
 */
class AA_Manageraction_Links_Check extends AA_Manageraction {

    protected $perm;

    /** Constructor - fills the information about the target bin */
    function __construct($id) {
        parent::__construct($id);
        $this->perm = PS_LINKS_CHECK_LINK;
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Check Link');
    }

    /** main executive function
     * @param AA_Manager $manager
     * @param $item_zids - array of id of AA records to check
     * @param $action_param - not used
     * @return bool
     */
    function perform($manager, $item_zids, $action_param) {
        global $auth;
        DB_AA::sql("UPDATE links_links SET checked = '". time() ."', checked_by = '". $auth->auth['uid'] ."' WHERE ". $item_zids->sqlin('id', true));
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool|if|mixed
     */
    function isPerm4Action($manager) {
        return IsCatPerm( $this->perm, $GLOBALS['r_state']['cat_path'] );
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @param $item_zids
     * @param $action_param
     * @return bool|if|mixed
     */
    function isPerm4Perform($manager, $item_zids, $action_param) {
        return $this->isPerm4Action($manager) AND self::permCheck($item_zids, $this->perm);       // _m('No permission to change state of the link');  // error
    }

    /** function, which checks permissions in for many Links action classes */
    static public function permCheck($item_zids, $perm, $allow_nobase4super=false) {

        $base_categs = [];
        $check_super = false;
        foreach ($item_zids as $lid) {
            // get link's base category
            if ($base_categsory_path = GetBaseCategoryPath( $lid )) {
                $base_categs[$base_categsory_path] = true;
            } elseif ($allow_nobase4super) {
                $check_super = true;
            } else {
                return false;  // _m('Can\'t get link data');  // error
            }
        }

        foreach ($base_categs as $cat_path => $foo) {
            if (!IsCatPerm( $perm, $cat_path)) {
                return false;  //  _m('No permission to move link');  // error
            }
        }

        if  ($check_super) {
            return IsSuperadmin();
        }
        return true;
    }
}

/**  */
class AA_Manageraction_Links_Move extends AA_Manageraction_Links_Check {

    /** specifies, to which bin the move should be performed */
    var $to_bin;

    /** Constructor - fills the information about the target bin */
    function __construct($id, $to_bin) {
        parent::__construct($id);
        $this->to_bin = $to_bin;
    }

    /** Name of this Manager's action */
    function getName() {
        switch($this->to_bin) {
            case 1: return _m('Move to Active');
            case 2: return _m('Move to Holding bin');
            case 3: return _m('Move to Trash');
        }
        return "";
    }

    /** main executive function
     * @param AA_Manager $manager
     * @param $item_zids - array of id of AA records to check
     * @param $action_param - not used
     * @return bool
     */
    function perform($manager, $item_zids, $action_param) {
        $SQL = "UPDATE links_links SET folder = '".$this->to_bin."' WHERE ". $item_zids->sqlin('id', true);
        DB_AA::sql($SQL);
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
       $cid = $GLOBALS['r_state']['cat_path'];
       $subtree = $GLOBALS['r_state']['show_subtree'];
       $current_bin = $GLOBALS['r_state']['bin'];

        switch ($this->to_bin) {
            case 1: return !$subtree && IsCatPerm( PS_LINKS_ADD_LINK, $cid );
            case 2: return ($current_bin != 'folder2') && IsCatPerm( PS_LINKS_LINK2FOLDER, $cid );
            case 3: return ($current_bin != 'folder3') && IsCatPerm( PS_LINKS_LINK2FOLDER, $cid );
        }
        return false;
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @param $item_zids
     * @param $action_param
     * @return bool
     */
    function isPerm4Perform($manager, $item_zids, $action_param) {
        $perm_needed = ($manager->getBin()==1 ? PS_LINKS_LINK2ACT : PS_LINKS_LINK2FOLDER);
        return $this->isPerm4Action($manager) AND AA_Manageraction_Links_Check::permCheck($item_zids, $perm_needed, true);
    }
}

/** Marks given link as checked (means visited and content checked) */
class AA_Manageraction_Links_Highlight extends AA_Manageraction {

    protected $highlight;

    /** Constructor - fills the information about the target bin */
    function __construct($id, $highlight) {
        parent::__construct($id);
        $this->highlight = $highlight ? 'highlight' : 'visible';
    }

    /** Name of this Manager's action */
    function getName() {
        return ($this->highlight=='highlight') ? _m('Highlight Link') :  _m('Dehighlight Link');
    }

    /** main executive function
     * @param AA_Manager $manager
     * @param $item_zids - array of id of AA records to check
     * @param $action_param - not used
     * @return bool
     */
    function perform($manager, $item_zids, $action_param) {
        global $r_state;
        foreach ($item_zids as $lid) {
            if ( !($aid = Links_GetAsociationId($lid, $r_state['cat_id']))) {
                continue;    // return _m('Can\'t find link in given category');  // error
            }
            DB_AA::sql("UPDATE links_link_cat SET state = '".$this->highlight."' WHERE a_id = $aid");
        }
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        return !$GLOBALS['r_state']['show_subtree'] && IsCatPerm( PS_LINKS_HIGHLIGHT_LINK, $GLOBALS['r_state']['cat_path'] );
    }
}

/**  */
class AA_Manageraction_Links_Delete extends AA_Manageraction {

    /** Constructor - fills the information about the target bin */
    function __construct($id) {
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Remove from category');
    }

    /** main executive function
     * @param AA_Manager $manager
     * @param $item_zids - array of id of AA records to check
     * @param $action_param - not used
     * @return bool
     */
    function perform($manager, $item_zids, $action_param) {
        foreach ($item_zids as $lid) {
            Links_DeleteLink($lid);
        }
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        return !$GLOBALS['r_state']['show_subtree'] && IsCatPerm( PS_LINKS_DELETE_LINK, $GLOBALS['r_state']['cat_path'] );
    }
}

/**  */
class AA_Manageraction_Links_Add2Cat extends AA_Manageraction {

    /** Constructor - fills the information about the target bin */
    function __construct($id, $url) {
        parent::__construct($id);
        $this->setOpenUrl($url);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Add to category');
    }

    /** main executive function
     * @param AA_Manager $manager
     * @param $item_zids - array of id of AA records to check
     * @param $action_param - not used
     * @return bool
     */
    function perform($manager, $item_zids, $action_param) {
        if ( ctype_digit((string)$action_param) ) {
            foreach ($item_zids as $lid) {
                Links_Assign2Category($lid, $action_param);
            }
        }
        return false;                                     // OK - no error
    }
}


/**  */
class AA_Manageraction_Links_Move2Cat extends AA_Manageraction {

    /** Constructor - fills the information about the target bin */
    function __construct($id, $url) {
        parent::__construct($id);
        $this->setOpenUrl($url);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Move to category');
    }

    /** main executive function
     * @param AA_Manager $manager
     * @param $item_zids - array of id of AA records to check
     * @param $action_param - not used
     * @return bool
     */
    function perform($manager, $item_zids, $action_param) {
        if ( ctype_digit((string)$action_param) ) {
            foreach ($item_zids as $lid) {
                Links_DeleteLink($lid);
                Links_Assign2Category($lid, $action_param);
            }
        }
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        return !$GLOBALS['r_state']['show_subtree'] && IsCatPerm( PS_LINKS_DELETE_LINK, $GLOBALS['r_state']['cat_path'] );
    }
}


/** ---switches ---- */



/**  */
class AA_Manageraction_Links_DeleteTrash extends AA_Manageraction {

    /** main executive function
     * @param AA_Manager $manager
     * @param $item_zids - array of id of AA records to check
     * @param $action_param - not used
     * @return bool
     */
    function perform($manager, $item_zids, $action_param) {
        $db = getDB();

        // first delete the trashed links
        $SQL = 'DELETE links_links FROM links_links WHERE links_links.folder=3';
        $db->query($SQL);

        // now we fix all incionsistences in the database (most of them caused by previous deletion)

        // delete all proposals which do not have its counterpart in list of links
        $SQL = 'DELETE p FROM links_links AS p INNER JOIN links_changes ON p.id = links_changes.proposal_link_id LEFT JOIN links_links AS s ON s.id = links_changes.changed_link_id WHERE s.id IS NULL';
        $db->query($SQL);

        // delete propasal - link relation
        $SQL = 'DELETE links_changes FROM links_changes LEFT JOIN links_links ON links_changes.changed_link_id = links_links.id WHERE links_links.id IS NULL';
        $db->query($SQL);

        // delete all unmatched language relations
        $SQL = 'DELETE links_link_lang FROM links_link_lang LEFT JOIN links_links ON links_link_lang.link_id = links_links.id WHERE links_links.id IS NULL';
        $db->query($SQL);

        // delete all unmatched region relations
        $SQL = 'DELETE links_link_reg FROM links_link_reg LEFT JOIN links_links ON links_link_reg.link_id = links_links.id WHERE links_links.id IS NULL';
        $db->query($SQL);

        // and now remove all category assignments for deleted links
        $SQL = 'DELETE links_link_cat FROM links_link_cat LEFT JOIN links_links ON links_link_cat.what_id = links_links.id WHERE links_links.id IS NULL';
        $db->query($SQL);

        freeDB($db);
    }

    /** Checks if the user have enough permission to perform the action
     * @param AA_Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        return IsSuperadmin();
    }
}

/**  */
class AA_Manageraction_Links_GoCateg extends AA_Manageraction {

    /** main executive function
     * @param AA_Manager $manager
     * @param $item_zids - array of id of AA records to check
     * @param $action_param - not used
     * @return bool
     */
    function perform($manager, $item_zids, $action_param) {
        global $r_state;
        // TODO - refresh permission for new category!!!
        $cpath = GetCategoryPath( $action_param );
        if ( IsCatPerm( PS_LINKS_EDIT_LINKS, $cpath ) ) {
            $r_state["show_subtree"] = false;
            $r_state['cat_id']       = $action_param;
            $r_state['cat_path']     = $cpath;
        }
    }
}


/**  */
class AA_Manageraction_Links_Tab extends AA_Manageraction {

    /** main executive function
     * @param AA_Manager $manager
     * @param $item_zids - array of id of AA records to check
     * @param $action_param - not used
     * @return bool
     */
    function perform($manager, $item_zids, $action_param) {
        $GLOBALS['r_state']['bin'] = $action_param;
        $manager->go2page(1);
    }
}

/**  */
class AA_Manageraction_Links_GoBookmark extends AA_Manageraction {

    /** main executive function
     * @param AA_Manager $manager
     * @param $item_zids - array of id of AA records to check
     * @param $action_param - not used
     * @return bool
     */
    function perform($manager, $item_zids, $action_param) {
        global $manager, $links_info;

        $start_id   = $links_info['start_id'];
        $start_path = GetCategoryPath( $start_id );

        switch( (string)$action_param ) {
            case '1':             // all links
                $GLOBALS['r_state']['show_subtree']    = 1;
                $GLOBALS['r_state']['cat_id']          = $start_id;
                $GLOBALS['r_state']['cat_path']        = $start_path;
                $GLOBALS['r_state']['start_path']      = $start_path;
                $GLOBALS['r_state']['tree_start_path'] = $links_info['tree_start'];
                $GLOBALS['r_state']['bin'] = 'app';
                $manager->resetSearchBar();
                $manager->addOrderBar(  [0=> ['name'=>'a']]);
                $manager->addSearchBar( [0=> ['name'=>1, 'value'=>'', 'operator'=>'RLIKE']]);
                break;
            case '2':             // links to check
                $GLOBALS['r_state']['show_subtree']    = 1;
                $GLOBALS['r_state']['cat_id']          = $start_id;
                $GLOBALS['r_state']['cat_path']        = $start_path;
                $GLOBALS['r_state']['start_path']      = $start_path;
                $GLOBALS['r_state']['tree_start_path'] = $links_info['tree_start'];
                $GLOBALS['r_state']['bin'] = 'app';
                $manager->resetSearchBar();
                $manager->addOrderBar(  [0=> ['checked'=>'a']]);
                $manager->addSearchBar( [0=> ['name'=>1, 'value'=>'', 'operator'=>'RLIKE']]);
                break;
            case '3':             // last edited links
                $GLOBALS['r_state']['show_subtree']    = 1;
                $GLOBALS['r_state']['cat_id']          = $start_id;
                $GLOBALS['r_state']['cat_path']        = $start_path;
                $GLOBALS['r_state']['start_path']      = $start_path;
                $GLOBALS['r_state']['tree_start_path'] = $links_info['tree_start'];
                $GLOBALS['r_state']['bin'] = 'app';
                $manager->resetSearchBar();
                $manager->addOrderBar(  [0=> ['last_edit'=>'d']]);
                $manager->addSearchBar( [0=> ['name'=>1, 'value'=>'', 'operator'=>'RLIKE']]);
                break;
        }
    }
}



/**
 * Removes link from given category
 */
function Links_DeleteLink($lid) {
    global $r_state;
    $aid = Links_GetAsociationId($lid, $r_state['cat_id']);
    if ( !$aid ) {
        return _m('Can\'t find link in given category');  // error
    }

    if ( !IsCatPerm( PS_LINKS_DELETE_LINK, $r_state['cat_path'] ) ) { // have I perm to del?
        DB_AA::sql("UPDATE links_link_cat SET proposal_delete = 'y' WHERE a_id = $aid");      //   delele_proposal too
        return _m('No permission to delete link from the category - link set as PROPOSAL to DELETE from given category');
    }

    $db = getDB();
    // get assignment info
    $SQL = "SELECT * FROM links_link_cat WHERE a_id = $aid";
    $db->query($SQL);
    if ( !$db->next_record() ) {
        freeDB($db);
        return _m('Can\'t get asociation informations');
    }

    if ( $db->f('base') == 'y' ) {    // we have to find another base for the link
        $SQL = "SELECT * FROM links_link_cat WHERE what_id = ". $db->f('what_id'). " AND a_id <> $aid  ORDER BY state DESC";  // visible - highlight - hidden
        $db->query($SQL);
        if ( $db->next_record() ) {
            if ( $db->f('state') == 'hidden' ) {
                $SQL = "UPDATE links_link_cat SET state='visible', base='y' WHERE a_id = ". $db->f('a_id');
            } else {
                $SQL = "UPDATE links_link_cat SET base='y' WHERE a_id = ". $db->f('a_id');
            }
            $db->query($SQL);                                 // new base
        }
    }
           // else - no other assignment - we create unused link
    $SQL = "DELETE FROM links_link_cat WHERE a_id = $aid";
    $db->query($SQL);
    freeDB($db);
    return false;                                         // OK - no error
}


/**
 * Approves the suggested link on public site
 */
function Links_ApproveLink($param, $lid, $akce_param) {
    global $r_state;
    $aid = Links_GetAsociationId($lid, $r_state['cat_id']);
    if ( !$aid ) {
        return _m('Can\'t find link in given category');  // error
    }

    if ( !IsCatPerm( PS_LINKS_ADD_LINK, $r_state['cat_path'] ) ) {  // have I perm to add?
        return _m('No permission to approve link to given category');
    }

    DB_AA::sql("UPDATE links_link_cat SET proposal = 'n' WHERE a_id = $aid");
    // we have to make all other assignments visible
    DB_AA::sql("UPDATE links_link_cat SET state='visible', base='n', proposal='y' WHERE category_id = '". $r_state['cat_id'] ."' AND what_id = $lid AND state = 'hidden'");
    return false;                                         // OK - no error
}


/**
 * Refuses the suggested link from given category
 */
function Links_RefuseLink($param, $lid, $akce_param) {
    global $r_state;
    $aid = Links_GetAsociationId($lid, $r_state['cat_id']);
    if ( !$aid ) {
        return _m('Can\'t find link in given category');  // error
    }

    if ( !IsCatPerm( PS_LINKS_DELETE_LINK, $r_state['cat_path']) ) { // have I perm to DEL?
        DB_AA::sql("UPDATE links_link_cat SET proposal_delete = 'y' WHERE a_id = $aid");                      //   delele_proposal too
        return _m('No permission to delete link from the category - link set as PROPOSAL to DELETE from given category');
    }

    $db = getDB();
    // get assignment info
    $SQL = "SELECT * FROM links_link_cat WHERE a_id = $aid";
    $db->query($SQL);
    if ( !$db->next_record() ) {
        freeDB($db);
        return _m('Can\'t get asociation informations');
    }

    if ( $db->f('base') == 'y' ) {            // we have to find new base
        $SQL = "SELECT * FROM links_link_cat
                   WHERE what_id = ". $db->f('what_id') ."
                   AND base = 'n'
                   ORDER BY proposal, state";  // true links first
        $db->query($SQL);
        if ( $db->next_record() ) {       // link is linked to another category
            $SQL = ( ( $db->f('state')=='hidden' ) ?
                     "UPDATE links_link_cat SET base='y', state='visible'
                       WHERE a_id=".$db->f('a_id') :
                     "UPDATE links_link_cat SET base='y'
                       WHERE a_id=".$db->f('a_id') );
            $db->query($SQL);
        }
    }
    $db->query("DELETE FROM links_link_cat WHERE a_id=$aid");  // delete assig.
    // we have to make all other assignments

    freeDB($db);
    return false;                                         // OK - no error
}


/**
 * Moves link to specified folder
 */
function Links_Move2Folder($lid, $folder) {
    // get link's base category
    $base_category_path = GetBaseCategoryPath( $lid );

    // have I perm to move?
    if ( $base_category_path ) {
        if (!IsCatPerm( $folder==1 ? PS_LINKS_LINK2ACT : PS_LINKS_LINK2FOLDER,  $base_category_path)) {
            return _m('No permission to move link');  // error
        }
    } elseif (!IsSuperadmin()) {
        return _m('No permission to move link');  // error
    }

    DB_AA::sql("UPDATE links_links SET folder='$folder' WHERE id = $lid");
    return false;                                         // OK - no error
}


/**
 * Finds and returns asociation number (id of row in links_link_cat table)
 * for given link and category
 */
function Links_GetAsociationId($lid, $cid) {
    if ( !$lid OR !$cid ) {
        return false;
    }
    return DB_AA::select1('a_id', 'SELECT a_id FROM links_link_cat', [
        ['category_id', $cid, 'i'],
        ['what_id', $lid, 'i']
    ]);
}
