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


// used in init_page.php script to include config.php from the right directory

// const  AA_REGISTER_GLOBALS = true;
use AA\Util\InputVars;

require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../modules/links/constants.php";
require_once __DIR__ . "/../../modules/links/cattree.php";
require_once __DIR__ . "/../../modules/links/util.php";      // module specific utils

try {
    $start = InputVars::intGET('start') ?: 1;
} catch (Exception) {}

function PrintCategory( $id, $name, $base, $state, $parent, $level ) {
    echo "<br>";
    while ( $level-- ) {
        echo '.&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
    }
    echo " <a href=\"javascript:ReturnParam('$id')\">$name</a> $base";
}

$tree = new cattree( $start );

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '  <title>'. _m('Category Tree') .'</title>';
IncludeManagerJavascript();
echo '
</head>
<body>
  <p>'. _m('Select category'). '</p>';

$tree->update();
$tree->walkTree($start, 'PrintCategory');

echo '</BODY></HTML>';

