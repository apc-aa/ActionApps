<?php
/**
 * Event class - this class invokes various event handlers based on input
 * event. Basicaly - AA allways should call event class instance, if any
 * event ocures (new item, item changed, ...). The event istance will look
 * into a table, if any handler waits for the event. If so, all matched
 * handlers are called.
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
 * @package   UserInput
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (c) 2002-3 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/


require_once AA_INC_PATH."mail.php3";
require_once AA_BASE_PATH."modules/alerts/event.php3";
//mimo add
require_once AA_INC_PATH."mlx.php";


/**
 * aahandler class - stores handler function and 'trigger' conditions for
 * the function invoking
 */
class aahandler {
    var $funct;    // handler function - function to call is conditions are met
    var $conds;
    /** aahandler function
     * @param $funct
     * @param $conds
     */
    function aahandler( $funct, $conds ) {
        $this->funct = $funct;
        $this->conds = $conds;
    }
    /** matches function
     * @param $type
     * @param $slice
     * @param $slice_type
     */
    function matches( $type, $slice, $slice_type ) {
        if ( !(isset($this->conds) AND is_array($this->conds)) ) {
            return true;   // no conditions are set for handler - do it allways
        }
        foreach ( $this->conds as $condition => $value ) {
            if ( $$condition != $value ) {
                return false;
            }
        }
        return true;       // all defined conditions matches
    }

    /** process function
     *  Process the event - it is the time of event
     * @param $type
     * @param $slice
     * @param $slice_type
     * @param $ret_params
     * @param $params
     * @param $params2
     */
    function process($type, $slice, $slice_type, &$ret_params, $params, $params2) {
        $function = $this->funct;
        return $function($type, $slice, $slice_type, $ret_params, $params, $params2);
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
        if ( $this->handlers == 'not_filled' ) {
            $this->get_handlers($type, $slice, $slice_type);
        }
        if ( !(isset($this->handlers) AND is_array($this->handlers)) ) {
            return false;
        }
        foreach ( $this->handlers as $handler ) {
            if ( $handler->matches($type, $slice, $slice_type) ) {
                $this->returns[] = $handler->process($type, $slice, $slice_type, $ret_params, $params, $params2);
            }
        }
    }

