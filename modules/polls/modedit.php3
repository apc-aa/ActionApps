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

/* Params:
    $template['P'] .. id of site template - new will be based on this one
    $update=1 .. write changes to database
*/

if ( $template['P'] ) {
    $no_slice_id = true;       // message for init_page.php3
}

$directory_depth = "../";
require_once "../../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."date.php3";
require_once AA_INC_PATH."modutils.php3";

if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

$err["Init"] = "";          // error array (Init - just for initializing variable
is_object( $db ) || ($db = getDB());

$varset      = new CVarset();
$superadmin  = IsSuperadmin();
$module_id   = $slice_id;

if ($template['P']) {        // add module
    if (!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD)) {
        MsgPage($sess->url(self_base())."index.php3", _m("You have not permissions to add polls"), "standalone");
        exit;
    }
} else {                    // edit module
    if (!CheckPerms( $auth->auth["uid"], "slice", $module_id, PS_MODP_SETTINGS)) {
        MsgPage($sess->url(self_base())."index.php3", _m("You have not permissions to edit this polls"), "standalone");
        exit;
    }
}

if ( $insert || $update ) {
    do {
        if ( !$owner ) {  // insert new owner
            if ( !( $owner = CreateNewOwner($new_owner, $new_owner_email, $err, $varset, $db))) {
                break;
            }
        }

        // validate all fields needed for module table (name, slice_url, lang_file, owner)
        ValidateModuleFields( $name, $slice_url, $priority, $lang_file, $owner, $err );
        $deleted  = ( $deleted  ? 1 : 0 );

        // now validate all module specific fields
        ValidateInput("ip_lock_timeout", _m("IP Locking timeout"), $ip_lock_timeout, $err, false, "text");
        ValidateInput("cookies_prefix",  _m("Cookies prefix"),     $cookies_prefix,  $err, false, "text");
        ValidateInput("params",          _m("Parameters"),         $params,          $err, false, "text");
        $logging      = ( $logging     ? 1 : 0 );
        $ip_locking   = ( $ip_locking  ? 1 : 0 );
        $set_cookies  = ( $set_cookies ? 1 : 0 );

        if ( count($err) > 1) {
            break;
        }

        // write all fields needed for module table
        $module_id = WriteModuleFields( ($update && $module_id) ? $module_id : false, $superadmin, 'P', $name, $slice_url, $priority, $lang_file, $owner, $deleted );

        if ( !$module_id ) {      // error?
            break;
        }
        $slice_id = $module_id;

        // now set all module specific settings
        if ( $update ) {
            $p_module_id = q_pack_id($module_id);

            $varset->clear();

            $varset->add("logging",       "number", $logging);
            $varset->add("ip_locking",     "number", $ip_locking);
            $varset->add("ip_lock_timeout", "number", $ip_lock_timeout);
            $varset->add("set_cookies",    "number", $set_cookies);
            $varset->add("cookies_prefix", "quoted", $cookies_prefix);

            // defaults - we use the same table for all polls. The setting
            // status_code=0 flags this poll as default for this poll module
            $SQL = "UPDATE polls SET ". $varset->makeUPDATE() . " WHERE (module_id='$p_module_id') AND (status_code=0)";

            echo $SQL;
            $debug=1;
            if (!$db->tquery($SQL)) {  // not necessary - we have set the halt_on_error
                $err["DB"] = MsgErr("Can't change site");
                break;
            }
        } else { // insert (add)
            $varset->clear();

            $p_template_id = ( $template['P'] ? q_pack_id(substr($template['P'],1)) : 'PollTemplate....' );

            $SQL = "SELECT * FROM polls WHERE (module_id='$p_template_id' AND status_code=0)";
            $db->query($SQL);
            if ( !$db->next_record() ) {
                $err["DB"] = MsgErr("Bad template id");
                break;
            }
            $varset->setFromArray($db->Record);
            $varset->set("id",             new_id(),        "quoted");
            $varset->set("module_id",      $module_id,      "unpacked");
            $varset->set("logging",        $logging,        "number");
            $varset->set("ip_locking",     $ip_locking,     "number");
            $varset->set("ip_lock_timeout",$ip_lock_timeout,"number");
            $varset->set("set_cookies",    $set_cookies,    "number");
            $varset->set("cookies_prefix", $cookies_prefix, "quoted");
            $varset->set("status_code",    0,               "number");

            // create new poll
            $varset->doInsert('polls');

            // copy design themes...
            $SQL = "SELECT * FROM polls_design WHERE module_id='$p_template_id'";
            $db->query($SQL);
            while ( $db->next_record() ) {
                $varset->resetFromRecord($db->Record);
                $varset->set("module_id", $module_id, "unpacked" );
                $varset->set('id',        new_id(),   'quoted');
                $varset->doInsert('polls_design');
            }
        }
        $GLOBALS['pagecache']->invalidateFor("slice_id=$module_id");  // invalidate old cached values for this slice
    } while(false);

    if ( count($err) <= 1 ) {
        go_return_or_url(self_base() . "modedit.php3", 0, 0);
    }
}

