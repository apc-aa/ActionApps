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

/* 	Author: Jakub Adámek

  Params: $list

  Shows all the help to all the list items (i.e. all alias functions, all field types)
 */

require_once "../include/config.php3";
require_once "../include/util.php3";
require_once "../include/mgettext.php3";
mgettext_bind(get_mgettext_lang(), 'param_wizard');
require_once "../include/constants_param_wizard.php3";

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<TITLE>" . _m("Param Wizard Summary") . "</TITLE>";
echo "<style> a {color: #CCCCFF}; </style>
    </HEAD><BODY>";

$list = $$mylist;

$lists = array("INPUT_TYPES", "FIELD_FUNCTIONS", "INSERT_TYPES", "DEFAULT_VALUE_TYPES");

if (!is_array($list)) {
    echo "<h1>" . _m("Choose a Parameter Wizard") . "</h1>";
    echo '<FORM action="param_wizard_list.php3" method="get">';
    echo '<SELECT name=mylist MULTIPLE size="' . count($list) . '" style=".">';
    reset($lists);
    while (list (, $mylist) = each($lists)) {
        $list = $$mylist;
        echo "<OPTION value='$mylist'>" . $list["name"] . "s";
    }
    echo "</SELECT><BR><br>";
    echo "<INPUT TYPE=submit value='" . _m("Go") . "'>&nbsp;";
    echo "</FORM></BODY></HTML>";
    exit;
}

echo "<a id='top'> </a>";
echo "<h1>" . $list["name"] . "s</h1>\n";

echo '<FORM action="param_wizard_list.php3" method="get">' . "\n";
echo _m("Change to: ") . "<SELECT name=mylist>\n";
reset($lists);
while (list (, $xlist) = each($lists)) {
    if ($xlist == $mylist)
        continue;
    $xxlist = $$xlist;
    echo "<OPTION value='$xlist'>" . $xxlist["name"] . "s\n";
}
echo "</SELECT>&nbsp;\n";
echo "<INPUT TYPE=submit value='" . _m("Go") . "'></FORM>\n";

if ($list["hint"])
    echo $list["hint"] . "<br>";

ksort($list["items"]);
reset($list["items"]);
echo "<table border=0>\n";
$bottom_row = "<a href='#top'>" . _m("TOP") . "</a>\n";
while (list ($name, $item) = each($list["items"])) {
    echo "<tr><td><b>$name</b></td><td><a href='#$name'>$item[name]</a></td></tr>\n";
    $bottom_row .= " - <a href='#$name'>$name</a>";
}
echo "</table>\n";

reset($list["items"]);
while (list ($name, $item) = each($list["items"])) {
    echo "<hr>";
    echo "<h2 id=$name>$name: $item[name]</h2>\n";
    echo processSlashes($item[desc]) . "<br>\n";
    $params = $item["params"];
    if (is_array($params)) {
        echo "<br>" . _m("Parameters:");
        echo "<TABLE border=1 cellpadding=3>";
        echo "<TR>"
        . "<TD><I>" . _m("name") . "</I></TD>"
        . "<TD><I>" . _m("type") . "</I></TD>"
        . "<TD><I>" . _m("description") . "</I></TD>"
        . "<TD><I>" . _m("example") . "</I></TD></TR>\n";
        reset($params);
        while (list (, $param) = each($params)) {
            echo "<TR>";
            echo "<TD><B>" . nl($param[name]) . "</B></TD><TD>";
            switch ($param[type]) {
                case "INT": echo " (" . _m("integer number") . ")";
                    break;
                case "STR": echo " (" . _m("any text") . ")";
                    break;
                case "STRID":echo " (" . _m("field id") . ")";
                    break;
                case "BOOL": echo " (" . _m("boolean: 0=false,1=true") . ")";
                    break;
                default : echo "&nbsp;";
            }
            echo "</TD>
              <TD>" . nl(processSlashes($param[desc])) . "</TD>
              <TD>" . nl($param[example]) . "</TD></TR>\n";
        }
        echo "</TABLE>\n";
    }
    echo "<font size=-1><br>$bottom_row</font>\n";
}

echo "</body></html>";

function nl($s) {
    return $s ? $s : "&nbsp;";
}

function processSlashes($s) {
    $s = str_replace("\\<", htmlspecialchars("<"), $s);
    $s = str_replace("\\>", htmlspecialchars(">"), $s);
    return $s;
}

?>
