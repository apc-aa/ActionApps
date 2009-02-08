<?php
/**  This page is called from sliceexp.php3 to generate the exported text.
 *
 *    slices: an associative array variable of all the slices to be exported,
 *    the index is the slice ID.
 *    The value for each slice is an associative array again,
 *    it contains all the members of one slice.
 *    The fields for each slice are one member of the
 *    slice array, in the form of a third-level associative array.
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
 * @author    Jakub Adámek, Pavel Jisl
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/


require_once AA_INC_PATH . "searchlib.php3";
require_once AA_INC_PATH . "slice.class.php3";
require_once AA_INC_PATH . "xml_serializer.php3";
require_once AA_INC_PATH . "convert_charset.class.php3";


function getRecord(&$record) {
    $ret = array();
    foreach ($record as $key => $val) {
        if (!is_integer($key)) {
            $ret[$key] = $val;
        }
    }
    return $ret;
}

class AA_Slice_Exporter {
    var $type;
    var $gzip;
    var $struct;
    var $data;
    var $spec_date;
    var $from_date;
    var $to_date;
    var $hex;
    var $views;
    var $to_utf;

    // used for conversion data to utf-8
    var $_encoder;

    /** Constructor */
    function AA_Slice_Exporter($type, $gzip, $struct, $data, $spec_date, $from_date, $to_date, $hex, $views, $to_utf) {
        $this->type       = $type;
        $this->gzip       = $gzip;
        $this->struct     = $struct;
        $this->data       = $data;
        $this->spec_date  = $spec_date;
        $this->from_date  = $from_date;
        $this->to_date    = $to_date;
        $this->hex        = $hex;
        $this->views      = $views;
        $this->to_utf     = $to_utf;

        $this->_encoder   = null;
    }

    /** exportOneSliceStruct function
     * Export information about the slice
     * @param $slobj
     * @param $new_slice_id
     * @param $temp_file
     * @return writes to file
     */
    function exportOneSliceStruct($slobj, $new_slice_id, $temp_file) {
        global $db, $sess;

        $SQL = "SELECT * FROM slice WHERE id='".$slobj->sql_id()."'";
        $slice = GetTable2Array($SQL, 'aa_first', 'aa_fields');
        if (!$slice) {
            MsgPage($sess->url(self_base())."index.php3", "ERROR - slice ".$slobj->unpacked_id() ." not found", "standalone");
            exit;
        }
        $uid = unpack_id128($slice['id']);

        //unpack the IDs
        //TODO: add fields which contain IDs that should be unpacked
        // but add them in sliceimp.php3 too!
        $slice["owner"] = unpack_id($slice["owner"]);

        if ($this->type != _m("Export to Backup")) {
            if (strlen ($new_slice_id) != 16) {
                MsgPage($sess->url(self_base())."index.php3", _m("Wrong slice ID length: ").strlen($new_slice_id), "standalone");
                exit;
            } else {
                $uid = unpack_id128($new_slice_id);
            }
        }

        $slice["id"] = $uid;

        $SQL = "SELECT * FROM field WHERE slice_id='".$slobj->sql_id()."'";
        $db->query($SQL);
        while ($db->next_record()) {
            //add the record to the fields array:
            $new = getRecord($db->Record);
            $new["slice_id"] = $uid; // Use new id if set

            //unpack the IDs
            //TODO: add fields which contain IDs that should be unpacked
            // but add them in sliceimp.php3 too!
            // I don't think so, this can't know about struc of actual fields
            // better to take care to handle any binary data

            $slice["fields"][] = $new;
        }

        $slice_data = $this->encode($slice, 'slice');
        fwrite($temp_file, "<slicedata gzip=\"".$this->gzip."\">\n$slice_data\n</slicedata>\n");
    }


    /** exportOneSliceViews function
     *  Export each view
     * @param $slobj
     * @param $temp_file
     * @return writes to file
     */
    function exportOneSliceViews($slobj, $temp_file) {
        if (!($views_data = $slobj->views())) {
            return;
        }
        foreach ($views_data as $k => $v) {
            $views_data[$k]->load();
            unset($views_data[$k]->fields['deleted']);
        }
        $views_data = $this->encode($views_data, 'views', ' ');
        if ($this->hex) {
            $views_data = "<views coding=\"serialize".($this->gzip ? "gzip" : "")."\">$views_data</views>";
        }
        fwrite($temp_file, $views_data."\n");
    }


    function exportOneSliceData($slobj, $temp_file) {
        if ($this->spec_date) {
            $conds[0]["operator"]         = "e:>=";
            $conds[0]["publish_date...."] = 1;
            $conds[0]['value']            = $this->from_date;
            $conds[1]["operator"]         = "e:<=";
            $conds[1]["publish_date...."] = 1;
            $conds[1]['value']            = $this->to_date;
        } else {
            $conds="";
        }
        $zids=QueryZIDs(array($slobj->unpacked_id()), $conds, "", "ALL");
        if ($zids->count() == 0) {
            if ($this->spec_date) {
                fwrite($temp_file, "<comment>\nThere are no data in selected days (from ".$this->from_date." to ".$this->to_date.").\n</comment>\n");
            } else {
                fwrite($temp_file, "<comment>\nThere are no data in slice.\n</comment>\n");
            }
        } else {
            $item_ids   = $zids->longids();
            $item_count = count($item_ids);
            for ($i=0; $i<$item_count; $i++) {
                $content = GetItemContent($item_ids[$i]);
                fwrite($temp_file, "<data item_id=\"$item_ids[$i]\" gzip=\"".$this->gzip."\">\n" . $this->encode($content, 'item'). "\n</data>\n");
            }
        }
    }

    /** exporter function
     *  Generate the output and write to a temporary file
     *  I'm assuming $export_slices contains UNPACKED slice ids
     * @param $slice_id
     * @param $export_slices
     * @param $new_slice_id
     */
    function exporter($slice_id, $export_slices, $new_slice_id) {
        global $db, $sess;
        $temp_file = tmpfile();

        if ( !$temp_file ) {
            echo _m("Can't create temporary file.");
            exit;
        }

        if ($this->type != _m("Export to Backup")) {
            unset($export_slices);
            $export_slices = array($slice_id);
        }

        fwrite($temp_file, "<sliceexport version=\"1.1\">\n");
        fwrite($temp_file, "<comment>\nThis text contains exported slices definitions (and/or slices data). You may import them to any ActionApps.\n");
        if ($this->type != _m("Export to Backup")) {
            fwrite($temp_file, "This text is exported slice data for use in another ActionApps instalation (new slice_id)\n");
        } else {
            fwrite($temp_file, "This text is backuped slice data with the same slice_id as is in the source slice\n");
        }
        if ($this->spec_date && $this->data) {
            fwrite($temp_file, "Exported data from ".$this->from_date." to ".$this->to_date."\n");
        }
        fwrite($temp_file, "</comment>\n");

        if ($this->gzip != 1) {
            $this->gzip = 0;
        }

        foreach ($export_slices as $sid) {
            $slobj = AA_Slices::getSlice($sid);
            $this->prepareEncoder($slobj->getCharset());

            if ($this->type != _m("Export to Backup")) {
                if (strlen ($new_slice_id) != 16) {
                    MsgPage($sess->url(self_base())."index.php3", _m("Wrong slice ID length:").strlen($new_slice_id), "standalone");
                    exit;
                } else {
                    $new_slice_idunpack = unpack_id128($new_slice_id);
                }
            }

            fwrite($temp_file, "<slice id=\"");
            fwrite($temp_file, ($this->type != _m("Export to Backup") ? $new_slice_idunpack : $slobj->unpacked_id()));
            fwrite($temp_file, "\" name=\"".htmlentities($slobj->name())."\">\n");

            if ($this->struct) {
                // export of slice structure
                $this->exportOneSliceStruct($slobj, $new_slice_id, $temp_file);
            }
            if ($this->data) {
                // export of slice data
                $this->exportOneSliceData($slobj, $temp_file);
            }
            if ($this->views) {
                // export of views
                $this->exportOneSliceViews($slobj, $temp_file);
            }
            fwrite($temp_file, "</slice>\n");
        }

        fwrite($temp_file, "</sliceexport>");
        return $temp_file;
    }

    /** exportToFile function
     *  Export data to file:
     *  Opens browser's dialog to write file to disk...
     *
     * @param $slice_id
     * @param $export_slices
     * @param $new_slice_id
     */
    function exportToFile($slice_id, $export_slices, $new_slice_id) {
        if ($this->gzip != 1) {
            $this->gzip = 0;
        }

        $temp_file = $this->exporter($slice_id, $export_slices, $new_slice_id);

        rewind($temp_file);

        header("Content-type: application/octec-stream");
    //	header("Content-type: text/xml");
        header("Content-Disposition: attachment; filename=aaa.aaxml");

        while (!feof($temp_file)) {
            $buffer = fread($temp_file, 4096);
            echo $buffer;
        }
        fclose($temp_file);
    }

    /** exportToForm function
     *  Export data to text area in browser's window ...
     * @param $slice_id
     * @param $export_slices
     * @param $new_slice_id
     */
     function exportToForm($slice_id, $export_slices, $new_slice_id) {

        if ($this->gzip != 1) {
            $this->gzip = 0;
        }

        $temp_file = $this->exporter($slice_id, $export_slices, $new_slice_id);

        rewind($temp_file);

        echo "
            <tr><td class=\"tabtxt\">
            <form>
            <p><b>".  _m("Save this text. You may use it to import the slices into any ActionApps:") ."</b>
            </P>
            <textarea cols=\"80\" rows=\"20\">";

        // fpassthru($export_file);

        while (!feof($temp_file)) {
            $buffer = fread($temp_file, 4096);
            echo $buffer;
        }
        fclose($temp_file);

        echo "</textarea>
            </form>
            </p>
            </tr></td>";
    }

    function prepareEncoder($charset) {
        if ($this->to_utf) {
            $this->_encoder = new ConvertCharset($charset, 'utf-8');
        }
    }

    /** Converts text or values of array to utf, if it is requested */
    function encode($content, $type, $add=null) {
        if ($this->to_utf) {
            $content = $this->convertToUtf($content);
        }

        if ($this->hex OR $this->gzip) {
            $content = serialize($content);
            if ($this->gzip) {
                $content = gzcompress($content);
            }
            $content = htmlentities(base64_encode($content));
        } else {
            $content = xml_serialize($type, $content, "\n", "    ", $add);
        }
        return $content;
    }

    function convertToUtf($text) {
        if ( is_array($text) ) {
            foreach ($text as $k =>$v) {
                $text[$k] = $this->convertToUtf($v);
            }
        } else {
            $text = $this->_encoder->Convert($text);
        }
        return $text;
    }
}

?>