    /** get_handlers function
     *  Fills the handlers array from database
     * @param $type
     * @param $class
     * @param $selector
     */
    function get_handlers($type, $class, $selector) {
        // TODO - read the events from database instead of this static definition
        $this->handlers   = array();
        $this->handlers[] = new aahandler('Event_ItemsBeforeDelete',       array('type' => 'ITEMS_BEFORE_DELETE',   'slice_type' => 'S'));  // all slices
        $this->handlers[] = new aahandler('Event_ItemsMoved',              array('type' => 'ITEMS_MOVED',           'slice_type' => 'S'));  // all slices
        $this->handlers[] = new aahandler('Event_ItemBeforeUpdate',        array('type' => 'ITEM_BEFORE_UPDATE',    'slice_type' => 'S'));  // all slices
        $this->handlers[] = new aahandler('Event_ItemBeforeInsert',        array('type' => 'ITEM_BEFORE_INSERT',    'slice_type' => 'S'));  // all slices
        $this->handlers[] = new aahandler('Event_ItemAfterInsert',         array('type' => 'ITEM_NEW',              'slice_type' => 'S'));  // all slices
        $this->handlers[] = new aahandler('Event_ItemAfterUpdate',         array('type' => 'ITEM_UPDATED',          'slice_type' => 'S'));  // all slices
        $this->handlers[] = new aahandler('Event_CommentAfterInsert',      array('type' => 'COMMENT_UPDATED',       'slice_type' => 'S'));  // all slices
        $this->handlers[] = new aahandler('Event_ConstantBeforeUpdate',    array('type' => 'CONSTANT_BEFORE_UPDATE','slice_type' => 'S'));  // all slices
        $this->handlers[] = new aahandler('Event_ConstantUpdated',         array('type' => 'CONSTANT_UPDATED',      'slice_type' => 'S'));  // all slices
        $this->handlers[] = new aahandler('Event_AddLinkGlobalCat',        array('type' => 'LINK_NEW',              'slice_type' => 'Links'));
        $this->handlers[] = new aahandler('Event_AddLinkGlobalCat',        array('type' => 'LINK_UPDATED',          'slice_type' => 'Links'));
        $this->handlers[] = new aahandler('Event_ItemUpdated_DropIn',      array('type' => 'ITEM_UPDATED',     'slice'        => 'c7a5b60cf82652549f518a2476d0d497'));  // dropin poradna
        $this->handlers[] = new aahandler('Event_ItemUpdated_DropIn',      array('type' => 'ITEM_NEW',         'slice'        => 'c7a5b60cf82652549f518a2476d0d497'));  // dropin poradna
        $this->handlers[] = new aahandler('Event_ItemUpdated_Aperio',      array('type' => 'ITEM_UPDATED',     'slice'        => '22613f53fdaa6e092569b6021b23fee2'));  // Aperio - rodina (poradna)
        $this->handlers[] = new aahandler('Event_ItemUpdated_Aperio',      array('type' => 'ITEM_NEW',         'slice'        => '22613f53fdaa6e092569b6021b23fee2'));  // Aperio - rodina (poradna)
        $this->handlers[] = new aahandler('Event_ItemUpdated_Aperio_porod',array('type' => 'ITEM_UPDATED',     'slice'        => '18f916e58b8929d79d6c69efd87e85b8'));  // Aperio - porodnice (poradna)
        $this->handlers[] = new aahandler('Event_ItemUpdated_Aperio_porod',array('type' => 'ITEM_NEW',         'slice'        => '18f916e58b8929d79d6c69efd87e85b8'));  // Aperio - porodnice (poradna)
        $this->handlers[] = new aahandler('Event_ItemUpdated_Ekoinfocentrum',array('type' => 'ITEM_UPDATED',   'slice'        => 'eedbdb4543581e21d89c89877cfdc70f'));  // Ekoinfocentrum poradna
        $this->handlers[] = new aahandler('Event_ItemUpdated_Ekoinfocentrum',array('type' => 'ITEM_NEW',       'slice'        => 'eedbdb4543581e21d89c89877cfdc70f'));  // Ekoinfocentrum poradna
        $this->handlers[] = new aahandler('Event_ItemAfterInsert_NszmAkce',array('type' => 'ITEM_NEW',         'slice'        => '987c680c5adfc6f872909d703f98ba97'));  // NSZM - akce - lidi
        $this->handlers[] = new aahandler('Event_ItemAfterInsert_NszmPruzkum',array('type' => 'ITEM_NEW',         'slice'        => '63e7f6ee3d20167df1663444a9d828c2'));  // NSZM - pruzkum
        $this->handlers[] = new aahandler('Event_ItemNewComment',          array('type' => 'ITEM_NEW_COMMENT',      'slice_type' => 'Item'));
        $this->handlers[] = new aahandler('Event_ItemUpdated_Aperio_porad',array('type' => 'ITEM_UPDATED',     'slice'        => 'e455517b6d142d19cc8ad08c5be98eef'));  // Aperio - poradna
        $this->handlers[] = new aahandler('Event_ItemUpdated_Aperio_porad',array('type' => 'ITEM_NEW',         'slice'        => 'e455517b6d142d19cc8ad08c5be98eef'));  // Aperio - poradna
        $this->handlers[] = new aahandler('Event_ItemUpdated_Profem',      array('type' => 'ITEM_UPDATED',     'slice'        => '834dfc55e512ef4145ca2e73d2b461a3'));  // Profem poradna
        $this->handlers[] = new aahandler('Event_ItemUpdated_Profem',      array('type' => 'ITEM_NEW',         'slice'        => '834dfc55e512ef4145ca2e73d2b461a3'));  // Profem poradna
        $this->handlers[] = new aahandler('Event_ItemInserted_Efekt',      array('type' => 'ITEM_NEW',         'slice'        => '5f8d11e83b206f3c1a89f39039e9c38b'));  // EFEKT - iEKIS
        $this->handlers[] = new aahandler('Event_ItemUpdated_Efekt',       array('type' => 'ITEM_UPDATED',     'slice'        => '5f8d11e83b206f3c1a89f39039e9c38b'));  // EFEKT - iEKIS
        $this->handlers[] = new aahandler('Event_ItemUpdated_Sasov_objed', array('type' => 'ITEM_UPDATED',     'slice'        => '2d81635df9bbff2a7deebd89808f3cfb'));  // Biofarma Sasov - objednavka
    }

