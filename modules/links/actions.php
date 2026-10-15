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
function Links_DeleteLink($lid)
{
    global $r_state;
    $aid = Links_GetAsociationId($lid, $r_state['cat_id']);
    if (!$aid) {
        return _m('Can\'t find link in given category');  // error
    }

    if (!IsCatPerm(PS_LINKS_DELETE_LINK, $r_state['cat_path'])) { // have I perm to del?
        AA::Metabase()->doUpdate('links_link_cat', ['a_id' => $aid, 'proposal_delete' => 'y']);      //   delele_proposal too
        return _m('No permission to delete link from the category - link set as PROPOSAL to DELETE from given category');
    }

    // get assignment info
    $assignment = DB_AA::select1([], 'SELECT * FROM links_link_cat', [['a_id', $aid, 'i']]);
    if (!$assignment) {
        return _m("Can't get association information");
    }

    // the new base and the removal together - a link must not end with two bases or none
    DB_AA::transaction(static function () use ($assignment, $aid) {
        if ($assignment['base'] === 'y') {    // we have to find another base for the link
            // no LIMIT 1: rows tie on state, and a LIMIT may let MySQL put another of them first
            $new_base = DB_AA::select([], 'SELECT * FROM links_link_cat', [['what_id', $assignment['what_id'], 'i'], ['a_id', $aid, '<>']], ['state-'])[0] ?? false;  // visible - highlight - hidden
            if ($new_base) {                                      // new base
                if ($new_base['state'] === 'hidden') {
                    AA::Metabase()->doUpdate('links_link_cat', ['a_id' => $new_base['a_id'], 'state' => 'visible', 'base' => 'y']);
                } else {
                    AA::Metabase()->doUpdate('links_link_cat', ['a_id' => $new_base['a_id'], 'base' => 'y']);
                }
            }
        }
        // else - no other assignment - we create unused link
        DB_AA::delete('links_link_cat', [['a_id', $aid, 'i']]);
    });
    return false;                                         // OK - no error
}


/**
 * Approves the suggested link on public site
 */
function Links_ApproveLink($param, $lid, $akce_param)
{
    global $r_state;
    $aid = Links_GetAsociationId($lid, $r_state['cat_id']);
    if (!$aid) {
        return _m('Can\'t find link in given category');  // error
    }

    if (!IsCatPerm(PS_LINKS_ADD_LINK, $r_state['cat_path'])) {  // have I perm to add?
        return _m('No permission to approve link to given category');
    }

    DB_AA::transaction(static function () use ($aid, $lid, $r_state) {
        AA::Metabase()->doUpdate('links_link_cat', ['a_id' => $aid, 'proposal' => 'n']);
        // we have to make all other assignments visible
        DB_AA::update('links_link_cat', [['state', 'visible', 's'], ['base', 'n', 's'], ['proposal', 'y', 's']], [['category_id', $r_state['cat_id']], ['what_id', $lid, 'i'], ['state', 'hidden']]);
    });
    return false;                                         // OK - no error
}


/**
 * Refuses the suggested link from given category
 */
function Links_RefuseLink($param, $lid, $akce_param)
{
    global $r_state;
    $aid = Links_GetAsociationId($lid, $r_state['cat_id']);
    if (!$aid) {
        return _m('Can\'t find link in given category');  // error
    }

    if (!IsCatPerm(PS_LINKS_DELETE_LINK, $r_state['cat_path'])) { // have I perm to DEL?
        AA::Metabase()->doUpdate('links_link_cat', ['a_id' => $aid, 'proposal_delete' => 'y']);                      //   delele_proposal too
        return _m('No permission to delete link from the category - link set as PROPOSAL to DELETE from given category');
    }

    // get assignment info
    $assignment = DB_AA::select1([], 'SELECT * FROM links_link_cat', [['a_id', $aid, 'i']]);
    if (!$assignment) {
        return _m("Can't get association information");
    }

    // the new base and the removal together - a link must not end with two bases or none
    DB_AA::transaction(static function () use ($assignment, $aid) {
        if ($assignment['base'] === 'y') {            // we have to find new base
            // no LIMIT 1: rows tie on proposal and state, and a LIMIT may let MySQL put another of them first
            $new_base = DB_AA::select([], 'SELECT * FROM links_link_cat', [['what_id', $assignment['what_id'], 'i'], ['base', 'n']], ['proposal', 'state'])[0] ?? false;  // true links first
            if ($new_base) {       // link is linked to another category
                AA::Metabase()->doUpdate('links_link_cat', ($new_base['state'] === 'hidden')
                    ? ['a_id' => $new_base['a_id'], 'base' => 'y', 'state' => 'visible']
                    : ['a_id' => $new_base['a_id'], 'base' => 'y']);
            }
        }
        DB_AA::delete('links_link_cat', [['a_id', $aid, 'i']]);  // delete assig.
    });
    return false;                                         // OK - no error
}


/**
 * Moves link to specified folder
 */
function Links_Move2Folder($lid, $folder)
{
    // get link's base category
    $base_category_path = GetBaseCategoryPath($lid);

    // have I perm to move?
    if ($base_category_path) {
        if (!IsCatPerm($folder == 1 ? PS_LINKS_LINK2ACT : PS_LINKS_LINK2FOLDER, $base_category_path)) {
            return _m('No permission to move link');  // error
        }
    } elseif (!IsSuperadmin()) {
        return _m('No permission to move link');  // error
    }

    DB_AA::update('links_links', [['folder', $folder, 's']], [['id', $lid, 'i']]);
    return false;                                         // OK - no error
}


/**
 * Finds and returns asociation number (id of row in links_link_cat table)
 * for given link and category
 */
function Links_GetAsociationId($lid, $cid)
{
    if (!$lid || !$cid) {
        return false;
    }
    return DB_AA::select1('a_id', 'SELECT a_id FROM links_link_cat', [
        ['category_id', $cid, 'i'],
        ['what_id', $lid, 'i'],
    ]);
}
