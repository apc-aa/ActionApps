<?php

//$Id$
/*
  Copyright (C) 1999, 2000 Association for Progressive Communications
  https://www.apc.org/

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

require_once __DIR__."/../include/config.php";
require_once __DIR__ . "/../include/util.php";
require_once __DIR__ . "/../include/mgettext.php";
mgettext_bind(\AA\Util\Langs::currentLang(), 'param_wizard');
require_once __DIR__ . "/../include/constants_param_wizard.php";

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<TITLE>" . _m("Param Wizard Summary") . "</TITLE>";
echo "<style> a {color: #CCCCFF}; </style>
    </HEAD><BODY>";

$list = $$mylist;

$lists = ["INPUT_TYPES", "FIELD_FUNCTIONS", "INSERT_TYPES", "DEFAULT_VALUE_TYPES"];

if (!is_array($list)) {
    echo "<h1>" . _m("Choose a Parameter Wizard") . "</h1>";
    echo '<FORM action="param_wizard_list.php" method="get">';
    echo '<SELECT name=mylist MULTIPLE size="' . count($list) . '" style=".">';
    foreach ($lists as $mylist) {
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

echo '<FORM action="param_wizard_list.php" method="get">' . "\n";
echo _m("Change to: ") . "<SELECT name=mylist>\n";
foreach ($lists as $xlist) {
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
echo "<table>\n";
$bottom_row = "<a href='#top'>" . _m("TOP") . "</a>\n";
foreach ($list["items"] as $name => $item) {
    echo "<tr><td><b>$name</b></td><td><a href='#$name'>$item[name]</a></td></tr>\n";
    $bottom_row .= " - <a href='#$name'>$name</a>";
}
echo "</table>\n";

foreach ($list["items"] as $name => $item) {
    echo "<hr>";
    echo "<h2 id=$name>$name: $item[name]</h2>\n";
    echo processSlashes($item['desc']) . "<br>\n";
    $params = $item["params"];
    if (is_array($params)) {
        echo "<br>" . _m("Parameters:");
        echo "<TABLE>";
        echo "<TR>"
        . "<TD><I>" . _m("name") . "</I></TD>"
        . "<TD><I>" . _m("type") . "</I></TD>"
        . "<TD><I>" . _m("description") . "</I></TD>"
        . "<TD><I>" . _m("example") . "</I></TD></TR>\n";
        foreach ($params as $param) {
            echo "<TR>";
            echo "<TD><B>" . nl($param['name']) . "</B></TD><TD>";
            echo match ($param['type']) {
                "INT"   => " ("._m("integer number").")",
                "STR"   => " ("._m("any text").")",
                "STRID" => " ("._m("field id").")",
                "BOOL"  => " ("._m("boolean: 0=false,1=true").")",
                default => "&nbsp;",
            };
            echo "</TD>
              <TD>" . nl(processSlashes($param['desc'])) . "</TD>
              <TD>" . nl($param['example']) . "</TD></TR>\n";
        }
        echo "</TABLE>\n";
    }
    echo "<span style=\"font-size: smaller; \"><br>$bottom_row</span>\n";
}

echo "</body></html>";

function nl($s) {
    return $s ?: "&nbsp;";
}

function processSlashes($s) {
    $s = str_replace("\\<", safe("<"), $s);
    $s = str_replace("\\>", safe(">"), $s);
    return $s;
}


