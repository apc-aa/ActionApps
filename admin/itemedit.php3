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

# expected at least $slice_id
# user calling is with $edit for edit item
# optionaly encap="false" if this form is not encapsulated into *.shtml file
# optionaly free and freepwd for anonymous user login (free == login, freepwd == password)



$encap = ( ($encap=="false") ? false : true );

if( $edit OR $add )         # parameter for init_page - we edited new item so
  $unset_r_hidden = true;   # clear stored content

require_once "../include/init_page.php3";     # This pays attention to $change_id
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
require_once $GLOBALS["AA_INC_PATH"]."feeding.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."itemfunc.php3";
require_once $GLOBALS["AA_INC_PATH"]."notify.php3";
require_once $GLOBALS["AA_INC_PATH"]."sliceobj.php3";
//mimo include mlx functions
require_once $GLOBALS["AA_INC_PATH"]."mlx.php";

//require_once $GLOBALS["AA_INC_PATH"]."inputform.class.php3";

// needed for field JavaScript to work

if( file_exists( $GLOBALS["AA_INC_PATH"]."usr_validate.php3" ) ) {
  include( $GLOBALS["AA_INC_PATH"]."usr_validate.php3" );
}

FetchSliceReadingPassword();

if ($encap) add_vars();        # adds values from QUERY_STRING_UNESCAPED
                               #       and REDIRECT_STRING_UNESCAPED - from url

QuoteVars("post", array('encap'=>1) );  # if magicquotes are not set, quote variables
                                        # but skip (already edited) encap variable

GetHidden();        // unpacks variables from $r_hidden session var.
unset($r_hidden);
$r_hidden["hidden_acceptor"] = (($DOCUMENT_URI != "") ? $DOCUMENT_URI : $PHP_SELF);
                    // only this script accepts r_hidden variable
                    // - if it don't match - unset($r_hidden) (see init_page.pgp3)

if( $ins_preview )
  $insert = true;
if( $upd_preview )
  $update = true;

$add = !( $update OR $cancel OR $insert OR $edit );

if($cancel) {
  if( $anonymous ) { // anonymous login
    if( $encap ) {
      echo '<SCRIPT Language="JavaScript"><!--
              document.location = "'. $r_slice_view_url .'";
            // -->
           </SCRIPT>';
    } else
      go_url( $r_slice_view_url );
  }
  else
    go_return_or_url(self_base() . "index.php3",1,1,"slice_id=$slice_id");
}

#$db = new DB_AA;

$varset = new Cvarset();
$itemvarset = new Cvarset();

if ($add) $action = "add";
else if ($insert) $action = "insert";
else if ($update) $action = "update";
else $action = "edit";

// ValidateContent4Id() sets GLOBAL!! variables:
//   $show_func_used   - list of show func used in the form
//   $js_proove_fields - JavaScript code for form validation
//   list ($fields, $prifields) = GetSliceFields ()
//   $oldcontent4id

ValidateContent4Id ($err, $slice_id, $action, $id);

$slice = new slice($slice_id);

//mimo changes
$lang_control = IsMLXSlice($slice);
//

  # update database
