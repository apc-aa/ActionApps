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
# expected $slice_id for edit slice, Add_slice=1 for adding slice
require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."date.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if($slice_id) {  // edit slice
  if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT)) {
   HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

    ?>
     <TITLE><?php echo L_A_SLICE_TIT;?></TITLE>
    </HEAD>
    <?php
    $xx = ($slice_id!="");
    $show = Array("main"=>false, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx,
                  "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
    require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_EDIT);
    exit;
  }
} else {          // add slice
  if(!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD)) {
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_ADD);
    exit;
  }
}

$err["Init"] = "";          // error array (Init - just for initializing variable
#$dexpirydate = new datectrl("d_expiry_date", 0, 15, true);
$varset = new Cvarset();

if( $add || $update ) {
  do {
    ValidateInput("headline", L_HEADLINE, &$headline, &$err, true, "text");
    ValidateInput("short_name", L_SHORT_NAME, &$short_name, &$err, true, "text");
    ValidateInput("slice_url", L_SLICE_URL, &$slice_url, &$err, false, "url");
    ValidateInput("d_expiry_limit", L_D_EXPIRY_LIMIT, $d_expiry_limit, &$err, false, "number");
    ValidateInput("d_hl_href", L_D_HL_HREF, $d_hl_href, &$err, false, "url");
    ValidateInput("d_source", L_D_SOURCE, $d_source, &$err, false, "text");
    ValidateInput("d_source_href", L_D_SOURCE_HREF, $d_source_href, &$err, false, "url");
    ValidateInput("d_place", L_D_PLACE, $d_place &$err, false, "text");
    ValidateInput("d_listlen", L_D_LISTLEN, $d_listlen, &$err, true, "number");
    ValidateInput("grab_len", L_GRAB_LEN, $grab_len, &$err, true, "number");
    ValidateInput("d_img_src", L_D_IMG_SRC, $d_img_src, &$err, false, "url");
    ValidateInput("d_img_width", L_D_IMG_WIDTH, $d_img_width, &$err, false, "number");
    ValidateInput("d_img_height", L_D_IMG_HEIGHT, $d_img_height, &$err, false, "number");
    ValidateInput("d_posted_by", L_D_POSTED_BY, $d_posted_by, &$err, false, "text");
    ValidateInput("d_e_posted_by", L_D_E_POSTED_BY, $d_e_posted_by, &$err, false, "email");
#    $dexpirydate->ValidateDate (L_D_EXPIRY_DATE, &$err);

    if( count($err) > 1)
      break;

    $varset->add("headline", "quoted", $headline);
    $varset->add("short_name", "quoted", $short_name);
    $varset->add("slice_url", "quoted", $slice_url);
    $varset->add("post_enabled", "number", $post_enabled ? 1 : 0);
    $varset->add("deleted", "number", $deleted ? 1 : 0);
    $varset->add("d_language_code", "quoted", $d_language_code);
    $varset->add("d_cp_code", "quoted", $d_cp_code);
    $varset->add("d_highlight", "number", $d_highlight ? 1 : 0);
    $varset->add("d_category_id", "unpacked", $d_category_id);
    $varset->add("d_status_code", "number", $d_status_code);
    $varset->add("d_expiry_limit", "number", $d_expiry_limit);
#    $varset->add("d_expiry_date", "date", $dexpirydate->getdate());
    $varset->add("d_hl_href", "quoted", $d_hl_href);
    $varset->add("d_source", "quoted", $d_source);
    $varset->add("d_source_href", "quoted", $d_source_href);
    $varset->add("d_place", "quoted", $d_place);
    $varset->add("d_listlen", "number", $d_listlen);
    $varset->add("grab_len", "number", $grab_len);
    $varset->add("d_html_formatted", "number", $d_html_formatted ? 1 : 0);
    $varset->add("d_link_only", "number", $d_link_only ? 1 : 0);
    $varset->add("d_img_src", "quoted", $d_img_src);
    $varset->add("d_img_width", "quoted", $d_img_width);
    $varset->add("d_img_height", "quoted", $d_img_height);
    $varset->add("d_posted_by", "quoted", $d_posted_by);
    $varset->add("d_e_posted_by", "quoted", $d_e_posted_by);

    if(!$d_expiry_limit)   // default value for limit
      $d_expiry_limit = 2000;
    
    if( $update )
    {
      $varset->add("res_persID", "unpacked", $res_persID);

      $SQL = "UPDATE slices SET ". $varset->makeUPDATE() . "WHERE id='$p_slice_id'";
      $db->query($SQL);
      if ($db->affected_rows() == 0)
      { $err["DB"] = "<div class=err>Can't change slice</div>";
        break;
      }
    }
    else  // insert
    {
      $slice_id = new_id();
      $varset->add("id", "unpacked", $slice_id);
      $p_slice_id=q_pack_id($slice_id);
      $varset->add("created_by", "text", $auth->auth["uid"]);
      $varset->add("created_at", "text", now());
      $varset->add("res_persID", "unpacked", 0);
      $varset->add("type", "quoted", $slice_type);

      $varset->add("edit_fields", "text", DEFAULT_EDIT_FIELDS);
      $varset->add("needed_fields", "text", DEFAULT_NEEDED_FIELDS);
      $varset->add("fulltext_format", "text", DEFAULT_FULLTEXT_HTML);
      $varset->add("odd_row_format", "text", DEFAULT_ODD_HTML);
      $varset->add("even_row_format", "text", DEFAULT_EVEN_HTML);
      $varset->add("compact_top", "text", DEFAULT_TOP_HTML);
      $varset->add("compact_bottom", "text", DEFAULT_BOTTOM_HTML);
      $varset->add("category_format", "text", DEFAULT_CATEGORY_HTML);
      $varset->add("category_sort", "number", DEFAULT_CATEGORY_SORT);
      $varset->add("even_odd_differ", "number", DEFAULT_EVEN_ODD_DIFFER);
      $varset->add("search_show", "text", DEFAULT_SEARCH_SHOW);
      $varset->add("search_default", "text", DEFAULT_SEARCH_DEFAULT);

      $db->query("INSERT INTO slices" . $varset->makeINSERT() );
      if ($db->affected_rows() == 0)
      { $err["DB"] .= "<div class=err>Can't add slice</div>";
        break;
      }
      $r_config_type[$slice_id] = $slice_type;
      $sess->register(slice_id);

      AddPermObject($slice_id, "slice");    // no special permission added - only superuser can access
    }
  }while(false);
  if( count($err) <= 1 )
  {
    page_close();                                // to save session variables

    $netscape = (($r=="") ? "r=1" : "r=".++$r);   // special parameter for Natscape to reload page

    if( $add )
      go_url($sess->url(self_base() . "se_category.php3"));
     else
      go_url($sess->url(self_base() . "slicedit.php3?$netscape"));
  }
}

