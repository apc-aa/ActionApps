<?php
/**
 * Event class - this class invokes various event handlers based on input
 * event. Basicaly - AA allways should call event class instance, if any
 * event ocures (new item, item changed, ...). The event istance will look
 * into a table, if any handler waits for the event. If so, all matched
 * handlers are called.
 *
 * @package UserInput
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>, Econnect
 * @copyright (c) 2002-3 Association for Progressive Communications
*/
/*
Copyright (C) 1999-2003 Association for Progressive Communications
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

require_once $GLOBALS["AA_INC_PATH"]."auth.php3";
require_once $GLOBALS["AA_INC_PATH"]."mailman.php3";
require_once $GLOBALS["AA_INC_PATH"]."mail.php3";
require_once $GLOBALS["AA_BASE_PATH"]."modules/alerts/event.php3";

/**
 * aahandler class - stores handler function and 'trigger' conditions for
 * the function invoking
 */
class aahandler {
    var $funct;    // handler function - function to call is conditions are met
    var $conds;

    function aahandler( $funct, $conds ) {
        $this->funct = $funct;
        $this->conds = $conds;
    }

    function matches( $type, $slice, $slice_type ) {
        if ( !(isset($this->conds) AND is_array($this->conds)) )
            return true;   // no conditions are set for handler - do it allways
        foreach ( $this->conds as $condition => $value ) {
            if ( $$condition != $value ) {
                return false;
            }
        }
        return $this->funct;       // all defined conditions matches
    }
}

/**
 * aaevent class - stores list of aahandlers. If event comes, aa calls 'comes'
 * method. aaevent object then looks for handles, which wait for the event
 * and invoke handler funtion for each.
 */
class aaevent {
    var $handlers = 'not_filled';    // array of aahandler objects
    var $returns;                    // array of return values of last event

    /** Main event function - called when any event ocures. The method then
     *  search all handlers and calls all that matches all criteria
     *
     *  @param string $type        - event type identifier
     *  @param string $slice       - slice id, where event occures
     *  @param string $slice_type  - type of the slice, where event occures
     *                              ('S' for slice, 'Links' for links, ...)
     *  @param mixed  &$ret_params - event parameters which could be modified
     *                               by handler
     *  @param mixed  $params      - event parameters - static different for
     *                               each event $type (mainly new values)
     *  @param mixed  $params2     - event parameters - static different for
     *                               each event $type (mainly old values)
     */
    function comes($type, $slice, $slice_type, &$ret_params, $params='', $params2='') {
        unset($this->returns);
        if ( $this->handlers == 'not_filled' )
            $this->get_handlers();
        if ( !(isset($this->handlers) AND is_array($this->handlers)) )
            return false;
        foreach ( $this->handlers as $handler ) {
            $function = $handler->matches($type, $slice, $slice_type);

            // matches and function begins with 'Event_' - security check
            if ( $function AND (substr($function, 0, 6) == 'Event_') ) {
                $this->returns[] = $function($type, $slice, $slice_type, $ret_params, $params, $params2);
            }
        }
    }

    /** Fills the handlers array from database */
    function get_handlers() {
        // TODO - read the events from database instead of this static definition
        $this->handlers   = array();
        $this->handlers[] = new aahandler('Event_AddLinkGlobalCat',
                                          array('type'       => 'LINK_NEW',
                                                'slice_type' => 'Links'));
        $this->handlers[] = new aahandler('Event_AddLinkGlobalCat',
                                          array('type'       => 'LINK_UPDATED',
                                                'slice_type' => 'Links'));
        $this->handlers[] = new aahandler('Event_ItemUpdated_DropIn',
                                          array('type'       => 'ITEM_UPDATED',
                                                'slice'      => 'c7a5b60cf82652549f518a2476d0d497'));  // dropin poradna
        $this->handlers[] = new aahandler('Event_ItemUpdated_DropIn',
                                          array('type'       => 'ITEM_NEW',
                                                'slice'      => 'c7a5b60cf82652549f518a2476d0d497'));  // dropin poradna
    }
}

/** Handlers */

/** Creates 'general' categories (if not created yet) when new link type belongs
 *  to 'global categories'. Then it modifies category set, where to assign link
 *
 *  @param array  &$ret_params  - category set, where to assign link - modified
 *                                array[] = category_id
 *  @param string  $params      - global category name or false
 *  @param string  $params2     - old (previous) global category name or false
 */
