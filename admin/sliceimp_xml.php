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
 * @author    Mitra (based on earlier versions by Jakub Ad�mek, Pavel Jisl)
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;

require_once __DIR__ . "/../include/xml_serializer.php";

//$dry_run = 1;


/** sliceimp_xml_parse function
 * Create and parse data
 * @param $xml_data
 * @param $dry_run = false
 * @param $force_this_slice = false
 */
function sliceimp_xml_parse($xml_data, $dry_run=false, $force_this_slice=false): void
{
    MoreTimeLimit(600); // set_time_limit to 5 minutes (at least) - this can take a while
    $xu = new xml_unserializer();
    if ($dry_run) {
        huhl("Importing data=",safe($xml_data));
    }

    /** Create array structure from XML data */
    $i = $xu->parse($xml_data);  // PHP data structure
    if ($dry_run) {
        huhl("Parsed data=",$i);
    }

    $s = $i["SLICEEXPORT"][0];
    if ($dry_run) {
        echo "<br>Slices:".count($s["SLICE"]);
    }

    if (! $s) {
        MsgPage(_m("\nERROR: File doesn't contain SLICEEXPORT"));
        exit;
    }
    if ($s['PROCESS']) {

        // This is some kind of data preprocessing from Mitra
        // Looks like slice specific - ask Mitra for more details
        $v = $s['PROCESS'] . "_preimport";
        print("\nPre-processing with $v");
        $v($s);
    }

    if ($s['VERSION'] === "1.0") {   // older format of XML file
        $sl            = $s['SLICE'][0];
        $slice         = unserialize(base64_decode($sl['DATA']), ['allowed_classes' => false]);
        if (!is_array($slice)) {
            MsgPage(_m("ERROR: Text is not OK. Check whether you copied it well from the Export.") . " Version=" . $s['VERSION']);
            exit;
        }
        $slice["id"]   = $sl['ID'];
        $slice["name"] = $sl['NAME'];
        if ($dry_run) {
            echo "<br><br>Would import slice=".safe($slice['name']);
        } else {
            import_slice($slice);
        }
    }
    elseif ($s['VERSION'] === "1.1") {
        foreach ($s['SLICE'] as $sl) {
            $sld = $sl['SLICEDATA'][0];

            /** First we have to import slice data */
            if ($sld) { // Can skip structure if just data
                // slice structure defined - create slice array
                if ($sld['CHARDATA']) { // Its an encoded serialized data
                    $chardata = base64_decode($sld['CHARDATA']);
                    $chardata = $sld['GZIP'] ? gzuncompress($chardata) : $chardata;
                    $slice    = unserialize($chardata, ['allowed_classes' => false]);
                } elseif ($sld['slice']) {
                    $slice    = $sld['slice'];
                }
                if (!is_array($slice)) {
                    MsgPage(_m("ERROR: Text is not OK. Check whether you copied it well from the Export.") . " Version=" . $s['VERSION']);
                    exit;
                }

                $slice["id"]   = $sl['ID'];
                $slice["name"] = $sl['NAME'];
                if ($dry_run) {
                    echo "<br><br>Would import slice=".safe($slice['name']);
                    $slice_id_new = '11111111111111112222222222222222';
                } else {
                    $slice_id_new = import_slice($slice);
                }
            }
            /** Now we will import views. View ids are changed (it is
              * autoincremented database value, so it is possible that there
              * will be the need to update some aliases. View is overwritten
              * only if you select OVERWRITE for slice
              */
            if (is_array($sl['VIEWS'])) {
                // in fact there is only [0] element of the array, where more
                // views is defined
                foreach ( $sl['VIEWS'] as $viewdata ) {
                    $viewdata = base64_decode($viewdata);
                    $viewdata = $sld['GZIP'] ? gzuncompress($viewdata) : $viewdata;
                    // views are exported as getViewInfo() field-arrays (no objects)
                    $views = unserialize($viewdata, ['allowed_classes' => false]);
                    if ($dry_run) {
                        echo "<br>Views:".count($views);
                    }
                    import_views($views, $slice_id_new, $dry_run);
                }
            } else {
                if ($dry_run) {
                    echo "No views";
                }
                huhl("No views");
            }

            /** Now import items */
            if (is_array($sl['DATA'])) {
                if ($dry_run) {
                    echo "<br>Items:".count($sl["DATA"]);
                }
                foreach ($sl['DATA'] as $sld) {
                    if (isset($sld['CHARDATA'])) {
                        $chardata   = base64_decode($sld['CHARDATA']);
                        $chardata   = $sld['GZIP'] ? gzuncompress($chardata) : $chardata;
                        $content4id = unserialize($chardata, ['allowed_classes' => false]);
                    } else { // It's in XML
                        $content4id = $sld['item'];
                    }
                    if (!is_array($content4id)) {
                        MsgPage(_m("ERROR: Text is not OK. Check whether you copied it well from the Export.") . " Version=" . $s['VERSION']);
                        exit;
                    }
                    if ($dry_run) {
                        huhl("Would import data to ",$sld['ITEM_ID'],$content4id);
                    } else {
                        import_slice_data( $force_this_slice ? $GLOBALS['slice_id'] : $sl['ID'], $sld['ITEM_ID'], $content4id, true, true);
                    }
                }
            } // loop over each item
        } // loop over each slice
    } // Version 1.1
    else {
        MsgPage(_m("ERROR: Unsupported version for import") . $s['VERSION']);
        exit;
    }
}

