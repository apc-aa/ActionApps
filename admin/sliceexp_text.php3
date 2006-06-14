<?php
//$Id$
/* 
Copyright (C) 2001 Association for Progressive Communications 
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/* 
	Author: Jakub Adámek, Pavel Jisl
	
	This page is called from sliceexp.php3 to generate the exported text.

 	slices: an associative array variable of all the slices to be exported, 
	the index is the slice ID.
	The value for each slice is an associative array again, 
	it contains all the members of one slice. 
	The fields for each slice are one member of the
	slice array, in the form of a third-level associative array. 
*/

require_once AA_INC_PATH . "searchlib.php3";
require_once AA_INC_PATH . "sliceobj.php3";
require_once AA_INC_PATH . "xml_serializer.php3";

function getRecord (&$array, &$record) 
{
	reset($record);
	while (list($key,$val)=each($record))
		if (!is_integer($key)) $array[$key] = $val;
}	

// Export information about the slice
function exportOneSliceStruct ($slobj, $b_export_type, $new_slice_id, $b_export_gzip, $temp_file,$b_export_hex) {
    global $db, $sess;
	
	$SQL = "SELECT * FROM slice WHERE id='".$slobj->sql_id()."'";
	$db->query($SQL);
	if (!$db->next_record()) {
		MsgPage($sess->url(self_base())."index.php3", "ERROR - slice ".$slobj->unpacked_id() ." not found", "standalone");
		exit;	
	}
	
	$uid = unpack_id128($db->f(id));
	getRecord ($slice, $db->Record);
	
	//unpack the IDs
	//TODO: add fields which contain IDs that should be unpacked
	// but add them in sliceimp.php3 too!
	$slice["owner"] = unpack_id ($slice["owner"]);
	
	if ($b_export_type != _m("Export to Backup")) {
		if (strlen ($new_slice_id) != 16) {
			MsgPage($sess->url(self_base())."index.php3", _m("Wrong slice ID length: ").strlen($new_slice_id), "standalone");
			exit;	
		}
		else $uid = unpack_id128($new_slice_id);
	}
	
	$slice["id"] = $uid;
	
	$SQL = "SELECT * FROM field WHERE slice_id='".$slobj->sql_id()."'";
	$db->query($SQL);
	
	while ($db->next_record()) {	
		//add the record to the fields array:
		getRecord($new, $db->Record);
		$new["slice_id"] = $uid; // Use new id if set
	
		//unpack the IDs
		//TODO: add fields which contain IDs that should be unpacked
		// but add them in sliceimp.php3 too!
        // I don't think so, this can't know about struc of actual fields
        // better to take care to handle any binary data

		$slice["fields"][] = $new;
	}


// TODO: Get Views and Constants

    if ($b_export_hex) {
        $slice_data = serialize($slice);
       	$slice_data = $b_export_gzip ? gzcompress($slice_data) : $slice_data;
    	$slice_data = HTMLEntities(base64_encode($slice_data));
    } else {
        $slice_data = xml_serialize("slice",$slice,"\n","    ");
    }

	fwrite($temp_file, "<slicedata gzip=\"".$b_export_gzip."\">\n$slice_data\n</slicedata>\n");
}


// Export each view
function exportOneSliceViews($slobj, $b_export_gzip, $temp_file, 
        $b_export_hex) {
    $a = " ";
    if (!($slova = $slobj->views())) return;
    if ($b_export_hex) {
        $a .= " coding=\"serialize".($b_export_gzip ? "gzip" : "")."\"";
	    $e_temp = serialize($slova);
       	$e_temp = $b_export_gzip ? gzcompress($e_temp) : $e_temp;	
	    $e_temp = HTMLEntities(base64_encode($e_temp));
        $e_temp = "<views $a>$e_temp</views>";
    } else {
        reset($slova);
        while (list($k,$v) = each($slova)) {
            unset($slova[$k]->fields[deleted]);
        }
        $e_temp = xml_serialize("views",$slova,"\n","    ",$a);
    }
    fwrite($temp_file,$e_temp."\n");
}


function exportOneSliceData ($slobj, $b_export_gzip, $temp_file, $b_export_spec_date, $b_export_from_date, $b_export_to_date,$b_export_hex) 
{
    list($fields,) = GetSliceFields($slobj->unpacked_id());
	if ($b_export_spec_date) {
		$conds[0]["operator"] = "e:>=";
		$conds[0]["publish_date...."] = 1;
		$conds[0]['value'] = $b_export_from_date;
		$conds[1]["operator"] = "e:<=";
		$conds[1]["publish_date...."] = 1;
		$conds[1]['value'] = $b_export_to_date;
	} else {
		$conds="";
	}
	$zids=QueryZIDs($fields, $slobj->unpacked_id(), $conds, "", "", "ALL");
	if ($zids->count() == 0) { 
		if ($b_export_spec_date) { fwrite($temp_file, "<comment>\nThere are no data in selected days (from ".$b_export_from_date." to ".$b_export_to_date.").\n</comment>\n");}
		else {fwrite($temp_file, "<comment>\nThere are no data in slice.\n</comment>\n");}
	} else {
		$item_ids = $zids->longids();
		reset($item_ids);	
		$item_count = count($item_ids);
		for ($i=0; $i<$item_count; $i++) {
		   $content = GetItemContent($item_ids[$i]);
		   fwrite($temp_file, "<data item_id=\"$item_ids[$i]\" gzip=\"$b_export_gzip\">\n");
           if ($b_export_hex) {
    		   $e_temp = serialize($content);
       		   $e_temp = $b_export_gzip ? gzcompress($e_temp) : $e_temp;	
	    	   $e_temp = HTMLEntities(base64_encode($e_temp));
           } else {
                $e_temp = xml_serialize("item",$content,"\n","    ");
           }
		   fwrite($temp_file, "$e_temp\n</data>\n");
		   unset($myids);
		   unset($e_temp);
		}
	}	
}

