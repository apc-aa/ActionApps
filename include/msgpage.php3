<?php
/**
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/
//
// Message page with menu. Can't be in util.php3 since of the menu usage.
//

if (!defined ("AA_MSGPAGE_INCLUDED")) {
    define("AA_MSGPAGE_INCLUDED","1");
} else {
    return;
}

require_once AA_INC_PATH."constants.php3";
if ( isset($g_modules[$slice_id])) {
    require_once menu_include();   //show navigation column depending on $show
} else {
    require_once AA_INC_PATH."menu.php3";
}


/** MsgPageMenu function
 *  Displays page with message and link to $url
 * @param $url - where to go if user clicks on Back link on this message page
 * @param $msg - displayed message
 * @param $mode - items/admin/standalone for surrounding of message
 * @param $menu
 */
function MsgPageMenu($url, $msg, $mode, $menu="") {
  global $sess, $auth, $slice_id, $aamenus;

  if ( !isset($sess) ) {
    require_once AA_INC_PATH . "locauth.php3";
    pageOpen();
  }

  HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
  ?>
  <title><?php echo _m("Toolkit news message") ?></title>
  </head>
  <body>

  <?php

  switch( $mode ) {
    case "items":    // Message page on main page (index.php3) or such page
    case "sliceadmin":
      showMenu($aamenus, "sliceadmin", $menu);
      break;
    case "admin":    // Message page on admin pages (se_*.php3) or such page
      showMenu($aamenus, "aaadmin", $menu);
      break;
  }

  if ( isset($msg) AND is_array($msg)) {
      PrintArray($msg);
  } else {
      echo "<p>$msg</p><br><br>";
  }
  echo "<a href=\"$url\">"._m("Back")."</a>";
  HTMLPageEnd();
  page_close();
  exit;
}

?>
