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


function CompareSliceDefs($template_slice_defs, $comp_slice_defs, $mapping) {
    // resulting array of all differences between selected slices
    $differences = array();
    if ( is_array($template_slice_defs) ) {
        foreach ( $template_slice_defs as $tmp_slice_name => $slice_def ) {
            $cmp_slice_name = $mapping[$tmp_slice_name];
            $differences[$tmp_slice_name] = array();
            if ( empty($comp_slice_defs[$cmp_slice_name]) ) {
                $differences[$tmp_slice_name][] = new AA_Difference(_m('Comparation slice (%1) noes not exist', array($tmp_slice_name)));
            }
            $differences[$tmp_slice_name] = array_merge($differences[$tmp_slice_name], $slice_def->compareWith($comp_slice_defs[$cmp_slice_name]));
        }
    }
    return $differences;
}



page_open(array("sess" => "AA_CP_Session", "auth" => "AA_CP_Auth"));

if (!IsSuperadmin()) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You don't have permissions to synchronize slices."), "admin");
  exit;
}

// ActionApps to synchronize
$aas = AA_Actionapps::getArray();
$slices4template = array();
$slices2compare  = array();

if ($_POST['compare']) {
    foreach($_POST['sync_slices'] as $slice_tmp => $slice_cmp) {
        if ($slice_cmp) {
            $slices4template[] = $slice_tmp;
            $slices2compare[]  = $slice_cmp;
        }
    }
    
    $template_slice_defs = $aas[$_POST['template_aa']]->requestSliceDefinitions($slices4template);
    if (isset($_POST['comparation_aa']) ) {
        $comp_slice_defs = $aas[$_POST['comparation_aa']]->requestSliceDefinitions($slices2compare);
        // now compare slices
        $differences = CompareSliceDefs($template_slice_defs, $comp_slice_defs, $_POST['sync_slices']);
    }
}

if ($_POST['synchronize']) {
    if (is_array($_POST['destination_aa']) ) {
        foreach ($_POST['destination_aa'] as $dest_aa) {
            $sync_result[$aas[$dest_aa]->getName()] = $aas[$dest_aa]->synchronize($_POST['sync']);
        }
    }
    huhl($sync_result);
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
FrmJavascriptFile('javascript/aajslib.php3');

?>
<TITLE><?php echo _m("Central - Synchronize ActionApps (3/3) - Synchronize Slices"); ?></TITLE>
</HEAD>
<BODY>
<?php
$useOnLoad = true;
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "central", "synchronize");

echo "<H1><B>" . _m("Central - Synchronize ActionApps (3/3) - Synchronize Slices") . "</B></H1>";
PrintArray($err);
echo $Msg;

// ActionApps to synchronize
$aas_array = array();
foreach ( $aas as $k => $aa ) {
    $aas_array[$k] = $aa->getName();
}

// slices to compare
$tmp_slices = $aas[$_POST['template_aa']]->requestSlices();

// in synchronization we are working with !!names!! not ids
foreach ($tmp_slices as $sid => $name) {
    $template_slices[$name] = $name;
}

$form_buttons = array("synchronize"  => array( "type"      => "submit",
                                               "value"     => _m("Synchronize"),
                                               "accesskey" => "S"),
                      "template_aa"    => array( "value"     =>  $_POST['template_aa']),
                      "comparation_aa" => array( "value"     =>  $_POST['comparation_aa'])
                     );
                     
?>
<form name=f method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<?php

FrmTabCaption(_m('Slice Comparison - %1 x %2', array($aas[$_POST['template_aa']]->getName(), $aas[$_POST['comparation_aa']]->getName())), '','', $form_buttons);
if ( isset($differences) ) {
    // and print diffs out
    foreach ($differences as $slice_name => $diffs) {
        FrmTabSeparator($slice_name . ' x ' . $_POST['sync_slices'][$slice_name]);
        foreach ($diffs as $diff) {
            $diff->printOut();
        }
    }
}
FrmTabSeparator(_m('Synchronize'));
FrmStaticText(_m('Template ActionApps'), $aas[$_POST['template_aa']]->getName());
FrmStaticText(_m('Compared ActionApps'), $aas[$_POST['comparation_aa']]->getName());
FrmInputMultiSelect('destination_aa[]', _m('AA to update'), $aas_array, $_POST['destination_aa'], 20, false, true, _m('ActionApps installation to update'));
//FrmInputMultiChBox('sync_slices[]', _m('Slices to synchronize'), $template_slices, $_POST['sync_slices'], false, '', '', 3);
// prepared for multiple update
FrmTabEnd($form_buttons, $sess, $slice_id);
?>
</FORM>
<?php
HtmlPageEnd();
page_close();

?>
