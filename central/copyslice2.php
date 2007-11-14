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

require_once dirname(__FILE__). "/include/init_central.php";
require_once AA_INC_PATH.     'formutil.php3';
require_once AA_INC_PATH.     'files.class.php3';
require_once AA_INC_PATH.     "msgpage.php3";

page_open(array("sess" => "AA_CP_Session", "auth" => "AA_CP_Auth"));

if (!IsSuperadmin()) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You don't have permissions to synchronize slices."), "admin");
  exit;
}

set_time_limit(360);

// ActionApps to synchronize
$aas = AA_Actionapps::getArray();

if ($_POST['copy']) {
    $template_slice_defs = $aas[$_POST['template_aa']]->requestDefinitions('Slice', $_POST['sync_slices']);
    $template_site_defs  = $aas[$_POST['template_aa']]->requestDefinitions('Site',  $_POST['sync_sites']);
    
    foreach ($_POST['destination_aa'] as $dest_aa) {
        if (is_array($_POST['sync_slices'])) {
            foreach ($_POST['sync_slices'] as $sid) {
                if ($sid) {
                    $aas[$dest_aa]->importModule($template_slice_defs[$sid]);
                }
            }
        }
        if (is_array($_POST['sync_sites'])) {
            foreach ($_POST['sync_sites'] as $sid) {
                if ($sid) {
                    $aas[$dest_aa]->importModule($template_site_defs[$sid]);
                }
            }
        }
    }
}

HtmlPageBegin('default', true);   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
FrmJavascriptFile( 'javascript/aajslib.php3?sess_name='.$sess->classname .'&sess_id='.$sess->id );

?>
<TITLE><?php echo _m("Central - Copy Slice (2/2) - Slices to Copy"); ?></TITLE>
</HEAD>
<BODY>
<?php
$useOnLoad = false;
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "central", "copyslice");

echo "<H1><B>" . _m("Central - Copy Slice (2/2) - Slices to Copy") . "</B></H1>";
PrintArray($err);
echo $Msg;

// ActionApps to synchronize
$aas_array = array();
foreach ( $aas as $k => $aa ) {
    $aas_array[$k] = $aa->getName();
}

// Template slice (and site) - grab from remote AA
$template_slices = $aas[$_POST['template_aa']]->requestModules(array('S'));
$template_sites  = $aas[$_POST['template_aa']]->requestModules(array('W'));

$form_buttons = array("copy"      => array( "type"      => "submit",
                                            "value"     => _m("Copy"),
                                            "accesskey" => "C"),
                      "template_aa"     => array( "value"     =>  $_POST['template_aa'])
                     );

?>
<form name=f method=post action="<?php echo $sess->url(self_base() ."copyslice2.php") ?>">
<?php

FrmTabCaption('', '','', $form_buttons);
FrmStaticText(_m('Template ActionApps'), $aas[$_POST['template_aa']]->getName());
FrmTabSeparator(_m('Modules to Copy from %1', array($aas[$_POST['template_aa']]->getName())));
FrmInputMultiSelect('sync_slices[]', _m('Slices to copy'), $template_slices, $_POST['sync_slices'], 10);
FrmInputMultiSelect('sync_sites[]', _m('Site modules to copy'), $template_sites, @reset($_POST['sync_sites']), 10);
FrmInputMultiSelect('destination_aa[]', _m('Destination AAs'), $aas_array, @reset($_POST['destination_aa']), 20, false, true, _m('ActionApps installation to update'));
FrmTabEnd($form_buttons, $sess, $slice_id);
?>
</FORM>
<?php
HtmlPageEnd();
page_close();

?>