    /** get_handlers_newwwwww function
     *  Fills the handlers array from database
     * @param $type
     * @param $class
     * @param $selector
     */
    function get_handlers_newwwwww($type, $class, $selector) {
        $db = getDB();
        $SQL = "SELECT reaction, params FROM event WHERE type='".quote($type)."'";
        if ($class) {
            $SQL .= " AND class='".quote($class)."'";
        }
        if ($selector) {
            $SQL .= " AND selector='".quote($selector)."'";
        }
        $db->query($SQL);
        while ($db->next_record()) {
            $reaction_class = $db->f('reaction');
            // security check - class must end with "Event"
            if ( substr($reaction_class, -5) == 'Event' ) {
                $this->handlers[] = new $reaction_class( $db->f('params'), $type, $class, $selector);
            }
        }
    }
}

/** Newest approach - events are stored in the database (table event) - no need
 *  for match method - it is already implemented in database layer - see
 *  get_handlers() method above
 */
class NewDiscussionCommentEvent {
    var $email;
    /** NewDiscussionCommentEvent function
     * @param $params
     * @param $type
     * @param $class
     * @param $selector
     */
    function NewDiscussionCommentEvent( $params, $type, $class, $selector ) {
        $email = unserialize($params);
    }

    /** matches function
     *  not necessary - implemented in get_handlers method in database layer
     * @param $type
     * @param $slice
     * @param $slice_type
     */
    function matches( $type, $slice, $slice_type ) {
        return true;
    }

    /** process function
     *  Process the event - it is the time of event
     * @param $type
     * @param $slice
     * @param $slice_type
     * @param $ret_params
     * @param $params
     * @param $params2
     */
    function process($type, $slice, $slice_type, &$ret_params, $params, $params2) {


//todo
        // get discussion item content
        $columns = GetDiscussionContentSQL(
           "SELECT * FROM discussion WHERE id = '".q_pack_id($new_id)."'",
           $d_item_id, "", true, $html, "");
        $columns = reset($columns);  // get first element

        // get aliases
        $aliases = GetDiscussionAliases();
        for ( $i=2, $ino=count($item_params); $i<$ino; ++$i) {
            FillFakeAlias($columns, $aliases, "_#ITEMPAR".($i+1), $item_params[$i]);
        }

        $CurItem = new AA_Item($columns, $aliases);

        // newer version based on email templates
        if ( $vid{0} == 't' ) {   // email template
            $mail_id = substr($vid,1);
            AA_Mail::sendTemplate($mail_id, $maillist, $CurItem);
            return;
        }


        $function = $this->funct;
        return $function($type, $slice, $slice_type, $ret_params, $params, $params2);
    }
}

/** GetNotifications function
 * @param $type
 * @param $class
 * @param $selector
 * @param $reaction
 * @param $params
 */
function GetNotifications($type, $class, $selector, $reaction=null, $params=null) {
    $type     = quote($type);
    $class    = quote($class);
    $selector = quote($selector);

    $SQL = "SELECT params FROM event WHERE type='$type' AND class='$class' AND selector = '$selector'";

    if ( isset($reaction) ) {
       $SQL .= ' AND reaction =\''.quote($reaction).'\'';
    }
    if ( isset($params) ) {
       $SQL .= ' AND params =\''.quote($params).'\'';
    }
    $notifications = GetTable2Array($SQL, 'NoCoLuMn', 'params');
    return $notifications ? array_unique((array)$notifications) : array();
}
/** AddNotification function
 * @param $type
 * @param $class
 * @param $selector
 * @param $reaction
 * @param $params
 */
