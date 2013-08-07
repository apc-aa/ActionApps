<?php
/**  Slice Import - XML parsing function
 *
 *      Note: This parser does not check correctness of the data. It assumes, that xml document
 *            was exported by slice export and has the form of
 *
 *   <sliceexport version="1.0">
 *   ...
 *   <slice id="new id" name="new name">
 *   base 64 data
 *   </slice>
 *   </sliceexport>
 *
 *   new version 1.1:
 *
 *   <sliceexport version="1.1">
 *   <slice id="new id" name="new name">
 *   <slicedata gzip="1">
 *   if gzip parameter == 1 => gzipped base 64 slice struct
 *                     == 0 => base 64 slice struct
 *   </slicedata>
 *   <data item_id="item id" gzip="1">
 *   base 64 data from item_id (w/wo gzip)
 *   </data>
 *   </slice>
 *   </sliceexport>
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
 * @author    Mitra (based on earlier versions by Jakub Adámek, Pavel Jisl)
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once AA_INC_PATH . "xml_serializer.php3";

//$dry_run = 1;

// This function should go in site-specific file, just here to make easier to debug
// and to have an example
/** bayfm_preimport function
 * @param $s
 * @return huhl($s)
 */
function bayfm_preimport($s) {
    // Create a vid_translate table, and change ids while there
    foreach ( $s['SLICE'] as $k => $v ) {
        $sl = &$s['SLICE'][$k];
        if ($slvs = &$sl['VIEWS']) {
            print("<br>Processing Views, translating shortids");
//            foreach ($slvs->a as $slv) { // $slv is a viewobj
            $a = &$slvs->a; reset($a);
            while (list($k,) = each($a)) { // $slv is a viewobj
                $slv = &$slvs->a[$k];
                $slvf = &$slv->fields; // Array of fields
                $id = $slvf["id"];
                $newid = $id+1000;
                $vid_translate[$id] = $newid;
                $slvf["id"] = $newid;
                $slv->id = $newid;
                $slvs->a[$newid] = $slv;
                unset($slvs->a[$id]);
            }
        }
    }
    // Edit Items, translate vids in field "unspecified....1"
    reset($s['SLICE']);
    while (list($k,$sl) = each(&$s['SLICE'])) {
        if (is_array($sl['DATA'])) {
            print("<br>Processing Data");
            foreach ($sl['DATA'] as $sld) {
                foreach ($sld['item'] as $content4id) { // should only be one
                    // unspecified....1 contains a vid
                    print("Setting unspecified....1 from ".$content4id["unspecified....1"][0]['value']." to ".$vid_translate[$content4id["unspecified....1"][0]['value']]);
                    $content4id["unspecified....1"][0]['value'] = $vid_translate[$content4id["unspecified....1"][0]['value']];
                }
            }
        }
    }
huhl($s);
exit;
}
/** si_err function
 * @param $str
 */
function si_err($str) {
    global $sess;
    MsgPage($sess->url(self_base())."index.php3", $str, "standalone");
    exit;
}

/** sliceimp_xml_parse function
 * Create and parse data
 * @param $xml_data
 * @param $dry_run = false
 * @param $force_this_slice = false
 */
