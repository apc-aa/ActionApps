<?php 
/* 
Copyright (C) 1999, 2000 Association for Progressive Communications 
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
	Created by Jakub Adamek, January 2002
	
	Params:
		my_item_id .. short ID of the item
		fillConds=1 .. generates the fillConds code (otherwise, generate fillForm code)
		notrun=1 .. doesn't run the JavaScript function
		form=formname .. look for the controls in the form formname. If not set, will use 'f'.
	
	This script contains two similar functions:
	
	fillForm is used on anonymous posting forms
	fillConds is used on search forms 

	<h1>Function fillForm</h1>
	
   Prints a JavaScript function which refills form fields with values from database
   
   you must supply the var $my_item_id with the item id, and that item must have
   ITEM_FLAG_ANONYMOUS_EDITABLE set
   
   Works well with HTML - Plain text radio buttons and with Dates represented by 3 select boxes
   
   <h1>Function fillConds</h1>

   Prints a JavaScript function which refills form fields with name conds[][] with 
	 previous values

   uses the array conds and function setControlOrAADate - see fillformutils to get a feel
   about which form control types are supported (most of them are)

   special feature: array dateConds may contain names of textfields which are 
   dates represented by 3 select boxes in the way of AA. E.g. by fillConds:

   dateConds[3]="mydate" means:
   
   conds[3][value] is a date in some strtotime format, mydate_day is the select
   box cotaining day, mydate_month contains month, mydate_year contains year 
   
*/

	if (!isset ($form)) $form = "f";
?>

<?
require "./include/config.php3";
require $GLOBALS[AA_INC_PATH]."locsess.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."itemfunc.php3";
require $GLOBALS[AA_INC_PATH]."notify.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."date.php3";
require $GLOBALS[AA_INC_PATH]."feeding.php3";

// core JavaScript functions
require $GLOBALS[AA_INC_PATH]."fillformutils.php3"; 

# init used objects
$db = new DB_AA;
$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$itemvarset = new Cvarset();

add_vars();
?>

<?php 
/* gives JavaScript filling the AA date 3 selectboxes
	params: $mydate .. UNIX timestamp
			$dateField .. field name
*/

/* * * * * * * * * * * FILL CONDS * * * * * * * * * */

function fillConds () {
	global $form, $conds, $dateConds;
	
	echo "function fillConds () {";
	
	if (is_array ($conds)) {
		reset ($conds);
		while (list ($i,$cond) = each ($conds)) {
			if (isset ($cond["value"]))
				echo "setControl ('$form','conds[$i][value]',\"".$cond["value"]."\");\n";
		}
	}
	
	if (is_array ($dateConds)) {
		reset ($dateConds);
		while (list($i,$dateField) = each ($dateConds)) {
			if (isset ($conds[$i][value]) && $conds[$i][value]) 
				echo "setControlOrAADate ('$form','$dateField','".
					strtotime($conds[$i][value])."','',0);\n";
		}
	}
	echo "\n}\n";
	
	if (!isset ($notrun)) echo "\n fillConds ();\n";
}

/* * * * * * * * * * * FILL FORM * * * * * * * * * */

function safeChars ($str) {
  for ($i=0; $i < strlen ($str); ++$i)
    if (ord($str[$i]) > 31) 
		if ($str[$i] == '"') $retVal .= '\\"';
		else $retVal .= $str[$i];
  return $retVal;
}

function fillForm () {
	global $form, $conds, $dateConds, $my_item_id, $db;

	$item_pid = addslashes(pack_id($my_item_id));
	$SQL = "SELECT * FROM item WHERE id='$item_pid'";
	$db->query($SQL);
	if (!$db->next_record()) return;
	# are we allowed to update this item?
	if (!($db->f("flags") & ITEM_FLAG_ANONYMOUS_EDITABLE)) {
		echo "<!-- This item isn't allowed to be changed anonymously. -->";
		return;
	}
	
    $oldcontent = GetItemContent($my_item_id);
    $oldcontent4id = $oldcontent[$my_item_id];   

	echo "
	function fillForm () 
	{";
	
	if (is_array ($oldcontent4id)) {
		reset ($oldcontent4id);
		while (list ($field_id,$field_array) = each ($oldcontent4id)) {
			reset ($field_array);
			while (list (,$field) = each ($field_array)) {
				$myvalue = safeChars ($field[value]);
				$control_id = 'v'.unpack_id ($field_id);
				if ($myvalue != "") 
					echo "setControlOrAADate ('$form','$control_id','$myvalue','tdctr_','".
					($field[flag] & FLAG_HTML ? "h" : "t")."');\n";
			}
		}
	}
	
	echo "}\n";
	
	if (!isset ($notrun)) echo "\n fillForm ();\n";
}
?>

<SCRIPT language=JavaScript>
<!--

<?php
if (isset($fillConds)) 
	fillConds();
else
	fillForm ();
?>

// -->
</SCRIPT>