function Event_AddLinkGlobalCat( $type, $slice, $slice_type, &$ret_params, $params, $params2) {
    global $db, $LINK_TYPE_CONSTANTS;

    // quite Econnectonous code
    $name    = $params;              // name of general category is in params
    $oldname = $params2;             // name of old general category in params2

    // if new link type is not general (global) category or general category
    // was already set - return
    if( !( trim($name)) OR trim($oldname) )
        return false;

    // get all informations about general categories
    $SQL = "SELECT pri, description, name FROM constant
             WHERE group_id='$LINK_TYPE_CONSTANTS'
               AND value='$name'";
    $db->tquery($SQL);
    if( $db->next_record() ) {
        $general_cat = $db->Record;
    } else {
        return false;    // not general category - do not modify category set
    }

    // category translations are stored in 'description' field of constants
    // the format of translations are: 1,2-1,2,1224:1,2,4-1,2,4,42:...
    // which means - do not store to category 1,2 but to the category 1,2,1224
    $trans_string = str_replace( array("\n","\t","\r",' '), '', $general_cat['description'] );
    $translations = explode(':', $trans_string);             // parse
    if ( isset($translations) AND is_array($translations) ) {
        foreach ( $translations as $k ) {
            list($from,$to) = explode('-', $k);
            $trans[$from] = $to;
        }
    }

    // get categories in which we have to create global category
    if ( isset($ret_params) AND is_array($ret_params) ) {
        foreach( $ret_params as $cid ) {
            $cpath = GetCategoryPath( $cid );
            if ( substr($cpath, 0, 4) != '1,2,' ) {
                continue;                 // category is not in 'Kormidlo'
            }
            $cat_on_path = explode(',', $cpath);
            $curr_path = '';
            $i=0;
            unset($reverse_cat);
            unset($reverse_path);
            foreach ( $cat_on_path as $subcat ) {
                $curr_path .= ( $i ? ',' : ''). $subcat;  // Create path
                if ( $i++ ) {                             // Skip first level
                    $reverse_cat[]  = $subcat;
                    $reverse_path[] = $curr_path;           // There we have to
                }                                         // add general categ.
            }
            // created categories are in wrong order - we need the deepest
            // category as first
            for ( $j = count($reverse_cat)-1; $j>=0 ; $j-- ) {
                $subcategories[$reverse_cat[$j]] = $reverse_path[$j];
            }
        }
    }

    // go through desired categories and translate it, if we have to
    if ( isset($subcategories) AND isset($trans) ) {
        foreach( $subcategories as $cid => $path ) {
            foreach( $trans as $from => $to ) {
                if ( $path == $from ) {        // translate this category
                    $subcat_translated[GetCategoryFromPath($to)] = $to;
                    continue 2;  // next subcategory
                }
            }
            $subcat_translated[$cid] = $path;  // no need to translate
        }
    } else {
        $subcat_translated = $subcategories;   // translation is no defined
    }

    // So, finaly create categories, if not created yet and build the result
    // category list
    $ret_params = array();  // clear return values (=categories)
    if (!$subcat_translated) {
        return false;       // no category to assign - seldom case
    }

    $tree = new cattree( $db );

    foreach ( $subcat_translated as $cid => $path ) {
        $sub_cat_id = $tree->subcatExist($cid, $name);
        if ( !$sub_cat_id AND !Links_IsGlobalCategory($tree->getName($cid)) ) {
            $sub_cat_id = Links_AddCategory($name, $cid, $path);
            Links_AssignCategory($cid, $sub_cat_id, $general_cat['pri']);
        }
        // result set of categories to assign link
        $ret_params[] = $sub_cat_id ? $sub_cat_id : $cid;
    }
    return true;
}

/** Send email with answer to Dropin staff with the answer (from item)
 *  @param array  &$ret_params  - no return values
 *  @param object  $params      - ItemContent object with new values
 */
function Event_ItemUpdated_DropIn( $type, $slice, $slice_type, &$ret_params, $params, $params2) {
    global $db;

    $short_id = $params->getValue('short_id........');              // item's short_id is in params
    $email    = trim($params->getValue('con_email......1'));
    $otazka   = trim($params->getValue('abstract.......1'));
    $odpoved  = trim($params->getValue('abstract.......2'));
    $send     = trim($params->getValue('switch.........2'));

    if ( $email AND $otazka AND $odpoved AND (($send == 'on') OR ($send == '1')) ) {
        $item = GetItemFromId($short_id, true);
        return send_mail_from_table_inner (8, $email, $item) > 0 ;
    }
    return false;
}

?>