<?php
//$Id$
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

/*	Author: Jakub Adámek
    
    Params: $list
    
    Shows all the help to all the list items (i.e. all alias functions, all field types)
*/	

require "../include/en_news_lang.php3";
require "../include/en_param_wizard_lang.php3";
require "../include/constants_param_wizard.php3";
require "../include/util.php3";
require "../include/mgettext.php3";

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<TITLE>"._m("Param Wizard Summary")."</TITLE>";
echo "</HEAD><BODY>";

$list = $$mylist;
if (!is_array ($list)) { echo "Wrong value of param mylist (use ?mylist=INPUT_TYPES or ?mylist=FIELD_FUNCTIONS)"; exit; }
echo "<a id='top'>";
echo "<h1>".$list["name"]."s</h1>";
if ($list["hint"]) echo $list["hint"]."<br>";

ksort ($list["items"]);
reset ($list["items"]);
echo "<table border=0>";
$bottom_row = "<a href='top'>"._m("TOP")."</a>";
while (list ($name, $item) = each ($list["items"])) {
    echo "<tr><td><b>$name</b></td><td><a href='#$name'>$item[name]</a></td></tr>";
    $bottom_row .= " - <a href='#$name'>$name</a>";
}
echo "</table>";    
    
reset ($list["items"]);
while (list ($name, $item) = each ($list["items"])) {
    echo "<hr>";
    echo "<h2 id=$name>$name: $item[name]</h2>";
    echo processSlashes ($item[desc]). "<br>";
    $params = $item["params"];
    if (is_array ($params)) {
        echo "<br>"._m("Parameters:");
        echo "<TABLE border=1 cellpadding=3>";
        echo "<TR>"
                ."<TD><I>"._m("name")."</I></TD>"
                ."<TD><I>"._m("type")."</I></TD>"
                ."<TD><I>"._m("description")."</I></TD>"
                ."<TD><I>"._m("example")."</I></TD></TR>";
        reset ($params);
        while (list (,$param) = each ($params)) {
            echo "<TR>";
            echo "<TD><B>".nl($param[name])."</B></TD><TD>";
			switch($param[type]) {
			case "INT":  echo L_PARAM_WIZARD_TYPE_INT; break;
			case "STR":  echo L_PARAM_WIZARD_TYPE_STR; break;
			case "STRID":echo L_PARAM_WIZARD_TYPE_STRID; break;
			case "BOOL": echo L_PARAM_WIZARD_TYPE_BOOL; break;
            default : echo "&nbsp;";
			}
            echo "</TD>
              <TD>".nl(processSlashes($param[desc]))."</TD>
              <TD>".nl($param[example])."</TD></TR>";
        }
        echo "</TABLE>";
    }
    echo "<font size=-1><br>$bottom_row</font>";
}   

echo "</body></html>";

function nl ($s) { return $s ? $s : "&nbsp;"; }

function processSlashes ($s)
{
	$s = str_replace ("\\<", HTMLSpecialChars("<"), $s);
	$s = str_replace ("\\>", HTMLSpecialChars(">"), $s);
	return $s;
}
?>
