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

$aas = AA_Actionapps::getArray();


$apage = new AA_Adminpageutil('central', 'copyslice');
$apage->setTitle( _m("Central - Copy Slice (1/2) - Select Source ActionApps") );
$apage->setForm(array('action'=>'copyslice2.php'));
$apage->printHead($err, $Msg);


$aas_array = array();
foreach ( $aas as $k => $aa ) {
    $aas_array[$k] = $aa->getName();
}

$form_buttons = array ("submit");
FrmTabCaption('', '', '', $form_buttons);
FrmInputSelect('template_aa', _m('Template ActionApps'), $aas_array, $_POST['template_aa'], true, _m('ActionApps installation used as template'));
FrmTabEnd($form_buttons);

$apage->printFoot();