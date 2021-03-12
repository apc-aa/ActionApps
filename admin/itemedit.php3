<?php
/**
 *
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

// expected at least $slice_id
// user calling is with $edit for edit item
// optionaly encap="false" if this form is not encapsulated into *.shtml file
// optionaly free and freepwd for anonymous user login (free == login, freepwd == password)


// buttons
//   update      - submit - "Update"
//   upd_edit    - submit - "Update & Edit"
//   upd_preview - submit - "Update & View"
//   insert      - submit - "Insert as new"
//   insert      - submit - "Insert"
//   ins_edit    - submit - "Insert & Edit"
//   ins_preview - submit - "Insert & View"
//   reset       - reset  - "Reset form"
//   cancel      - button - "Cancel"

$encap = ( ($encap=="false") ? false : true );

if ($edit OR $add) {         // parameter for init_page - we edited new item so
    $unset_r_hidden = true;  // clear stored content
}

require_once __DIR__."/../include/init_page.php3";     // This pays attention to $change_id
require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../include/varset.php3";
require_once __DIR__."/../include/feeding.php3";
require_once __DIR__."/../include/itemfunc.php3";
require_once __DIR__."/../include/notify.php3";
//mimo include mlx functions
require_once __DIR__."/../include/mlx.php";

if ( file_exists( "../include/usr_validate.php3" ) ) {
    require_once __DIR__."/../include/usr_validate.php3";
}

/** Function for extracting variables from $r_hidden session field */
/*
 not used at this moment (all variables are sent in the form - clearer approach)
function GetHidden($itemform_id) {
    global $r_hidden;
    if ( isset($r_hidden) AND is_array($r_hidden[$itemform_id])) {
        foreach ($r_hidden[$itemform_id] as $varname => $value) {
            $GLOBALS[$varname] = ($value);
        }
    }
}
*/
/** CloseDialog function
 * @param $zid=null
 * @param $openervar=null
 * @param $insert=true
 * @param $url2go=null
 *
 */
function CloseDialog($zid = null, $openervar = null, $insert=true, $url2go=null) {
    global $TPS; // defined in formutil.php3
    // Used for adding item to another slice from itemedit's popup.
    $js = '';
    if ($zid) {               // id of new item defined
        // now we need to fill $item in order we can display item headline
        $content  = new ItemContent($zid);
        $slice    = AA_Slice::getModule($content->getSliceID());
        $aliases  = $slice->aliases();
        DefineBaseAliases($aliases, $content->getSliceID());  // _#JS_HEAD_, ...
        $item     = new AA_Item($content->getContent(),$aliases);
        $function = $insert ? 'SelectRelations' : 'UpdateRelations';
        $item->setformat( "$function('$openervar','".$TPS['AMB']['A']['tag']."','".$TPS['AMB']['A']['prefix']."','".$TPS['AMB']['A']['tag']."_#ITEM_ID_','_#JS_HEAD_');" );

        $js = $item->get_item() ."\n";
    }
    $js .= ($url2go ? "document.location = '$url2go';\n" : "window.close();\n");

    // inputform.min.js for SelectRelations
    echo getHtmlPage(getFrmJavascriptFile('javascript/inputform.min.js?v='.AA_JS_VERSION).  getFrmJavascript($js));
}

// Allow edit current slice without slice_pwd
AA_Credentials::singleton()->loadFromSlice($slice_id);

if ($encap) {
    add_vars();        // adds values from QUERY_STRING_UNESCAPED
}                      // and REDIRECT_STRING_UNESCAPED - from url

QuoteVars("post", ['encap'=>1]);  // if magicquotes are not set, quote variables
                                        // but skip (already edited) encap variable
AA::setEncoding(AA_Langs::getCharset());

$insert  = false;
$update  = false;
$preview = false;
$go_edit = false;
if ($_POST['insert'])      { $insert = true;                                               }
if ($_POST['ins_preview']) { $insert = true;                 $preview=true;                }
if ($_POST['ins_edit'])    { $insert = true;                                $go_edit=true; }
if ($_POST['update'])      {                 $update = true;                               }
if ($_POST['upd_preview']) {                 $update = true; $preview=true;                }
if ($_POST['upd_edit'])    {                 $update = true;                $go_edit=true; }

$cancel = (bool)$_GET['cancel'];

$add = !( $update OR $cancel OR $insert OR $edit );

