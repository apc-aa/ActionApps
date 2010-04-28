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
function email_notify($slice_id, $event, $item_id) {
    $p_slice_id = q_pack_id($slice_id);
    $slice      = AA_Slices::getSlice($slice_id);

    // select the text templates
    switch ($event){
        case 1: $prefix = 'notify_holding_item';      break;
        case 2: $prefix = 'notify_holding_item_edit'; break;
        case 3: $prefix = 'notify_active_item';       break;
        case 4: $prefix = 'notify_active_item_edit';  break;
    }

    $SQL    = "SELECT ${prefix}_s as s, ${prefix}_b as b FROM slice WHERE id = '$p_slice_id'";
    $notify = GetTable2Array($SQL, 'aa_first', 'aa_fields');

    $SQL    = "SELECT uid FROM email_notify WHERE slice_id = '$p_slice_id' AND function = '$event'";
    $emails = GetTable2Array($SQL, '', 'uid');

    if ( $notify AND $emails) {
        $item    = AA_Items::getItem($item_id);

        if ($item) {
            $subject = $item->unalias($notify['s']);
            $body    = $item->unalias($notify['b']);

            $mail = new AA_Mail();
            $mail->setSubject($subject);
            $mail->setHtml($body, html2text($body));
            $mail->setCharset($slice->getCharset());
            $mail->send($emails);
        }
    }
}
?>
