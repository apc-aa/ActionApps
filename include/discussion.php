<?php

/**
 *
 * PHP version 7.2+
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

// discussion.php - discussion utility functions

use AA\IO\DB\DB_AA;
use AA\Util\Langs;

require_once __DIR__ . "/mail.php";

// discussion images
const D_EXPAND_IMG = 0;   // not used
const D_HLINE_IMG = 1;    // not use
const D_VLINE_IMG = 2;
const D_CORNER_IMG = 3;
const D_SPACE_IMG = 4;
const D_T_IMG = 5;
const D_ITEM = 6;

/** GetImageSrc function
 * @param $img
 */
function GetImageSrc($img)
{
    $imgsrc = [
        D_EXPAND_IMG => GetAAImage('blank.gif', '', 9, 21),  // not used
        D_HLINE_IMG  => GetAAImage('blank.gif', '', 9, 21), // not used
        D_VLINE_IMG  => GetAAImage('i.gif', '', 9, 21),
        D_CORNER_IMG => GetAAImage('l.gif', '', 9, 21),
        D_SPACE_IMG  => GetAAImage('blank.gif', '', 12, 21),
        D_T_IMG      => GetAAImage('t.gif', '', 9, 21),
        D_ITEM       => "",
    ];
    return $imgsrc[$img];
}

/** QueryDiscussionZIDs function
 *  Get discussion content from database belong to item_id
 *
 * @param $item_id
 * @param $ids    = ""
 * @param $order  = 'timeorder'
 *
 * @return zids|null
 */
function QueryDiscussionZIDs($item_id, $ids = "", $order = 'timeorder'): ?zids
{
    if (!$item_id) {
        return null;
    }
    $d_ids = DB_AA::select('id', 'SELECT id FROM discussion', [['item_id', $item_id, 'l']], [($order === 'reverse timeorder') ? 'date-' : 'date']);
    if (!$ids) {
        return new zids($d_ids, 'p');
    }
    // filter for ids
    $ret = [];
    foreach ($d_ids as $p_d_id) {
        if ($ids["x" . unpack_id($p_d_id)]) {
            $ret[] = $p_d_id;
        }
    }
    return new zids($ret, 'p');
}

/** GetDiscussionContent function
 *
 * @param  zids|null  $zids
 * @param  bool       $state      = true
 * @param             $html_flag  = false
 * @param             $clean_url  = ''
 *
 * @return array
 */
function GetDiscussionContent(?zids $zids, bool $state = true, $html_flag = false, $clean_url = ''): array
{
    /** Fills Abstract data srtructure for Constants */
    if (!$zids) {
        return [];
    }
    $col              = [];
    $d_content        = [];
    $unsorted_content = [];
    foreach (DB_AA::select([], 'SELECT * FROM discussion', [$zids->whereIn('id')]) as $row) {
        $d_id = unpack_id($row['id']);
        $col["d_id............"][0]['value'] = $d_id;
        $col["d_parent........"][0]['value'] = $row['parent'] ? unpack_id($row['parent']) : "0";
        $col["d_item_id......."][0]['value'] = unpack_id($row['item_id']);
        $col["d_subject......."][0]['value'] = $row['subject'];
        $col["d_body.........."][0]['value'] = $row['body'];
        $col["d_author........"][0]['value'] = $row['author'];
        $col["d_e_mail........"][0]['value'] = $row['e_mail'];
        $col["d_url_address..."][0]['value'] = $row['url_address'];
        $col["d_url_descript.."][0]['value'] = $row['url_description'];
        $col["d_date.........."][0]['value'] = $row['date'];
        $col["d_remote_addr..."][0]['value'] = $row['remote_addr'];
        $col["d_state........."][0]['value'] = $row['state'];
        setDiscUrls($col, $clean_url, unpack_id($row['item_id']), $d_id);

        // set html flag
        if ($html_flag) {
            $col["d_body.........."][0]['flag'] = FLAG_HTML;
        }
        $col["d_checkbox......"][0]['flag'] = FLAG_HTML;
        $col["d_treeimages...."][0]['flag'] = FLAG_HTML;

        $col["hide"] = ($row['state'] == '1' && $state);     //mark hidden comment.
        $unsorted_content[$d_id] = $col;
    }

    // MySQL returns the items in random order - we have to sort it as is in zids
    $longids = $zids->longids();
    if (is_array($longids)) {
        foreach ($longids as $d_id) {
            $d_content[$d_id] = $unsorted_content[$d_id];
        }
    }
    return $d_content;
}
/** setDiscUrls function
 * @param $col
 * @param $clean_url
 * @param $item_id
 * @param $d_id = null
 */
