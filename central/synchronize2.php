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

// ActionApps to synchronize
$aas = AA_Actionapps::getArray();

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
FrmJavascriptFile( 'javascript/aajslib.php' );

?>
<TITLE><?php echo _m("Central - Synchronize ActionApps (2/3) - Slices to Compare"); ?></TITLE>
</HEAD>
<BODY>
<?php
$useOnLoad = true;
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "central", "synchronize");

echo "<H1><B>" . _m("Central - Synchronize ActionApps (2/3) - Slices to Compare") . "</B></H1>";
PrintArray($err);
echo $Msg;

// ActionApps to synchronize
$aas_array = array();
foreach ( $aas as $k => $aa ) {
    $aas_array[$k] = $aa->getName();
}

// Template slice - grab from remote AA
$tmplate_slices = $aas[$_POST['template_aa']]->requestSlices();

// Compared slice - grab from remote AA
$cmp_slices = array_merge( array(0 => _m('do not compare')), $aas[$_POST['comparation_aa']]->requestSlices());

$form_buttons = array("compare"      => array( "type"      => "submit",
                                               "value"     => _m("Compare"),
                                               "accesskey" => "C"),
                      "template_aa"     => array( "value"     =>  $_POST['template_aa']),
                      "comparation_aa"  => array( "value"     =>  $_POST['comparation_aa'])
                     );

?>
<form name=f method=post action="<?php echo StateUrl(self_base() ."synchronize3.php") ?>">
<?php

FrmTabCaption('', '', '', $form_buttons);
FrmStaticText(_m('Template ActionApps'), $aas[$_POST['template_aa']]->getName());
FrmTabSeparator(_m('Slice Mapping'));
FrmStaticText( $aas[$_POST['template_aa']]->getName(), $aas[$_POST['comparation_aa']]->getName());
foreach($tmplate_slices as $sid => $name) {
    FrmInputSelect('sync_slices['.$sid.']', $name, $cmp_slices, $_POST['sync_slices'], true);
}
FrmTabEnd($form_buttons);
?>
</FORM>
<?php
HtmlPageEnd();
page_close();

?>
