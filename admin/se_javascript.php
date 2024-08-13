<?php
/**
 * PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Jakub Adamek, June 2002
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;
use AA\Util\InputVars;
use AA\Util\JsTriggers;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/varset.php";

try {
    $update = InputVars::boolPOST('update');
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php"));
}

if (!IfSlPerm(PS_FIELDS)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You have not permissions to change fields settings"), "admin");
    exit;
}

$err = [];          // error array (Init - just for initializing variable

// update database or get the value


if (AA::$module_id && $update) {
    DB_AA::update('slice', [['javascript',$_POST['javascript']]], [['id', AA::$module_id, 'l']]);
    $javascript = $_POST['javascript'];
} else {
    $javascript = DB_AA::select1('javascript', 'SELECT javascript FROM `slice`', [['id', AA::$module_id, 'l']]);
}


$script2run = ' 
   var opts = { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed" };
   window.cm_top  = CodeMirror.fromTextArea(document.getElementById("javascript"), opts);
   ';

$apage = new AA_Adminpageutil('sliceadmin','javascript');
$apage->setTitle(_m("Field Triggers"));
$apage->addRequire('codemirror@5');
$apage->addRequire($script2run, 'AA_Req_Load');
$apage->printHead($err, $Msg);



FrmTabCaption(_m("JavaScript for fields"));
FrmTextarea("javascript", _m("Code"), $javascript, 4, 60, false, _m("Enter code in the JavaScript language. It will be included in the Add / Edit item page (itemedit.php)."), '', true);

$form_buttons =  [
    "update",
    "update" => ["type" => "hidden", "value" => "1"],
    "cancel" => ["url" => "se_fields.php"]
];
FrmTabEnd($form_buttons);

$fields = AA_Slice::getModule(AA::$module_id)->getFields()->getNameArray();

$rows = [[_m("Id"), _m("Field")]];
foreach ($fields as $fid => $fname) {
    $rows[] = [$fid, $fname];
}
$apage->addHelpbox(_m("Field IDs"), GetHtmlTable($rows, 'th'), true);

$trig_hlp = '<p>'._m("Write trigger functions like").' "aa_onSubmit (fieldid) { }", <a href="https://actionapps.org/faq/detail.shtml?x=1706" target="_blank">'._m("see FAQ</a> for more details and examples").'</p>';
$trig_hlp .= '<p>'._m("You can place {require:...} anywhere in the code to load any js library. If you do not use any {require:...}, AA will use default {require:aa-fillform@1} {require:aa-jslib@1}. Feel free to use just {require:aa-fillform} if you do not need old {require:aa-jslib@1}.").'</p>';
$trig_hlp .= '<p>'._m("If you want to use javascript without any library, use {require:} or // {require:}.").'</p>';
$trig_hlp .= '<table class="aa-table"><tr class="aa-tbl-header"><th>'._m("Field Type").'</th><th>'._m("Triggers Available -- see some JavaScript help for when a trigger is run").'</th></tr>';
$trig_hlp .= JsTriggers::getSummary();
$trig_hlp .= '</table>';

$apage->addHelpbox(_m("Triggers"), $trig_hlp);

$apage->printFoot();




