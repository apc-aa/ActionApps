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
// expected $del - unpacked id of module to delete

require_once "../../include/init_page.php3";
require_once AA_INC_PATH . "msgpage.php3";
require_once AA_INC_PATH . "modutils.php3";


if ($cancel)
  go_url( $sess->url(self_base() . "index.php3"));


if ($del) {
  if (!IsSuperadmin()) {
    MsgPage($sess->url(self_base())."index.php3", _m("You don't have permissions to delete a links module."), "admin");
    exit;
  }
} else {
  MsgPage($sess->url(self_base())."index.php3", _m("Module not found."), "admin");
  exit;
}

$err["Init"] = "";      // error array (Init - just for initializing variable

// check if module can be deleted
ExitIfCantDelete( $del );

// delete module (from common module table)
DeleteModule( $del );

// delete all module specific tables
DB_AA::sql('DELETE LOW_PRIORITY FROM `links`', array(array('id', $del, 'l')));

// delete module from permission system
DelPermObject($del, "slice");  // the word 'slice' is not mistake - do not change

page_close();                                // to save session variables
go_url(con_url($sess->url(AA_INSTAL_PATH . "admin/slicedel.php3"), "Msg=".rawurlencode(_m("Links module successfully deleted"))));

?>

