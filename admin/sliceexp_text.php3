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
	Author: Jakub Adámek
	
	This page is called from sliceexp.php3 to generate the exported text.

 	slices: an associative array variable of all the slices to be exported, 
	the index is the slice ID.
	The value for each slice is an associative array again, 
	it contains all the members of one slice. 
	The fields for each slice are one member of the
	slice array, in the form of a third-level associative array. 
*/

function getRecord (&$array, &$record) 
{
	reset($record);
	while (list($key,$val)=each($record))
		if (!is_integer($key)) $array[$key] = $val;
}	

if ($b_export_type != L_E_EXPORT_SWITCH) {
	unset ($export_slices);
	$export_slices = array($slice_id);
}

reset($export_slices);
while (list(,$slice_id_bck) = each($export_slices)) {
	$slice_id = addslashes(pack_id($slice_id_bck));
	$SQL = "SELECT * FROM slice WHERE id='$slice_id'";
	$db->query($SQL);
	if (!$db->next_record()) {
		MsgPage($sess->url(self_base())."index.php3", "ERROR - slice $slice_id_bck (".pack_id($slice_id_bck).") not found", "standalone");
		exit;	
	}
	
	$uid = unpack_id($db->f(id));
	getRecord ($slice, $db->Record);
	
	//unpack the IDs
	//TODO: add fields which contain IDs that should be unpacked
	// but add them in sliceimp.php3 too!
	$slice["owner"] = unpack_id ($slice["owner"]);
	
	if ($b_export_type != L_E_EXPORT_SWITCH) {
		if (strlen ($SliceID) != 16) {
			MsgPage($sess->url(self_base())."index.php3", L_E_EXPORT_TITLE_IDLENGTH.strlen($SliceID), "standalone");
			exit;	
		}
		else $uid = unpack_id($SliceID);
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
	
	$export_text .= "<slice id=\"".$uid."\" name=\"".$slice["name"]."\">";
	$export_text .= HTMLEntities(base64_encode(serialize($slice)));
	$export_text .= "</slice>\n\n\n";
}	
$header .= "<sliceexport version=\"1.0\">\n";
$header .= "<comment>This text contains exported slices definitions. You may import them to any Toolkit.</comment>\n";

$export_text = $header.$export_text."</sliceexport>";
?>

<tr><td class = tabtxt>
<FORM>
<b><?php echo L_E_EXPORT_TEXT_LABEL ?></b>
</P>
<TEXTAREA COLS = 80 ROWS = 20>
<?php echo $export_text ?>
</TEXTAREA>
</FORM>
</P>
</tr></td>

<?PHP
/*
$Log$
Revision 1.3  2001/10/24 18:45:02  honzam
fixed bug of two listed slices in slice export

Revision 1.2  2001/10/05 10:51:29  honzam
Slice import/export allows backup of more slices, bugfixes

Revision 1.1  2001/10/02 11:33:54  honzam
new sliceexport/import feature

*/
?>

	