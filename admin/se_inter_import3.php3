<?php
/** se_inter_import3.php3 - Store feeds into tables
 *
 *   $slice_id
 *   $f_slices[]  - array of slice ids
 *   $aa          - string holding serialized array from aa_rss parser
 *   $remote_node_node
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

use AA\IO\DB\DB_AA;

require_once __DIR__."/../include/init_page.php3";

if (!IfSlPerm(PS_FEEDING)) {
    MsgPage(StateUrl(self_base()."index.php3"), _m("You have not permissions to change feeding setting"));
    exit;
}
require_once __DIR__."/../include/varset.php3";
require_once __DIR__."/../include/csn_util.php3";

$inputs = new AA\Util\InputVars();
$aa               = $inputs->ps('aa');
$f_slices         = $inputs->pa('f_slices');
$remote_node_name = $inputs->ps('remote_node_name');
$exact_copy       = $inputs->pb('exact_copy');

$aa_rss    = unserialize($inputs->ps('aa'));

$l_categs = GetGroupConstants(AA::$module_id);        // get all categories belong to local slice

$catVS = new Cvarset();
foreach ($f_slices as $f_slice) {
    $channel = $aa_rss['channels'][$f_slice];

    $remote_slice_id = $f_slice;
    if (DB_AA::test('external_feeds', [['slice_id', AA::$module_id, 'l'], ['remote_slice_id', $remote_slice_id, 'l']])) {
        // feed from $remote_slice_id to $slice_id is already contained in the table
        $msg = rawurlencode(MsgOk(_m("The import was already created")));
        continue;
    }

    $data = ['slice_id'          => AA::$module_id,
             'remote_slice_id'   => $remote_slice_id,
             'remote_slice_name' => $channel['title'],
             'user_id'           => $auth->auth['uname'],
             'node_name'         => $remote_node_name,
             'newest_item'       => unixstamp_to_iso8601(time()),
             'feed_mode'         => ($exact_copy ? 'exact' : '')
    ];

    if ( !($feed_id = AA::Metabase()->doInsert('external_feeds', $data)) ) {
        $err["DB"] .= MsgErr("Can't add external import");
    }

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

        if ( !$catVS->doTrueReplace('ef_categories')) {
            $err["DB"] .= MsgErr("Can't add external import");
        }
    }

    // fill up feedmap table
    foreach ( $channel['fields'] as $field_id => $v ) {

        $catVS->clear();
        $catVS->add("from_slice_id",  "unpacked", $remote_slice_id );
        $catVS->add("from_field_id",  "packed",   $field_id );
        $catVS->add("to_slice_id",    "unpacked", AA::$module_id);
        $catVS->add("to_field_id",    "packed",   $field_id );
        $catVS->add("flag",           "number",   FEEDMAP_FLAG_EXTMAP);
        $catVS->add("value",          "text",     '');
        $catVS->add("from_field_name","text",     $aa_rss['fields'][$field_id]['name']);

        if ( !$catVS->doINSERT('feedmap')) {
            $err["DB"] .= MsgErr("Can't add external import");
        }
    }
    $msg = rawurlencode(MsgOk(_m("The import was successfully created")));
}

go_url( StateUrl(self_base() . "se_inter_import.php3"). "&Msg=" . $msg );
page_close();
