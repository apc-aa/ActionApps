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

	Imports the slice definition and data, exported from toolkit
	
*/

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."itemfunc.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."feeding.php3";
require $GLOBALS[AA_INC_PATH]."notify.php3";

if(!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD) ) {
	MsgPage($sess->url(self_base())."index.php3", L_NO_PS_EXPORT_IMPORT, "standalone");
	exit;
}

$varset = new Cvarset();
$itemvarset = new Cvarset();

// Prooves whether this ID already exists in the slices table,
// changes the ID to a new chosen one

function proove_ID (&$slice) 
{
	global $newID,
		   $sess,
		   $showme,
		   $resolve_conflicts,
		   $overwrite,
		   $new_slice_ids;
		   
	$res = $resolve_conflicts[$slice["id"]];
	if (strlen($res) == 32)	{
		$res = pack_id($res);
		if (strlen($res) == 16)	$slice["id"] = unpack_id($res);
	}
	// Find out whether a slice of the same ID already exists
	if (strlen($slice["id"]) != 32) {
		MsgPage($sess->url(self_base())."index.php3", L_E_IMPORT_WRONG_FILE, "standalone");
		exit;
	}		
	// back-up old ids, if you want import slice definition with new id	
	$new_slice_ids[$slice["id"]]["new_id"]=new_id();
		
	$slice_id = addslashes(pack_id($slice["id"]));
	$SQL = "SELECT * FROM slice WHERE id=\"$slice_id\"";
	global $db;
	$db->query($SQL);
	if($db->next_record()) {
		// if we want overwrite, delete old slice definition
		if ($GLOBALS["Submit"] == L_E_IMPORT_OVERWRITE) {
			$SQL = "DELETE FROM slice WHERE id='$slice_id'";	$db->query($SQL);
			$SQL = "DELETE FROM field WHERE slice_id='$slice_id'";	$db->query($SQL);
			$SQL = "DELETE FROM module WHERE id='$slice_id'"; $db->query($SQL);
			$overwrite = true;
		}
		else return false;
	}
	else $overwrite = false;
	return true;
}

// same function as above, but for table item and content
function proove_data_ID ($data_id) 
{
	global $data_newID,
		   $sess,
		   $data_showme,
		   $data_resolve_conflicts,
		   $data_overwrite;
		   
	$res = $data_resolve_conflicts[$data_id];
	if (strlen($res) == 32)	{
		$res = pack_id($res);
		if (strlen($res) == 16)	$data_id = unpack_id($res);
	}
	// Find out whether item with the same ID already exists
	if (strlen($data_id) != 32) {
		MsgPage($sess->url(self_base())."index.php3", L_E_IMPORT_WRONG_FILE, "standalone");
		exit;
	}		
	$old_data_id = addslashes(pack_id($data_id));
	$SQL = "SELECT * FROM item WHERE id=\"$old_data_id\"";
	global $db;
	$db->query($SQL);
	if($db->next_record()) {
		// if we want overwrite existing items, delete it
		if ($GLOBALS["Submit"] == L_E_IMPORT_OVERWRITE) {
			$SQL = "DELETE FROM item WHERE id='$old_data_id'";	$db->query($SQL);
			$SQL = "DELETE FROM content WHERE item_id='$old_data_id'";	$db->query($SQL);
			$data_overwrite = true;
		}
		else return false;
	}
	else $data_overwrite = false;
	return true;
}

// imports one slice (called by XML parser)
function import_slice (&$slice)
{	
	global $db,
		   $showme,
		   $IDconflict,
		   $slice_id,
		   $sess,
		   $conflicts_ID,
		   $Cancel,
		   $imported_list,
		   $overwritten_list,
		   $overwrite,
		   $only_slice,
		   $new_slice_ids;	
		   
	if ($only_slice) { // import slice definition ?
		$IDconflict = !proove_ID($slice);
		if (($IDconflict)&&($GLOBALS["Submit"]!=L_E_IMPORT_INSERT_AS_NEW)) {
			$conflicts_ID[$slice["id"]] = $slice["name"];
			$Cancel = 0;
			return false;
		}
		if ($GLOBALS["Submit"] == L_E_IMPORT_INSERT_AS_NEW) {
		  $slice["id"]=$new_slice_ids[$slice["id"]]["new_id"];
		}  
		// inserting to table slice
		$sqltext = create_SQL_insert_statement ($slice, "slice", ";id;owner;","","")."\n";
		$db->query($sqltext);
		// inserting to table module
		$sqltext = create_SQL_insert_statement ($slice, "module", ";id;owner;", ";id;name;deleted;slice_url;lang_file;created_at;created_by;owner;flag;","type=S")."\n";
		$db->query($sqltext);
		$fields = $slice["fields"];
		reset($fields); 
		while (list(,$curf) = each($fields)) {
			$curf["slice_id"] = $slice["id"];
			// inserting to table fields
			$sqltext = create_SQL_insert_statement ($curf, "field", ";slice_id;","","")."\n";
			$db->query($sqltext);
		}
		if ($overwrite)
			$overwritten_list[] = $slice["name"]." (id:".$slice["id"].")";
		else
			$imported_list[] = $slice["name"]." (id:".$slice["id"].")";
		$Cancel = "OHYES";
	}	
}

