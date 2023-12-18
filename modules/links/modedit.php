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

/* Params:
    $template['Links'] .. id of site template - new will be based on this one
    $update=1 .. write changes to database
*/

use AA\Util\InputVars;

$LINKS_LANGUAGE_FILES = [
    "en_links_lang.php" => "en_links_lang.php",
    "cz_links_lang.php" => "cz_links_lang.php",
    "cz-utf8_links_lang.php" => "cz-utf8_links_lang.php"
];


if ($_POST['template']['Links'] ?? false) {
    $no_slice_id = true;       // message for init_page.php
}

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../../include/init_page.php";
//require_once __DIR__."/../../include/en_links_lang.php";
require_once __DIR__ . "/../../include/formutil.php";

require_once __DIR__ . "/../../include/varset.php";
require_once __DIR__ . "/../../include/date.php";
require_once __DIR__ . "/../../include/modutils.php";
require_once __DIR__ . "/../../include/mgettext.php";
require_once __DIR__ . "/../../modules/links/util.php";

try {
    // array ( ' Vložit […' => 6, )
    $insert = InputVars::boolPOST('insert');
    // array ( 'cz_links_la…' => 3, 'en_links_la…' => 3, )
    $lang_file = InputVars::stringPOST('lang_file');
    // array ( '🐵' => 1, 'Naštvanej …' => 5, )
    $name = InputVars::stringPOST('name');
    // array ( 'JÁ!' => 5, '' => 1, )
    $new_owner = InputVars::stringPOST('new_owner'); // empty
    // array ( 'já@jsem.bo…' => 3, '' => 1, 'mati.hejny@…' => 1, 'já@Fň.cz' => 1, )
    $new_owner_email = InputVars::stringPOST('new_owner_email'); // empty
    // array ( 1 => 10, )
    $no_slice_id = InputVars::boolGET('no_slice_id', true);
    // array ( 0 => 3, '' => 2, '96ab12bbeae…' => 1, )
    $owner = InputVars::stringPOST('owner'); // alnum, empty
    // array ( 3838 => 1, 'nejvě' => 1, 0 => 2, 38 => 2, )
    $priority = InputVars::stringPOST('priority');
    // array ( '/(!":?' => 3, 1 => 3, )
    $select_start = InputVars::stringPOST('select_start');
    // array ( 'http://exam…' => 6, )
    $slice_url = InputVars::stringPOST('slice_url');
    // array ( '12345678909…' => 3, 1 => 3, )
    $start_id = InputVars::intPOST('start_id');
    // array ( )-array ( )
    $template = InputVars::arrayGETPOST('template'); // alnum-alnum
    // array ( 'ěščřž�…' => 3, 1 => 3, )
    $tree_start = InputVars::stringPOST('tree_start');
    $deleted = InputVars::boolPOST('deleted');
    $update = InputVars::boolPOST('update');
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php"));
}

$err = [];          // error array (Init - just for initializing variable
$varset      = new CVarset();
$superadmin  = IsSuperadmin();
$module_id   = AA::$module_id;


if ($template['Links']) {        // add module
    if (!CheckPerms( AuthGetUid(), "aa", AA_ID, PS_ADD)) {
        MsgPage(_m('No permission to add module'));
        exit;
    }
} else {                    // edit module
    if (!CheckPerms( AuthGetUid(), "slice", $module_id, PS_LINKS_SETTINGS)) {
        MsgPage(_m('No permission to edit module'));
        exit;
    }
}

