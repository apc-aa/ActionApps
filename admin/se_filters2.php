<?php

/** se_filters2.php - assigns feeding filters to specified slice - writes it to database
 *    expected $slice_id for edit slice
 *          $import_id for id of imported slice
 *          $all (set to 1 if import from all categories is selected)
 *          $C contains category into which all categories are imported (only when $all is 1)
 *             if $C==0 then import to the same category as source item category
 *          $F[] array of imported categories, plus string "-0" or "-1" in order to approved checked
 *          $T[] array of categories into which we should import (corresponds to F[] array)
 *             if $T[]==0 then import to the same category as source item category
 *          $feed_id
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
require_once __DIR__ . "/../include/varset.php";

try {
    // array ( '0-0' => 1, '0-1' => 5, )
    $C = InputVars::stringGET('C');
    // array ( )
    $F = InputVars::arrayGET('F');
    // array ( )
    $T = InputVars::arrayGET('T');
    // array ( 1 => 6, )
    $all = InputVars::boolGET('all');
    // array ( '' => 8, )
    $feed_id = InputVars::intGET('feed_id'); // empty
    // array ( 'dcf6f197e02…' => 2, '4429a8e44bb…' => 5, '6c81ec28dd4…' => 1, )
    $import_id = InputVars::idGET('import_id', true);
    $cancel = InputVars::boolPOST('cancel');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

if (!IfSlPerm(PS_FEEDING)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You do not have permissions to change feeding setting"), "admin");
    exit;
}
/** ParseIdA function
 * @param $param
 * @param $app (by link)
 * @return string parsed
 */
function ParseIdA($param, &$app): string
{
    if (preg_match("/([0-9a-f]{1,32}|0)-([01])/i", $param, $parse)) {  // slice_id or 0
        $app = $parse[2];
        return $parse[1];
    }
    return '';
}

$err = [];       // error array (Init - just for initializing variable)

if ($feed_id) { // cross server feeding
    // setting filters from external slice

    if ($ext_categs = FeedUtil::GetExternalCategories($feed_id)) {
        // clear setting of feeding
        // Note all external categories MUST be stored in the ef_categories
        // table even if no feed (we need to have listing of external categs)
        if (is_array($ext_categs)) {
            foreach (array_keys($ext_categs) as $id) {
                $ext_categs[$id]['target_category_id'] = '';   // do not feed
                $ext_categs[$id]['approved']           = 0;
            }
        }

        // now set the external category feeding as specified in the array
        if ($all) {                           // all categories
            $to_id = ParseIdA($C, $app);
            // $to_id could be 'AA_The_Same_Cate' keyword, which means "The Same category"
            // ('AA_Other_Categor' and 'AA_The_Same_Cate' are keywords)
            $ext_categs[FeedUtil::UNPACKED_AA_OTHER_CATEGOR]['target_category_id'] = $to_id;
            $ext_categs[FeedUtil::UNPACKED_AA_OTHER_CATEGOR]['approved']           = $app;
            // add also name and value (not crucial for the functionality, but nice)
            $ext_categs[FeedUtil::UNPACKED_AA_OTHER_CATEGOR]['value'] = 'AA_Other_Categor';
            $ext_categs[FeedUtil::UNPACKED_AA_OTHER_CATEGOR]['name']  = _m('Other categories');
        } else {                              // individual categories
            // set according the posted values
            foreach ($_GET['F'] as $index => $id) {
                $from_cat = ParseIdA($id, $app);
                $ext_categs[$from_cat]['target_category_id'] = $_GET['T'][$index];
                $ext_categs[$from_cat]['approved']           = $app;
            }
        }

        // a 0 / '0' category id is stored as an empty id
        $rows = [];
        foreach ($ext_categs as $id => $v) {
            $rows[] = [
                'category'           => (string) $v['value'],
                'category_name'      => (string) $v['name'],
                'category_id'        => $id ?: '',
                'feed_id'            => $feed_id,
                'target_category_id' => $v['target_category_id'] ?: '',
                'approved'           => (int) $v['approved'],
            ];
        }
        // delete current filters and then insert new
        $stored = DB_AA::transaction(static function () use ($feed_id, $rows) {
            DB_AA::delete('ef_categories', [['feed_id', $feed_id]]);
            return AA::Metabase()->doInsertMulti('ef_categories', $rows);
        });
        if ($stored === false) {    // not necessary - we have set the halt_on_error
            $err["DB"] .= MsgErr("Can't add import from feed $feed_id");
        }
    }
} else { // inner feeding

    // First we DELETE current filters and then INSERT new.
    // We can't use UPDATE because the count of old and new rows can be different.
    // We could UPDATE existing rows and INSERT new, but DELETE/INSERT is simpler.
    // 0 / '0' (the same category) is stored as an empty id.

    $feeds = [];
    if ($all) {                                         // all_categories
        $id = ParseIdA($C, $app);
        if ($id == FeedUtil::UNPACKED_AA_THE_SAME_CATE) {           // the same category
            $id = 0;
        }
        $feeds[] = [
            'to_id'          => AA::$module_id,
            'from_id'        => $import_id,
            'all_categories' => 1,
            'to_approved'    => (int) $app,
            'to_category_id' => $id ?: '',
        ];
    } elseif (isset($_GET['F']) && is_array($_GET['F'])) {            // insert to categories
        foreach ($_GET['F'] as $index => $val) {
            $from_cat = ParseIdA($val, $app);
            $to_cat = $_GET['T'][$index];
            if ($to_cat == FeedUtil::UNPACKED_AA_THE_SAME_CATE || $to_cat == "0") { // "0" is from older versions - it could be never "0"
                $to_cat = $from_cat;
            }
            $feeds[] = [
                'to_id'          => AA::$module_id,
                'from_id'        => $import_id,
                'all_categories' => 0,
                'to_approved'    => (int) $app,
                'category_id'    => $from_cat ?: '',
                'to_category_id' => $to_cat ?: '',
            ];
        }
    }
    $stored = DB_AA::transaction(static function () use ($import_id, $feeds) {
        DB_AA::delete('feeds', [['to_id', AA::$module_id, 'l'], ['from_id', $import_id, 'l']]);
        return AA::Metabase()->doInsertMulti('feeds', $feeds);
    });
    if ($stored === false) {    // not necessary - we have set the halt_on_error
        $err["DB"] .= MsgErr("Can't add import from $import_id");
    }
}

if (!count($err)) {
    go_url(StateUrl(self_base() . "se_filters.php") . "&import_id=$import_id&Msg=" . rawurlencode(_m("Content Pooling update successful")));
} else {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base() . "se_import.php"), $err, "admin");
}

Session::page_close();
