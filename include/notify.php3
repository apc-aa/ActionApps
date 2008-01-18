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

//
// utility for notifying people of events by email
//

require_once AA_INC_PATH."item.php3";
require_once AA_INC_PATH."view.php3";
require_once AA_INC_PATH."mail.php3";

/** email_notify function
 *  notify users of an event
 * @param $slice_id
 * @param $event
 * @param $item_id
 * @param $extra
 */
function email_notify($slice_id, $event, $item_id, $extra = ""){
    global $db;
    $p_slice_id = q_pack_id($slice_id);

    // expand the body template using the itemview function
    $format['group_by']        = '';
    $format['category_format'] = '';
    $format['compact_bottom']  = '';
    $format['compact_remove']  = '';
    $format['even_row_format'] ='';
    $format['even_odd_differ'] = '0';
    $format['id']              = $slice_id;

    // get alias list from database
    list($fields,) = GetSliceFields($slice_id);
    $aliases       = GetAliasesFromFields($fields, $als);

    // select the text templates
    switch ($event){
        case 1: $prefix = 'notify_holding_item'; break;
        case 2: $prefix = 'notify_holding_item_edit'; break;
        case 3: $prefix = 'notify_active_item'; break;
        case 4: $prefix = 'notify_active_item_edit'; break;
    }

    $SQL = "SELECT ${prefix}_s as s, ${prefix}_b as b from slice where id = '$p_slice_id'";
    $db->query($SQL);
    if ( $db->next_record() ){
        $s = $db->f('s');
        $b = $db->f('b');
    } else {
        die ("email_notify(): bad slice_id ($slice_id)");
    }

    // determine subject of message
    $format['odd_row_format'] = $s;
    $zids = new zids($item_id);

    $itemview = new itemview($format, $fields, $aliases, $zids, 0, 1, '');
    $subject = $itemview->get_output_cached("view");

    // determine body of message
    $format['odd_row_format'] = $b;
    //$item_ids[] = $item_id;   // Ick, this would have put two ids in!

    $itemview = new itemview($format, $fields, $aliases, $zids, 0, 1, '');
    $body     = $itemview->get_output_cached("view");

    // select all the users
    $SQL = "SELECT uid from email_notify where slice_id = '$p_slice_id' AND function = '$event'";
    $db->query($SQL);

    $emails = "";
    // loop through the users for the event
    while ( $db->next_record() ) {
        $emails[] = $db->f('uid');
    }

    $mail = new HtmlMail();
    $mail->setSubject($subject);
    $mail->setHtml($body, html2text($body));
    $mail->setCharset($LANGUAGE_CHARSETS[get_mgettext_lang()]);
    $mail->send($emails);
}
?>
