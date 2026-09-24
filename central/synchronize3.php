<?php

//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

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

use AA\Central\Actionapps;
use AA\Central\Difference;
use AA\Central\Task\Sync;
use AA\Later\Toexecute;

require_once __DIR__ . "/include/init_central.php";
require_once __DIR__ . "/../include/formutil.php";



function CompareSliceDefs($template_slice_defs, $comp_slice_defs, $mapping): array
{
    // resulting array of all differences between selected slices
    $differences = [];
    if (is_array($template_slice_defs)) {
        foreach ($template_slice_defs as $tmp_slice_name => $slice_def) {
            // the key comes from the remote AA and is printed into ids and javascript: links below
            if (!is_long_id((string) $tmp_slice_name)) {
                continue;
            }
            if (!($slice_def instanceof AA_Definition_Module_Slice)) {
                $differences[$tmp_slice_name] = [new Difference('INFO', _m('Template AA sent no definition for slice %1', [safe($tmp_slice_name)]))];
                continue;
            }
            // a missing comparison slice gives the "does not exist" row
            $differences[$tmp_slice_name] = $slice_def->compareWith($comp_slice_defs[$mapping[$tmp_slice_name] ?? ''] ?? null);
        }
    }
    return $differences;
}

pageOpen();

if (!IsSuperadmin()) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You don't have permissions to synchronize slices."), "admin");
}

// ActionApps to synchronize
$aas = Actionapps::getArray();
$slices4template = [];
$slices2compare  = [];

if (!empty($_POST['compare'])) {

    foreach ($_POST['sync_slices'] as $slice_tmp => $slice_cmp) {
        if ($slice_cmp) {
            $slices4template[] = $slice_tmp;
            if ($slice_cmp != '1') {   // 0 - do not compare, 1 - exact copy
                $slices2compare[]  = $slice_cmp;
            }
        }
    }

    // bool true arrives as [] in the responder - it loaded nothing and the comparison failed
    $template_slice_defs = $aas[$_POST['template_aa']]->requestDefinitions('Slice', $slices4template, ['definitions' => true]);
    if (isset($_POST['comparation_aa'])) {
        $comp_slice_defs = $aas[$_POST['comparation_aa']]->requestDefinitions('Slice', $slices2compare, ['definitions' => true]);
        // now compare slices
        $differences = CompareSliceDefs($template_slice_defs, $comp_slice_defs, $_POST['sync_slices']);
    }
}

if (!empty($_POST['synchronize'])) {
    $toexecute     = new Toexecute();
    $no_sync_tasks = 0;

    if (is_array($_POST['destination_aa'])) {
        foreach ($_POST['destination_aa'] as $dest_aa) {
            if (is_array($_POST['sync'] ?? null) && isset($aas[$dest_aa])) {
                foreach ($_POST['sync'] as $form_value) {
                    if (!($sync_action = AA_Sync_Action::fromFormValue((string) $form_value))) {
                        continue;
                    }
                    // plan the synchronization action to for execution via Task Manager;
                    // the remote AA gets the serialized string, as it always did
                    // the queue row keeps the central_conf id of the AA, not its login
                    $sync_task = new Sync(serialize($sync_action), $aas[$dest_aa]);
                    $toexecute->userQueue($sync_task, [], 'AA_Task_Sync');   // the selector keeps its old name
                    ++$no_sync_tasks;
                }
            }
        }
    }
    echo _m("%1 synchronization actions planned. See", [$no_sync_tasks]) . ' ';
    echo a_href(get_admin_url('se_taskmanager.php'), _m('Task Manager'));
}

$apage = new AA_Adminpageutil('central', 'synchronize');
$apage->setTitle(_m("Central - Synchronize ActionApps (3/3) - Synchronize Slices"));
$apage->addRequire('aa-jslib@1');
$apage->printHead();

// ActionApps to synchronize
$aas_array = [];
foreach ($aas as $k => $aa) {
    $aas_array[$k] = $aa->getName();
}

// slices to compare
$template_slices = $aas[$_POST['template_aa']]->requestSlices();

$form_buttons = [
    "synchronize"  => [
        "type"      => "submit",
        "value"     => _m("Synchronize"),
        "accesskey" => "S",
    ],
    "template_aa"    => ["value"     =>  $_POST['template_aa']],
    "comparation_aa" => ["value"     =>  $_POST['comparation_aa']],
];

FrmTabCaption(_m('Slice Comparison - %1 x %2', [
    safe($aas[$_POST['template_aa']]->getName()),
    safe($aas[$_POST['comparation_aa']]->getName()),
]), $form_buttons);
if (isset($differences)) {
    // and print diffs out
    foreach ($differences as $sid => $diffs) {
        $sync_sid = $_POST['sync_slices'][$sid];
        FrmTabSeparator(AA_Slice::getModuleNameSafe($sid) . ' (' . safe($sid) . ') x ' . AA_Slice::getModuleNameSafe($sync_sid) . ' (' . safe($sync_sid) . ')');

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
        <a id="toggle_info_' . $sid . '" href="javascript:toggleViaCss(\'#diff_' . $sid . ' tr.diff_info\')">' . _m("Hide/show info values") . '</a>
        <a id="toggle_check_' . $sid . '" href="javascript:toggleCheckViaCss(\'#diff_' . $sid . ' input[type=checkbox]\')">' . _m("Check/Uncheck") . "</a>
        <table id=\"diff_$sid\">";
        foreach ($diffs as $diff) {
            $diff->printOut();
        }
        echo '</table></td></tr>';
    }
}
FrmTabSeparator(_m('Synchronize'));
FrmStaticText(_m('Template ActionApps'), $aas[$_POST['template_aa']]->getName());
FrmStaticText(_m('Compared and Updated ActionApps'), $aas[$_POST['comparation_aa']]->getName());
// posted back to this page, where Synchronize reads a list of AA ids - after a Synchronize it also arrives as one
foreach ((array) ($_POST['destination_aa'] ?? $_POST['comparation_aa']) as $aa2update) {
    if (is_string($aa2update)) {
        FrmHidden('destination_aa[]', $aa2update);
    }
}

foreach ((array) ($_POST['sync_slices'] ?? []) as $k => $v) {
    if ($v && is_string($v) && is_long_id((string) $k)) {   // FrmHidden() escapes the value, not the name
        FrmHidden("sync_slices[$k]", $v);
    }
}
FrmTabEnd($form_buttons);

$apage->printFoot();