// Generate the output and write to a temporary file
// I'm assuming $export_slices contains UNPACKED slice ids
function exporter($b_export_type, $slice_id, $b_export_gzip, $export_slices, $new_slice_id, $b_export_struct, $b_export_data, $b_export_spec_date, $b_export_from_date, $b_export_to_date,$b_export_hex,$b_export_views) 
{
	global $db;
	$temp_file = tmpfile();

	if ($b_export_type != _m("Export to Backup")) {
		unset ($export_slices);
		$export_slices = array($slice_id);
	}
    // Changed to work with slice objects, to better share code
    $slices = new slices($export_slices);
	
	fwrite($temp_file, "<sliceexport version=\"1.1\">\n");
	fwrite($temp_file, "<comment>\nThis text contains exported slices definitions (and/or slices data). You may import them to any ActionApps.\n");
	if ($b_export_type != _m("Export to Backup")) {
		fwrite($temp_file, "This text is exported slice data for use in another ActionApps instalation (new slice_id)\n");
	} else {
		fwrite($temp_file, "This text is backuped slice data with the same slice_id as is in the source slice\n");
	}	
	if ($b_export_spec_date && $b_export_data) {
		fwrite($temp_file, "Exported data from ".$b_export_from_date." to ".$b_export_to_date."\n");
	}		
	fwrite($temp_file, "</comment>\n");
	
	if ($b_export_gzip != 1) { $b_export_gzip = 0; }
	
	reset($export_slices);
    foreach ($slices->objarr() as $slobj) {

		if ($b_export_type != _m("Export to Backup")) {
			if (strlen ($new_slice_id) != 16) {
				MsgPage($sess->url(self_base())."index.php3", _m("Wrong slice ID length:").strlen($new_slice_id), "standalone");
				exit;	
			}
			else $new_slice_idunpack = unpack_id128($new_slice_id);
		}
						
		fwrite($temp_file, "<slice id=\"");
		fwrite($temp_file, ($b_export_type != _m("Export to Backup") ? $new_slice_idunpack : $slobj->unpacked_id()));
		fwrite($temp_file, "\" name=\"".HTMLEntities($slobj->name())."\">\n");	
		
		if ($b_export_struct) {
		// export of slice structure
			exportOneSliceStruct($slobj, $b_export_type, $new_slice_id, $b_export_gzip, $temp_file,$b_export_hex);	
		}
		if ($b_export_data) {		  
		// export of slice data
		  exportOneSliceData($slobj, $b_export_gzip, $temp_file, $b_export_spec_date, $b_export_from_date, $b_export_to_date,$b_export_hex);
		}
        if ($b_export_views) {
            // export of views 
		  exportOneSliceViews($slobj, $b_export_gzip, $temp_file, $b_export_hex);
        }
		fwrite($temp_file, "</slice>\n");
	}	
	
	fwrite($temp_file, "</sliceexport>");
	return $temp_file;
}
	
function exportToFile($b_export_type, $slice_id, $b_export_gzip, $export_slices, $new_slice_id, $b_export_struct, $b_export_data, $b_export_spec_date, $b_export_from_date, $b_export_to_date,$b_export_hex,$b_export_views) 
// Export data to file:
//   Opens browser's dialog to write file to disk...
{	
	if ($b_export_gzip != 1) { $b_export_gzip = 0; }
	
	$temp_file = exporter($b_export_type, $slice_id, $b_export_gzip, $export_slices, $new_slice_id, $b_export_struct, $b_export_data, $b_export_spec_date, $b_export_from_date, $b_export_to_date,$b_export_hex,$b_export_views);
	
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
					
function exportToForm($b_export_type, $slice_id, $b_export_gzip, $export_slices, $new_slice_id, $b_export_struct, $b_export_data, $b_export_spec_date, $b_export_from_date, $b_export_to_date,$b_export_hex,$b_export_views) 
// Export data to text area in browser's window ...
{		

	if ($b_export_gzip != 1) { $b_export_gzip = 0; }
	
	$temp_file = exporter($b_export_type, $slice_id, $b_export_gzip, $export_slices, $new_slice_id, $b_export_struct, $b_export_data, $b_export_spec_date, $b_export_from_date, $b_export_to_date,$b_export_hex,$b_export_views);
	
	rewind($temp_file);
	
	echo "
		<tr><td class = tabtxt>
		<FORM>
		<p><b>".  _m("Save this text. You may use it to import the slices into any ActionApps:") ."</b>
		</P>
		<TEXTAREA COLS = 80 ROWS = 20>";

//		fpassthru($export_file);

		 while (!feof($temp_file)) {
			   $buffer = fread($temp_file, 4096);
			   echo $buffer;
		 }
		fclose($temp_file);

	echo "</TEXTAREA>
		</FORM>
		</P>
		</tr></td>";
}
page_close();
?>