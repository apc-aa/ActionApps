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

# These aren't used here , would be better to create where used like $db!
$varset = new Cvarset();
$itemvarset = new Cvarset();

if ($add) $action = "add";
else if ($insert) $action = "insert";
else if ($update) $action = "update";
else $action = "edit";

ValidateContent4Id ($err, $slice_id, $action, $id);
$slice_info = GetSliceInfo ($slice_id);

  # update database
if( ($insert || $update) AND (count($err)<=1) AND is_array($prifields) ) {

  # prepare content4id array before call StoreItem function
  $content4id = GetContentFromForm( $fields, $prifields, $oldcontent4id, $insert );

  if ($slice_info["permit_anonymous_edit"] == ANONYMOUS_EDIT_NOT_EDITED_IN_AA
   && ($content4id["flags..........."][0]['value'] & ITEM_FLAG_ANONYMOUS_EDITABLE))
    $content4id["flags..........."][0]['value'] -= ITEM_FLAG_ANONYMOUS_EDITABLE;

  if( $insert )
    $id = new_id();

  $added_to_db = StoreItem( $id, $slice_id, $content4id, $fields, $insert,
                            true, true, $oldcontent4id );     # invalidatecache, feed

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

//print_r($content);

# print begin ---------------------------------------------------------------

if( !$encap ) {
  HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
  echo '
    <style>
        #body_white_color { color: #000000; }
    </style>';
  echo GetFormJavascript ($show_func_used, $js_proove_fields);
  echo '
    <title>'.( $edit=="" ? _m("Add Item") : _m("Edit Item")). '</title>
  </head>
  <body id="body_white_color">
    <H1><B>' . ( $edit=="" ? _m("Add Item") : _m("Edit Item")) . '</B></H1>';
}

PrintArray($err);
echo $Msg;

$PASS_PARAM=$PHP_SELF;
if ($return_url)
  $PASS_PARAM .= "?return_url=".urlencode($return_url);

if ( $show_func_used ['fil'])  # uses fileupload?
  $html_form_type = 'enctype="multipart/form-data"';

echo "<form name=inputform $html_form_type method=post action=\""
    .($DOCUMENT_URI != "" ? $DOCUMENT_URI : $PASS_PARAM).'"'
    .getTriggers ("form","v".unpack_id("inputform"),array("onSubmit"=>"return BeforeSubmit()")).'>
    <table width="95%" border="0" cellspacing="0" cellpadding="1" bgcolor="'.COLOR_TABTITBG.'" align="center" class="inputtab">'; ?>
<tr><td class=tabtit align="center"><b>&nbsp;</b></td></tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" class="inputtab2">
<?php
if( ($errmsg = ShowForm($content4id, $fields, $prifields, $edit)) != "" )
  echo "<tr><td>$errmsg</td></tr>";

?>
<tr>
  <td colspan=2>
  <?php
  $r_hidden["slice_id"] = $slice_id;
  $r_hidden["anonymous"] = (($free OR $anonymous) ? true : "");
  $sess->hidden_session();
  echo '<input type="hidden" name="slice_id" value="'. $slice_id .'">';
  echo '<input type="hidden" name="MAX_FILE_SIZE" value="'. IMG_UPLOAD_MAX_SIZE .'">';
  echo '<input type="hidden" name="encap" value="'. (($encap) ? "true" : "false") .'">'; ?>
  </td>
</tr>
</table></td></tr>
<tr><td align=center><?php

// is the accesskey working?
detect_browser();
if ($BPlatform == "Macintosh") {
    if ($BName == "MSIE"
        || ($BName == "Netscape" && $BVersion >= "6"))
        $accesskey = "(ctrl+S)";
}
else {
    if ($BName == "MSIE"
        || ($BName == "Netscape" && $BVersion > "5"))
       $accesskey = "(alt+S)";
};

if($edit || $update || ($insert && $added_to_db)) {
    echo '<input type=submit name=update accesskey=S value="'._m("Update")." ".$accesskey.'"> ';
    if ((!($post_preview==0)) or (!(isset($post_preview))))
        echo "<input type=submit name=upd_preview value='"._m("Update & View")."'> ";
	echo '
    <input type=submit name=insert value="'._m("Insert as new").'">
    <input type=reset value="'._m("Reset form").'">';
    $r_hidden["id"] = $id;
} else {
	echo '
    <input type=submit name=insert accesskey=S value="'._m("Insert")." ".$accesskey.'">
    <input type=submit name=ins_preview value="'._m("Insert & View").'">';
}
$cancel_url = ($anonymous  ? $r_slice_view_url :
              ($return_url ? expand_return_url(1) :
                             con_url($sess->url(self_base() ."index.php3"), "slice_id=$slice_id")));
echo '
    <input type=button name=cancel value="'._m("Cancel").'"
	onclick="document.location=\''.$cancel_url.'\'">
</td>
</tr>
</table>
</form>';
if( !$encap )
    echo "</body></html>";
page_close();
?>
