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

use AA\Util\InputVars;

require_once __DIR__ . "/include/init_central.php";
require_once __DIR__ . "/../include/formutil.php";


pageOpen();

if (!IsSuperadmin()) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You don't have permissions to synchronize slices."), "admin");
    exit;
}

MoreTimeLimit(); // set_time_limit to 5 minutes (at least)

// ActionApps to synchronize
$aas = AA_Actionapps::getArray();

// both the copy and the form below need the template AA
if (!isset($aas[$_POST['template_aa'] ?? ''])) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . 'copyslice.php', _m('Unknown template ActionApps'), 'admin');
    exit;
}

// Checkboxes posted by the form below - read explicitly, the register_globals
// emulation which used to fill them is gone (see include/init_central.php).
$sync_defs  = InputVars::boolPOST('sync_defs');
$sync_items = InputVars::boolPOST('sync_items');

// multiselects of the form below; ids only - anything else would be an illegal array offset,
// and without the empty option FrmInputMultiSelect() adds
$posted_ids     = static fn(string $name): array => array_values(array_filter(InputVars::arrayPOST($name), static fn($v) => is_string($v) && ($v !== '')));
$sync_slices    = $posted_ids('sync_slices');
$sync_sites     = $posted_ids('sync_sites');
$sync_alerts    = $posted_ids('sync_alerts');
$destination_aa = $posted_ids('destination_aa');

$err = [];
$Msg = '';

if ($_POST['copy']) {
    $no_sync_tasks = 0;

    $limit = [];
    if ($sync_items) {
        $limit['items']       = true;
    }
    if ($sync_defs) {
        $limit['definitions'] = true;
    }
    $requested = [
        'Slice'  => $limit ? $sync_slices : [],
        'Site'   => $sync_sites,
        'Alerts' => $sync_alerts,
    ];
    $module_defs = [];
    foreach ($requested as $type => $ids) {
        $defs = $ids ? $aas[$_POST['template_aa']]->requestDefinitions($type, $ids, ($type === 'Slice') ? $limit : []) : [];
        foreach ($ids as $mid) {
            // the template AA may not send a definition for every selected module
            if (($defs[$mid] ?? null) instanceof AA_Definition_Module) {
                $module_defs[$mid] = $defs[$mid];
            }
        }
    }

    $local_report = [];  // this AA imports right away; the remote imports report to Task Manager log
    $remote_dest  = false;
    foreach ($destination_aa as $dest_aa) {
        if (($dest_aa !== 'thisAA') && !isset($aas[$dest_aa])) {
            $err[] = _m('Unknown destination ActionApps %1 skipped.', [safe($dest_aa)]);
            continue;
        }
        $remote_dest = $remote_dest || ($dest_aa !== 'thisAA');

        // each destination gets its own new module ids - a second copy into the same AA must not collide
        $changed_ids = array_map(static fn($def) => new_id(), $module_defs);
        foreach ($module_defs as $module_def) {
            if ($dest_aa === 'thisAA') {
                $local_report[] = $module_def->moduleImport($changed_ids);
            } else {
                $no_sync_tasks += $module_def->planModuleImport($aas[$dest_aa], $changed_ids);
            }
        }
    }

    $local_report = array_merge(...$local_report);
    $msgs         = [];
    foreach ($local_report as $line) {
        $text = _m('This ActionApps') . ': ' . safe($line);   // the ids in the report come from the template AA
        if (str_starts_with($line, 'Err: ')) {
            $err[]  = $text;
        } else {
            $msgs[] = $text;
        }
    }
    if ($remote_dest) {
        $msgs[] = _m("%1 import actions planned. See", [$no_sync_tasks]) . ' ' . a_href(get_admin_url('se_taskmanager.php'), _m('Task Manager'));
    } elseif (!$local_report) {
        $msgs[] = _m('Nothing was imported.');
    }
    $Msg = implode('<br>', $msgs);
} else {
    // init values for form
    $sync_defs  = true;
    $sync_items = false;
}

$apage = new AA_Adminpageutil('central', 'copyslice');
$apage->setTitle(_m("Central - Copy Slice (2/2) - Slices to Copy"));
$apage->printHead($err, $Msg);

// ActionApps to synchronize
$aas_array = ['thisAA' => _m('* this ActionApps')];
foreach ($aas as $k => $aa) {
    $aas_array[$k] = $aa->getName();
}

// Template slice (and site) - grab from remote AA
$template_slices = $aas[$_POST['template_aa']]->requestModules(['S']);
$template_sites  = $aas[$_POST['template_aa']]->requestModules(['W']);
$template_alerts = $aas[$_POST['template_aa']]->requestModules(['Alerts']);


//huhl($aas[$_POST['template_aa']], $template_slices, $template_sites, $template_alerts);

$form_buttons = [
    "copy"      => [
        "type"      => "submit",
        "value"     => _m("Copy"),
        "accesskey" => "C",
    ],
    "template_aa"     => ["value"     =>  $_POST['template_aa']],
];

FrmTabCaption('', $form_buttons);
FrmStaticText(_m('Template ActionApps'), $aas[$_POST['template_aa']]->getName());
FrmTabSeparator(_m('Modules to Copy from %1', [safe($aas[$_POST['template_aa']]->getName())]));
FrmInputMultiSelect('sync_slices[]', _m('Slices to copy'), $template_slices, reset($sync_slices), 10);
FrmInputChBox('sync_defs', _m('Copy definitions'), $sync_defs, false, '', 1, false, _m('Copy slice, fields, views, .... of selected slices above'));
FrmInputChBox('sync_items', _m('Copy also items'), $sync_items, false, '', 1, false, _m('Copy also item data (items, content, discussions tables) of selected slices above - You can also check only this checkbox to copy the content of the slice into previously prepared (and empty) slice'));
FrmInputMultiSelect('sync_sites[]', _m('Site modules to copy'), $template_sites, reset($sync_sites), 10);
FrmInputMultiSelect('sync_alerts[]', _m('Alerts modules to copy'), $template_alerts, reset($sync_alerts), 10);
FrmInputMultiSelect('destination_aa[]', _m('Destination AAs'), $aas_array, reset($destination_aa), 20, true, _m('ActionApps installation to update'));
FrmTabEnd($form_buttons);

$apage->printFoot();
