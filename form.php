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

/**
 * @param form_id   - long id of form
 * @param type      - "ajax"
 * @param ret_code  - AA expression (with aliases to be returned after ajax insert)
 * @param object_id - optional item_id (for edit)
 * @return array|string
 */


// ----- input variables normalization - start --------------------------------

// This code handles with "magic quotes" and "register globals" PHP (<5.4) setting
// It make us sure, taht
//  1) in $_POST,$_GET,$_COOKIE,$_REQUEST variables the values are not quoted
//  2) the variables are imported in global scope and is quoted
// We are trying to remove any dependecy on the point 2) and use only $_* superglobals
function AddslashesDeep($value)   { return is_array($value) ? array_map('AddslashesDeep',   $value) : addslashes($value);   }
function StripslashesDeep($value) { return is_array($value) ? array_map('StripslashesDeep', $value) : stripslashes($value); }

if ( get_magic_quotes_gpc() ) {
    $_POST    = StripslashesDeep($_POST);
    $_GET     = StripslashesDeep($_GET);
    $_COOKIE  = StripslashesDeep($_COOKIE);
    $_REQUEST = StripslashesDeep($_REQUEST);
}

if (!ini_get('register_globals') OR !get_magic_quotes_gpc()) {
    foreach ($_REQUEST as $k => $v) {
        $$k = AddslashesDeep($v);
    }
}
// ----- input variables normalization - end ----------------------------------


require_once "./include/config.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."item.php3";
require_once AA_INC_PATH."searchlib.php3";
$encap = true; // just for calling extsessi.php
require_once AA_INC_PATH."locsess.php3";    // DB_AA object definition

if (!$_GET['form_id']) {
    echo '<!-- no form_id defined in /form.php -->';
    exit;
}

$form = AA_Object::load($_GET['form_id'], 'AA_Form');

if (is_null($form)) {
    echo '<!-- bad form_id in /form.php - '. $_GET['form_id']. ' -->';
    exit;
}

if ($_GET['type']=='ajax') {
    $html = $form->getAjaxHtml($_GET['ret_code']);
    if (($charset = AA_Slice::getModule($form->getOwnerId())->getCharset()) != 'utf-8') {
        $html = ConvertCharset::singleton($charset, 'utf-8')->Convert($html);
    }
    echo $html;
}

