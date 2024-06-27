<?php

/** Shows a Table View, allowing to edit, delete, update fields of a table
   @param $set_tview -- required, name of the table view
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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
*/

use AA\Util\InputVars;

$require_default_lang = true;      // do not use module specific language file
                                   // (message for init_page.php)
// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/mgettext.php";
// ----------------------------------------------------------------------------------------
try {
    $code = InputVars::stringPOST('code');
    $html = InputVars::boolPOST('html');
} catch (Exception $e) {}

if (!IsSuperadmin()) {
    MsgPage(_m("You have not permissions to access Console"));
    exit;
}

$script2run = ' 
   var opts = { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed" };
   window.cm_code  = CodeMirror.fromTextArea(document.getElementById("code"), opts);
   ';
$apage = new AA_Adminpageutil('aaadmin','console');
$apage->setTitle(_m("ActionApps Console"));
$apage->addRequire($script2run, 'AA_Req_Load');
$apage->addRequire('codemirror@5');
$apage->printHead();

$form_buttons = ["show" => ["type"=>"submit", "value"=>_m("Show")]];

// ------------------------------------------------------------------------------------------
FrmTabCaption(_m('Console'), $form_buttons);
FrmTextarea('code', _m('Code'),$code);
FrmInputChBox('html', _m('HTML output'), $html);
if (strlen($code)) {
    FrmStaticText(_m('Result'), $html ? AA::Un($code) : '<pre><code>'. safe(AA::Un($code)).'</code></pre>','','',false);
}
FrmTabEnd($form_buttons);

$apage->printFoot();
