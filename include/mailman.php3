<?php
/**
 * Mailman feature related functions: 
 * Event handlers.
 *
 * @package ReaderInput
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

require_once "config.php3";
require_once $GLOBALS["AA_INC_PATH"]."util.php3";
require_once $GLOBALS["AA_BASE_PATH"]."modules/alerts/reader_field_ids.php3";

if (!is_object( $db )) $db = new DB_AA;

// status codes:
define("SC_ACTIVE", 1);
define("SC_HOLDING_BIN", 2);

// --------------------------------------------------------------------------

/** Finds all reader-mailing list assignments for the given slice.
*   Creates one file for each mailing list, named the same as the list.
*   If no users are subscribed to a list, an empty file is created.
*/
function MailmanCreateSynchroFiles ($slice_id) {
    global $db, $MAILMAN_SYNCHRO_DIR;
           
    if (! is_dir ($MAILMAN_SYNCHRO_DIR))
        return;

    $slice_info = GetSliceInfo ($slice_id);
    $field = $slice_info["mailman_field_lists"];
    if ($slice_info["type"] != "ReaderManagement" || ! $field)
        return;
        
    $db->tquery("SELECT email.text AS email, maillist.text AS maillist,
        mailconf.text AS mailconf
        FROM item INNER JOIN content email ON item.id = email.item_id
        INNER JOIN content maillist ON item.id = maillist.item_id
        INNER JOIN content mailconf ON item.id = mailconf.item_id
        WHERE email.field_id='".FIELDID_EMAIL."'
        AND maillist.field_id='".$field."'
        AND mailconf.field_id='".FIELDID_MAIL_CONFIRMED."'
        AND item.slice_id='".q_pack_id($slice_id)."'
        AND item.status_code=1
        AND item.publish_date <= ".time()."
        AND item.expiry_date >= ".time());
        
    while ($db->next_record()) 
        if ($db->f("mailconf") && $db->f("mailconf") != "off")
            $maillist[$db->f("maillist")][] = $db->f("email");
        
    // Add empty mailing lists
    $db->query ("SELECT input_show_func FROM field 
        WHERE slice_id='".q_pack_id($slice_id)."' AND id='$field'");
    if (! $db->next_record())
        return;
    list (,$group_id) = split (":", $db->f("input_show_func"));
    $db->query ("SELECT value FROM constant WHERE group_id='".addslashes($group_id)."'");
    while ($db->next_record())
        if (! $maillist[$db->f("value")])
            $maillist[$db->f("value")] = array ();                
        
    endslash ($MAILMAN_SYNCHRO_DIR);            

    if (!is_array ($maillist))
        return;
            
    // Write files    
    reset ($maillist);
    while (list ($listname, $emails) = each ($maillist)) {
        // I don't want to use @fopen because I believe it is better to know
        // that an error occured
        if ($listname && $fd = fopen ($MAILMAN_SYNCHRO_DIR.$listname, "w")) {
            foreach ($emails as $email)
                fwrite ($fd, $email."\n");
            fclose ($fd);
        }
    }
}        
   
// --------------------------------------------------------------------------           

function MailmanConstantsChanged( $constant_id, $oldvalue, $newvalue ) {
    global $db;
    $db->query ("SELECT group_id FROM constant WHERE id='".q_pack_id($constant_id)."'");
    if (! $db->next_record()) 
        return;
    $group_id = $db->f("group_id");
    $db->query ("SELECT slice.id FROM slice
        INNER JOIN field ON field.slice_id = slice.id
        WHERE slice.type = 'ReaderManagement'
        AND (field.input_show_func LIKE '___:$group_id:%'
        OR  field.input_show_func LIKE '___:$group_id')");
    $slices = array();
    while ($db->next_record())
        $slices[] = unpack_id ($db->f("id"));        
    foreach ($slices as $slice_id)
        MailmanCreateSynchroFiles ($slice_id);
}
      
?>