if( $slice_id!="" ) {  // set variables from database - allways
  $SQL= " SELECT * FROM slices WHERE id='".q_pack_id($slice_id)."'";
  $db->query($SQL);
  if ($db->next_record())
    while (list($key,$val,,) = each($db->Record))
    {
      if( EReg("^[0-9]*$", $key))
        continue;
      $$key = $val; // there are the same name for variables and database atributs => fill variables
    }
  $id = unpack_id($db->f("id"));  // correct ids
  $d_category_id = unpack_id($db->f("d_category_id"));
  $res_pers_id = unpack_id($db->f("res_pers_id"));
#  $dexpirydate->setdate($d_expiry_date);
}

// lookup (languages)
$SQL= " SELECT * FROM lt_langs ";
$db->query($SQL);
while($db->next_record()) {
  $languages[$db->f(code)]= $db->f(name);
  $languages[$db->f(code)].=$db->f(altcode)?" (".$db->f(altcode).")":"";
}

// lookup (codepages)
$SQL= " SELECT * FROM lt_cps ";
$db->query($SQL);
while($db->next_record()) {
  $codepages[$db->f(code)]= $db->f(code);
  $codepages[$db->f(code)].=$db->f(w32cp)?" (".$db->f(w32cp).")":"";
}

// lookup (categories)
if( $slice_id=="" )
  $SQL= " SELECT name, id FROM categories";
else
  $SQL= " SELECT name, id FROM categories LEFT JOIN catbinds ON categories.id = catbinds.category_id WHERE catbinds.slice_id='".q_pack_id($slice_id)."'";
$db->query($SQL);
while($db->next_record()) {
  $categories[unpack_id($db->f(id))] = $db->f(name);
}

