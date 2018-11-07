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


require_once __DIR__. "/include/init_central.php";
require_once AA_INC_PATH.     'formutil.php3';
require_once AA_INC_PATH.     "msgpage.php3";

pageOpen();

if (!IsSuperadmin()) {
  MsgPageMenu(StateUrl(self_base())."index.php3", _m("You don't have permissions to synchronize slices."), "admin");
  exit;
}

$apage = new AA_Adminpageutil('central', 'synchronize');
$apage->setTitle(_m("Central - Synchronize ActionApps (1/3) - Select ActionApps for Comparison"));
$apage->setForm();
$apage->printHead($err, $Msg);

$aas       = AA_Actionapps::getArray();
$aas_array = array();
foreach ( $aas as $k => $aa ) {
    $aas_array[$k] = $aa->getName();
}

$form_buttons = array ("submit");
?>
<form name=f method=post action="<?php echo StateUrl(self_base() ."synchronize2.php") ?>">
<?php
FrmTabCaption('', '', '', $form_buttons, $slice_id);
FrmInputSelect('template_aa', _m('Template ActionApps'), $aas_array, $_POST['template_aa'], true, _m('ActionApps installation used as template'));
FrmInputSelect('comparation_aa', _m('AA to compare'),    $aas_array, $_POST['comparation_aa'], true, _m('ActionApps installation to check for differences (just checking right now - noting is changed by this step)'));
FrmTabEnd($form_buttons);
?>
</form>
<?php

$apage->printFoot();