function sliceimp_xml_parse($xml_data, $dry_run=false, $force_this_slice=false) {
    global $debugimport;
    set_time_limit(600); // This can take a while
    $xu = new xml_unserializer();
    if ($debugimport) {
        huhl("Importing data=",myspecialchars($xml_data));
    }

    /** Create array strusture from XML data */
    $i = $xu->parse($xml_data);  // PHP data structure
    if ($debugimport) {
        huhl("Parsed data=",$i);
    }

    $s = $i["SLICEEXPORT"][0];
    if (! $s) {
        si_err(_m("\nERROR: File doesn't contain SLICEEXPORT"));
    }
    if ($s['PROCESS']) {

        // This is some kind of data preprocessing from Mitra
        // Looks like slice specific - ask Mitra for more details
        $v = $s['PROCESS'] . "_preimport";
        print("\nPre-processing with $v");
        $v($s);
    }

    if ($s['VERSION'] == "1.0") {   // older format of XML file
        $sl            = $s['SLICE'][0];
        $slice         = unserialize (base64_decode($sl['DATA']));
        if (!is_array($slice)) {
            si_err(_m("ERROR: Text is not OK. Check whether you copied it well from the Export.") . " Version=" . $s['VERSION']);
        }
        $slice["id"]   = $sl['ID'];
        $slice["name"] = $sl['NAME'];
        if ($dry_run) {
            huhl("Would import slice=",$slice);
            $slice_id_new = '11111111111111112222222222222222';
        } else {
            $slice_id_new = import_slice($slice);
        }
    }
    elseif ($s['VERSION'] == "1.1") {
        foreach ($s['SLICE'] as $sl) {
            $sld = $sl['SLICEDATA'][0];

            /** First we have to import slice data */
            if ($sld) { // Can skip structure if just data
                // slice structure defined - create slice array
                if ($sld['CHARDATA']) { // Its an encoded serialized data
                    $chardata = base64_decode($sld['CHARDATA']);
                    $chardata = $sld['GZIP'] ? gzuncompress($chardata) : $chardata;
                    $slice    = unserialize ($chardata);
                } elseif ($sld['slice']) {
                    $slice    = $sld['slice'];
                }
                if (!is_array($slice)) {
                    si_err(_m("ERROR: Text is not OK. Check whether you copied it well from the Export.") . " Version=" . $s['VERSION']);
                }

                $slice["id"]   = $sl['ID'];
                $slice["name"] = $sl['NAME'];
                if ($dry_run) {
                    huhl("Would import slice=",$slice);
                    $slice_id_new = '11111111111111112222222222222222';
                } else {
                    $slice_id_new = import_slice($slice);
                }
            }
            /** Now we will import views. View ids are changed (it is
              * autoincremented database velue, so it is possible that there
              * will be the need to update some aliases. View is overwritten
              * only if you select OVERWRITE for slice
              */
            if (is_array($sl['VIEWS'])) {
                // in fact there is only [0] element of the array, where more
                // views is defined
                foreach ( $sl['VIEWS'] as $viewdata ) {
                    $viewdata = base64_decode($viewdata);
                    $viewdata = $sld['GZIP'] ? gzuncompress($viewdata) : $viewdata;
                    $view = unserialize ($viewdata);
                    import_views($view, $slice_id_new);
                }
            }
            /** Now import items */
            if (is_array($sl['DATA'])) {
                foreach ($sl['DATA'] as $sld) {
                    if (isset($sld['CHARDATA'])) {
                        $chardata   = base64_decode($sld['CHARDATA']);
                        $chardata   = $sld['GZIP'] ? gzuncompress($chardata) : $chardata;
                        $content4id = unserialize ($chardata);
                    } else { // Its in XML
                        $content4id = $sld['item'];
                    }
                    if (!is_array($content4id)) {
                        si_err(_m("ERROR: Text is not OK. Check whether you copied it well from the Export.") . " Version=" . $s['VERSION']);
                    }
                    if ($dry_run) {
                        huhl("Would import data to ",$sld['ITEM_ID'],$content4id);
                    } else {
                        import_slice_data( $force_this_slice ? $GLOBALS['slice_id'] : $sl['ID'],
                                           $sld['ITEM_ID'], $content4id, true, true);
                    }
                }
            } // loop over each item
        } // loop over each slice
    } // Version 1.1
    else si_err(_m("ERROR: Unsupported version for import").$s['VERSION']);
}
/** import_views function
 * @param $slvs (by link)
 * @param $slice_id_new
 */
