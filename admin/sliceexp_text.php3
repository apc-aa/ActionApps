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

require $GLOBALS[AA_INC_PATH] . "searchlib.php3";

function getRecord (&$array, &$record) 
{
	reset($record);
	while (list($key,$val)=each($record))
		if (!is_integer($key)) $array[$key] = $val;
}	

function exportOneSliceStruct ($slice_id, $b_export_type, $SliceID, $b_export_gzip, $temp_file) {
global $db, $sess;
	
	$slice_id_bck = stripslashes(unpack_id128($slice_id));
	
	$SQL = "SELECT * FROM slice WHERE id='$slice_id'";
	$db->query($SQL);
	if (!$db->next_record()) {
		MsgPage($sess->url(self_base())."index.php3", "ERROR - slice $slice_id_bck (".pack_id128($slice_id_bck).") not found", "standalone");
		exit;	
	}
	
	$uid = unpack_id128($db->f(id));
	getRecord ($slice, $db->Record);
	
	//unpack the IDs
	//TODO: add fields which contain IDs that should be unpacked
	// but add them in sliceimp.php3 too!
	$slice["owner"] = unpack_id ($slice["owner"]);
	
	if ($b_export_type != _m("Export to Backup")) {
		if (strlen ($SliceID) != 16) {
			MsgPage($sess->url(self_base())."index.php3", _m("Wrong slice ID length: ").strlen($SliceID), "standalone");
			exit;	
		}
		else $uid = unpack_id128($SliceID);
	}
	
	$slice["id"] = $uid;
	
	$SQL = "SELECT * FROM field WHERE slice_id='$slice_id'";
	$db->query($SQL);
	
	while($db->next_record()) {	
		//add the record to the fields array:
		getRecord($new, $db->Record);
		$new["slice_id"] = $uid;
	
		//unpack the IDs
		//TODO: add fields which contain IDs that should be unpacked
		// but add them in sliceimp.php3 too!
	
		$slice["fields"][] = $new;
	}
    $slice_data = serialize($slice);
	$slice_data = $b_export_gzip ? gzcompress($slice_data) : $slice_data;
	$slice_data = HTMLEntities(base64_encode($slice_data));
	fwrite($temp_file, "<slicedata gzip=\"".$b_export_gzip."\">\n$slice_data\n</slicedata>\n");	
}

function exportOneSliceData ($slice_id, $b_export_gzip, $temp_file, $b_export_spec_date, $b_export_from_date, $b_export_to_date) 
{
    $slice_id2 = unpack_id128($slice_id);
    list($fields,) = GetSliceFields($slice_id2);
	if ($b_export_spec_date) {
		$conds[0]["operator"] = "e:>=";
		$conds[0]["publish_date...."] = 1;
		$conds[0]["value"] = $b_export_from_date;
		$conds[1]["operator"] = "e:<=";
		$conds[1]["publish_date...."] = 1;
		$conds[1]["value"] = $b_export_to_date;
	} else {
		$conds="";
	}
	$zids=QueryZIDs($fields, $slice_id2, $conds, "", "", "ALL");
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
		   $e_temp = serialize($content);
		   $e_temp = $b_export_gzip ? gzcompress($e_temp) : $e_temp;	
		   $e_temp = HTMLEntities(base64_encode($e_temp));
		   fwrite($temp_file, "$e_temp\n</data>\n");
		   unset($myids);
		   unset($e_temp);
		}
	}	
}

function exporter($b_export_type, $slice_id, $b_export_gzip, $export_slices, $SliceID, $b_export_struct, $b_export_data, $b_export_spec_date, $b_export_from_date, $b_export_to_date) 
{
	global $db;

	$temp_file = tmpfile();
	
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
	
	if ($b_export_type != _m("Export to Backup")) {
		unset ($export_slices);
		$export_slices = array($slice_id);
	}

	reset($export_slices);
	while (list(,$slice_id_bck) = each($export_slices)) {
		$slice_id = addslashes(pack_id128($slice_id_bck));

		$SQL= "SELECT name FROM slice WHERE id like '".$slice_id."' ORDER BY name";
		$db->query($SQL);
		while($db->next_record())
			$slice_name = $db->f(name);

		if ($b_export_type != _m("Export to Backup")) {
			if (strlen ($SliceID) != 16) {
				MsgPage($sess->url(self_base())."index.php3", _m("Wrong slice ID length:").strlen($SliceID), "standalone");
				exit;	
			}
			else $SliceIDunpack = unpack_id128($SliceID);
		}
						
		fwrite($temp_file, "<slice id=\"");
		fwrite($temp_file, ($b_export_type != _m("Export to Backup") ? $SliceIDunpack : unpack_id128($slice_id)));
		fwrite($temp_file, "\" name=\"".$slice_name."\">\n");	
		
		if ($b_export_struct) {
		// export of slice structure
			exportOneSliceStruct($slice_id, $b_export_type, $SliceID, $b_export_gzip, $temp_file);	
		}
		if ($b_export_data) {		  
		// export of slice data
		  exportOneSliceData($slice_id, $b_export_gzip, $temp_file, $b_export_spec_date, $b_export_from_date, $b_export_to_date);
		}
		fwrite($temp_file, "</slice>\n");
	}	
	
	fwrite($temp_file, "</sliceexport>");
	return $temp_file;
}
	
function exportToFile($b_export_type, $slice_id, $b_export_gzip, $export_slices, $SliceID, $b_export_struct, $b_export_data, $b_export_spec_date, $b_export_from_date, $b_export_to_date) 
// Export data to file:
//   Opens browser's dialog to write file to disk...
{	
	if ($b_export_gzip != 1) { $b_export_gzip = 0; }
	
	$temp_file = exporter($b_export_type, $slice_id, $b_export_gzip, $export_slices, $SliceID, $b_export_struct, $b_export_data, $b_export_spec_date, $b_export_from_date, $b_export_to_date);
	
	rewind($temp_file);
		
	header("Content-type: application/octec-stream");
	header("Content-Disposition: attachment; filename=aaa.aaxml");

	 while(!feof($temp_file)) {
		   $buffer = fread($temp_file, 4096);
		   echo $buffer;
	 }
	fclose($temp_file);
}
					
function exportToForm($b_export_type, $slice_id, $b_export_gzip, $export_slices, $SliceID, $b_export_struct, $b_export_data, $b_export_spec_date, $b_export_from_date, $b_export_to_date) 
// Export data to text area in browser's window ...
{		

	if ($b_export_gzip != 1) { $b_export_gzip = 0; }
	
	$temp_file = exporter($b_export_type, $slice_id, $b_export_gzip, $export_slices, $SliceID, $b_export_struct, $b_export_data, $b_export_spec_date, $b_export_from_date, $b_export_to_date);
	
	rewind($temp_file);
	
	echo "
		<tr><td class = tabtxt>
		<FORM>
		<b>".  _m("Save this text. You may use it to import the slices into any ActionApps:") ."</b>
		</P>
		<TEXTAREA COLS = 80 ROWS = 20>";

//		fpassthru($export_file);

		 while(!feof($temp_file)) {
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