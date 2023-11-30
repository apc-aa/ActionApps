<?php
/**  expected at least $slice_id
 *   user calling is with $edit for edit item
 *   optionally free and freepwd for anonymous user login (free == login, freepwd == password)
 *
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
 *
*/


use AA\Util\InputVars;
use AA\Util\Langs;


if ( isset($_REQUEST['edit']) OR isset($_REQUEST['add'])) {         // parameter for init_page - we edited new item so
    $unset_r_hidden = true;  // clear stored content
}

const AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";     // This pays attention to $change_id
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/feeding.php";
require_once __DIR__ . "/../include/itemfunc.php";
require_once __DIR__ . "/../include/notify.php";
require_once __DIR__ . "/../include/menu_util.php";

if ( file_exists( "../include/usr_validate.php3" ) ) {
    require_once __DIR__."/../include/usr_validate.php3";
}

// Allow edit current slice without slice_pwd
AA_Credentials::singleton()->loadFromSlice(AA::$module_id);

InputVars::QuoteVars("post");  // if magicquotes are not set, quote variables

AA::setEncoding(Langs::getCharset());

$id = AA::$module_id;


if ($cancel) {
    go_return_or_url(self_base() . "index.php",true,true);
}

$go_edit = false;
if ($_POST['upd_edit'])    {$update = true; $go_edit=true; }

if ($update) {
    $action = "update";
} else {
    $action = "edit";
}

// ValidateContent4Id() sets GLOBAL!! variables:
//   $show_func_used   - list of show func used in the form
//   $js_proove_fields - JavaScript code for form validation
//   $oldcontent4id

$slice = AA_Slice::getModule($slice_id);
ValidateContent4Id($err, $slice, $action, $id);
$fields = $slice->getFields(true);

//  update database
if ( $update AND !count($err) AND $fields->count() ) {

    $profile = AA_Profile::getProfile(AuthGetUid(), $slice->getId()); // current user settings


    // prepare content4id array before call StoreItem function
    $content4id = new ItemContent;
    $content4id->setFieldsFromForm($oldcontent4id, false, $fields, $profile );

    // added_to_db contains id
    // removed $oldcontent4id (see ItemContent::storeItem)
    $content4id->storeSliceFields( $slice_id, $fields );

    if (!count($err) AND !$go_edit) {
        page_close();
        go_return_or_url(self_base() . "index.php",true,true);
    }
}

// -----------------------------------------------------------------------------
// Input form
// -----------------------------------------------------------------------------


unset( $content );       // used in another context for storing item to db
unset( $content4id );

if ($edit) {
    if ( !$fields->count() ) {
        $err["DB"] = MsgErr(_m("Error: no fields."));
        // do not quit - just go back.
        go_return_or_url(self_base() . "index.php",true,true);
    }

    // fill content array from item and content tables
    $content4id = $slice->get_dynamic_setting_content(true);
    if ( !$content4id ) {
        $err["DB"] = MsgErr(_m("Bad item ID id=%1", [$id]));
        MsgPage($err);
        exit;
    }
} else {
    // we need the $content4id to be object (for getForm, at least)
    $content4id = new ItemContent;
}

// print begin ---------------------------------------------------------------
$inputform_settings = [
    'display_aa_begin_end' => true,
    'page_title'           => _m("Slice Setting"). " (". AA_Slice::getModuleName(AA::$module_id).")",
];

$inputform_settings['messages']            = ['err' => $err];
$inputform_settings['form_action']         = ($_SERVER['DOCUMENT_URI'] != "" ? $_SERVER['DOCUMENT_URI'] :
                                             $_SERVER['PHP_SELF'] . ($return_url ? "?return_url=".urlencode($return_url) : ''));
$inputform_settings['form4update']         = true;
$inputform_settings['cancel_url']          = ($return_url=='close_dialog' ? get_admin_url("itemedit.php?cancel=1&return_url=close_dialog") :
                                              ($return_url ? expand_return_url(1) : get_admin_url("index.php?cancel=1")));

$inputform_settings['hidden']              = ['openervar'   => $openervar];  // id of variable in parent window (used for popup inputform)

if ($inputform_settings['form4update']) {
    $inputform_settings['hidden']['id']    = $id;
}

if ($vid) {
    $inputform_settings['template'] = $vid;
}

//AddPermObject($slice_id, "slice");    // no special permission added - only superuser can access
$form = new inputform($inputform_settings);
$form->printForm($content4id, $slice, $edit, true);

page_close();