if ($cancel) {
    if ($anonymous) { // anonymous login
        go_url( $r_slice_view_url, '', $encap );
    } elseif ($return_url=='close_dialog') {
        // Used for adding item to another slice from itemedit's popup.
        CloseDialog();
    } else {
        go_return_or_url(StateUrl(self_base()."index.php3"),true,true);
    }
}

if ($add) {
    $action = "add";
} elseif ($insert) {
    $action = "insert";
} elseif ($update) {
    $action = "update";
} else {
    $action = "edit";
}

// ValidateContent4Id() sets GLOBAL!! variables:
//   $show_func_used   - list of show func used in the form
//   $js_proove_fields - JavaScript code for form validation
//   $oldcontent4id

$id = $_REQUEST['id'];

// link from public pages sometimes do not contain slice_id
if ( $id ) {
    if ((strlen($id)==33) AND strpos(' xyz', $id{0})) {
        // x, y, z prefixed ids for new added related items
        $id = substr($id,1);
    }

    $content4id = new ItemContent($id);
    $slice_id   = $content4id->getSliceID();
    // we need just slice_id of current item - we can unset the content4id

} else {
    $content4id = new ItemContent();
}

// --- Process all AJAX commandes and possibly EXIT ---------------------

$content_function = function () use (&$content4id, $slice_id, $edit) {
    return (new inputform([]))->dryEvaluate($content4id, AA_Slice::getModule($slice_id), $edit);
};

$page_changer = new \AA\IO\OnPageChanger($content_function);
$page_changer->checkAndProcessCall();


unset($content4id);

// --- if not EXITEed above, continue

$slice = AA_Slice::getModule($slice_id);

// we need it for setting default fields - without it it is not filled
// ValidateContent4Id($err, $slice, $action, $id);

// Are we editing dynamic slice setting fields?
$slice_fields = ($id == $slice->getId());

// get slice fields and its priorities in inputform
$fields = $slice->getFields($slice_fields);

//mimo changes
$lang_control = MLXSlice($slice);


//  update database
if ( ($insert || $update) AND count($fields) ) {

    // prepare content4id array before call StoreItem function
    $content4id    = new ItemContent;
    $oldcontent4id = [];

    // it is needed to call IsEditable() function and GetContentFromForm()
    if ( $action == "update" ) {
        // if we are editing dynamic slice setting fields (stored in content
        // table), we need to get values from slice's fields
        if ($slice_fields) {
            $oldcontent4id = $slice->get_dynamic_setting_content(true)->getContent();   // shortcut
        } else {
            $oldcontent4id = GetItemContent($id)[$id];   // shortcut
        }
    }

    $content4id->setFromForm($slice, $id, $oldcontent4id, $insert, $slice_fields); // sets also [slice_id] as well as [id]

    if ($slice->getProperty('permit_anonymous_edit') == ANONYMOUS_EDIT_NOT_EDITED_IN_AA) {
        // unset ITEM_FLAG_ANONYMOUS_EDITABLE bit in flag
        $content4id->setValue('flags...........', $content4id->getValue('flags...........') & ~ITEM_FLAG_ANONYMOUS_EDITABLE);
    }

    if ( $validate_report = $content4id->validateReport('visible')) {
        foreach ($validate_report as $vr_fid => $vr_err) {
            $err[$vr_fid] =  MsgErr(_m("Error in").' <b>'.$fields->getProperty($vr_fid,'name')."</b> - ".$vr_err[1]);
        }
    }

    if (!count($err)) {

        // we need to know the new id before mlx->update, since it is written
        // to the MLX control slice
        if ($insert) {
            $id = new_id();
            $content4id->setItemID($id);
        }

        // mimo change
        if ($lang_control) {
            $mlx = new MLX($slice);
            $mlx->update($content4id, $id, $action, $mlxl, $mlxid);
        }
        // end

        // added_to_db contains id
        // removed $oldcontent4id (see ItemContent::storeItem)
        $added_to_db = $content4id->storeItem($insert ? 'insert' : 'update');     // invalidatecache, feed

        page_close();
        if ($preview) {
            $preview_url = get_url(get_admin_url("preview.php3"), "slice_id=$slice_id&sh_itm=$id&return_url=$return_url");
        }
        if ($anonymous) { // anonymous login
            go_url( $r_slice_view_url, '', $encap );
        } elseif ($return_url=='close_dialog') {
            // Used for adding item to another slice from itemedit's popup.
            CloseDialog(new zids($added_to_db, 'l'), $openervar, $insert, $preview_url);
            page_close();
            exit;
        } elseif ($preview) {
            go_url( $preview_url );
        } elseif ($go_edit) {   // if go_edit - continue to edit again
            go_url( Inputform_url(false, $added_to_db, $slice_id, '', null, null, false) );
        } else {
            go_return_or_url(StateUrl(self_base() . "index.php3"),true,true);
        }
    }
}