function setDiscUrls(&$col, $clean_url, $item_id, $d_id = null): void
{
    // $tmp_disc_url = get_url($clean_url,"nocache=invalidate&sh_itm=$item_id");
    $tmp_disc_url = $GLOBALS['apc_state']['router'] ? $clean_url : get_url($clean_url, ["sh_itm" => $item_id]);     // removed nocache=invalidate - testing - Honza 2011-01-19
    $col["d_disc_url......"][0]['value'] = $tmp_disc_url . "#disc";
    // in urls we do not need to replace & by &amp;
    $col["d_disc_url......"][0]['flag']  = FLAG_HTML;
    if ($d_id) {
        $col["d_url_fulltext.."][0]['value'] = get_url($tmp_disc_url, ["sel_ids" => 1, "ids[x$d_id]" => 1]) . '#disc';
        $col["d_url_reply....."][0]['value'] = get_url($tmp_disc_url, ["add_disc" => 1, "parent_id" => $d_id]) . "#disc";
    }
}

/** SetCheckboxContent function
 * Set the right content for a checkbox
 * @param $content
 * @param $d_id
 * @param $cnt
 */
function SetCheckboxContent(&$content, $d_id, $cnt): void
{
    $content[$d_id]["d_checkbox......"][0]['value'] = "<input type=\"checkbox\" name=\"c_" . $cnt . "\" ><input type=\"hidden\" name=\"h_" . $cnt . "\" value=\"x" . $d_id . "\"> ";
}

/** SetImagesContent function
 * Set the right content for images
 * @param $content
 * @param $d_id
 * @param $images
 * @param $showimages
 * @param $imgtags
 */
function SetImagesContent(&$content, $d_id, $images, $showimages, $imgtags): void
{
    if ($showimages) {
        foreach ($images as $img) {
            $imgs .= $imgtags[$img];
        }
    } else {
        $imgs = GetAAImage('blank.gif', '', count($images) * 15, 21);
    }
    $content[$d_id]["d_treeimages...."][0]['value'] = $imgs;
}

/** GetdiscussionFormat function
 * @param $view_info
 */
function GetDiscussionFormat($view_info): array
{
    $view_type_info = getViewTypesInfo('discus');

    $format['d_name']       = $view_info['name'];
    $format['d_top']        = $view_info['before'];
    $format['d_bottom']     = $view_info['after'];
    $format['d_fulltext']   = $view_info['even'];
    $format['d_compact']    = $view_info['odd'];
    $format['d_showimages'] = $view_info['even_odd_differ'];
    $format['d_order']      = $view_type_info['modification'][$view_info['modification']];
    $format['slice_id']     = $view_info['slice_id'];
    $format['d_form']       = $view_info['remove_string'];
    $format['d_spacer']     = ($view_info['aditional'] ?: '<img src="' . AA_INSTAL_PATH . 'images/blank.gif" width="20" height="1">');
    $format['d_sel_butt']   = ($view_info['aditional2'] ?: '<input type="button" name="sel_ids" value="' . _m("Show selected") . '" onClick="showSelectedComments()" class="discbuttons">');
    $format['d_all_butt']   = ($view_info['aditional3'] ?: '<input type="button" name="all_ids" value="' . _m("Show all") . '" onClick="showAllComments()" class="discbuttons">');
    $format['d_add_butt']   = ($view_info['aditional4'] ?: '<input type="button" name="add_disc" value="' . _m("Add new") . '" onClick="showAddComments()" class="discbuttons">');
    $format['images'] = [
        D_VLINE_IMG  => $view_info['img1'],
        D_CORNER_IMG => $view_info['img2'],
        D_T_IMG      => $view_info['img3'],
        D_SPACE_IMG  => $view_info['img4'],
    ];
    return $format;
}

