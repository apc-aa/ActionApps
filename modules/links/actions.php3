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
if (!defined("LINKS_ACTIONS_INCLUDED"))
     define ("LINKS_ACTIONS_INCLUDED",1);
else return;

/**
 * Finds and returns asociation number (id of row in links_link_cat table)
 * for given link and category
 */
function Links_GetAsociationId($lid, $cid) {
    global $db;

    if ( !$lid OR !$cid )
        return false;
    $SQL = "SELECT a_id FROM links_link_cat
             WHERE category_id = '$cid'
               AND what_id = '$lid'";
    $db->tquery($SQL);

    return $db->next_record() ? $db->f('a_id') : false;
}



/**
 * Marks given link as checked (means visited and content checked)
 */
function Links_CheckLink($param, $lid, $akce_param) {
    global $auth, $db;

      // get link's base category
    $base_category_path = GetBaseCategoryPath( $lid );
    if ( !isset($base_category_path) )
        return _m('Can\'t get link data');  // error

    if (!IsCatPerm( PS_LINKS_CHECK_LINK, $base_category_path)) // have I perm to check?
        return _m('No permission to change state of the link');  // error

    $SQL = "UPDATE links_links SET checked = '". now() ."',
                                   checked_by = '". $auth->auth['uid'] ."'
             WHERE id = $lid";
    $db->query($SQL);
    return false;                                         // OK - no error
}

/**
 * Marks the link in given category as Highlighted (or Normal - based
 * on $highlight parameter
 */
function Links_HighlightLink($param, $lid, $akce_param, $highlight=true) {
    global $db, $r_state;

    $aid = Links_GetAsociationId($lid, $r_state['cat_id']);
    if ( !$aid )
        return _m('Can\'t find link in given category');  // error

    $state = ( $highlight ? 'highlight' : 'visible');
    if ( !IsCatPerm( PS_LINKS_HIGHLIGHT_LINK, $r_state['cat_path'] ))            // Perm to highlight?
        return _m('No permission to change state of the link');

    $SQL = "UPDATE links_link_cat SET state = '$state'
             WHERE a_id = $aid";
    $db->query($SQL);
    return false;                                         // OK - no error
}

/**
 * Marks the link in given category as Normal
 */
function Links_DeHighlightLink($param, $lid, $akce_param) {
    return Links_HighlightLink($param, $lid, $akce_param, false);
}


/**
 * Removes link from given category
 */
function Links_DeleteLink($param, $lid, $akce_param) {
    global $db, $r_state;
    $aid = Links_GetAsociationId($lid, $r_state['cat_id']);
    if ( !$aid )
        return _m('Can\'t find link in given category');  // error

    if ( !IsCatPerm( PS_LINKS_DELETE_LINK, $r_state['cat_path'] ) ) { // have I perm to del?
        $SQL = "UPDATE links_link_cat SET proposal_delete = 'y'
                WHERE a_id = $aid";
        $db->query($SQL);                      //   delele_proposal too
        return _m('No permission to delete link from the category - link set as PROPOSAL to DELETE from given category');
    }

      // get assignment info
    $SQL = "SELECT * FROM links_link_cat WHERE a_id = $aid";
    $db->query($SQL);
    if ( !$db->next_record() )
        return _m('Can\'t get asociation informations');

    if ( $db->f('base') == 'y' ) {    // we have to find another base for the link
      $SQL = "SELECT * FROM links_link_cat WHERE what_id = ". $db->f('what_id').
                                         " AND a_id <> $aid
                                         ORDER BY state DESC";  // visible - highlight - hidden
      $db->query($SQL);
      if ( $db->next_record() ) {
        if ( $db->f('state') == 'hidden' ) {
          $SQL = "UPDATE links_link_cat SET state='visible', base='y'
                   WHERE a_id = ". $db->f('a_id');
        } else {
          $SQL = "UPDATE links_link_cat SET base='y'
                   WHERE a_id = ". $db->f('a_id');
        }
        $db->query($SQL);                                 // new base
      }
    }
           // else - no other assignment - we create unused link
    $SQL = "DELETE FROM links_link_cat WHERE a_id = $aid";
    $db->query($SQL);
    return false;                                         // OK - no error
}


