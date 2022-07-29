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

use AA\Later\Toexecute;

require_once __DIR__."/include/init_central.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/msgpage.php";

pageOpen();

if (!IsSuperadmin()) {
  MsgPageMenu(StateUrl(self_base())."index.php3", _m("You don't have permissions to synchronize slices."), "admin");
  exit;
}

set_time_limit(360);

// ActionApps to synchronize
$aas = AA_Actionapps::getArray();

if ($_POST['copy']) {
    $toexecute = new Toexecute;
    $no_sync_tasks = 0;

    $limit = [];
    if ($sync_items) { $limit['items']       = true; }
    if ($sync_defs)  { $limit['definitions'] = true; }
    if (count($limit) > 0) {
        $template_slice_defs = $aas[$_POST['template_aa']]->requestDefinitions('Slice', $_POST['sync_slices'], $limit);
    }
    $template_site_defs   = $aas[$_POST['template_aa']]->requestDefinitions('Site',    $_POST['sync_sites']);
    $template_alerts_defs  = $aas[$_POST['template_aa']]->requestDefinitions('Alerts',  $_POST['sync_alerts']);

    foreach ($_POST['destination_aa'] as $dest_aa) {

        // import with changed module ids
        $changed_ids = [];
        foreach ($_POST['sync_slices'] as $mid) { $changed_ids[$mid] = new_id(); }
        foreach ($_POST['sync_sites']  as $mid) { $changed_ids[$mid] = new_id(); }
        foreach ($_POST['sync_alerts'] as $mid) { $changed_ids[$mid] = new_id(); }

        if (is_array($_POST['sync_slices']) AND (count($limit) > 0)) {
            foreach ($_POST['sync_slices'] as $sid) {
                if ($sid) {
                    // plan the synchronization action to for execution via Task Manager
                    $module_def     = $template_slice_defs[$sid];
                    $no_sync_tasks += ($dest_aa == 'thisAA') ? $module_def->moduleImport($changed_ids) : $module_def->planModuleImport($aas[$dest_aa], $changed_ids);
                }
            }
        }
        if (is_array($_POST['sync_sites'])) {
            foreach ($_POST['sync_sites'] as $sid) {
                if ($sid) {
                    $module_def     = $template_site_defs[$sid];
                    $no_sync_tasks += ($dest_aa == 'thisAA') ? $module_def->moduleImport($changed_ids) : $module_def->planModuleImport($aas[$dest_aa], $changed_ids);
                }
            }
        }
        if (is_array($_POST['sync_alerts'])) {
            foreach ($_POST['sync_alerts'] as $aid) {
                if ($aid) {
                    $module_def     = $template_alerts_defs[$aid];
                    $no_sync_tasks += ($dest_aa == 'thisAA') ? $module_def->moduleImport($changed_ids) : $module_def->planModuleImport($aas[$dest_aa], $changed_ids);
                }
            }
        }
    }
    echo _m("%1 import actions planed. See", [$no_sync_tasks]). ' ';
    echo a_href(get_admin_url('se_taskmanager.php'), _m('Task Manager'));
} else {
    // init values for form
    $sync_defs  = true;
    $sync_items = false;
}

$apage = new AA_Adminpageutil('central', 'copyslice');
$apage->setTitle( _m("Central - Copy Slice (2/2) - Slices to Copy") );
$apage->printHead($err, $Msg);

// ActionApps to synchronize
$aas_array = ['thisAA' => _m('* this ActionApps')];
foreach ( $aas as $k => $aa ) {
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
                                            "accesskey" => "C"
    ],
                      "template_aa"     => ["value"     =>  $_POST['template_aa']]
];

FrmTabCaption('', $form_buttons);
FrmStaticText(_m('Template ActionApps'), $aas[$_POST['template_aa']]->getName());
FrmTabSeparator(_m('Modules to Copy from %1', [$aas[$_POST['template_aa']]->getName()]));
FrmInputMultiSelect('sync_slices[]', _m('Slices to copy'), $template_slices, @reset($_POST['sync_slices']), 10);
FrmInputChBox('sync_defs',  _m('Copy definitions'), $sync_defs, false, '', 1, false, _m('Copy slice, fields, views, .... of selected slices above'));
FrmInputChBox('sync_items', _m('Copy also items'), $sync_items, false, '', 1, false, _m('Copy also item data (items, content, discussions tables) of selected slices above - You can also check only this checkbox to copy the content of the slice into previously prepared (and empty) slice'));
FrmInputMultiSelect('sync_sites[]', _m('Site modules to copy'), $template_sites, @reset($_POST['sync_sites']), 10);
FrmInputMultiSelect('sync_alerts[]', _m('Alerts modules to copy'), $template_alerts, @reset($_POST['sync_alerts']), 10);
FrmInputMultiSelect('destination_aa[]', _m('Destination AAs'), $aas_array, @reset($_POST['destination_aa']), 20, false, true, _m('ActionApps installation to update'));
FrmTabEnd($form_buttons);

$apage->printFoot();