function AddNotification($type, $class, $selector, $reaction, $params) {

    // check if user already is not set for this item
    if ( count(GetNotifications($type, $class, $selector, $reaction, $params)) > 0 ) {
        return false;
    }
    $notification_id = new_id();
    $notificationVS  = new Cvarset();
    $notificationVS->add("id",        "text",   $notification_id);
    $notificationVS->add("type",      "text",   $type);
    $notificationVS->add("class",     "text",   $class);
    $notificationVS->add("selector",  "text",   $selector);
    $notificationVS->add("reaction",  "text",   $reaction);
    $notificationVS->add("params",    "quoted", $params);
    $notificationVS->doInsert('event');
}


/** ------------- Handlers --------------*/

/** Event_ItemNewComment function
 * @param $type
 * @param $item_id
 * @param $slice_type
 * @param $disc_id
 * @param $foo
 * @param $foo2
 */
function Event_ItemNewComment( $type, $item_id, $slice_type, &$disc_id, $foo, $foo2 ) {

    $emails = GetNotifications($type, $slice_type, $item_id);

    $item_zid    = new zids($item_id, 'l');
    $content4ids = GetItemContent($item_zid, false, false, array('id..............','slice_id........','e_posted_by.....'));
    $content4id  = reset($content4ids);  // get first element

    // send e-mail also to author of the item
    if ( AA_Validate::validate($content4id['e_posted_by.....'][0]['value'], 'email')) {
        $emails[] = $content4id['e_posted_by.....'][0]['value'];
    }
    if ( count($emails) < 1 ) {
        return true;
    }

    // get e-mail template - quite comlicated, isn't?
    $slice_id    = unpack_id($content4id['slice_id........'][0]['value']);

    // if the view vid is assigned to fulltext view, take the e-mail template
    // from that view
    $view_id     = AA_Slices::getSliceProperty($slice_id, 'vid');

    if ( $view_id > 0 ) {
        // get id of e-mail template (stored in aditional6 field of view definition)
        $mail_id = AA_Views::getViewField($view_id, 'aditional6');
    } else {
        // get id of e-mail template from first discussion view of the slice
        $mail_id = GetTable2Array("SELECT aditional6 FROM view WHERE slice_id='".q_pack_id($slice_id)."' AND type='discus' ORDER BY id", 'aa_first', 'aditional6');
    }

    if ( $mail_id < 1 ) {
        return true;
    }

    // get discussion item content
    $zids      = new zids($disc_id, 'l');
    $d_content = GetDiscussionContent($zids);
    $columns   = reset($d_content);  // get first element
    $CurItem   = new AA_Item($columns, GetDiscussionAliases());

    $mail = new AA_Mail;
    $mail->setFromTemplate($mail_id, $CurItem);
    $mail->sendLater($emails);
    return true;
}


/** Event_ItemAfterInsert function
 *   Called after inserting a new item.
 *   $itemContent is sent by reference but for better performance only.
 * @param $type
 * @param $slice_id
 * @param $slice_type
 * @param $itemContent
 * @param $foo
 * @param $foo2
 */
function Event_ItemAfterInsert( $type, $slice_id, $slice_type, &$itemContent, $foo, $foo2 ) {
    $item_id = $itemContent->getItemID();
    AA_Mysqlauth::updateReaders( array( pack_id( $item_id )), $slice_id );
    AlertsSendWelcome( $slice_id, $itemContent );
    // AlertsSendInstantAlert( $item_id, $slice_id );
    $GLOBALS['MAILMAN_SYNCHRO_DIR'] && AA_Mailman::createSynchroFiles($slice_id);

    // notifications
    switch ($itemContent->getStatusCode()) {
        case SC_ACTIVE:      email_notify($slice_id, 3, $item_id); break;
        case SC_HOLDING_BIN: email_notify($slice_id, 1, $item_id); break;
    }
    return true;
}

/** Event_ItemAfterUpdate function
 *   Called after updating an existing item.
 *   $itemContent is sent by reference but for better performance only.
 * @param $type
 * @param $slice_id
 * @param $slice_type
 * @param $itemContent
 * @param $oldItemContent
 * @param $foo2
 */
