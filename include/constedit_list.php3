<?php
require_once "../include/config.php3";
require_once $GLOBALS["AA_INC_PATH"]."locsess.php3";
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
require_once "../include/constedit_util.php3";

if (!$group_id) {
	echo
	"<HTML><BODY><B>Error: You must supply a group_id to this script.</B></BODY></HTML>";
	exit ();
}

function printConst ($arr) {
	global $hcCol;
	$desc = $arr[$hcCol["Desc"]];
        //die(var_dump($arr[$hcCol["Child"]]));
	if ($desc) $desc = " - ".$desc;
	echo "<LI><B>".$arr[$hcCol["Name"]]."</B>".$desc."</LI>";
	if (count ($arr) > $hcCol["Child"]) {
		echo "<UL>";
                usort($arr[$hcCol["Child"]],"cmp");
		for ($i=0; $i < count($arr[$hcCol["Child"]]); ++$i) 
			printConst ($arr[$hcCol["Child"]][$i]);
		echo "</UL>";
	}
}	
function cmp($a,$b) {
        global $hcCol;
	return (strcmp($a[0],$b[0]));
}

?>
<HTML><head><link rel="stylesheet" href="/einstyle.css" type="text/css"></head><BODY>
<table width="100%" border="0" cellspacing="0" cellpadding="5">
        <tr> 
          <td class="pageheading" valign="bottom">Glossary of index terms</td>
        </tr>
      </table>
<hr color="#CC3300" width="100%" size="1" noshade align="center">
<span class=bodytext><b>Tip:</b> In order to search through this long list please use CTRL-F
 on your keyboard and type in the word or phrase you are looking for.</span><p>
<?php
createConstsArray ($group_id, true, $myconsts);
eval ('$data = '.$myconsts.';');
echo "<UL>";
usort($data,"cmp");
for ($i=0; $i < count($data); ++$i)
	printConst ($data[$i]);
echo "</UL>";

echo "</BODY></HTML>";
?>