/** Create discussion tree from d_content
 *
 * @param  array  $d_content
 *
 * @return array
 */
function GetDiscussionTree(array $d_content): array
{
    $d_tree = [];
    if (!$d_content) {
        return [];
    }
    foreach ($d_content as $d_id => $val) {
        if ($val["hide"]) {                  // if hidden => skip
            continue;
        }
        $id = $d_id;                                // searching approved parent disc. comment for $d_id
        do {
            $id = $d_content[$id]["d_parent........"][0]['value'];
            if ($id == "0") {
                break;
            }
        } while ($d_content[$id]["hide"] == true);

        $d_tree[$id][$d_id] = true;
    }
    return $d_tree;
}


/** GetDiscussionThread function
 * Create array of images
 * @param $tree
 * @param $d_id
 * @param $depth
 * @param $outcome
 * @param $images = ""
 */
function GetDiscussionThread(&$tree, $d_id, $depth, &$outcome, $images = ""): void
{
    if ($d_id != "0") {
        for ($i = 1; $i < $depth - 1; ++$i) {
            $outcome[$d_id][] = $images[$i];
        }
        if ($depth > 1) {
            $outcome[$d_id][] = $images[$depth];
        }
        $outcome[$d_id][] = D_ITEM;
    }
    if (!($nodes = $tree[$d_id])) {
        return;
    }

    foreach ($nodes as $dest_id => $foo) {
        if (current($nodes)) {
            $images[$depth]   = D_VLINE_IMG;
            $images[$depth + 1] = D_T_IMG;
        } else {
            $images[$depth]   = D_SPACE_IMG;
            $images[$depth + 1] = D_CORNER_IMG;
        }
        GetDiscussionThread($tree, $dest_id, $depth + 1, $outcome, $images);
    }
}

/** DeleteTree function
 * delete subtree of d_id - not used yet
 * @param $tree
 * @param $d_id
 */
function DeleteTree(&$tree, $d_id): void
{
    DB_AA::delete('discussion', [['id', $d_id, 'l']]);
    if (!($nodes = $tree[$d_id])) {
        return;
    }
    foreach ($nodes as $dest_id => $foo) {
        DeleteTree($tree, $dest_id);
    }
}

/** DeleteNode function
 * Delete one comment
 * @param $tree
 * @param $d_content
 * @param $d_id
 */
function DeleteNode($tree, $d_content, $d_id): void
{
    DB_AA::delete('discussion', [['id', $d_id, 'l']]);
    if (!$tree[$d_id]) {
        return;
    }
    $parent = $d_content[$d_id]["d_parent........"][0]['value'];

    foreach ($tree[$d_id] as $child => $foo) {
        DB_AA::sql("UPDATE discussion SET parent=" . ($parent == "0" ? "''" : xpack_id($parent)) . " WHERE id=" . xpack_id($child));
    }
}



/** DeleteNode function
 * Delete one comment
 * @param $item_id
 * @param $slice_id
 */
function DeleteDiscForItem($item_id, $slice_id, $state = 99999): void
{
    // 99999 - just unused number (state is 0-shown or 1-hidden)
    $where = ($state == 99999) ? '' : " AND state = '" . ($state ? 1 : 0) . "'";

    // check perms -  if the item is in the right slice
    $item = DB_AA::select1([], 'SELECT slice_id FROM item', [['id', $item_id, 'l']]);
    $db = getDB();
    if (unpack_id($item['slice_id'] ?? null) == $slice_id) {
        $db->query("DELETE FROM discussion WHERE item_id=" . xpack_id($item_id) . " $where");
    }
    freeDB($db);
}

/** updateDiscussionCount function
 * Update a count of discussion comments in the view table
 * (called after adding|deleting|hiding|approving comment)
 * @param $item_id
 */
