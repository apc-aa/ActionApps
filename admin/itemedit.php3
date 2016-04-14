<?php
/**
 *
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

// expected at least $slice_id
// user calling is with $edit for edit item
// optionaly encap="false" if this form is not encapsulated into *.shtml file
// optionaly free and freepwd for anonymous user login (free == login, freepwd == password)

$encap = ( ($encap=="false") ? false : true );

if ($edit OR $add) {         // parameter for init_page - we edited new item so
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
//mimo include mlx functions
require_once AA_INC_PATH."mlx.php";

if ( file_exists( AA_INC_PATH."usr_validate.php3" ) ) {
    require_once AA_INC_PATH."usr_validate.php3";
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
    global $tps; // defined in constants.php3
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
        $item->setformat( "$function('$openervar','".$tps['AMB']['A']['tag']."','".$tps['AMB']['A']['prefix']."','".$tps['AMB']['A']['tag']."_#ITEM_ID_','_#JS_HEAD_');" );

        $js = ' // variables for related selection
                var maxcount = '. MAX_RELATED_COUNT .';
                var relmessage = "'._m("There are too many related items. The number of related items is limited.") .'";
                '. $item->get_item() ."\n";
    }
    $js .= ($url2go ? "document.location = '$url2go';\n" : "window.close();\n");

    FrmHtmlPage(array('body'=> getFrmJavascriptFile('javascript/inputform.js').  // for SelectRelations
                               getFrmJavascript($js)));
}

// Allow edit current slice without slice_pwd
$credentials = AA_Credentials::singleton();
$credentials->loadFromSlice($slice_id);

if ($encap) {
    add_vars();        // adds values from QUERY_STRING_UNESCAPED
}                      // and REDIRECT_STRING_UNESCAPED - from url

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

if ( $ins_preview ) {
    $insert = true; $preview=true;
}
if ( $ins_edit ) {
    $insert = true; $go_edit=true;
}
if ( $upd_edit ) {
    $update = true; $go_edit=true;
}
if ( $upd_preview ) {
    $update = true; $preview=true;
}


$add = !( $update OR $cancel OR $insert OR $edit );

if ($cancel) {
    if ($anonymous) { // anonymous login
        go_url( $r_slice_view_url, '', $encap );
    } elseif ($return_url=='close_dialog') {
        // Used for adding item to another slice from itemedit's popup.
        CloseDialog();
    } else {
        go_return_or_url(self_base() . "index.php3?slice_id=$slice_id",true,true);
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
//   list ($fields, $prifields) = GetSliceFields ()
//   $oldcontent4id

// link from public pages sometimes do not contain slice_id
if ( $id ) {
    $content4id = new ItemContent($id);
    $slice_id   = $content4id->getSliceID();
    // we need just slice_id of current item - we can unset the content4id
    unset($content4id);
}

$slice = AA_Slice::getModule($slice_id);
ValidateContent4Id($err, $slice, $action, $id);
list($fields, $prifields) = $slice->fields();

//mimo changes
$lang_control = IsMLXSlice($slice);

//  update database
if ( ($insert || $update) AND (count($err)<=1) AND is_array($prifields) ) {

    // prepare content4id array before call StoreItem function
    $content4id = new ItemContent;
    $content4id->setFromForm( $slice, $oldcontent4id, $insert ); // sets also [slice_id] as well as [id]

    if ($slice->getProperty('permit_anonymous_edit') == ANONYMOUS_EDIT_NOT_EDITED_IN_AA) {
        // unset ITEM_FLAG_ANONYMOUS_EDITABLE bit in flag
        $content4id->setValue('flags...........', $content4id->getValue('flags...........') & ~ITEM_FLAG_ANONYMOUS_EDITABLE);
    }

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

    if (count($err) <= 1) {
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
            go_url( Inputform_url(false, $added_to_db, '', '', null, null, false) );
        } else {
            go_return_or_url(self_base() . "index.php3?slice_id=$slice_id",true,true);
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
        MsgPage(con_url($sess->url(self_base() ."index.php3"), "slice_id=$slice_id"), $err);
        exit;
    }

    // fill content array from item and content tables
    $content = GetItemContent($id);
    if ( !$content ) {
        $err["DB"] = MsgErr(_m("Bad item ID id=%1", array($id)));
        MsgPage(con_url($sess->url(self_base() ."index.php3"), "slice_id=$slice_id"), $err);
        exit;
    }

    $content4id = new ItemContent($content[$id]);

    // authors have only permission to edit their own items
    $perm_edit_all  = IfSlPerm(PS_EDIT_ALL_ITEMS);
    $item_user = $content4id->getValue('posted_by.......');
    $real_user = $auth->auth['uid'];
    if (!( $perm_edit_all || ( $item_user == $real_user ) )) {
        $err["DB"] = MsgErr(_m("Error: You have no rights to edit item."));
        MsgPage(con_url($sess->url(self_base() ."index.php3"), "slice_id=$slice_id"), $err);
        exit;
    }

} else {
    // we need the $content4id to be object (for getForm, at least)
    $content4id = new ItemContent;
}

// mimo changes
if ($lang_control) {
    if (MLX_TRACE) {
        print("mlxl=$mlxl<br>mlxid=$mlxid<br>action=$action<br>");
    }
    if (empty($mlx)) {
        $mlx = new MLX($slice);
    }
    list($mlx_formheading,$mlxl,$mlxid) = $mlx->itemform(array('AA_CP_Session'=>$AA_CP_Session,'slice_id'=>$slice_id,'encap'=>$encap), $content4id->getContent(),$action,$mlxl,$mlxid);
}
// end mimo changes

// print begin ---------------------------------------------------------------
if ( !$encap ) {
    $inputform_settings = array(
        'display_aa_begin_end' => true,
        'page_title'           => (($edit=="") ? _m("Add Item") : _m("Edit Item")). " (". trim($slice->name()).")",
        'formheading'          => $mlx_formheading ); //added MLX
}
$inputform_settings['messages']            = array('err' => $err);
$inputform_settings['form_action']         = ($_SERVER['DOCUMENT_URI'] != "" ? $_SERVER['DOCUMENT_URI'] :
                                             $_SERVER['PHP_SELF'] . ($return_url ? "?return_url=".urlencode($return_url) : ''));
$inputform_settings['form4update']         = $edit || $update || ($insert && $added_to_db);
$inputform_settings['show_preview_button'] = (($post_preview!=0) OR !isset($post_preview));

$inputform_settings['cancel_url']          =  ($anonymous  ? $r_slice_view_url :
                                              ($return_url=='close_dialog' ? get_admin_url("itemedit.php3?slice_id=$slice_id&cancel=1&return_url=close_dialog") :
                                              ($return_url ? expand_return_url(1) :
                                              get_admin_url("index.php3?cancel=1&slice_id=$slice_id"))));

$inputform_settings['hidden']              = array(
                             'anonymous'   => (($free OR $anonymous) ? true : ""),
                             'mlxid'       => $mlxid,
                             'mlxl'        => $mlxl,
                             'openervar'   => $openervar);  // id of variable in parent window (used for popup inputform)

if ( $inputform_settings['form4update'] ) {
    $inputform_settings['hidden']['id']    = $id;
}



if ( $vid ) {
    $inputform_settings['template'] = $vid;
}

//AddPermObject($slice_id, "slice");    // no special permission added - only superuser can access
$form = new inputform($inputform_settings);
$form->printForm($content4id, $slice, $edit);

page_close();
?>
