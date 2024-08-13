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
    $template['P'] .. id of site template - new will be based on this one
    $update=1 .. write changes to database
*/

use AA\IO\DB\DB_AA;
use AA\Util\InputVars;

if ($_GET['template']['P'] ?? null ) {
    $no_slice_id = true;       // message for init_page.php
}

$directory_depth = "../";
// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../include/varset.php";
require_once __DIR__ . "/../../include/date.php";
require_once __DIR__ . "/../../include/modutils.php";


try {
    // array ( 'AA_POLLS2' => 1, 'AA_POLLSs' => 6, 'AA_POLLSq' => 1, )
    $cookies_prefix = InputVars::stringPOST('cookies_prefix');
    // array ( ' Insert [^…' => 8, )
    $insert = InputVars::boolPOST('insert');
    // array ( 445 => 1, 44 => 6, 4 => 1, )
    $ip_lock_timeout = InputVars::intPOST('ip_lock_timeout');
    // array ( 'on' => 8, )
    $ip_locking = InputVars::boolPOST('ip_locking');
    // array ( 'cz-utf8_pol…' => 1, 'es-utf8_pol…' => 6, 'en_polls_la…' => 1, )
    $lang_file = InputVars::stringPOST('lang_file');
    // array ( 'on' => 8, )
    $logging = InputVars::boolPOST('logging');
    // array ( 'start \' " /…' => 7, 'test polls' => 1, )
    $name = InputVars::stringPOST('name');
    // array ( 1 => 14, )
    $no_slice_id = InputVars::boolGET('no_slice_id');
    // array ( '41415f436f7…' => 7, '' => 1, )
    $owner = InputVars::idPOST('owner'); // empty
    // array ( 14 => 1, 2 => 7, )
    $priority = InputVars::intPOST('priority');
    // array ( 'on' => 8, )
    $set_cookies = InputVars::boolPOST('set_cookies');
    // array ( 'start \' " /…' => 1, 'http://aa.l…' => 7, )
    $slice_url = InputVars::stringPOST('slice_url');
    // array ( )-array ( )
    $new_owner = InputVars::stringPOST('new_owner'); // empty
    $new_owner_email = InputVars::stringPOST('new_owner_email'); // empty
    $template = InputVars::arrayGETPOST('template'); // alnum-alnum
    $deleted = InputVars::boolPOST('deleted');
    $update = InputVars::boolPOST('update');
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}


if ($cancel) {
    go_url( StateUrl(self_base() . "index.php"));
}

$err = [];          // error array (Init - just for initializing variable

$varset      = new CVarset();
$superadmin  = IsSuperadmin();
$module_id   = AA::$module_id;

if ($template['P']) {        // add module
    if (!CheckPerms( AuthGetUid(), "aa", AA_ID, PS_ADD)) {
        MsgPage(_m("You have not permissions to add polls"));
        exit;
    }
} else {                    // edit module
    if (!CheckPerms( AuthGetUid(), "slice", $module_id, PS_MODP_SETTINGS)) {
        MsgPage(_m("You have not permissions to edit this polls"));
        exit;
    }
}

