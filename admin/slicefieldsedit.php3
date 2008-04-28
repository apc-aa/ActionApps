<?php
/**  expected at least $slice_id
 *   user calling is with $edit for edit item
 *   optionaly encap="false" if this form is not encapsulated into *.shtml file
 *   optionaly free and freepwd for anonymous user login (free == login, freepwd == password)
 *
 * PHP versions 4 and 5
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
 * @link      http://www.apc.org/ APC
 *
*/


$encap = ( ($encap=="false") ? false : true );

if ($edit) {         // parameter for init_page - we edited new item so
    $unset_r_hidden = true;  // clear stored content
}

require_once "../include/init_page.php3";     // This pays attention to $change_id
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."feeding.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."itemfunc.php3";
require_once AA_INC_PATH."notify.php3";
require_once AA_INC_PATH."slice.class.php3";

if ( file_exists( AA_INC_PATH."usr_validate.php3" ) ) {
    require_once AA_INC_PATH."usr_validate.php3";
}

FetchSliceReadingPassword();

if ($encap) {
    add_vars();        // adds values from QUERY_STRING_UNESCAPED
}                             //       and REDIRECT_STRING_UNESCAPED - from url

QuoteVars("post", array('encap'=>1) );  // if magicquotes are not set, quote variables
                                        // but skip (already edited) encap variable

// TODO: GetHidden is not so correct in current AA code
// Now we can have multiple itemedits open, so one set of r_hidden isn't correct
// We should move r_hiddens functionality to inputform class
// Later we have also rewrite this code to use filler script for filling (in
// order we have only one - good - filling code and not two
//   GetHidden($itemform_id);  // unpacks variables from $r_hidden session var.
//   unset($r_hidden[$itemform_id]);
//$r_hidden["hidden_acceptor"] = (($DOCUMENT_URI != "") ? $DOCUMENT_URI : $_SERVER['PHP_SELF']);
//     only this script accepts r_hidden variable
//     - if it don't match - unset($r_hidden) (see init_page.pgp3)


if ($cancel) {
    if ($anonymous) { // anonymous login
        go_url( $r_slice_view_url, '', $encap );
    } else {
        go_return_or_url(self_base() . "index.php3",1,1,"slice_id=$slice_id");
    }
}

if ($update) {
    $action = "update";
} else {
    $action = "edit";
}

// ValidateContent4Id() sets GLOBAL!! variables:
//   $show_func_used   - list of show func used in the form
//   $js_proove_fields - JavaScript code for form validation
//   list ($fields, $prifields) = GetSliceFields ()
//   $oldcontent4id

$slice = AA_Slices::getSlice($slice_id);
ValidateContent4Id($err, $slice, $action, $id);
list($fields, $prifields) = $slice->fields(null, true);


//  update database
if ( $update AND (count($err)<=1) AND is_array($prifields) ) {

    // prepare content4id array before call StoreItem function
    $content4id = new ItemContent;
    $content4id->setFieldsFromForm( $slice, $oldcontent4id, false, true );

    // added_to_db contains id
    // removed $oldcontent4id (see ItemContent::storeItem)
    $added_to_db = $content4id->storeSliceFields( $slice_id, $fields);

    if (count($err) <= 1) {
        page_close();
        if ($anonymous) { // anonymous login
            go_url( $r_slice_view_url, '', $encap );
        } else {
            go_return_or_url(self_base() . "index.php3",1,1);
        }
    }
}

// -----------------------------------------------------------------------------
// Input form
// -----------------------------------------------------------------------------


unset( $content );       // used in another context for storing item to db
unset( $content4id );

if ($edit) {
    if ( !is_array($fields) ) {
        $err["DB"] = MsgErr(_m("Error: no fields."));
        // do not quit - just go back.
        go_return_or_url(self_base() . "index.php3",1,1);
    }

    // fill content array from item and content tables
    $content4id = $slice->get_dynamic_setting_content(true);
    if ( !$content4id ) {
        $err["DB"] = MsgErr(_m("Bad item ID id=%1", array($id)));
        MsgPage(con_url($sess->url(self_base() ."index.php3"), "slice_id=$slice_id"), $err, "standalone");
        exit;
    }
} else {
    // we need the $content4id to be object (for getForm, at least)
    $content4id = new ItemContent;
}

// print begin ---------------------------------------------------------------
if (!$encap) {
    $inputform_settings = array(
        'display_aa_begin_end' => true,
        'page_title'           => _m("Slice Setting"). " (". AA_Slices::getName($slice_id).")",
    );
}

$inputform_settings['messages']            = array('err' => $err);
$inputform_settings['form_action']         = ($_SERVER['DOCUMENT_URI'] != "" ? $_SERVER['DOCUMENT_URI'] :
                                             $_SERVER['PHP_SELF'] . ($return_url ? "?return_url=".urlencode($return_url) : ''));
$inputform_settings['form4update']         = true;
$inputform_settings['cancel_url']          =  ($anonymous  ? $r_slice_view_url :
                                              ($return_url=='close_dialog' ? get_admin_url("itemedit.php3?slice_id=$slice_id&cancel=1&return_url=close_dialog") :
                                              ($return_url ? expand_return_url(1) :
                                              get_admin_url("index.php3?cancel=1&slice_id=$slice_id"))));

$inputform_settings['hidden']              = array(
                             'anonymous'   => (($free OR $anonymous) ? true : ""),
                             'openervar'   => $openervar);  // id of variable in parent window (used for popup inputform)

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
?>
