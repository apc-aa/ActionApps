<?php
require "../include/config.php3";
require $GLOBALS[AA_INC_PATH]."locsess.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require "../include/constedit_util.php3";

if (!$group_id) {
	echo
	"<HTML><BODY><B>Error: You must supply a group_id to this script.</B></BODY></HTML>";
	exit ();
}

function printConst ($arr) {
	global $hcCol;
	$desc = $arr[$hcCol["Desc"]];
	if ($desc) $desc = " - ".$desc;
	echo "<LI><B>".$arr[$hcCol["Name"]]."</B>".$desc."</LI>";
	if (count ($arr) > $hcCol["Child"]) {
		echo "<UL>";
		for ($i=0; $i < count($arr[$hcCol["Child"]]); ++$i) 
			printConst ($arr[$hcCol["Child"]][$i]);
		echo "</UL>";
	}
}	

echo "<HTML><BODY>
<h1>List of all constants</h1>
<b>You may search with Ctrl+F in the list.</b><br><br>";

createConstsArray ($group_id, true, $myconsts);
eval ('$data = '.$myconsts.';');
echo "<UL>";
for ($i=0; $i < count($data); ++$i)
	printConst ($data[$i]);
echo "</UL>";

echo "</BODY></HTML>";
?>
