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

// APC AA - Module main administration page. A template to copy - the whole checklist for a new
// module type (class, permissions, the other pages, translations, tests) is in README.md here.

use AA\Util\InputVars;

require_once __DIR__ . "/../../include/init_page.php";   // session, login, perm_core.php, AA::$module_id

// read the input only through InputVars
try {
    $update = InputVars::boolPOST('update');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

// id of the edited module
$module_id = AA::$module_id;        // id in long form (32-digit hexadecimal number)

// getOwnModule(), not getModule(): the latter follows the module type in the database, so an id of
// another type gives an object without this module's methods ("Call to undefined method")
if (!($module = AA_Module_Xxx::getOwnModule($module_id))) {
    MsgPage(_m("Xxx module not found"));   // MsgPage() never returns
}

// Change PS_XXX_VIEW to the permission letter of your module - see include/perm_core.php
if (!IfSlPerm(PS_XXX_VIEW)) {
    MsgPage(_m("You do not have permission to see this module"));
}

$err = [];   // error messages for the top of the page: $err[] = MsgErr(_m("..."));

if ($update) {
    // fill code for handling the operations managed on this page
}

$apage = new AA_Adminpageutil('xxx', 'main');   // keys of the main menu and of its submenu item in menu.php
$apage->setTitle(_m("Xxx - main page"));
// $apage->setForm();   // no default <form name="f" method="post"> around the page (own forms, application shell)

// possible other imports
// $apage->addRequire('aa-jslib');   // {htmltoggle in helpbox}
// $apage->addRequire('aa-code@1');   // code editor for FrmTextarea(..., code: true) - templates

$apage->printHead($err);   // never pass Msg from the request here - it is printed unescaped

// fill code for the main admin page layout

$apage->printFoot();