if ( $insert || $update ) {
    do {
        if ( !$owner ) {  // insert new owner
            if ( !( $owner = CreateNewOwner($new_owner, $new_owner_email, $err, $varset))) {
                break;
            }
        }

        // validate all fields needed for module table (name, slice_url, lang_file, owner)
        ValidateModuleFields( $name, $slice_url, $priority, $lang_file, $owner, $err );
        $deleted  = ( $deleted  ? 1 : 0 );

        // now validate all module specific fields
        ValidateInput("ip_lock_timeout", _m("IP Locking timeout"), $ip_lock_timeout, $err, false, "text");
        ValidateInput("cookies_prefix",  _m("Cookies prefix"),     $cookies_prefix,  $err, false, "text");
        //ValidateInput("params",          _m("Parameters"),         $params,          $err, false, "text");
        $logging      = ( $logging     ? 1 : 0 );
        $ip_locking   = ( $ip_locking  ? 1 : 0 );
        $set_cookies  = ( $set_cookies ? 1 : 0 );

        if ( count($err)) {
            break;
        }

        // write all fields needed for module table
        $module_id = WriteModuleFields( ($update && $module_id) ? $module_id : false, $superadmin, 'P', $name, $slice_url, $priority, $lang_file, $owner, $deleted );

        if ( !$module_id ) {      // error?
            break;
        }
        AA::$module_id = $module_id;
        $slice_id = $module_id;

        // now set all module specific settings
        if ( $update ) {
            $record = ["module_id"        => $module_id,
                       'status_code'      => 0,   // status_code=0 flags this poll as default for this poll module
                       "logging"          => $logging,
                       "ip_locking"       => $ip_locking,
                       "ip_lock_timeout"  => $ip_lock_timeout,
                       "set_cookies"      => $set_cookies,
                       "cookies_prefix"   => $cookies_prefix,
                       "params"           => '',
                       "headline"         => '',
                       "publish_date"     => 0,
                       "expiry_date"      => 0,
                       "locked"           => 0,
            ];
            if ($pollid = DB_AA::select1('id', 'SELECT id FROM polls', [['module_id',$module_id,'l'],['status_code',0]])) {
                $record['id'] = $pollid;
            } else {
                $record['id'] = new_id();
            }
            if (false === AA::Metabase()->doReplace('polls', $record)) {  // if there was problem on polls creation, replace instead of UPDATE will fix it
                $err["DB"] = MsgErr("Can't change polls");
                break;
            }
        } else { // insert (add)
            $varset->clear();

            $p_template_id = ( $template['P'] ? q_pack_id(substr($template['P'],1)) : 'PollsTemplate...' );

            $SQL = "SELECT * FROM polls WHERE (module_id='$p_template_id' AND status_code=0)";
            $db->query($SQL);
            if ( $db->next_record() ) {
                $varset->resetFromRecord($db->record());
            } else {
                warn("Bad template id");
                $varset->resetFromRecord(\AA\Optimize\AddPolls::POLLS_DEFAULT_VALUES);
            }
            $varset->set("id",             new_id(),        "text");
            $varset->set("module_id",      $module_id,      "unpacked");
            $varset->set("logging",        $logging,        "number");
            $varset->set("ip_locking",     $ip_locking,     "number");
            $varset->set("ip_lock_timeout",$ip_lock_timeout,"number");
            $varset->set("set_cookies",    $set_cookies,    "number");
            $varset->set("cookies_prefix", $cookies_prefix, "text");
            $varset->set("status_code",    0,               "number");
            $varset->set("headline",       '',              "text");
            $varset->set("params",         '',              "text");
            $varset->set("publish_date",   now(),           "number");

            // create new poll
            $varset->doInsert('polls');

            // copy design themes...
            $SQL = "SELECT * FROM polls_design WHERE module_id='$p_template_id'";
            $db->query($SQL);
            while ( $db->next_record() ) {
                $varset->resetFromRecord($db->record());
                $varset->set("module_id", $module_id, "unpacked" );
                $varset->set('id',        new_id(),   'text');
                $varset->doInsert('polls_design');
            }
        }
        AA::Pagecache()->invalidateFor($module_id);  // invalidate old cached values for this slice
    } while(false);

    if ( !count($err) ) {
        go_return_or_url(self_base() . "modedit.php", 0, 0);
    }
}

// And the form -----------------------------------------------------

$source_id   = ($template['P'] ? substr($template['P'],1) : $module_id );
$p_source_id = q_pack_id( $source_id );

// load module specific data
[ $status_code, $headline, $publish_date, $expiry_date, $locked, $logging, $ip_locking, $ip_lock_timeout, $set_cookies, $cookies_prefix, $design_id, $params] = array_values((array)DB_AA::select1('', "SELECT status_code, headline, publish_date, expiry_date, locked, logging, ip_locking, ip_lock_timeout, set_cookies, cookies_prefix, design_id, params FROM polls", [
    ['module_id', $source_id, 'l'],
    ['status_code', 0]
]));

if ( $template['P'] ) {
    $form_buttons = [
        'insert',
        'template[P]' => ['type'=>"hidden", 'value'=> $template['P']]
    ];
} else {
    $form_buttons = ['update',  "update"  => ['type' => 'hidden', 'value'=>'1']];
}

$apage = new AA_Adminpageutil('modadmin', 'main');
$apage->setModuleMenu('modules/polls');
$apage->setTitle($template['P'] ? _m("Add Polls") : _m("Edit Polls"));
$apage->printHead($err, $Msg);

FrmTabCaption(_m("Polls Module general data"), $form_buttons);

ModuleFieldsForm($source_id, $marr = GetModuleFields($source_id), $superadmin, (bool)$template['P'], $new_owner, $new_owner_email);
$lang_file = rtrim($marr[3],'3');   // trim for old php3 lang file extension

FrmInputSelect("lang_file", _m("Used Language File"), $MODULES['P']['language_files'], $lang_file);

// module specific...

FrmTabSeparator(_m("Defaults for polls in this module"));
FrmInputChBox("logging",      _m("Use logging"),        $logging);
FrmInputChBox("ip_locking",    _m("Use IP locking"),     $ip_locking);
FrmInputNumber("ip_lock_timeout", _m("IP Locking timeout"), $ip_lock_timeout);
FrmInputChBox("set_cookies",   _m("Use cookies"),        $set_cookies);
FrmInputText("cookies_prefix", _m("Cookies prefix"), $cookies_prefix);
//    FrmInputText("params",        _m("Parameters"),         $params);     // @todo - add paramwizard
FrmTabEnd($form_buttons);

$apage->printFoot();
