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
	This file is under construction. I am learning to work with AA,
	and there is a lot to learn yet ...
*/

/*	
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
		   $sess;
		   
	if ($newID != "") {
		if (strlen ($newID) != 32) {
			MsgPage($sess->url(self_base())."index.php3", L_E_IMPORT_IDLENGTH.strlen($newID), "standalone");
			exit;	
		}
		else $slice["id"] = $newID;
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
		}
		else return false;
	}
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
		   $sess;	
//	$GLOBALS["showme"] = serialize( $slice );
	$IDconflict = !proove_ID($slice);
	$slice_id = $slice["id"];
	if ($IDconflict) return false;

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
	go_url( $sess->url(self_base() . "index.php3"));	
}

if ($Cancel)
	go_url( $sess->url(self_base() . "index.php3"));	

$IDconflict = false;
$slice_def_bck = $slice_def = stripslashes($slice_def);
require "./sliceimp_xml.php3";
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
<?php echo L_E_IMPORT_MEMO ?></p>
<?php
/* There is some problem with open file permitions, I will use Text area instead ...
<P align=center><INPUT TYPE=FILE NAME=definition SIZE=60></P> 
<input type="hidden" name="MAX_FILE_SIZE" value="100000">
*/
?>
<?php 
if ($IDconflict):?>
	</td></tr>
	<tr><td class=tabtxt>
	<b><?php echo sprintf (L_E_IMPORT_IDCONFLICT,pack_id($slice_id)) ?></b></p>
	<p align=center>
	<INPUT TYPE=TEXT NAME=newID SIZE=40 MAXLENGTH=32 VALUE="<?php echo $slice_id ?>">
	<INPUT TYPE=SUBMIT NAME=Submit VALUE="<?php echo L_E_IMPORT_OVERWRITE ?>">
	</td></tr>
	<tr><td class=tabtit>
	</p>
<?php
endif;?>
<TEXTAREA NAME="slice_def" ROWS = 10 COLS = 100>
<?php if ($IDconflict) echo $slice_def_bck ?>
<?php echo $showme ?>
</TEXTAREA>
</p>
<INPUT TYPE=SUBMIT NAME=Submit VALUE="<?php echo L_E_IMPORT_SEND ?>">
<INPUT TYPE=SUBMIT NAME=Cancel VALUE="<?php echo L_CANCEL ?>">
</form>
</tr></td>
</table>

</BODY>
</HTML>

<?PHP
/*
$Log$
Revision 1.1  2001/10/02 11:33:54  honzam
new sliceexport/import feature

*/
?>

	