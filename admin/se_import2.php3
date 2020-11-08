<?php
/** se_import2.php3 - assigns imports (feeding) to specified slice - writes it to database
 *   expected $slice_id for edit slice
 *            $I[] with ids of imported slices
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
require_once __DIR__."/../include/logs.php3";
require_once __DIR__."/../include/varset.php3";
require_once __DIR__."/../include/msgpage.php3";

if (!IfSlPerm(PS_FEEDING)) {
  MsgPageMenu(StateUrl(self_base())."index.php3", _m("You have not permissions to change feeding setting"), "admin");
  exit;
}

$err = [];          // error array (Init - just for initializing variable
$catVS       = new Cvarset();
$expVS       = new Cvarset();

$p_slice_id = q_pack_id($slice_id);
$slice      = AA_Slice::getModule($slice_id);

// update export_to_all switch
if ( ($slice->getProperty('export_to_all') ? 1:0) != ($to_all ? 1:0) ) {
    $SQL = "UPDATE slice SET export_to_all=". ($to_all ? 1 : 0) ." WHERE id='$p_slice_id'";
    $db->query($SQL);
    AA_Log::write(($to_all ? "FEED2ALL_1" : "FEED2ALL_0"), $slice_id);
}

// ------------------------ Export --------------------------
// feeding lookup
$feedto = DB_AA::select(['unid'=>true], "SELECT LOWER(HEX(`to_id`)) AS unid FROM `feedperms`", [['from_id',$slice_id,'l']]);

//$feedto["Init"] = false;  // create array
//$SQL= "SELECT to_id FROM feedperms WHERE from_id='$p_slice_id'";
//$db->query($SQL);
//while ($db->next_record()) {
//    $feedto[unpack_id($db->f('to_id'))] = true;
//}

do {
    if ( isset($E) AND is_array($E) ) {  // Export to any slice
        foreach ($E as $val) {
            if ( $feedto[$val] ) {
                $feedto[$val] = false;      // this feed is allready in database => don't change
                continue;
            }
            $expVS->clear();
            $expVS->add("from_id", "unpacked", $slice_id);
            $expVS->add("to_id", "unpacked", $val);
            if ( !$expVS->doINSERT('feedperms')) {
                $err["DB"] .= MsgErr("Can't add export to $val");
                break;    // not necessary - we have set the halt_on_error
            }
            AA_Log::write("FEED_ENBLE", "$slice_id:$val");
        }
  }
  foreach ($feedto as $to => $val) {
      if ( $val ) {
            $SQL = "DELETE FROM feedperms WHERE from_id = '$p_slice_id' AND to_id='". q_pack_id($to). "'";
            $db->query( $SQL );
            AA_Log::write("FEED_DSBLE", "$slice_id:$val");
        }
    }
} while (false);

// ------------------------ Import --------------------------
// feeding lookup

$feedfrom = DB_AA::select(['unid'=>true], "SELECT LOWER(HEX(`from_id`)) AS unid FROM `feeds`", [['to_id',$slice_id,'l']]);

do {
    if ( isset($I) AND is_array($I) ) {  // insert to categories
        foreach ($I as $val) {
            if ( $feedfrom[$val] ) {
                $feedfrom[$val] = false;      // this feed is allready in database => don't change
                continue;
            }
            $catVS->clear();
            $catVS->add("to_id", "unpacked", $slice_id);
            $catVS->add("from_id", "unpacked", $val);
            $catVS->add("all_categories", "number", 1);
            $catVS->add("to_approved", "number", 0);
            $catVS->add("to_category_id", "unpacked", "0");   // zero means import to the same category (if all_actegories==1)
            if ( !$catVS->doINSERT('feeds')) {
                $err["DB"] .= MsgErr("Can't add import from $val");
                break;  // not necessary - we have set the halt_on_error
            }
            AA_Log::write("FEED_ADD", "$slice_id:$val");
        }
    }

  foreach ($feedfrom as $from => $val) {  // delete removed feeds
      if ( $val ) {
            $SQL = "DELETE FROM feeds WHERE to_id = '$p_slice_id' AND from_id='". q_pack_id($from). "'";
            $db->query( $SQL );
            AA_Log::write("FEED_DEL", "$slice_id:$val");
        }
    }
} while (false);


if ( !count($err) ) {
    if ( isset($I) AND is_array($I) ) {   // slice imports some slices
        go_url( StateUrl(self_base() . "se_filters.php3") ."&Msg=" . rawurlencode(MsgOk(_m("Content Pooling update successful"))));
    } else {
        go_url( StateUrl(self_base() . "se_import.php3") ."&Msg=" . rawurlencode(MsgOk(_m("Content Pooling update successful"))));
    }
} else {
  MsgPage(StateUrl(self_base()."se_import.php3"), $err);
}

page_close();

