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

	Imports the slice definition as a template (without any data).
*/

require "../include/init_page.php3";

if(!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD) ) {
	MsgPage($sess->url(self_base())."index.php3", L_NO_PS_EXPORT_IMPORT, "standalone");
	exit;
}

/* There is some problem with open file permitions, I will use Text area instead ...

// $DefFile is the file from which you should extract the slice definition

if ($DefFile != "") {
	
  	// the copying is taken from itemfunc.php3
    # file is copied to subdirectory of IMG_UPLOAD_PATH named as slice_id
    $dirname = IMG_UPLOAD_PATH. $GLOBALS["slice_id"];
	$dest_file = basename($DefFile);

    if( !is_dir( $dirname ))
      if( !mkdir( $dirname, IMG_UPLOAD_DIR_MODE ) ){
        return L_CANT_CREATE_IMG_DIR;
      }    

    if( file_exists("$dirname/$dest_file") )
      $dest_file = new_id().substr(strrchr($dest_file, "." ), 0 );

    # copy the file from the temp directory to the upload directory, and test for success    
    if(!copy($DefFile,"$dirname/$dest_file")) {
      return L_CANT_UPLOAD;
    }  
	
	$fd = fopen ("$dirname/$dest_file", "r");

	if (!$fd) {
		MsgPage($sess->url(self_base())."index.php3", L_E_IMPORT_OPEN_ERROR, "standalone");
		exit;
	}
	
	$definition = "";
	while (!feof ($fd)) {
	    $definiton = $definition + fgets($fd, 4096);
	}
	fclose ($fd);
}

*/

// Prooves whether this ID already exists in the slices table,
// changes the ID to a new chosen one

function proove_ID (&$slice) 
{
	global $newID,
		   $sess,
		   $showme,
		   $resolve_conflicts,
		   $overwrite;
		   
	$res = $resolve_conflicts[$slice["id"]];
	//$showme .= "Resolve:".$slice["id"]."=".$res." ".strlen($res)."\n";
	if (strlen($res) == 32)	{
		$res = pack_id($res);
		if (strlen($res) == 16)	$slice["id"] = unpack_id($res);
	}

	// Find out whether a slice of the same ID already exists
	if (strlen($slice["id"]) != 32) {
		MsgPage($sess->url(self_base())."index.php3", L_E_IMPORT_WRONG_FILE, "standalone");
		exit;
	}		
	$slice_id = addslashes(pack_id($slice["id"]));
	$SQL = "SELECT * FROM slice WHERE id=\"$slice_id\"";
	global $db;
	$db->query($SQL);
	if($db->next_record()) {
		if ($GLOBALS["Submit"] == L_E_IMPORT_OVERWRITE) {
			$SQL = "DELETE FROM slice WHERE id='$slice_id'";	$db->query($SQL);
			$SQL = "DELETE FROM field WHERE slice_id='$slice_id'";	$db->query($SQL);
			$overwrite = true;
		}
		else return false;
	}
	else $overwrite = false;
	return true;
}

function create_SQL_insert_statement ($fields, $table, $pack_fields = "")
{
	$sqlfields = "";
	$sqlvalues = "";
	reset($fields);	
	while (list($key,$val) = each($fields)) {
		if (!is_array($val) && !is_int ($key)) {
			if ($sqlfields > "") {
				$sqlfields .= ",\n";
				$sqlvalues .= ",\n";
			}
			$sqlfields .= $key;
		
			if (strstr($pack_fields,";".$key.";"))
				$val = pack_id ($val);
			$sqlvalues .= '"'.addslashes($val).'"';
		}
	}
	return "INSERT INTO ".$table." (".$sqlfields.") VALUES (".$sqlvalues.")";
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
		   $overwrite;	
//	$GLOBALS["showme"] = serialize( $slice );
	$IDconflict = !proove_ID($slice);
	if ($IDconflict) {
		$conflicts_ID[$slice["id"]] = $slice["name"];
		$Cancel = 0;
		return false;
	}

	$sqltext = create_SQL_insert_statement ($slice, "slice", ";id;owner;")."\n";
	//$showme = $sqltext;
	$db->query($sqltext);
	$fields = $slice["fields"];
	reset($fields); 
	while (list(,$curf) = each($fields)) {
		$curf["slice_id"] = $slice["id"];
		$sqltext = create_SQL_insert_statement ($curf, "field", ";slice_id;")."\n";
		$db->query($sqltext);
		//$showme .= $sqltext;
	}
	if ($overwrite)
		$overwritten_list[] = $slice["name"]." (id:".$slice["id"].")";
	else
		$imported_list[] = $slice["name"]." (id:".$slice["id"].")";
	$Cancel = "OHYES";
}

if ($Cancel)
	go_url( $sess->url(self_base() . "index.php3"));	

$IDconflict = false;
$slice_def_bck = $slice_def = stripslashes($slice_def);
$imported_count = 0;
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
/*  	$show["sliceimp"] = false;
	require $GLOBALS[AA_INC_PATH]."se_inc.php3"; //show navigation column depending on $show 
*/?>
<?php echo $pom ?>
<form name=formular method=post action="<?php echo $sess->url("sliceimp.php3") ?>" 
enctype="multipart/form-data">

<h1><b><?php echo L_E_IMPORT_TITLE.$pom?></b></h1>

<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit>
<?php 
if ($Cancel || $conflicts_list):
	echo "<B>".sprintf(L_E_IMPORT_COUNT,count($imported_list)+count($overwritten_list))."</p>";
	echo $showme;
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
	?>
	</P>
	<INPUT TYPE=SUBMIT NAME=Cancel VALUE="  OK  ">
<?php
else:
?>

<?php echo L_E_IMPORT_MEMO ?></p>
<?php
/* There is some problem with open file permitions, I will use Text area instead ...
<P align=center><INPUT TYPE=FILE NAME=definition SIZE=60></P> 
<input type="hidden" name="MAX_FILE_SIZE" value="100000">
*/
?>
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
	</P>	
	<p align=center>
	<INPUT TYPE=SUBMIT NAME=Submit VALUE="<?php echo L_E_IMPORT_OVERWRITE ?>">
	<INPUT TYPE=SUBMIT NAME=Submit VALUE="<?php echo L_E_IMPORT_SEND ?>">
	<INPUT TYPE=SUBMIT NAME=Cancel VALUE="<?php echo L_CANCEL ?>">
	</p>
	</td></tr>
<?php
endif;?>
<tr><td class=tabtit align=center>
<TEXTAREA NAME="slice_def" ROWS = 10 COLS = 100>
<?php if ($IDconflict) echo $slice_def_bck ?>
<?php echo $showme ?>
</TEXTAREA>
</p>
<?php 
if (!$IDconflict): ?>
	<INPUT TYPE=SUBMIT NAME=Submit VALUE="<?php echo L_E_IMPORT_SEND ?>">
	<INPUT TYPE=SUBMIT NAME=Cancel VALUE="<?php echo L_CANCEL ?>">
<?php
endif;
endif; //if ($cancel || $coflicts_list)?>
</form>
</tr></td>
</table>

</BODY>
</HTML>

<?PHP
/*
$Log$
Revision 1.2  2001/10/05 10:51:29  honzam
Slice import/export allows backup of more slices, bugfixes

Revision 1.1  2001/10/02 11:33:54  honzam
new sliceexport/import feature

*/
?>

	