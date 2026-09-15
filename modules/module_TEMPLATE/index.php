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

// APC AA - Module main administration page

// used in init_page.php script to include config.php from the right directory

use AA\Util\InputVars;

require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/varset.php";
require_once __DIR__ . "/../../include/formutil.php";

try {
    $cancel = InputVars::boolPOST('cancel');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

// id of the editted module
$module_id   = AA::$module_id;        // id in long form (32-digit hexadecimal number)

// Check permissions for this page.
// You should change PS_MODW_EDIT_CODE permission to match the permission in your
// module. See /include/perm_core.php for more details

// getOwnModule(), not getModule(): the latter follows the module type in the database, so an id of
// another type gives an object without this module's methods ("Call to undefined method")
if (!($module = AA_Module_XX::getOwnModule($module_id))) {
    MsgPage(_m("Module XX not found"));
}

if (!IfSlPerm(PS_MODX_EDIT_CODE)) {
    MsgPage(_m("You do not have permission to edit items in this module"));
    exit;
}


// fill code for handling the operations managed on this page




$apage = new AA_Adminpageutil('modadmin', 'xxmanager');
$apage->setTitle(_m("Editor window - xx manager"));

// possible other imports
// $apage->addRequire('aa-jslib');   // {htmltoggle in helpbox}
// $apage->addRequire('codemirror@5');
// $script2run = '
//    var opts = { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed" };
//    window.cm_a1 = CodeMirror.fromTextArea(document.getElementById("content"), opts);
//    ';
// $apage->addRequire($script2run, 'AA_Req_Load');

$apage->printHead($err, $Msg);





// fill code for the main admin page layout





$apage->printFoot();