// And the form -----------------------------------------------------

$source_id   = ($template['P'] ? substr($template['P'],1) : $module_id );
$p_source_id = q_pack_id( $source_id );

// load module common data
list( $name, $slice_url, $priority, $lang_file, $owner, $deleted, $slice_owners ) = GetModuleFields( $source_id, $db );
// load module specific data
list( $status_code, $headline, $publish_date, $expiry_date, $logging, $ip_locking, $ip_lock_timeout, $set_cookies, $cookies_prefix, $design_id, $params) = GetTable2Array("SELECT status_code, headline, publish_date, expiry_date, logging, ip_locking, ip_lock_timeout, set_cookies, cookies_prefix, design_id, params FROM polls WHERE module_id='$p_source_id' AND status_code=0", 'aa_first');

if ( $template['P'] ) {           // set new name for new module
    $name = "";
}

if ( $template['P'] ) {
    $form_buttons = array( 'insert',
                           'template[P]' => array( 'type'=>"hidden", 'value'=> $template['P']));
} else {
    $form_buttons = array( 'update',  "update"  => array ('type' => 'hidden', 'value'=>'1'));
}


HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Polls Admin");?></TITLE>
</HEAD>
<?php
    require_once AA_BASE_PATH."modules/polls/include/menu.php3";
    showMenu($aamenus, "modadmin", "main");

    echo "<H1><B>" . ( $template['P'] ? _m("Add Polls") : _m("Edit Polls")) . "</B></H1>";
    PrintArray($err);
    echo $Msg;
?>
<form method=post action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
<?php

    FrmTabCaption(_m("Polls Module general data"),'','',$form_buttons, $sess, $module_id);
    FrmStaticText(_m("Id"), $module_id);
    FrmInputText("name", _m("Name"), $name, 99, 25, true);
    $include_cmd = "<br>&lt;!--#include&nbsp;virtual=\"". AA_INSTAL_PATH ."modules/polls/poll.php3?pid=$module_id\"--&gt;";
    FrmInputText("slice_url", _m("URL"), $slice_url, 254, 25, false, _m("Use following SSI command to include the poll to the page: ". $include_cmd));
    FrmInputText("priority", _m("Priority (order in slice-menu)"), $priority, 5, 5, false);
    FrmInputSelect("owner", _m("Owner"), $slice_owners, $owner, false);
    if ( !$owner ) {
        FrmInputText("new_owner", _m("New Owner"), $new_owner, 99, 25, false);
        FrmInputText("new_owner_email", _m("New Owner's E-mail"), $new_owner_email, 99, 25, false);
    }
    if ( $superadmin ) {
        FrmInputChBox("deleted", _m("Deleted"), $deleted);
    }
    FrmInputSelect("lang_file", _m("Used Language File"), $MODULES['P']['language_files'], $lang_file, false);

    // module specific...

    FrmTabSeparator(_m("Defaults for polls in this module"));
    FrmInputChBox("logging",      _m("Use logging"),        $logging);
    FrmInputChBox("ip_locking",    _m("Use IP locking"),     $ip_locking);
    FrmInputText("ip_lock_timeout", _m("IP Locking timeout"), $ip_lock_timeout);
    FrmInputChBox("set_cookies",   _m("Use cookies"),        $set_cookies);
    FrmInputText("cookies_prefix", _m("Cookies prefix"),     $cookies_prefix);
//    FrmInputText("params",        _m("Parameters"),         $params);     // @todo - add paramwizard
    FrmTabEnd($form_buttons, $sess, $module_id);

echo "\n </form>";
HtmlPageEnd();
page_close();
?>