// -----------------------------------------------------------------------------
// Input form
// -----------------------------------------------------------------------------


unset( $content );       // used in another context for storing item to db
if ($edit) {
    if ( !count($fields) ) {
        $err["DB"] = MsgErr(_m("Error: no fields."));
        MsgPage(con_url(StateUrl(self_base() ."index.php3")), $err);
        exit;
    }

    // fill content array from item and content tables
    $content = GetItemContent($id);
    if ( !$content ) {
        $err["DB"] = MsgErr(_m("Bad item ID id=%1", [$id]));
        MsgPage(con_url(StateUrl(self_base() ."index.php3"), ''), $err);
        exit;
    }

    $content4id = new ItemContent($content[$id]);

    // authors have only permission to edit their own items
    $perm_edit_all  = IfSlPerm(PS_EDIT_ALL_ITEMS);
    $item_user = $content4id->getValue('posted_by.......');
    $real_user = $auth->auth['uid'];
    if (!( $perm_edit_all || ( $item_user == $real_user ) )) {
        $err["DB"] = MsgErr(_m("Error: You have no rights to edit item."));
        MsgPage(con_url(StateUrl(self_base() ."index.php3")), $err);
        exit;
    }
} elseif (!is_object($content4id)) {  // form is submitted
    // we need the $content4id to be object (for getForm, at least)
    $content4id = new ItemContent;

    // not necessary - filled later
    // $content4id->setFieldsFromProfile($fields, AA_Profile::getProfile($auth->auth["uid"], $slice->getId()));
}

// mimo changes
if ($lang_control) {
    if (MLX_TRACE) {
        print("mlxl=$mlxl<br>mlxid=$mlxid<br>action=$action<br>");
    }
    if (empty($mlx)) {
        $mlx = new MLX($slice);
    }
    [$mlx_formheading,$mlxl,$mlxid] = $mlx->itemform(['encap'=>$encap], $content4id->getContent(),$action,$mlxl,$mlxid);
}
// end mimo changes

// print begin ---------------------------------------------------------------
if ( !$encap ) {
    $inputform_settings = [
        'display_aa_begin_end' => true,
        'page_title'           => (($edit=="") ? _m("Add Item") : _m("Edit Item")). " (". trim($slice->getName()).")",
        'formheading'          => $mlx_formheading
    ]; //added MLX
}
$inputform_settings['messages']            = ['err' => $err];
$inputform_settings['form_action']         = ($_SERVER['DOCUMENT_URI'] != "" ? $_SERVER['DOCUMENT_URI'] :
                                             $_SERVER['PHP_SELF'] . ($return_url ? "?return_url=".urlencode($return_url) : ''));
$inputform_settings['form4update']         = $edit || $update || ($insert && $added_to_db);
$inputform_settings['show_preview_button'] = (($post_preview!=0) OR !isset($post_preview));

$inputform_settings['cancel_url']          =  ($anonymous  ? $r_slice_view_url :
                                              ($return_url=='close_dialog' ? get_admin_url("itemedit.php3?cancel=1&return_url=close_dialog") :
                                              ($return_url ? expand_return_url(1) :
                                              get_admin_url("index.php3?cancel=1"))));

$inputform_settings['hidden']              = [
                             'anonymous'   => (($free OR $anonymous) ? true : ""),
                             'mlxid'       => $mlxid,
                             'mlxl'        => $mlxl,
                             'openervar'   => $openervar
];  // id of variable in parent window (used for popup inputform)

if ( $inputform_settings['form4update'] ) {
    $inputform_settings['hidden']['id']    = $id;
}

if ( $vid ) {
    $inputform_settings['template'] = $vid;
}

//AddPermObject($slice_id, "slice");    // no special permission added - only superuser can access
$form = new inputform($inputform_settings);
$form->printForm($content4id, $slice, ($edit OR $update));

page_close();