function Event_ItemAfterUpdate( $type, $slice_id, $slice_type, &$itemContent, $oldItemContent, $foo2 ) {
    $item_id = $itemContent->getItemID();
    AA_Mysqlauth::updateReaders( array( pack_id( $item_id )), $slice_id );
//    AlertsSendInstantAlert( $item_id, $slice_id );
    $GLOBALS['MAILMAN_SYNCHRO_DIR'] && AA_Mailman::createSynchroFiles($slice_id);

    // notifications
    switch ($itemContent->getStatusCode()) {
        case SC_ACTIVE:      email_notify($slice_id, 4, $item_id); break;
        case SC_HOLDING_BIN: email_notify($slice_id, 2, $item_id); break;
    }
    return true;
}


/** Event_CommentAfterInsert function
 *   Called after inserting of new discussion comment
 *   $itemContent is sent by reference but for better performance only.
 * @param $type
 * @param $slice_id
 * @param $slice_type
 * @param $itemContent
 * @param $oldItemContent
 * @param $foo2
 */
function Event_CommentAfterInsert( $type, $slice_id, $slice_type, &$itemContent, $oldItemContent, $foo2 ) {

}

/** Event_ItemBeforeUpdate
 *   Called on updating an existing item.
 * @param $type
 * @param $slice_id
 * @param $slice_type
 * @param object $itemContent is sent by reference - you can change the data
 * @param $oldItemContent
 * @param $foo2
 */
function Event_ItemBeforeUpdate( $type, $slice_id, $slice_type, &$itemContent, $oldItemContent, $foo2 ) {
    $item_id = $itemContent->getItemID();
    // Delete reader from Auth tables because if the username changes,
    // AA_Mysqlauth::updateReaders can not recognize it.
    AA_Mysqlauth::deleteReaders( array( pack_id( $item_id)), $slice_id );
    return true;
}

/** Event_ItemBeforeInsert function
 *   Called on inserting a new item.
 * @param $type
 * @param $slice_id
 * @param $slice_type
 * @param object $itemContent is sent by reference - you can change the data
 * @param $foo
 * @param $foo2
 */
function Event_ItemBeforeInsert( $type, $slice_id, $slice_type, &$itemContent, $foo, $foo2 ) {
    return true;
}

/** Event_ItemsBeforeDelete function
 *   Called on deleting several items.
 * @param $type
 * @param $slice_id
 * @param $slice_type
 * @param object $item_ids is sent by reference but for better performance only
 * @param $foo
 * @param $foo2
 */
function Event_ItemsBeforeDelete( $type, $slice_id, $slice_type, &$item_ids, $foo, $foo2 ) {
    /* It is not really necessary to delete the readers from Auth tables,
       because they should be deleted on moving to Trash bin. But it is
       perhaps better to make sure. */
    AA_Mysqlauth::deleteReaders( $item_ids, $slice_id );
    $GLOBALS['MAILMAN_SYNCHRO_DIR'] && AA_Mailman::createSynchroFiles($slice_id);
    //mimo added
    $mlx = new MLXEvents();
    $mlx->itemsBeforeDelete($item_ids,$slice_id);
    return true;
}

/** Event_ItemsMoved function
 * Called after moving items to another bin (changing status code).
 * @param $type
 * @param $slice_id
 * @param $slice_type
 * @param $item_ids
 * @param $new_status
 * @param $foo2
 */
function Event_ItemsMoved( $type, $slice_id, $slice_type, &$item_ids, $new_status, $foo2 ) {
    AA_Mysqlauth::updateReaders( $item_ids, $slice_id );
    $GLOBALS['MAILMAN_SYNCHRO_DIR'] && AA_Mailman::createSynchroFiles( $slice_id );
}

/** Event_ConstantBeforeUpdate function
 *  Called on propagating a change in a constant value.
 * @param $type
 * @param $slice_id
 * @param $slice_type
 * @param string $newvalue, $oldvalue Both have added slashes (e.g. from a form).
 * @param $oldvalue
 * @param string $constant_id Unpacked ID of constant from the constant table.
 */
function Event_ConstantBeforeUpdate( $type, $slice_id, $slice_type, &$newvalue, $oldvalue, $constant_id ) {
    return true;
};