if ( ($insert AND $superadmin) OR $update ) {
    do {
        if ( !$owner )  { // insert new owner
            if ( !( $owner = CreateNewOwner($new_owner, $new_owner_email, $err, $varset))) {
                break;
            }
        }

        // validate all fields needed for module table (name, slice_url, lang_file, owner)
        ValidateModuleFields( $name, $slice_url, $priority, $lang_file, $owner, $err );
        $deleted  = ( $deleted  ? 1 : 0 );

        // now validate all module specific fields
        ValidateInput("start_id", _m("Start category id"), $start_id, $err, false, "number");
        ValidateInput("tree_start", _m("Tree start id"), $tree_start, $err, false, "number");
        ValidateInput("select_start", _m("Select start id"), $select_start, $err, false, "number");

        if ( count($err)) {
            break;
        }

        // write all fields needed for module table
        $module_id = WriteModuleFields(($update && $module_id) ? $module_id : false, $superadmin, 'Links', $name, $slice_url, $priority, $lang_file, $owner, $deleted, Links_Category2SliceID($start_id));
        if ( !$module_id ) {       // error?
            break;
        }
        $slice_id = $module_id;

        // now set all module specific settings
        if ( $update AND $superadmin) {
            // tree_start and select_start could be changed only by superadmin
            // Maybe we could allow also administrators to change $select_start in
            // future

            $p_module_id = q_pack_id($module_id);

            $varset->clear();

            // we can't change start_id (module_id is derived from it)
            //      $varset->add("start_id", "number", $start_id);
            $varset->add("tree_start", "number", $tree_start);
            $varset->add("select_start", "number", $select_start);

            // defaults - we use the same table for all links. The setting of defaults to 1
            // flags this as default for this poll module
            $SQL = "UPDATE links SET ". $varset->makeUPDATE() . " WHERE (id='$p_module_id')";
            if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
                $err["DB"] = MsgErr("Can't change links table");
                break;
            }
        } elseif ($insert AND $superadmin) { // insert (add)
            $varset->clear();

            $p_template_id = ( $template['Links'] ?
            q_pack_id(substr($template['Links'],1)) : 'LinksTemplate...' );

            $SQL = "SELECT * FROM links WHERE (id='$p_template_id')";
            $db->query($SQL);
            if ( !$db->next_record() ) {
                $err["DB"] = MsgErr("Bad template id");
                break;
            }
            $varset->resetFromRecord($db->record());
            $varset->set("id", $module_id, "unpacked");
            $varset->set("start_id", $start_id, "number");
            $varset->set("tree_start", $tree_start, "number");
            $varset->set("select_start", $select_start, "number");
            //      $varset->set("default_cat_tmpl", $default_cat_tmpl, "text");
            //      $varset->set("link_tmpl", $link_tmpl, "text");

            // create new links
            if ( !$varset->doINSERT('links')) {
                $err["DB"] .= MsgErr("Can't add links");
                break;
            }
        }
        AA::Pagecache()->invalidateFor($module_id);  // invalidate old cached values for this slice
    } while (false);

    if ( !count($err) ) {
        page_close();                                // to save session variables
        $netscape = (($r=="") ? "r=1" : "r=".++$r);  // special parameter for Netscape to reload page
        // added by Setu, 2002-0227
        if ($return_url) {  // after work for action, if return_url is there, we go to the page.
            go_url(urldecode($return_url));
        }
        go_url(StateUrl(self_base() . "modedit.php?$netscape"));
    }
}


// And the form -----------------------------------------------------

$source_id   = ($template['Links'] ? substr($template['Links'],1) : $module_id );
$p_source_id = q_pack_id( $source_id );

// load module specific data
$SQL= " SELECT * FROM links WHERE id='$p_source_id'";

$db->query($SQL);
if ($db->next_record()) {
    RestoreVariables($db->record());
}
$id = unpack_id($db->f("id"));  // correct ids

if ( $template['Links'] ) {           // set new name and owner for NEW module
    $name  = "";
    $owner = "";
}

$apage = new AA_Adminpageutil('modadmin','main');
$apage->setModuleMenu( 'modules/links');
$apage->setTitle( $template['Links'] ? _m("Add Links module") : _m("Edit Links module"));
$apage->printHead($err, $Msg);

FrmTabCaption(_m('Module Links data'));

ModuleFieldsForm($source_id, $marr = GetModuleFields($source_id), $superadmin, (bool)$template['Links'], $new_owner, $new_owner_email);
$lang_file = rtrim($marr[3],'3');   // trim for old php3 lang file extension

FrmInputSelect("lang_file", _m('Used Language File'), $LINKS_LANGUAGE_FILES, $lang_file);

// module specific...
if ($template['Links']) {
    FrmInputNumber("start_id", _m("Start category id"), $start_id, 0, 999999);
} else {
    // we can't change start_id (module_id is derived from it)
    FrmStaticText(_m('Start category id'), $start_id);
}
if ( $superadmin ) {
    FrmInputNumber("tree_start", _m("Tree start id"), $tree_start, 0, 999999);
    FrmInputNumber("select_start", _m("Select start id"), $select_start, 0, 999999);
} else {
    FrmStaticText(_m("Tree start id"), $tree_start);
    FrmStaticText(_m("Select start id"), $select_start);
}

if ( $template['Links'] ) {
    FrmTabEnd( ['insert', 'template[Links]' => ['type'=>"hidden", 'value'=> $template['Links']]]);
} else {
    FrmTabEnd( ['update', 'reset', 'cancel']);
}

$apage->printFoot();

