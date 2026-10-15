<?php

/** se_inter_import3.php - Store feeds into tables
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
use AA\IO\FeedUtil;
use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";
if (!IfSlPerm(PS_FEEDING)) {
    MsgPage(_m("You do not have permissions to change feeding setting"));
    exit;
}
require_once __DIR__ . "/../include/varset.php";

try {
    $f_slices         = InputVars::arrayPOST('f_slices');
    $remote_node_name = InputVars::stringPOST('remote_node_name');
    $exact_copy       = InputVars::boolPOST('exact_copy');
    $aa_rss           = unserialize(InputVars::stringPOST('aa'), ['allowed_classes' => false]);

} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}


$l_categs = FeedUtil::GetGroupConstants(AA::$module_id);        // get all categories belong to local slice

$msg   = '';
foreach ($f_slices as $f_slice) {
    $channel = $aa_rss['channels'][$f_slice];

    $remote_slice_id = $f_slice;
    if (DB_AA::test('external_feeds', [['slice_id', AA::$module_id, 'l'], ['remote_slice_id', $remote_slice_id, 'l']])) {
        // feed from $remote_slice_id to $slice_id is already contained in the table
        $msg = rawurlencode(_m("The import was already created"));
        continue;
    }

    $data = ['slice_id'          => AA::$module_id,
        'remote_slice_id'   => $remote_slice_id,
        'remote_slice_name' => $channel['title'],
        'user_id'           => AuthGetUname(),
        'node_name'         => $remote_node_name,
        'newest_item'       => FeedUtil::unixstamp_to_iso8601(time()),
        'feed_mode'         => ($exact_copy ? 'exact' : ''),
    ];

    // the feed, its categories and its field mapping are stored together or not at all
    DB_AA::transaction(static function () use ($data, $channel, $aa_rss, $l_categs, $remote_slice_id, &$err) {
        if (!($feed_id = AA::Metabase()->doInsert('external_feeds', $data))) {
            $err["DB"] .= MsgErr("Can't add external import");
        }

        // insert categories
        foreach ($channel['categories'] as $cat_id => $v) {
            $cat = $aa_rss['categories'][$cat_id];

            $category = [
                'feed_id'            => $feed_id,
                'category'           => (string) $cat['value'],
                'category_name'      => (string) $cat['name'],
                'category_id'        => $cat_id,
                'target_category_id' => FeedUtil::MapDefaultCategory($l_categs, $cat['value'], $cat['catparent']) ?: '',       // default category
                'approved'           => 0,
            ];
            if (!AA::Metabase()->doReplace('ef_categories', $category)) {
                $err["DB"] .= MsgErr("Can't add external import");
            }
        }

        // fill up feedmap table
        $feedmap = [];
        foreach ($channel['fields'] as $field_id => $v) {
            $feedmap[] = [
                'from_slice_id'   => $remote_slice_id,
                'from_field_id'   => $field_id,
                'to_slice_id'     => AA::$module_id,
                'to_field_id'     => $field_id,
                'flag'            => FEEDMAP_FLAG_EXTMAP,
                'value'           => '',
                'from_field_name' => (string) $aa_rss['fields'][$field_id]['name'],
            ];
        }
        if (AA::Metabase()->doInsertMulti('feedmap', $feedmap) === false) {
            $err["DB"] .= MsgErr("Can't add external import");
        }
    });
    $msg = rawurlencode(_m("The import was successfully created"));
}

go_url(StateUrl(self_base() . "se_inter_import.php") . "&Msg=" . $msg);
Session::page_close();
