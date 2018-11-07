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


function CompareSliceDefs($template_slice_defs, $comp_slice_defs, $mapping) {
    // resulting array of all differences between selected slices
    $differences = array();
    if ( is_array($template_slice_defs) ) {
        foreach ( $template_slice_defs as $tmp_slice_name => $slice_def ) {
            $cmp_slice_name = $mapping[$tmp_slice_name];
            $differences[$tmp_slice_name] = array();
            if ( empty($comp_slice_defs[$cmp_slice_name]) ) {
                $differences[$tmp_slice_name][] = new AA_Difference('INFO', _m('Comparation slice (%1) does not exist', array($tmp_slice_name)));
            }
            $differences[$tmp_slice_name] = array_merge($differences[$tmp_slice_name], $slice_def->compareWith($comp_slice_defs[$cmp_slice_name]));
        }
    }
    return $differences;
}

pageOpen();

if (!IsSuperadmin()) {
    MsgPageMenu(StateUrl(self_base())."index.php3", _m("You don't have permissions to synchronize slices."), "admin");
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
            if ( $slice_cmp != '1') {   // 0 - do not compare, 1 - exact copy
                $slices2compare[]  = $slice_cmp;
            }
        }
    }

    $template_slice_defs = $aas[$_POST['template_aa']]->requestDefinitions('Slice', $slices4template, true);
    if (isset($_POST['comparation_aa']) ) {
        $comp_slice_defs = $aas[$_POST['comparation_aa']]->requestDefinitions('Slice', $slices2compare, true);
        // now compare slices
        $differences = CompareSliceDefs($template_slice_defs, $comp_slice_defs, $_POST['sync_slices']);
    }
}

if ($_POST['synchronize']) {
    $toexecute = new AA_Toexecute;

    if (is_array($_POST['destination_aa']) ) {
        $no_sync_tasks = 0;
        foreach ($_POST['destination_aa'] as $dest_aa) {
            if (is_array($_POST['sync'])) {
                foreach ( $_POST['sync'] as $sync_action ) {
                    // plan the synchronization action to for execution via Task Manager
                    $sync_task = new AA_Task_Sync($sync_action, $aas[$dest_aa]);
                    $toexecute->userQueue($sync_task, array(), 'AA_Task_Sync');
                    ++$no_sync_tasks;
                }
            }
        }
    }
    echo _m("%1 synchronization actions planed. See", array($no_sync_tasks)). ' ';
    echo a_href(get_admin_url('se_taskmanager.php3'), _m('Task Manager'));
}

$apage = new AA_Adminpageutil('central', 'synchronize');
$apage->setTitle(_m("Central - Synchronize ActionApps (3/3) - Synchronize Slices"));
$apage->addRequire(get_aa_url('javascript/aajslib.php?v='.AA_JS_VERSION ));
$apage->printHead($err, $Msg);

// ActionApps to synchronize
$aas_array = array();
foreach ( $aas as $k => $aa ) {
    $aas_array[$k] = $aa->getName();
}

// slices to compare
$template_slices = $aas[$_POST['template_aa']]->requestSlices();

$form_buttons = array("synchronize"  => array( "type"      => "submit",
                                               "value"     => _m("Synchronize"),
                                               "accesskey" => "S"),
                      "template_aa"    => array( "value"     =>  $_POST['template_aa']),
                      "comparation_aa" => array( "value"     =>  $_POST['comparation_aa'])
                     );

FrmTabCaption(_m('Slice Comparison - %1 x %2', array($aas[$_POST['template_aa']]->getName(), $aas[$_POST['comparation_aa']]->getName())), '', '', $form_buttons);
if ( isset($differences) ) {
    // and print diffs out
    foreach ($differences as $sid => $diffs) {
        $sync_sid = $_POST['sync_slices'][$sid];
        FrmTabSeparator(AA_Slice::getModuleName($sid) . " ($sid) x " . AA_Slice::getModuleName($sync_sid) . " ($sync_sid)");

        echo '<tr><td  colspan="2">
        <script>
            function toggleViaCss(selector) {
                var trs = $$(selector);
                if (trs.size() > 0) {
                    if (trs.first().visible()) {
                        trs.invoke(\'hide\');
                    } else {
                        trs.invoke(\'show\');
                    }
                }
            }
            function toggleCheckViaCss(selector) {
                var chboxes = $$(selector);
                if (chboxes.size() > 0) {
                    if (chboxes.first().checked) {
                        chboxes.each( function(element) { element.checked = false; } );
                    } else {
                        chboxes.each( function(element) { element.checked = true; } );
                    }
                }
            }
        </script>
        <a id="toggle_info_'.$sid.'" href="javascript:toggleViaCss(\'#diff_'.$sid.' tr.diff_info\')">'._m("Hide/show info values").'</a>
        <a id="toggle_check_'.$sid.'" href="javascript:toggleCheckViaCss(\'#diff_'.$sid.' input[type=checkbox]\')">'._m("Check/Uncheck")."</a>
        <table border=\"0\" id=\"diff_$sid\">";
        foreach ($diffs as $diff) {
            $diff->printOut();
        }
        echo '</table></td></tr>';
    }
}
FrmTabSeparator(_m('Synchronize'));
FrmStaticText(_m('Template ActionApps'), $aas[$_POST['template_aa']]->getName());
FrmStaticText(_m('Compared and Updated ActionApps'), $aas[$_POST['comparation_aa']]->getName());
$aa2update = isset($_POST['destination_aa']) ? $_POST['destination_aa'] : $_POST['comparation_aa'];
FrmHidden("destination_aa[]", $aa2update);

//FrmInputMultiSelect('destination_aa[]', _m('AA to update'), $aas_array, $aa2update, 20, false, true, _m('ActionApps installation to update'));
//FrmInputMultiChBox('sync_slices[]', _m('Slices to synchronize'), $template_slices, $_POST['sync_slices'], false, '', '', 3);
foreach ($_POST['sync_slices'] as $k => $v) {
    if ($v) {
        FrmHidden("sync_slices[$k]", $v);
    }
}
//FrmInputMultiChBox('sync_slices[]', _m('Slices to synchronize'), $template_slices, $_POST['sync_slices'], false, '', '', 3);
// prepared for multiple update
FrmTabEnd($form_buttons);

$apage->printFoot();
