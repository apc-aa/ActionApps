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

require_once "../include/init_page.php3";
require_once AA_INC_PATH. 'formutil.php3';
require_once AA_INC_PATH. 'remote.class.php3';
require_once AA_INC_PATH. 'files.class.php3';

if (!IsSuperadmin()) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You don't have permissions to synchronize slices."), "admin");
  exit;
}

huhl($sess->id, '-');
if ($submit) {
   $r_remote = new AA_RemoteCommunicator(Files::makeFile($remote_url, 'admin/aa_synchronize_remote.php3'));
   if ($r_remote->authenticate($remote_user, $remote_pwd)) {
       huhl($r_remote, $sess->id);
       $sess->register('r_remote');
       exit;
       go_url($sess->url(self_base() . "aa_synchronize2.php3"));
       exit;
   }
   $Msg = _m('Authentification failed, try again'); 
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<TITLE><?php echo _m("Admin - Synchronize ActionApps"); ?></TITLE>
<SCRIPT Language="JavaScript"><!--
function InitPage() {}
//-->
</SCRIPT>
</HEAD>
<BODY>
<?php
$useOnLoad = true;
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "aaadmin", "synchronize");

echo "<H1><B>" . _m("Admin - Synchronize ActionApps (1/3) - Destination ActionApps") . "</B></H1>";
PrintArray($err);
echo $Msg;

$form_buttons = array ("submit");
//$destinations = array_flip(array_unique($COLNODO_DOMAINS));

?>
<form name=f method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<?php
FrmTabCaption('', '','', $form_buttons, $sess, $slice_id);
FrmStaticText(_m('Template ActionApps (current)'), $this_colnodo_domain);

FrmInputText("remote_url",  _m("Remote ActionApps URL"),      $remote_url,  255, 60, true, _m("like http://example.org/apc-aa/"));
FrmInputText("remote_user", _m("Remote Superadmin Username"), $remote_user, 255, 60, true);
FrmInputPwd("remote_pwd", _m("Remote Superadmin Password"), $remote_pwd, 255, 60, true);

// FrmInputSelect("destination", _m("Select destination ActionApps"), $destinations, $destination, true, _m("The list is taken from config.php3 file of ActionApps"));

FrmTabEnd($form_buttons, $sess, $slice_id);
?>
</FORM>
<?php
HtmlPageEnd();
page_close()
?>