/** import_views function
 * @param $slvs array
 * @param $slice_id_new
 */
function import_views($slvs, $slice_id_new, $dry_run=false): void
{

    if ( $dry_run ) {
        huhl($slvs);
    }

    $db = getDB();

    // Get all views in current AA
    // $av = GetViewsWhere();
    $av = DB_AA::select(['vid'=>1], "SELECT view.id as vid FROM `view`, `slice`", [
        ['slice.id', 'view.slice_id', 'j'],
        ['slice.deleted', '1', '<>']
    ]);

    /** Several cases here
      *  If there is a conflict, then
      *  - Overwrite           => just go ahead, use changed id if available
      *  - Insert with new ids => pick a new id for view
      *  - Insert              => skip conflicts
      *
      *
      * note if no conflict then will import, which might be bad if
      * slice_id had been changed.
      * Note that difference between Overwrite & InsertNew, is in whether
      * other views for this slice should be deleted first, they are not
      * currently deleted, although that would be an OK change to make
      */
    $varset = new Cvarset();
    foreach ($slvs as $slvf) {   // $slv is view field array

        $varset->clear();
        $id   = $slvf["id"];

        /** Change the slice_id for the view to the current one
         *  (could be the same, but could be also different if we are
         *  changing slice_id (due to conflict, ...)
         */
        $slvf["slice_id"] = pack_id($slice_id_new);

        foreach ($slvf as $k => $v) {
            // there was a bug in viewobj - we store 'slice.deleted' field to
            // view - we must skip it, now.
            if ( $k !== 'deleted' ) {
                $varset->add($k,"text",$v);
            }
        }

        /** Now we should resolve conflict in view ids - we overwrite view
         *  only if user selected 'Overwrite'. In other cases we change
         *  the view id to the new one (it is autoincremented). This
         *  behavior could lead to necessary code change for slice admins,
         *  but it is far best solution for now.
         */
        if ( isset($av[$id]) && ($GLOBALS["submit"] == _m("Overwrite"))) {
            // owerwrite
            $SQL = $varset->makeUPDATE('view')." WHERE id=".(int)$id;   // view id is an autoincrement int
            print(_m("<br>Overwriting view %1", [$id]));
        } else {
            // insert as new view
            $varset->remove('id');  // id is autoincremented

            $SQL = $varset->makeINSERT('view');
        }
        if ($dry_run) {
            print("<br><br>VIEW import: " .safe($SQL));
        } elseif ( !$db->query($SQL)) {
            $err["DB"] = MsgErr( _m("Can't insert into view.") );
        }
    } // each view
    freeDB($db);
}
