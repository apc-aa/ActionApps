<?php
/**
 * Various event handlers. These handlers help to link different
 * parts of AA, like Reader management slices with special Reader
 * related features (Auth, Alerts, Mailman) or sending notifications.
 *
 * @package UserInput
 * @version $Id$
 * @author Jakub Adamek, Econnect
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
require_once $GLOBALS["AA_BASE_PATH"]."modules/alerts/event.php3";

/** Called on updating an existing item.
*   @param object $itemContent (class ItemContent) is sent by reference,
*       you can change the data
*   @param object $oldItemContent is also sent by reference, but for
*       better performance only
*   @return bool true if update should proceed, false to interrupt */
function Event_ItemBeforeUpdate( $item_id, $slice_id, &$itemContent,
                                 &$oldItemContent ) {
    // Delete reader from Auth tables because if the username changes,
    // AuthUpdateReaders can not recognize it.
    AuthDeleteReaders( array( pack_id( $item_id)), $slice_id );
    return true;
}
/** Called on inserting a new item.
*   @param object $itemContent (class ItemContent) is sent by reference,
*       you can change the data
*   @param object $oldItemContent is also sent by reference, but for
*       better performance only
*   @return bool true if insert should proceed, false to interrupt */
function Event_ItemBeforeInsert( $item_id, $slice_id, &$itemContent ) {
    return true;
}
/** Called after updating an existing item.
*
*   Params are sent by reference but for better performance only.
*/
function Event_ItemAfterUpdate( $item_id, $slice_id, &$itemContent,
                                &$oldItemContent )
{
    AuthUpdateReaders( array( pack_id( $item_id )), $slice_id );
//    AlertsSendInstantAlert( $item_id, $slice_id );
    MailmanCreateSynchroFiles ($slice_id);

    // notifications
    switch ($itemContent->getStatusCode()) {
        case SC_ACTIVE:      email_notify($slice_id, 4, $item_id); break;
        case SC_HOLDING_BIN: email_notify($slice_id, 2, $item_id); break;
    }
}
/** Called after inserting a new item.
*
*   Params are sent by reference but for better performance only.
*/
function Event_ItemAfterInsert( $item_id, $slice_id, &$itemContent )
{
    AuthUpdateReaders( array( pack_id( $item_id )), $slice_id );
    AlertsSendWelcome( $item_id, $slice_id, $itemContent );
//    AlertsSendInstantAlert( $item_id, $slice_id );
    MailmanCreateSynchroFiles ($slice_id);

    // notifications
    switch ($itemContent->getStatusCode()) {
        case SC_ACTIVE:      email_notify($slice_id, 3, $item_id); break;
        case SC_HOLDING_BIN: email_notify($slice_id, 1, $item_id); break;
    }
}
/** Called on deleting several items.
*   @return bool true if delete should proceed, false to interrupt */
function Event_ItemsBeforeDelete( $item_ids, $slice_id ) {
    /* It is not really necessary to delete the readers from Auth tables,
       because they should be deleted on moving to Trash bin. But it is
       perhaps better to make sure. */
    AuthDeleteReaders( $item_ids, $slice_id );
    MailmanCreateSynchroFiles ($slice_id);
    return true;
}

/** Called on moving items to another bin (changing status code).
*   @return bool true if the operation should proceed, false to interrupt */
function Event_ItemsBeforeMove( $item_ids, $slice_id, $new_status ) {
    return true;
}

/** Called after moving items to another bin (changing status code).
*/
function Event_ItemsAfterMove( $item_ids, $slice_id, $new_status ) {
    AuthUpdateReaders( $item_ids, $slice_id );
    MailmanCreateSynchroFiles( $slice_id );
}

/** Called on propagating a change in a constant value.
*   @param string $constant_id Unpacked ID from the constant table.
*   @param string $oldvalue, $newvalue Both have added slashes (e.g. from a form).
*   @return bool true if the operation should proceed, false to interrupt */
function Event_ItemsBeforePropagateConstantChanges (
    $constant_id, $oldvalue, $newvalue) {
    return true;
};

/** Called after propagating a change in a constant value. Params like by ..Before.. */
function Event_ItemsAfterPropagateConstantChanges (
    $constant_id, $oldvalue, $newvalue) {
    AuthChangeGroups ($constant_id, $oldvalue, $newvalue);
    MailmanConstantsChanged( $constant_id, $oldvalue, $newvalue );
}

?>