// lookup (bins)
  $bins[1]= L_ACTIVE_BIN;
  $bins[2]= L_HOLDING_BIN;
  $bins[3]= L_TRASH_BIN;

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_SLICE_TIT;?></TITLE>
</HEAD>
<?php
  $xx = ($slice_id!="");
  $show = Array("main"=>false, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx,
                "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

  echo "<H1><B>" . ( $slice_id=="" ? L_A_SLICE_ADD : L_A_SLICE_EDT) . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form enctype="multipart/form-data" method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="#584011" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_SLICES_HDR?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<?php
  FrmStaticText(L_ID, $slice_id);
  FrmInputText("headline", L_HEADLINE, $headline, 254, 25, true);
  FrmInputText("short_name", L_SHORT_NAME, $short_name, 254, 25, true);
  FrmInputText("slice_url", L_SLICE_URL, $slice_url, 254, 25, false);
  FrmInputText("d_listlen", L_D_LISTLEN, $d_listlen, 5, 5, true);
  FrmInputText("grab_len", L_GRAB_LEN, $grab_len, 5, 5, true);
//  FrmInputChBox("post_enabled", L_POST_ENABLED, $post_enabled);
  FrmInputChBox("deleted", L_DELETED, $deleted);
?>
</table></td></tr>
<tr><td class=tabtit><b>&nbsp;<?php echo L_SLICE_DEFAULTS?></b></td></tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<?php
  FrmInputSelect("d_language_code", L_D_LANGUAGE_CODE, $languages, $d_language_code);
  FrmInputSelect("d_cp_code", L_D_CP_CODE, $codepages, $d_cp_code);
  if( !isset($categories) )
    $categories[0]="-------------------";
  FrmInputSelect("d_category_id", L_D_CATEGORY_ID, $categories, $d_category_id);
  FrmInputSelect("d_status_code", L_D_STATUS_CODE, $bins, $d_status_code);
  FrmInputChBox("d_highlight", L_D_HIGHLIGHT, $d_highlight);
  FrmInputText("d_expiry_limit", L_D_EXPIRY_LIMIT, $d_expiry_limit, 5, 5, false);
#  echo "<tr><td class=tabtxt><b>".L_D_EXPIRY_DATE."</b></td><td>";
#    $dexpirydate->pselect();
#  echo "</td></tr>\n";
  FrmInputText("d_hl_href", L_D_HL_HREF, $d_hl_href, 254, 25, false);
  FrmInputChBox("d_link_only", L_D_LINK_ONLY, $d_link_only);
  FrmInputText("d_source", L_D_SOURCE, $d_source, 254, 25, false);
  FrmInputText("d_source_href", L_D_SOURCE_HREF, $d_source_href, 254, 25, false);
  FrmInputText("d_place", L_D_PLACE, $d_place, 254, 25, false);
  FrmInputChBox("d_html_formatted", L_D_HTML_FORMATTED, $d_html_formatted);
  FrmInputText("d_img_src", L_D_IMG_SRC, $d_img_src, 254, 25, false);
  FrmInputText("d_img_width", L_D_IMG_WIDTH, $d_img_width, 32, 25, false);
  FrmInputText("d_img_height", L_D_IMG_HEIGHT, $d_img_height, 32, 25, false);
  FrmInputText("d_posted_by", L_D_POSTED_BY, $d_posted_by, 254, 25, false);
  FrmInputText("d_e_posted_by", L_D_E_POSTED_BY, $d_e_posted_by, 254, 25, false);
?>
</table>
<tr><td align="center">
<?php
  if($slice_id=="") {
    echo "<input type=hidden name=\"add\" value=1>";        // action
    echo "<input type=hidden name=\"Add_slice\" value=1>";  // detects new slice
    echo "<input type=hidden name=slice_type value=\"". $slice_type .'">';
    echo "<input type=submit name=insert value=\"". L_INSERT .'">';
  }else{
    echo "<input type=hidden name=\"update\" value=1>";
    echo "<input type=hidden name=\"slice_id\" value=$slice_id>";
    echo '<input type=submit name=update value="'. L_UPDATE .'">&nbsp;&nbsp;';
    echo '<input type=reset value="'. L_RESET .'">&nbsp;&nbsp;';
    echo '<input type=submit name=cancel value="'. L_CANCEL .'">';
   }

/*
$Log$
Revision 1.4  2000/07/13 09:19:01  kzajicek
Variables $created_by and $created_at are initialized later, so
the actual effect was that Updates zeroized the database values! In fact
the database fields created_by and created_at should remain constant.
Do we need changed_by and changed_at?

Revision 1.3  2000/07/07 21:37:45  honzam
Slice ID is displayed

Revision 1.1.1.1  2000/06/21 18:40:05  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:56  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.17  2000/06/12 19:58:25  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.16  2000/06/09 15:14:10  honzama
New configurable admin interface

Revision 1.15  2000/04/24 16:45:03  honzama
New usermanagement interface.

Revision 1.14  2000/03/22 09:36:44  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/
?>
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()?>