if( ($insert || $update) AND (count($err)<=1) AND is_array($prifields) ) {

  # prepare content4id array before call StoreItem function
  $content4id = GetContentFromForm( $fields, $prifields, $oldcontent4id, $insert );

  if ($slice->getfield('permit_anonymous_edit') == ANONYMOUS_EDIT_NOT_EDITED_IN_AA
   && ($content4id["flags..........."][0]['value'] & ITEM_FLAG_ANONYMOUS_EDITABLE))
    $content4id["flags..........."][0]['value'] -= ITEM_FLAG_ANONYMOUS_EDITABLE;

  if( $insert )
    $id = new_id();

// mimo change
  if($lang_control) {
    //print("mlxl=$mlxl<br>mlxid=$mlxid<br>action=$action<br>");
    $mlx = new MLX($slice);
    $mlx->update($content4id,$id,$action,$mlxl,$mlxid);
    //mlx_update($slice,$content4id,$id);
    //echo "<pre>"; print_r($content4id); echo "</pre>";
  }
// end

  $added_to_db = StoreItem( $id, $slice_id, $content4id, $fields, $insert,
                            true, true, $oldcontent4id );     # invalidatecache, feed
//echo "</pre>"; exit;
  if( count($err) <= 1) {
    page_close();
    if( $anonymous )  // anonymous login
      if( $encap ) {
        echo '<SCRIPT Language="JavaScript"><!--
                document.location = "'. $r_slice_view_url .'";
              // -->
             </SCRIPT>';
      } else
        go_url( $r_slice_view_url );
    elseif( $ins_preview OR $upd_preview )
      go_url( con_url($sess->url(self_base() .  "preview.php3"), "slice_id=$slice_id&sh_itm=$id"));
    else  {
      go_return_or_url(self_base() . "index.php3",1,1);
    }
  }
}

# -----------------------------------------------------------------------------
# Input form
# -----------------------------------------------------------------------------


unset( $content );       # used in another context for storing item to db
unset( $content4id );

if($edit) {
  if( ! is_array($fields) ) {
    $err["DB"] = MsgErr(_m("Error: no fields."));
    MsgPage(con_url($sess->url(self_base() ."index.php3"), "slice_id=$slice_id"),
            $err, "standalone");
    exit;
  }

    # fill content array from item and content tables
  $content = GetItemContent($id);
  if( !$content ) {
    $err["DB"] = MsgErr(_m("Bad item ID"));
    MsgPage(con_url($sess->url(self_base() ."index.php3"), "slice_id=$slice_id"),
            $err, "standalone");
    exit;
  }

  $content4id = $content[$id];
}

//mimo changes
if($lang_control ) {
	if(MLX_TRACE)
		print("mlxl=$mlxl<br>mlxid=$mlxid<br>action=$action<br>");
	if(empty($mlx)) 
		$mlx = new MLX($slice);
	$mlx_formheading = $mlx->itemform($lang_control,
		array('AA_CP_Session'=>$AA_CP_Session,'slice_id'=>$slice_id,'encap'=>$encap),
		$content4id,$action,$mlxl,$mlxid);
}

// end mimo changes

$r_hidden["slice_id"] = $slice_id;
$r_hidden["anonymous"] = (($free OR $anonymous) ? true : "");

# print begin ---------------------------------------------------------------
if( !$encap ) {
    $inputform_settings = array(
        'display_aa_begin_end' => true,
        'page_title'           => (($edit=="") ? _m("Add Item") : _m("Edit Item")),

        // next two variables are used for GetFormJavascript() function - javascript
        // validation when display_aa_begin_end is true
        'show_func_used'       => $show_func_used,
        'js_proove_fields'     => $js_proove_fields,
	'formheading'          => $mlx_formheading ); //added MLX
}
$inputform_settings['messages']            = array('err' => $err);
$inputform_settings['form_action']         = ($DOCUMENT_URI != "" ? $DOCUMENT_URI :
                                             $PHP_SELF . ($return_url ? "?return_url=".urlencode($return_url) : ''));

$inputform_settings['form4update']         = $edit || $update || ($insert && $added_to_db);
$inputform_settings['show_preview_button'] = (($post_preview!=0) OR !isset($post_preview));

$inputform_settings['cancel_url']          =  ($anonymous  ? $r_slice_view_url :
                                              ($return_url ? expand_return_url(1) :
                                              get_admin_url("index.php3?slice_id=$slice_id")));

if( $inputform_settings['form4update'] )  $r_hidden["id"] = $id;

if( $vid ) $inputform_settings['template'] = $vid;

//AddPermObject($slice_id, "slice");    // no special permission added - only superuser can access
$form = new inputform($inputform_settings);
$form->printForm($content4id, $fields, $prifields, $edit, $slice_id);

page_close();
?>
