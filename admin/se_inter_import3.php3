<?php
/** se_inter_import3.php3 - Store feeds into tables
 *
 *   $slice_id
 *   $f_slices[]  - array of slice ids
 *   $aa          - string holding serialized array from aa_rss parser
 *   $remote_node_node
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once "../include/init_page.php3";

if (!IfSlPerm(PS_FEEDING)) {
    MsgPage($sess->url(self_base()."index.php3"), _m("You have not permissions to change feeding setting"));
    exit;
}
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."csn_util.php3";

$aa_rss = unserialize(stripslashes($aa));

$l_categs = GetGroupConstants($slice_id);        // get all categories belong to local slice

$catVS = new Cvarset();
foreach ($f_slices as $f_slice) {
    $channel = $aa_rss['channels'][$f_slice];

    $remote_slice_id = $f_slice;
    $db->query("SELECT feed_id FROM external_feeds
                 WHERE slice_id='".q_pack_id($slice_id)."'
                   AND remote_slice_id='".q_pack_id($remote_slice_id)."'");
    if ($db->next_record()) {       // feed from $remote_slice_id to $slice_id is already contained in the table
        $msg = rawurlencode(MsgOK(_m("The import was already created")));
        continue;
    }

    $catVS->clear();
    $catVS->add("slice_id",         "unpacked", $slice_id);
    $catVS->add("remote_slice_id",  "unpacked", $remote_slice_id);
    $catVS->add("remote_slice_name","quoted",   $channel['title']);
    $catVS->add("user_id",          "text",     $auth->auth['uname']);
    $catVS->add("node_name",        "quoted",   $remote_node_name);
    $catVS->add("newest_item",      "quoted",   unixstamp_to_iso8601(time()));
    $catVS->add("feed_mode",        "quoted",   ($exact_copy ? 'exact' : ''));

    $SQL = "INSERT INTO external_feeds" . $catVS->makeINSERT();
    if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
        $err["DB"] .= MsgErr("Can't add external import");
    }

    $feed_id = $db->last_insert_id();

    // insert categories
    foreach ( $channel['categories'] as $cat_id => $v ) {
        $cat = $aa_rss['categories'][$cat_id];

        $catVS->clear();
        $catVS->add("feed_id",           "number",   $feed_id);
        $catVS->add("category",          "text",     $cat['value']);
        $catVS->add("category_name",     "text",     $cat['name']);
        $catVS->add("category_id",       "unpacked", $cat_id);
        $catVS->add("target_category_id","unpacked", MapDefaultCategory($l_categs,$cat['value'],$cat['catparent']));       // default category
        $catVS->add("approved",          "number",   0);

        $SQL = "INSERT INTO ef_categories" . $catVS->makeINSERT();
        if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
            $err["DB"] .= MsgErr("Can't add external import");
        }
    }

    // fill up feedmap table
    list( $slice_fields,) = GetSliceFields( $slice_id );        // get slice fields of the "to slice"
    foreach ( $channel['fields'] as $field_id => $v ) {

        //    if (!$slice_fields[$r_field_id])
        //      continue;

        $catVS->clear();
        $catVS->add("from_slice_id",  "unpacked", $remote_slice_id );
        $catVS->add("from_field_id",  "packed",   $field_id );
        $catVS->add("to_slice_id",    "unpacked", $slice_id);
        $catVS->add("to_field_id",    "packed",   $field_id );
        $catVS->add("flag",           "number",   FEEDMAP_FLAG_EXTMAP);
        $catVS->add("from_field_name","text",     $aa_rss['fields'][$field_id]['name']);

        $SQL = "INSERT INTO feedmap" . $catVS->makeINSERT();
        if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
            $err["DB"] .= MsgErr("Can't add external import");
        }
    }
    $msg = rawurlencode(MsgOK(_m("The import was successfully created")));
}

go_url( $sess->url(self_base() . "se_inter_import.php3"). "&Msg=" . $msg );
page_close()
?>
