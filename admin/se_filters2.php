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

// const  AA_REGISTER_GLOBALS = true;
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
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}

if (!IfSlPerm(PS_FEEDING)) {
  AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php3", _m("You have not permissions to change feeding setting"), "admin");
  exit;
}
/** ParseIdA function
 * @param $param
 * @param $app (by link)
 * @return string parsed
 */
function ParseIdA($param,&$app): string {
   if (preg_match("/([0-9a-f]{1,32}|0)-([01])/i", $param, $parse)) {  // slice_id or 0
      $app = $parse[2];
      return $parse[1];
   }
   return '';
}

$err = [];       // error array (Init - just for initializing variable)
$catVS       = new Cvarset();

if ($feed_id) { // cross server feeding
    // setting filters from external slice

    if ($ext_categs = FeedUtil::GetExternalCategories($feed_id)) {
        // delete current filters and then insert new
        DB_AA::delete('ef_categories', [['feed_id', $feed_id]]);

        // clear setting of feeding
        // Note all external categories MUST be stored in the ef_categories
        // table even if no feed (we need to have listing of external categs)
        if ( is_array( $ext_categs ) ) {
            foreach ( $ext_categs as $id => $v ) {
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
            foreach ($_GET['F'] as $index => $id ) {
                $from_cat = ParseIdA($id, $app);
                $ext_categs[$from_cat]['target_category_id'] = $_GET['T'][$index];
                $ext_categs[$from_cat]['approved']           = $app;
            }
        }

        foreach ( $ext_categs as $id => $v) {
            $catVS->clear();
            $catVS->add("category",           "text",     $v['value']);
            $catVS->add("category_name",      "text",     $v['name']);
            $catVS->add("category_id",        "unpacked", $id);
            $catVS->add("feed_id",            "number",   $feed_id);
            $catVS->add("target_category_id", "unpacked", $v['target_category_id']);
            $catVS->add("approved",           "number",   $v['approved']);   // zero = the same category
            if ( !$catVS->doINSERT('ef_categories')) {    // not necessary - we have set the halt_on_error
                $err["DB"] .= MsgErr("Can't add import from". $v['name']);
            }
        }
    }
} else { // inner feeding

    // First we DELETE current filters and then INSERT new.
    // We can't use UPDATE because the count of old and new rows can be different.
    // We could UPDATE existing rows and INSERT new, but DELETE/INSERT is simpler.
    // A transaction would be nice.

    DB_AA::delete('feeds', [['to_id', AA::$module_id, 'l'], ['from_id', $import_id, 'l']]);

    if ($all) {                                         // all_categories
        $id = ParseIdA($C, $app);
        if ( $id == FeedUtil::UNPACKED_AA_THE_SAME_CATE) {           // the same category
            $id = 0;
        }
        $catVS->clear();
        $catVS->add("to_id",          "unpacked", AA::$module_id);
        $catVS->add("from_id",        "unpacked", $import_id);
        $catVS->add("all_categories", "number",   1);
        $catVS->add("to_approved",    "number",   $app);
        $catVS->add("to_category_id", "unpacked", $id);   // zero = the same category
        if ( !$catVS->doINSERT('feeds')) {
            $err["DB"] .= MsgErr("Can't add import from $import_id");
        }
    } elseif (isset($_GET['F']) AND is_array($_GET['F'])) {            // insert to categories
        foreach ($_GET['F'] as $index => $val) {
            $from_cat = ParseIdA($val, $app);
            $to_cat = $_GET['T'][$index];
            if ( ($to_cat == FeedUtil::UNPACKED_AA_THE_SAME_CATE) OR ($to_cat == "0") ) { // "0" is from older versions - it could be never "0"
                $to_cat = $from_cat;
            }
            $catVS->clear();
            $catVS->add("to_id",          "unpacked", AA::$module_id);
            $catVS->add("from_id",        "unpacked", $import_id);
            $catVS->add("all_categories", "number",   0);
            $catVS->add("to_approved",    "number",   $app);
            $catVS->add("category_id",    "unpacked", $from_cat);
            $catVS->add("to_category_id", "unpacked", $to_cat);
            if ( !$catVS->doINSERT('feeds')) {
                $err["DB"] .= MsgErr("Can't add import from $val");
                break;
            }
        }
    }
}

if ( !count($err) ) {
    go_url( StateUrl(self_base() . "se_filters.php") ."&import_id=$import_id&Msg=" . rawurlencode(MsgOk(_m("Content Pooling update successful"))));
} else {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base() . "se_import.php"), $err, "admin");
}

page_close();