/** Event_ConstantUpdated function
 *  Called after propagating a change in a constant value.
 * @param $type
 * @param $slice_id
 * @param $slice_type
 * @param string $newvalue, $oldvalue Both have added slashes (e.g. from a form).
 * @param $oldvalue
 * @param string $constant_id Unpacked ID of constant from the constant table.
 */
function Event_ConstantUpdated( $type, $slice_id, $slice_type, &$newvalue, $oldvalue, $constant_id ) {
    AA_Mysqlauth::changeGroups($constant_id, $oldvalue, $newvalue);
    $GLOBALS['MAILMAN_SYNCHRO_DIR'] && AA_Mailman::constantsChanged( $constant_id, $oldvalue, $newvalue );
}


/** Event_AddLinkGlobalCat function
 *  Creates 'general' categories (if not created yet) when new link type belongs
 *  to 'global categories'. Then it modifies category set, where to assign link
 * @param $type
 * @param $slice
 * @param $slice_type
 * @param array  &$ret_params  - category set, where to assign link - modified
 *                               array[] = category_id
 * @param string  $params      - global category name or false
 * @param string  $params2     - old (previous) global category name or false
 */
function Event_AddLinkGlobalCat( $type, $slice, $slice_type, &$ret_params, $params, $params2) {
    global $db, $LINK_TYPE_CONSTANTS;

    // quite Econnectonous code
    $name    = $params;              // name of general category is in params
    $oldname = $params2;             // name of old general category in params2

    // if new link type is not general (global) category or general category
    // was already set - return
    if ( !( trim($name)) OR trim($oldname) ) {
        return false;
    }

    // get all informations about general categories
    $SQL = "SELECT pri, description, name FROM constant
             WHERE group_id='$LINK_TYPE_CONSTANTS'
               AND value='$name'";
    $db->tquery($SQL);
    if ( $db->next_record() ) {
        $general_cat = $db->Record;
    } else {
        return false;    // not general category - do not modify category set
    }

    // category translations are stored in 'description' field of constants
    // the format of translations are: 1,2-1,2,1224:1,2,4-1,2,4,42:...
    // which means - do not store to category 1,2 but to the category 1,2,1224

    // Example: 1,2-1,2,1223:1,2,4-1,2,4,43:1,2,983-1,2,983,1226:1,2,984-1,2,984,1229:1,2,985-1,2,985,1232:1,2,986-1,2,986,1235:1,2,987-1,2,987,1238
    $trans_string = str_replace( array("\n","\t","\r",' '), '', $general_cat['description'] );
    $translations = explode(':', $trans_string);             // parse
    if ( isset($translations) AND is_array($translations) ) {
        foreach ( $translations as $k ) {
            list($from,$to) = explode('-', $k);
            $trans[$from] = $to;
        }
    }

    // get categories in which we have to create global category
    // = categories and all subcategories - 1,2,33,88 => 1,2; 1,2,33; 1,2,33,88
    $final_categories = array();     // clear return categories
    if ( isset($ret_params) AND is_array($ret_params) ) {
        foreach ( $ret_params as $cid ) {
            $cpath = GetCategoryPath( $cid );
            if ( substr($cpath, 0, 4) != '1,2,' ) {
                // category is not in 'Kormidlo' => do not add link to
                // subcategories, but only to category itself
                $final_categories[] = $cid;
                continue;
            }
            $cat_on_path = explode(',', $cpath);
            $curr_path   = '';
            $i           = 0;
            unset($reverse_cat);
            unset($reverse_path);
            foreach ( $cat_on_path as $subcat ) {
                $curr_path .= ( $i ? ',' : ''). $subcat;  // Create path
                if ( $i++ ) {                             // Skip first level
                    $reverse_cat[]  = $subcat;
                    $reverse_path[] = $curr_path;         // There we have to
                }                                         // add general categ.
            }
            // created categories are in wrong order - we need the deepest
            // category as first
            for ( $j = count($reverse_cat)-1; $j>=0 ; --$j ) {
                $subcategories[$reverse_cat[$j]] = $reverse_path[$j];
            }
        }
    }
    // Now we have $subcategories[] (before translation), where link and global
    // category will be added AND $final_categories[] with other categories,
    // where we want to add link (without translation or global cat. creation)

    // go through desired categories and translate it, if we have to
    if ( isset($subcategories) AND isset($trans) ) {
        foreach ( $subcategories as $cid => $path ) {
            foreach ( $trans as $from => $to ) {
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
    $ret_params = $final_categories;  // add non-Kormidlo categories
    if (!$subcat_translated AND count($ret_params)<=0) {
        return false;       // no category to assign - seldom case
    }

    $tree = new cattree( $db );

    // we have $subcat_translated[] AND $ret_params[] already prefilled
    foreach ( $subcat_translated as $cid => $path ) {
        $sub_cat_id = $tree->subcatExist($cid, $name);
        if ( !$sub_cat_id AND ($tree->getName($cid) != $name) ) {
            $sub_cat_id = Links_AddCategory($name, $cid, $path);
            Links_AssignCategory($cid, $sub_cat_id, $general_cat['pri']);
        }
        // result set of categories to assign link
        $ret_params[] = $sub_cat_id ? $sub_cat_id : $cid;
    }
    return true;
}

/** SendFilledItem function
 *  Send email with answer to Dropin staff with the answer (from item)
 * @param object  &$ret_params  - ItemContent object with new values
 * @param $email_template
 */
function SendFilledItem(&$ret_params, $email_template) {
    $short_id = $ret_params->getValue('short_id........');              // item's short_id is in params
    $email    = trim($ret_params->getValue('con_email......1'));
    $otazka   = trim($ret_params->getValue('abstract.......1'));
    $odpoved  = trim($ret_params->getValue('abstract.......2'));
    $send     = trim($ret_params->getValue('switch.........2'));

    if ($email AND $otazka AND $odpoved AND (($send == 'on') OR ($send == '1'))) {
        $item = AA_Items::getItem(new zids($short_id, 's'));
        return AA_Mail::sendTemplate($email_template, array($email), $item, false) > 0;
    }
    return false;
}

/** Event_ItemUpdated_DropIn function
 * Send email with answer to Dropin staff with the answer (from item)
 * @param $type
 * @param $slice
 * @param $slice_type
 * @param $ret_params
 * @param $params
 * @param $params2
 */
function Event_ItemUpdated_DropIn( $type, $slice, $slice_type, &$ret_params, $params, $params2) {
    return SendFilledItem($ret_params, 8);
}

/** Event_ItemUpdated_Aperio function
 * Send email with answer to Aperio staff with the answer (from item)
 * @param $type
 * @param $slice
 * @param $slice_type
 * @param $ret_params
 * @param $params
 * @param $params2
 */
function Event_ItemUpdated_Aperio( $type, $slice, $slice_type, &$ret_params, $params, $params2) {
    return SendFilledItem($ret_params, 49);
}

/** Event_ItemUpdated_Aperio_porod function
 * Send email with answer to Aperio staff with the answer (from item)
 * @param $type
 * @param $slice
 * @param $slice_type
 * @param $ret_params
 * @param $params
 * @param $params2
 */
function Event_ItemUpdated_Aperio_porod( $type, $slice, $slice_type, &$ret_params, $params, $params2) {
    return SendFilledItem($ret_params, 50);
}

/** Event_ItemUpdated_Aperio_porad function
 * Send email with answer to Aperio staff with the answer (from item)
 * @param $type
 * @param $slice
 * @param $slice_type
 * @param $ret_params
 * @param $params
 * @param $params2
 */
function Event_ItemUpdated_Aperio_porad( $type, $slice, $slice_type, &$ret_params, $params, $params2) {
    return SendFilledItem($ret_params, 62);
}

/** Event_ItemUpdated_Ekoinfocentrum function
 * Send email with answer to Aperio staff with the answer (from item)
 * @param $type
 * @param $slice
 * @param $slice_type
 * @param $ret_params
 * @param $params
 * @param $params2
 */
function Event_ItemUpdated_Ekoinfocentrum( $type, $slice, $slice_type, &$ret_params, $params, $params2) {
    return SendFilledItem($ret_params, 53);
}
/**
 * @param $type
 * @param $slice
 * @param $slice_type
 * @param $ret_params
 * @param $foo
 * @param $foo2
 */
/** Send email with answer to Profem user with the answer (from item) */
function Event_ItemUpdated_Profem( $type, $slice, $slice_type, &$ret_params, $params, $params2) {
    return SendFilledItem($ret_params, 64);
}


function Event_ItemAfterInsert_NszmAkce( $type, $slice_id, $slice_type, &$ret_params, $foo, $foo2 ) {
    $short_id  = $ret_params->getValue('short_id........');              // item's short_id is in params
    $akce_id   = trim($ret_params->getValue('unspecified.....'));              // akce_id
    $email     = trim($ret_params->getValue('e_posted_by....1'));

    $item_akce = AA_Items::getItem(new zids($akce_id, 'l'));
    $text      = trim($item_akce->getval('text...........7'));

    $item      = AA_Items::getItem(new zids($short_id, 's'));

    if ($email AND $text) {
        return AA_Mail::sendTemplate(60, array($email), $item, false) > 0;
    }
    return false;
}

/** Event_ItemAfterInsert_NszmPruzkum function
 * @param $type
 * @param $slice
 * @param $slice_type
 * @param $ret_params
 * @param $foo
 * @param $foo2
 */
function Event_ItemAfterInsert_NszmPruzkum( $type, $slice_id, $slice_type, &$ret_params, $foo, $foo2 ) {
    $short_id = $ret_params->getValue('short_id........');              // item's short_id is in params
    $email1   = trim($ret_params->getValue('address.........'));
    $email2   = trim($ret_params->getValue('address........1'));

    $item     = AA_Items::getItem(new zids($short_id, 's'));

    if ($email1 OR $email2) {
        return AA_Mail::sendTemplate(63, array($email1, $email2), $item, false) > 0;
    }
    return false;
}


/** Event_ItemAfterInsert_NszmPruzkum function
 * @param $type
 * @param $slice
 * @param $slice_type
 * @param $ret_params
 * @param $foo
 * @param $foo2
 */
function Event_ItemUpdated_Efekt( $type, $slice_id, $slice_type, &$ret_params, $foo, $foo2 ) {
    $short_id   = $ret_params->getValue('short_id........');              // item's short_id is in params

    if (!$ret_params->getValue('switch.........2')) {
        return false;  // not stored for MPO, yet
    }

    $email1   = trim($ret_params->getValue('con_email.......'));

    $item     = AA_Items::getItem(new zids($short_id, 's'));

    if ($email1) {
        return AA_Mail::sendTemplate(5, array($email1), $item, false) > 0;
    }
    return false;
}

/** Event_ItemAfterInsert_NszmPruzkum function
 * @param $type
 * @param $slice
 * @param $slice_type
 * @param $ret_params
 * @param $foo
 * @param $foo2
 */
function Event_ItemInserted_Efekt( $type, $slice_id, $slice_type, &$ret_params, $foo, $foo2 ) {
    $short_id = $ret_params->getValue('short_id........');              // item's short_id is in params
    $ekis_id  = $ret_params->getValue('relation.......1');              // item's short_id is in params


    $email1   = trim(AA_Stringexpand::unalias('{item:'.$ekis_id.':_#EKISMAIL}'));

    $item     = AA_Items::getItem(new zids($short_id, 's'));

    if ($email1) {
        return AA_Mail::sendTemplate(4, array($email1), $item, false) > 0;
    }
    return false;
}


/** Event_ItemAfterInsert_NszmPruzkum function
 * @param $type
 * @param $slice
 * @param $slice_type
 * @param $ret_params
 * @param $foo
 * @param $foo2
 */
function Event_ItemUpdated_Sasov_objed( $type, $slice_id, $slice_type, &$ret_params, $foo, $foo2 ) {
    if ($_POST['souhlas'] != 1) {
        return false;
    }

    $short_id = $ret_params->getValue('short_id........');              // item's short_id is in params
    $emaily   = array('hm@ecn.cz', 'pykalova@biofarma.cz');

    //$email1 = trim($ret_params->getValue('con_email.......'));

    $item     = AA_Items::getItem(new zids($short_id, 's'));

    if ($email1) {
        $ret = AA_Mail::sendTemplate(7, $emaily, $item, false) > 0;
        return $ret;
    }

    return false;
}




?>