/**
 * Approves the suggested link on public site
 */
function Links_ApproveLink($param, $lid, $akce_param) {
    global $db, $r_state;
    $aid = Links_GetAsociationId($lid, $r_state['cat_id']);
    if ( !$aid )
        return _m('Can\'t find link in given category');  // error

    if ( !IsCatPerm( PS_LINKS_ADD_LINK, $r_state['cat_path'] ) )   // have I perm to add?
        return _m('No permission to approve link to given category');

    $SQL = "UPDATE links_link_cat SET proposal = 'n'
             WHERE a_id = $aid";
    $db->query($SQL);
      // we have to make all other assignments visible
    $SQL = "UPDATE links_link_cat SET state='visible', base='n', proposal='y'
             WHERE category_id = '". $r_state['cat_id'] ."'
               AND what_id = $lid
               AND state = 'hidden'";
    $db->query($SQL);
    return false;                                         // OK - no error
}


/**
 * Refuses the suggested link from given category
 */
function Links_RefuseLink($param, $lid, $akce_param) {
    global $db, $r_state;
    $aid = Links_GetAsociationId($lid, $r_state['cat_id']);
    if ( !$aid )
        return _m('Can\'t find link in given category');  // error

    if ( !IsCatPerm( PS_LINKS_DELETE_LINK, $r_state['cat_path']) ) { // have I perm to DEL?
        $SQL = "UPDATE links_link_cat SET proposal_delete = 'y'
                WHERE a_id = $aid";
        $db->query($SQL);                      //   delele_proposal too
        return _m('No permission to delete link from the category - link set as PROPOSAL to DELETE from given category');
    }

      // get assignment info
    $SQL = "SELECT * FROM links_link_cat WHERE a_id = $aid";
    $db->query($SQL);
    if ( !$db->next_record() )
        return _m('Can\'t get asociation informations');

    if ( $db->f('base') == 'y' ) {            // we have to find new base
        $SQL = "SELECT * FROM links_link_cat
                   WHERE what_id = ". $db->f('what_id') ."
                   AND base = 'n'
                   ORDER BY proposal, state";  // true links first
        $db->query($SQL);
        if ( $db->next_record() ) {       // link is linked to another category
            $SQL = ( ( $db->f(state)=='hidden' ) ?
                     "UPDATE links_link_cat SET base='y', state='visible'
                       WHERE a_id=".$db->f(a_id) :
                     "UPDATE links_link_cat SET base='y'
                       WHERE a_id=".$db->f(a_id) );
            $db->query($SQL);
        }
    }
    $db->query("DELETE FROM links_link_cat WHERE a_id=$aid");  // delete assig.
      // we have to make all other assignments
    return false;                                         // OK - no error
}


/**
 * Moves link to specified folder
 */
function Links_Move2Folder($lid, $folder) {
    global $auth, $db;

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

    $SQL = "UPDATE links_links SET folder='$folder' WHERE id = $lid";
    $db->query($SQL);
    return false;                                         // OK - no error
}

/**
 * Move link from folder to Active
 */
function Links_ActivateLink($param, $lid, $akce_param) {
    return Links_Move2Folder($lid, 1);
}

/**
 * Move link from folder to Folder2
 */
function Links_FolderLink($param, $lid, $akce_param) {
    return Links_Move2Folder($lid, $param);
}

/**
 * Assign link to the category specified in param
 */
function Links_Add2CatLink($param, $lid, $akce_param) {
    if ( is_numeric($akce_param) ) {
        return Links_Assign2Category($lid, $akce_param);
    }
    return false;
}

/**
 * Move link from current category to the destination category
 */
function Links_Move2CatLink($param, $lid, $akce_param) {
    if ( is_numeric($akce_param) ) {
        // remove link from current category
        Links_DeleteLink($param, $lid, $akce_param);
        // add link to destination category
        return Links_Assign2Category($lid, $akce_param);
    }
    return false;
}

?>