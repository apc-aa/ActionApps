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

$directory_depth = "../";
require_once "../../include/init_page.php3";
require_once AA_INC_PATH . "msgpage.php3";
require_once AA_INC_PATH . "modutils.php3";


if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

if ($del) {
    if (!IsSuperadmin()) {
        MsgPage($sess->url(self_base())."index.php3", L_NO_PS_DEL);
        exit;
    }
} else {
    MsgPage($sess->url(self_base())."index.php3", L_NO_MODULE);
    exit;
}

$err["Init"] = "";      // error array (Init - just for initializing variable
$p_del       = q_pack_id($del);

// check if module can be deleted
ExitIfCantDelete( $del );

// delete module (from common module table)
DeleteModule( $del );

// delete all module specific tables

// find all polls for this module
$delim      = "";
$polls_list = "";
$SQL        = "SELECT id FROM polls WHERE module_id='$p_del'";
$db->query($SQL);
while ( $db->next_record() ) {
    $polls_list .= $delim ."'". $db->f('id') ."'";
    $delim = ', ';
}

if ( $polls_list ) {
    $SQL = "DELETE LOW_PRIORITY FROM polls_ip_lock WHERE poll_id IN ($polls_list)";
    $db->query($SQL);

    $SQL = "DELETE LOW_PRIORITY FROM polls_answer WHERE poll_id IN ($polls_list )";
    $db->query($SQL);

/** @todo - delete all logs for the polls */
//    $SQL = "DELETE LOW_PRIORITY FROM polls_log WHERE id IN ($polls_list)";
//    $db->query($SQL);
}

$SQL = "DELETE LOW_PRIORITY FROM polls WHERE id='$p_del'";
$db->query($SQL);

$SQL = "DELETE LOW_PRIORITY FROM polls_design WHERE module_id='$p_del'";
$db->query($SQL);

// delete module from permission system
DelPermObject($del, "slice");  // the word 'slice' is not mistake - do not change

page_close();                                // to save session variables
go_url(con_url($sess->url(AA_INSTAL_PATH . "admin/slicedel.php3"), "Msg=".rawurlencode(L_DELSLICE_OK)));

?>