function import_slice_data($slice_id, $id, $content4id, $insert, $feed)
{
	global $db,
		   $data_showme,
		   $data_IDconflict,
		   $sess,
		   $data_conflicts_ID,
		   $Cancel,
		   $data_imported_list,
		   $data_overwritten_list,
		   $data_overwrite,
		   $only_data,
		   $new_slice_ids;	

	if ($only_data) { // import slice items ?   
		list($fields,) = GetSliceFields($slice_id);
	   	$cont = $content4id[$id];
		reset($fields);
		while (list($name,)=each($fields)) {
			$newcont[$name]=$cont[$name];
		}
		$data_IDconflict = !proove_data_ID($id);
		if (($data_IDconflict)&&($GLOBALS["Submit"]!=L_E_IMPORT_INSERT_AS_NEW)) {
			$data_conflicts_ID[$id] = $newcont["headline........"][0]["value"];
			$Cancel = 0;
			return false;
		}		

		if ($GLOBALS["Submit"] == L_E_IMPORT_INSERT_AS_NEW) {
		  // when iporting with new ids, we need create new id for item
		  // and get new id of slice
		  $new_data_id = new_id();		
		  $new_slice_id = $new_slice_ids[$slice_id]["new_id"];
		  $slice_id = $new_slice_id;
		  $id=$new_data_id;
		}		

		if ($data_overwrite)
			$data_overwritten_list[] = $id." (id:".$id.")";
		else
			$data_imported_list[] = $id." (id:".$id.")";
		
		$result = StoreItem($id, $slice_id, $newcont, $fields, $insert, true, $feed);
		$Cancel = "OHYES";
	}	
}

if ($Cancel)
	go_url( $sess->url(self_base() . "index.php3"));
	
$IDconflict = false;
$slice_def_bck = $slice_def = stripslashes($slice_def);
$imported_count = 0;

// insert xml parser
require "./sliceimp_xml.php3";

if ($conflicts_list) {
	$temp = split ("\n",$conflicts_list);
	reset($temp);
	while (list(,$line)=each($temp)) {
		list(,$line) = split(":",$line);
		list($old,$new) = split("->",$line);
		$resolve_conflicts[trim($old)] = trim($new);
	}
}

if ($data_conflicts_list) {
	$temp = split ("\n",$data_conflicts_list);
	reset($temp);
	while (list(,$line)=each($temp)) {
		list(,$line) = split(":",$line);
		list($old,$new) = split("->",$line);
		$data_resolve_conflicts[trim($old)] = trim($new);
	}
}

