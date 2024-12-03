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
// user calling is with $edit for edit item or $edit_new for add new item based on existing one
// optionally encap="false" if this form is not encapsulated into *.shtml file
// optionally free and freepwd for anonymous user login (free == login, freepwd == password)


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

use AA\Util\Langs;
use AA\Util\InputVars;

if ( isset($_REQUEST['edit']) || isset($_REQUEST['edit_new']) || isset($_REQUEST['add'])) {         // parameter for init_page - we edited new item so
    $unset_r_hidden = true;  // clear stored content
}

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";     // This pays attention to $change_id
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/feeding.php";
require_once __DIR__ . "/../include/itemfunc.php";
require_once __DIR__ . "/../include/notify.php";
require_once __DIR__ . "/../include/menu_util.php";
//mimo include mlx functions
require_once __DIR__."/../include/mlx.php";

if ( file_exists( "../include/usr_validate.php" ) ) {
    require_once __DIR__."/../include/usr_validate.php";
} elseif (file_exists( "../include/usr_validate.php3" ) ) {
    require_once __DIR__ . "/../include/usr_validate.php3";
}

try {
    // array ( )
    $aa = InputVars::arrayPOST('aa');
    // $aaedit, $aamng_* // not necessary
    // array ( '' => 153, )
    $anonymous = InputVars::boolPOST('anonymous'); // empty
    // array ( 1 => 246, )
    $edit     = InputVars::boolGET('edit');
    $edit_new = InputVars::boolGET('edit_new');
    // array ( '4b108926e80…' => 3, '541353ec974…' => 1, '31ac1b7f0be…' => 4, '37904f2f3bd…' => 4, '74c486828a6…' => 24, '79a16c06b61…' => 9, '0dbc7582674…' => 3, 'a3c409eccec…' => 5, '0a51c8215f6…' => 4, 'de8f491a367…' => 2, '845ebaf1f78…' => 1, 'ff380b84dcd…' => 2, '87959928674…' => 13, 'a07ea63a5e4…' => 2, '8f9a8bb4dbe…' => 1, '889f88d2b5b…' => 3, '66637bd5779…' => 3, 'a18c7c29971…' => 1, '5115e57c5f2…' => 2, 'a723bc05045…' => 70, 'ee1b7e3e3d9…' => 1, 'a51d744c1ee…' => 1, '4dc8c4e058f…' => 3, '67fda6a6d37…' => 16, '809cd40c6bb…' => 1, '76cbe350684…' => 51, '88cbbb83ae4…' => 3, '26f1853a7a1…' => 2, '603be11312a…' => 9, 'd0ca57c4e89…' => 1, '5bd78a13b0b…' => 1, )-array ( '4b108926e80…' => 2, '541353ec974…' => 2, '31ac1b7f0be…' => 10, '37904f2f3bd…' => 2, '74c486828a6…' => 13, '79a16c06b61…' => 14, '0dbc7582674…' => 1, 'a3c409eccec…' => 3, '0a51c8215f6…' => 3, 'ff380b84dcd…' => 1, '87959928674…' => 2, '889f88d2b5b…' => 1, 'a723bc05045…' => 56, '4dc8c4e058f…' => 1, '67fda6a6d37…' => 15, '76cbe350684…' => 7, '26f1853a7a1…' => 2, '603be11312a…' => 3, '5bd78a13b0b…' => 1, )
    $id = InputVars::idGETPOST('id'); // -
    // array ( '' => 153, )
    $mlxid = InputVars::idPOST('mlxid'); // empty
    // array ( '' => 153, )
    $mlxl = InputVars::stringPOST('mlxl'); // empty
    // array ( 'aa[n1_4085d…' => 2, 'aa[ua723bc0…' => 2, )-array ( '' => 150, 'aa[n1_4085d…' => 1, 'aa[ua723bc0…' => 2, )
    $openervar = InputVars::stringGETPOST('openervar'); // -
    // array ( 'close_dialog' => 9, )
    $return_url = InputVars::stringGETPOST('return_url');
    // array ( 20 => 1, 1 => 8, 6 => 2, 27 => 17, 8 => 2, 31 => 12, )
    //$tdctr_v6578706972795f646174652e2e2e2e2e_day = InputVars::intPOST('tdctr_v6578706972795f646174652e2e2e2e2e_day');
    // array ( '' => 30, )
    // $v616c65727473312e2e2e2e5073746669 = InputVars::nonePOST('v616c65727473312e2e2e2e5073746669'); // empty

    $vid = InputVars::stringGET('vid'); // display as view $vid

    $post_preview = $_REQUEST['post_preview'] ?? true;

    $cancel = InputVars::boolGET('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}





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
    // Used for adding item to another slice from itemedit's popup.
    $js = '';
    if ($zid) {               // id of new item defined
        // now we need to fill $item in order we can display item headline
        $item = AA_Item::getItem($zid);

        $aliases  = AA_Slice::getModule($item->getSliceID())?->aliases();
        DefineBaseAliases($aliases, $item->getSliceID());  // _#JS_HEAD_, ...
        $item->setAliases( (new AA_Aliases())->addFromArray($aliases) ); //      = new AA_Item($content->getContent(),$aliases);
        $function = $insert ? 'SelectRelations' : 'UpdateRelations';
        $item->setformat( "$function('$openervar','".TPS['AMB']['A']['tag']."','".TPS['AMB']['A']['prefix']."','".TPS['AMB']['A']['tag']."_#ITEM_ID_','_#JS_HEAD_');" );

        $js = $item->get_item() ."\n";
    }
    $js .= ($url2go ? "window.location = '$url2go';\n" : "window.close();\n");

    // inputform.min.js for SelectRelations
    echo getHtmlPage(getFrmJavascriptFile('javascript/inputform.min.js', ['v'=>AA_JS_VERSION]).  getFrmJavascript($js));
}

// Allow edit current slice without slice_pwd
AA_Credentials::singleton()->loadFromSlice(AA::$module_id);

if ($encap) {
    add_vars();        // adds values from QUERY_STRING_UNESCAPED
}                      // and REDIRECT_STRING_UNESCAPED - from url

InputVars::QuoteVars("post", ['encap' => 1]);  // quote variables to global space, but skip (already edited) encap variable
AA::setEncoding(Langs::getCharset());

$insert  = false;
$update  = false;
$preview = false;
$go_edit = false;
$edit    = $edit || $edit_new;
if ($_POST['insert'])      { $insert = true;                                               }
if ($_POST['insert_new'])  { $insert = true;                                               }
if ($_POST['ins_preview']) { $insert = true;                 $preview=true;                }
if ($_POST['ins_edit'])    { $insert = true;                                $go_edit=true; }
if ($_POST['update'])      {                 $update = true;                               }
if ($_POST['upd_preview']) {                 $update = true; $preview=true;                }
if ($_POST['upd_edit'])    {                 $update = true;                $go_edit=true; }

$add = !( $update OR $cancel OR $insert OR $edit );

if ($cancel) {
    if ($anonymous) { // anonymous login
        go_url( $r_slice_view_url, '', $encap );
    } elseif ($return_url === 'close_dialog') {
        // Used for adding item to another slice from itemedit's popup.
        CloseDialog();
    } else {
        go_return_or_url(self_base() . "index.php",true,true);
    }
}

// ValidateContent4Id() sets GLOBAL!! variables:
//   $show_func_used   - list of show func used in the form
//   $oldcontent4id

$id = $_REQUEST['id'];
$slice_id = AA::$module_id;

// link from public pages sometimes do not contain slice_id
if ( $id ) {
    if ((strlen($id)===33) && strpos(' xyz', $id[0])) {
        // x, y, z prefixed ids for new added related items
        $id = substr($id,1);
    }

    $content4id = new ItemContent($id);
    $slice_id   = $content4id->getSliceID();
    // we need just slice_id of current item - we can unset the content4id

} else {
    $content4id = new ItemContent();
}

// --- Process all AJAX commands and possibly EXIT ---------------------

$content_function = static function () use (&$content4id, $slice_id, $edit) {
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
$slice_fields = (!$edit_new && ($id === $slice->getId()));

// get slice fields and its priorities in inputform
$fields = $slice->getFields($slice_fields);

//mimo changes
if ($lang_control = MLXSlice($slice)) {
    if ($add) {
        $action = "add";
    } elseif ($insert) {
        $action = "insert";
    } elseif ($update) {
        $action = "update";
    } else {
        $action = "edit";
    }
}

$err = [];          // error array (Init - just for initializing variable

//  update database
if ( ($insert || $update) AND count($fields) ) {

    // prepare content4id array before call StoreItem function
    $content4id    = new ItemContent;
    $oldcontent4id = [];

    // it is needed to call IsEditable() function and GetContentFromForm()
    if ( $update ) {
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

    // id is updated before validation - unique username check needs it
    // we need to know the new id before mlx->update, since it is written to the MLX control slice
    if ($insert) {
        $id = new_id();
        $content4id->setItemID($id);
    }

    if ( $validate_report = $content4id->validateReport('visible')) {
        foreach ($validate_report as $vr_fid => $vr_err) {
            $err[$vr_fid] =  MsgErr(_m("Error in").' <b>'.$fields->getProperty($vr_fid,'name')."</b> - ".$vr_err[1]);
        }
    }

    if (!count($err)) {

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
            $preview_url = get_admin_url("preview.php", ["slice_id" => $slice_id, "sh_itm" => $id, "return_url" => $return_url]);
        }
        if ($anonymous) { // anonymous login
            go_url( $r_slice_view_url, '', $encap );
        } elseif ($return_url === 'close_dialog') {
            // Used for adding item to another slice from itemedit's popup.
            CloseDialog(new zids($added_to_db, 'l'), $openervar, $insert, $preview_url);
            page_close();
            exit;
        } elseif ($preview) {
            go_url( $preview_url );
        } elseif ($go_edit) {   // if go_edit - continue to edit again
            go_url( Inputform_url(false, $added_to_db, $slice_id, '', $vid, null, false) );
        } else {
            go_return_or_url(self_base() . "index.php",true,true);
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
        MsgPage($err);
        exit;
    }

    // fill content array from item and content tables
    $content = GetItemContent($id);
    if ( !$content ) {
        $err["DB"] = MsgErr(_m("Bad item ID id=%1", [$id]));
        MsgPage($err);
        exit;
    }

    $content4id = new ItemContent($content[$id]);

    // authors have only permission to edit their own items
    $perm_edit_all  = IfSlPerm(PS_EDIT_ALL_ITEMS);
    $item_user = $content4id->getValue('posted_by.......');
    $real_user = AuthGetUid();
    if (!( $perm_edit_all || ( $item_user == $real_user ) )) {
        $err["DB"] = MsgErr(_m("Error: You have no rights to edit item."));
        MsgPage($err);
        exit;
    }
} elseif (!is_object($content4id)) {  // form is submitted
    // we need the $content4id to be object (for getForm, at least)
    $content4id = new ItemContent;

    // not necessary - filled later
    // $content4id->setFieldsFromProfile($fields, AA_Profile::getProfile(AuthGetUid(), $slice->getId()));
}

// mimo changes
if ($lang_control) {
    if (is_null($mlx)) {
        $mlx = new MLX($slice);
    }
    [$mlx_formheading,$mlxl,$mlxid] = $mlx->itemform(['encap'=>$encap], $content4id->getContent(),$action,$mlxl,$mlxid);
}
// end mimo changes

// print begin ---------------------------------------------------------------
if ( !$encap ) {
    $inputform_settings = [
        'display_aa_begin_end' => true,
        'page_title'           => (($edit=="") ? _m("Add Item") : _m("Edit Item")). " (". trim(safe($slice->getName())).")",
        'formheading'          => $mlx_formheading
    ]; //added MLX
}
$inputform_settings['messages']            = ['err' => $err];
$inputform_settings['form_action']         = ($_SERVER['DOCUMENT_URI'] != "" ? $_SERVER['DOCUMENT_URI'] :
                                             $_SERVER['PHP_SELF'] . ($return_url ? "?return_url=".rawurlencode($return_url) : ''));
$inputform_settings['form4update']         = $edit || $update || ($insert && $added_to_db);
$inputform_settings['form4asnew']          = $edit_new;
$inputform_settings['show_preview_button'] = (($post_preview!=0) OR !isset($post_preview));

$inputform_settings['cancel_url']          =  ($anonymous  ? $r_slice_view_url :
                                              ($return_url === 'close_dialog' ? get_admin_url("itemedit.php", ['cancel' =>1, 'return_url' =>'close_dialog']) :
                                              ($return_url ? expand_return_url(1) :
                                              get_admin_url("index.php", ['cancel'=>1]))));

$inputform_settings['hidden']              = [
                             'anonymous'   => (($free OR $anonymous) ? true : ""),
                             'mlxid'       => $mlxid,
                             'mlxl'        => $mlxl,
                             'openervar'   => $openervar
];  // id of variable in parent window (used for popup inputform)

if ( $vid ) {
    $inputform_settings['template'] = $vid;
    $inputform_settings['hidden']['vid'] = $vid;
}


if ( $inputform_settings['form4update'] ) {
    $inputform_settings['hidden']['id']    = $id;
}


//AddPermObject($slice_id, "slice");    // no special permission added - only superuser can access
$form = new inputform($inputform_settings);
$form->printForm($content4id, $slice, ($edit OR $update));

page_close();