function updateDiscussionCount($item_id): void
{
    $all       = $hide = 0;
    $p_item_id = xpack_id($item_id);
    foreach (DB_AA::select('state', 'SELECT * FROM discussion', [['item_id', $item_id, 'l']]) as $d_state) {
        $all++;
        if ($d_state == '1') {   // hidden comment
            $hide++;
        }
    }

    $db  = getDB();
    $SQL = "UPDATE item SET disc_count='$all', disc_app='" . ($all - $hide) . "' WHERE id=$p_item_id";
    $db->query($SQL);
    freeDB($db);
}

// -----------------------------------------------------------------------------------------
/** send2mailList function
 *  Sends new discussion items to one mail address
 *   if a field with name DiscussionMailList
 *   exists and is filled with these parameters separated by ":" (use "#:" instead of verbatim ":")
 *
 * view_id:mail_address:param3:param4:...
 * @param $d_item_id
 * @param $new_id
 */
function send2mailList($d_item_id, $new_id): void
{
    $list_field = DB_AA::select1([], "SELECT content.text FROM
                 content INNER JOIN item ON item.id = content.item_id INNER JOIN
                 field ON content.field_id = field.id
                 AND field.slice_id = item.slice_id", [['item.id', $d_item_id, 'l'], ['field.name', 'DiscussionMailList']]);
    if ($list_field) {
        $item_params = split_escaped(":", $list_field["text"], "#:");
        [$vid, $maillist] = $item_params;
        // Don't do this if there is a field, but no vid in it
        if ($vid) {
            // get discussion item content

            $zids      = new zids($new_id, 'l');
            $d_content = GetDiscussionContent($zids);
            $columns   = reset($d_content);  // get first element

            // get aliases
            $aliases = new AA_Aliases(new AA_Aliasgenerator_Discussion());
            for ($i = 2, $ino = count($item_params); $i < $ino; ++$i) {
                FillFakeAlias($columns, $aliases, "_#ITEMPAR" . ($i + 1), $item_params[$i]);
            }

            $CurItem = new AA_Item($columns, $aliases);

            // newer version based on email templates
            if ($vid[0] === 't') {   // email template
                $mail_id = substr($vid, 1);
                $mails   = explode(',', str_replace(' ', '', $maillist));
                AA_Mail::sendTemplate($mail_id, $mails, $CurItem);
                return;
            }
            if ($view_info = DB_AA::select1([], 'SELECT * FROM view', [['id', $vid, 'i']])) {
                $html = $view_info['flag'] & DISCUS_HTML_FORMAT;

                // older and deprecated version with discussion view
                $mail_parts = [
                    "from"      => "aditional",
                    "reply_to"  => "aditional2",
                    "errors_to" => "aditional3",
                    "sender"    => "aditional4",
                    "subject"   => "aditional5",
                    "body"      => "even",
                ];

                $maildef = [];
                foreach ($mail_parts as $part => $field) {
                    $s = $view_info[$field];
                    for ($i = 2; $i < 9; ++$i) {
                        $s = str_replace("_#ITEMPAR" . ($i + 1), $item_params[$i], $s);
                    }
                    $CurItem->setformat($s);
                    $maildef[$part] = $CurItem->get_item();
                }

                $mail = new AA_Mail();
                $mail->setSubject($maildef["subject"]);
                $mail->setHtml($maildef["body"], html2text($maildef["body"]));
                if ($mail["from"]) {
                    $mail->setHeader("From", $maildef["from"]);
                }
                if ($mail["reply_to"]) {
                    $mail->setHeader("Reply-To", $maildef["reply_to"]);
                }
                if ($mail["errors_to"]) {
                    $mail->setHeader("Errors-To", $maildef["errors_to"]);
                }
                if ($mail["sender"]) {
                    $mail->setHeader("Sender", $maildef["sender"]);
                }

                $slice = DB_AA::select1([], 'SELECT lang_file FROM slice INNER JOIN item ON item.slice_id = slice.id', [['item.id', $d_item_id, 'l']]);
                $mail->setCharset(Langs::getCharset(substr($slice["lang_file"] ?? null, 0, 2)));
                $mail->send([$maillist]);
            } //view found
        } // vid present
    } // DiscussionMailList Field present
}
