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


# used in init_page.php3 script to include config.php3 from the right directory
$directory_depth = '../';

require_once "../../include/init_page.php3";
require_once "./constants.php3";
require_once "./cattree.php3";
require_once "./util.php3";      // module specific utils

function PrintCategory( $id, $name, $base, $state, $parent, $level ) {
    echo "<br>";
    while( $level-- )
      echo '.&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
    echo " <a href=\"javascript:ReturnParam('$id')\">$name</a> $base";
}

if ( !isset($_GET['start']) )
    $start = 1;

$tree = new cattree( $db, $start );

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '<title>'. _m('Category Tree') .'</title>
<script language="JavaScript" type="text/javascript"> <!--
  function ReturnParam(param) {
      window.opener.document.itemsform.elements[\'akce_param\'].value = param;
      window.opener.document.itemsform.submit()
      window.close();
  }
  // -->
</script>

</head>
<body>
  <p>'. _m('Select category'). '</p>';

$tree->update();
$tree->walkTree($start, 'PrintCategory');

echo '</BODY></HTML>';
?>