if ($slice_def != "") {
	$err = sliceimp_xml_parse ($slice_def);	
	if ($err != "") {
		MsgPage($sess->url(self_base())."index.php3", $err, "standalone");
		exit;
	}
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<TITLE><?php echo L_E_IMPORT_TITLE?></TITLE>

</HEAD>

<?php 
	require $GLOBALS[AA_INC_PATH]."menu.php3"; 
    showMenu ($aamenus, "aaadmin","sliceimp");
?>
<?php echo $pom ?>
<form name=formular method=post action="<?php echo $sess->url("sliceimp.php3") ?>" 
enctype="multipart/form-data">

<h1><b><?php echo L_E_IMPORT_TITLE.$pom?></b></h1>

<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit>
<?php 
if ($Cancel || $conflicts_list || $data_conflicts_list):
	echo "<B>".sprintf(L_E_IMPORT_COUNT,count($imported_list)+count($overwritten_list))."</p>";
	if (is_array($imported_list)) {
		echo "</p>".L_E_IMPORT_ADDED."</p>";
		reset($imported_list);
		while(list(,$desc)=each($imported_list))
			echo $desc."<br>";
	}
	if (is_array($overwritten_list)) {
		echo "</p>".L_E_IMPORT_OVERWRITTEN."</p>";
		reset($overwritten_list);
		while(list(,$desc)=each($overwritten_list))
			echo $desc."<br>";
	}
	
	echo "<br><br><B>".sprintf(L_E_IMPORT_DATA_COUNT,count($data_imported_list)+count($data_overwritten_list))."</p>";
	echo $data_showme;
	if (is_array($data_imported_list)) {
		echo "</p>".L_E_IMPORT_ADDED."</p>";
		reset($data_imported_list);
		while(list(,$desc)=each($data_imported_list))
			echo $desc."<br>";
	}
	if (is_array($data_overwritten_list)) {
		echo "</p>".L_E_IMPORT_OVERWRITTEN."</p>";
		reset($data_overwritten_list);
		while(list(,$desc)=each($data_overwritten_list))
			echo $desc."<br>";
	}

	?>
	</P>
	<INPUT TYPE=SUBMIT NAME=Cancel VALUE="  OK  ">
<?php
else:
?>

<?php echo L_E_IMPORT_MEMO ?></p>
</td></tr>
<?php 
if ($IDconflict):?>
	<tr><td class=tabtxt>
	<b><?php echo sprintf (L_E_IMPORT_IDCONFLICT,pack_id($slice_id)) ?></b></p>
	<p align=center>
<TEXTAREA NAME=conflicts_list ROWS=<?php echo count($conflicts_ID) ?> COLS=110>
<?php
	reset($conflicts_ID);
	while (list($c_id,$name)=each($conflicts_ID))
		echo $name.":\t".$c_id." -> ".$c_id."\n";
?>
</TEXTAREA>

<?php
endif;
if ($data_IDconflict): ?>

<tr><td class=tabtxt>
<b><?php echo sprintf (L_E_IMPORT_DATA_IDCONFLICT) ?></b></p>
<p align=center>
<TEXTAREA NAME=data_conflicts_list ROWS=<?php echo count($data_conflicts_ID) ?> COLS=120>
<?php
	reset($data_conflicts_ID);
	while (list($c_id,$name)=each($data_conflicts_ID))
		echo $name.":\t".$c_id." -> ".$c_id."\n";
?>
</TEXTAREA>
<?php
endif;
if($IDconflict || $data_IDconflict): ?>
	</P>	
<?	echo L_E_IMPORT_CONFLICT_INFO ?>
	<p align=center>
<? if ($only_slice)	 {?>
	<input type=hidden name=only_slice value=1>
<? };
	if($only_data) { ?>
	<input type=hidden name=only_data value=1>
<? }; ?>			
	<INPUT TYPE=SUBMIT NAME=Submit VALUE="<?php echo L_E_IMPORT_OVERWRITE ?>">
	<INPUT TYPE=SUBMIT NAME=Submit VALUE="<?php echo L_E_IMPORT_INSERT ?>">
	<INPUT TYPE=SUBMIT NAME=Submit VALUE="<?php echo L_E_IMPORT_INSERT_AS_NEW ?>">
	<INPUT TYPE=SUBMIT NAME=Cancel VALUE="<?php echo L_CANCEL ?>">
	</p>
	</td></tr>
<?php
endif;?>
<tr><td class=tabtit align=center>
<br>
	<TEXTAREA NAME="slice_def" ROWS = 10 COLS = 100><?php if ($IDconflict || $data_IDconflict) echo $slice_def_bck ?></TEXTAREA>
	<p>	
<?php if (!$IDconflict || !$data_IDconflict): ?>	
<? if (!$GLOBALS["Submit"]) { ?>
	<input type=checkbox name=only_slice checked><?php echo L_E_IMPORT_IMPORT_SLICE ?><br>
	<input type=checkbox name=only_data checked><?php echo L_E_IMPORT_IMPORT_ITEMS ?><br><br>	
	<INPUT TYPE=SUBMIT NAME=Submit VALUE="<?php echo L_E_IMPORT_SEND ?>">
	<INPUT TYPE=SUBMIT NAME=Cancel VALUE="<?php echo L_CANCEL ?>">
<? } ?>	
<?php
endif;
endif; //if ($cancel || $coflicts_list)?>
</form>
</tr></td>
</table>

<?PHP
HtmlPageEnd();
page_close();
?>

	