function import_views(&$slvs, $slice_id_new) {
    global $dry_run, $view_resolve_conflicts, $new_slice_ids,
           $view_IDconflict,$IDconflict, $view_conflicts_ID;

    if ( $dry_run ) {
        huhl($slvs);
    }

    $db = getDB();

    // Get all views in current AA
    $av = GetViewsWhere();

    /** Several cases here
      *  If there is a conflict, then
      *  - Overwrite           => just go ahead, use changed id if available
      *  - Insert with new ids => pick a new id for view
      *  - Insert              => skip conflicts
      *
      *
      * note if no conflict then will import, which might be bad if
      * slice_id would have been changed.
      * Note that difference between Overwrite & InsertNew, is in whether
      * other views for this slice should be deleted first, they are not
      * currently deleted, although that would be an OK change to make
      */
    $varset = new Cvarset();
    foreach ($slvs as $slv) {   // $slv is a viewobj

        $varset->clear();
        $slvf = $slv->getViewInfo();
        $id   = $slvf["id"];

        /** Change the slice_id for the view to the current one
         *  (could be the same, but could be also different if we are
         *  changing slice_id (due to conflict, ...)
         */
        $slvf["slice_id"] = pack_id($slice_id_new);

        foreach ($slvf as $k => $v) {
            // there was a bug in viewobj - we store 'slice.deleted' field to
            // view - we must skip it, now.
            if ( $k != 'deleted' ) {
                $varset->add($k,"text",$v);
            }
        }

        /** Now we should resolve conflict in view ids - we overwrite view
         *  only if user selected 'Overwrite'. In other cases we change
         *  the view id to the new one (it is autoincremented). This
         *  behavior could lead to necessary code change for slice admins,
         *  but it is far best solution for now.
         */
        if ( isset($av[$id]) AND ($GLOBALS["Submit"] == _m("Overwrite"))) {
            // owerwrite
            $SQL = "UPDATE view SET ".$varset->makeUPDATE()." WHERE id='$id'";
            print(_m("<br>Overwriting view %1",array($id)));
        } else {
            // insert as new view
            $varset->remove('id');  // id is autoincremented
            $SQL = "INSERT INTO view ". $varset->makeINSERT();
        }
        if ($dry_run) {
            print("VIEW import: " .$SQL);
        } elseif ( !$db->query($SQL)) {
            $err["DB"] = MsgErr( _m("Can't insert into view.") );
        }
    } // each view
    freeDB($db);
}


/** create_SQL_insert_statement function
 *  Creates SQL command for inserting
 * @param $fields      - fields for creating query
 * @param $table       - name of table
 * @param $pack_fields - whitch fields needs to be packed (some types of ids...)
 * @param $only_fields - put in SQL command only some values from $fields
 * @param $add_values  - adds some another values, whitch aren't in $fields
 */
function create_SQL_insert_statement ($fields, $table, $pack_fields = "", $only_fields="", $add_values="")
{
    $sqlfields = "";
    $sqlvalues = "";
    foreach ( $fields as $key => $val) {
        // Only import fields with integer keys, arrays - e.g. $slice['fields']
        // handled elsewhere
        if (!is_array($val) && !is_int ($key)) {
            if ((strstr($only_fields,";".$key.";")) || ($only_fields=="")) {
                if ($sqlfields > "") {
                    $sqlfields .= ",\n";
                    $sqlvalues .= ",\n";
                }
                $sqlfields .= $key;

                if (strstr($pack_fields,";".$key.";")) {
                    $val = pack_id($val);
                }
                $sqlvalues .= '"'.addslashes($val).'"';
            }
        }
    }

    if ($add_values) {
        $add = explode(",", $add_values);
        for ( $i=0, $ino=count($add); $i<$ino; ++$i) {
            $dummy=explode("=",$add[$i]);
            if ($sqlfields > "") {
                $sqlfields .= ",\n". $dummy[0];
                $sqlvalues .= ",\n". '"'.addslashes($dummy[1]).'"';
            }
        }
    }
    return "INSERT INTO ".$table." (".$sqlfields.") VALUES (".$sqlvalues.")";
}
?>
