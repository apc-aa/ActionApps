<?php
/**
 * File contains definitions of functions which corresponds with actions
 * on Link Manager page - manipulates with links
 *
 * Should be included to other scripts (module/links/index.php)
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
use AA\Manager;
use AA\Manageraction\AbstractManageraction;


/** ---switches ---- */


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
        return _m('Can\'t get asociation information');
    }

    if ( $db->f('base') === 'y' ) {    // we have to find another base for the link
        $SQL = "SELECT * FROM links_link_cat WHERE what_id = ". $db->f('what_id'). " AND a_id <> $aid  ORDER BY state DESC";  // visible - highlight - hidden
        $db->query($SQL);
        if ( $db->next_record() ) {
            if ( $db->f('state') === 'hidden' ) {
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
        return _m('Can\'t get asociation information');
    }

    if ( $db->f('base') === 'y' ) {            // we have to find new base
        $SQL = "SELECT * FROM links_link_cat
                   WHERE what_id = ". $db->f('what_id') ."
                   AND base = 'n'
                   ORDER BY proposal, state";  // true links first
        $db->query($SQL);
        if ( $db->next_record() ) {       // link is linked to another category
            $SQL = ( ( $db->f('state')==='hidden' ) ?
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
