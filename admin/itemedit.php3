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
# optionally $feeded - when user edit feeded item (if submitted it)
# optionaly encap="false" if this form is not encapsulated into *.shtml file
# optionaly free and freepwd for anonymous user login (free == login, freepwd == password)

$encap = ( ($encap=="false") ? false : true );

if( $ins_preview )
  $insert = true;
if( $upd_preview )
  $update = true;
  
$add = !( $update OR $cancel OR $insert OR $edit );

require "../include/init_page.php3";

require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."date.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."feeding.php3";

function LoadDefault($add,$insert,$update,$show, $variable, $value) {
  if( $add OR (!$show AND $insert) OR (!$show AND $update) ) 
    $variable = $value;
}

if($cancel) {
  if( $anonymous )  // anonymous login
    go_url( $r_slice_view_url );
   else 
    go_url( $sess->url(self_base() . "index.php3"));
}    

$db = new DB_AA;

$err["Init"] = "";          // error array (Init - just for initializing variable

$publishdate = new datectrl("publish_date", 1, 8);
$expirydate = new datectrl("expiry_date", 0, 15, true);
$varset = new Cvarset();

//lookup (slice) 
$SQL= " SELECT * FROM slices WHERE id='".q_pack_id($slice_id)."'";
$db->query($SQL);
if ($db->next_record()){ 
  $show = UnpackFieldsToArray($db->f(edit_fields), $itemedit_fields);
  $needed = UnpackFieldsToArray($db->f(needed_fields), $itemedit_fields);
  LoadDefault($add,$insert,$update,$show[language_code],&$language_code, quote($db->f(d_language_code)));
  LoadDefault($add,$insert,$update,$show[cp_code],&$cp_code, quote($db->f(d_cp_code)));
  LoadDefault($add,$insert,$update,$show[category_id],&$category_id, unpack_id($db->f(d_category_id)));
  LoadDefault($add,$insert,$update,$show[status_code],&$status_code, quote($db->f(d_status_code)));
  LoadDefault($add,$insert,$update,$show[hl_href],&$hl_href, quote($db->f(d_hl_href)));
  LoadDefault($add,$insert,$update,$show[link_only],&$link_only, quote($db->f(d_link_only)));
  LoadDefault($add,$insert,$update,$show[img_src],&$img_src, quote($db->f(d_img_src)));
  LoadDefault($add,$insert,$update,$show[img_width],&$img_width, quote($db->f(d_img_width)));
  LoadDefault($add,$insert,$update,$show[img_height],&$img_height, quote($db->f(d_img_height)));
  LoadDefault($add,$insert,$update,$show[source],&$source, quote($db->f(d_source)));
  LoadDefault($add,$insert,$update,$show[source_href],&$source_href, quote($db->f(d_source_href)));
  LoadDefault($add,$insert,$update,$show[place],&$place, quote($db->f(d_place)));
  LoadDefault($add,$insert,$update,$show[html_formatted],&$html_formatted, quote($db->f(d_html_formatted)));
  LoadDefault($add,$insert,$update,$show[posted_by],&$posted_by, quote($db->f(d_posted_by)));
  LoadDefault($add,$insert,$update,$show[e_posted_by],&$e_posted_by, quote($db->f(d_e_posted_by)));
  LoadDefault($add,$insert,$update,$show[highlight],&$highlight, $db->f(d_highlight));
  LoadDefault($add,$insert,$update,$show[post_date],&$post_date, now());
  LoadDefault($add,$insert,$update,$show[edited_by],&$edited_by, quote($auth->auth["uid"]));
  LoadDefault($add,$insert,$update,$show[last_edit],&$last_edit, now());
  LoadDefault($add,$insert,$update,$show[publish_date],&$publish_date, now());
  if ($db->f(d_expiry_limit)>0) 
    $e_d = date("Y-m-d H:m:s",mktime(0,0,0,date("m"),date("d")+$db->f(d_expiry_limit),date("Y")));
   else $e_d = $db->f(d_expiry_date);                   
  LoadDefault($add,$insert,$update,$show[expiry_date],&$expiry_date, $e_d);
  if( $publish_date != "")
    $publishdate->setdate($publish_date);
  if( $expiry_date != "")
    $expirydate->setdate($expiry_date);
} 

if($update) {
  $SQL= " SELECT items.*, fulltexts.full_text FROM items,fulltexts 
            WHERE fulltexts.ft_id = items.master_id AND id='".q_pack_id($id)."'";
  $db->query($SQL);
  if ($db->next_record()){ 
    if( !$show[full_text] ) $full_text = quote($db->f(full_text));
//    if( !$show[headline] ) $headline = $db->f(headline);
    if( !$show[abstract] ) $abstract = quote($db->f(abstract));
    if( !$show[edit_note] ) $edit_note = quote($db->f(edit_note));
  }
}  
  
// poor checkbox doesn't have value if unchecked
$link_only = ($link_only ? 1 : 0);  
$highlight = ($highlight ? 1 : 0); 
$html_formatted = ($html_formatted ? 1 : 0 ); 

// validate input data
if( $insert || $update )
{
  do{
    ValidateInput("headline", L_HEADLINE, &$headline, &$err, true, "text");
    ValidateInput("abstract", L_ABSTRACT, &$abstract, &$err, $needed[abstract], "text");
    if( !$feeded )
      ValidateInput("full_text", L_FULL_TEXT, &$full_text, &$err, $needed[full_text], "text");
    ValidateInput("hl_href", L_HL_HREF, &$hl_href, &$err, $needed[hl_href], "url");
    ValidateInput("place", L_PLACE, &$place, &$err, $needed[place], "text");
    ValidateInput("source", L_SOURCE, &$source, &$err, $needed[source], "text");
    ValidateInput("source_href", L_SOURCE_HREF, &$source_href, &$err, $needed[source_href], "url");
    ValidateInput("img_src", L_IMG_SRC, &$img_src, &$err, $needed[img_src], "text");
    ValidateInput("img_width", L_IMG_WIDTH, &$img_width, &$err, $needed[img_width], "text");
    ValidateInput("img_height", L_IMG_HEIGHT, &$img_height, &$err, $needed[img_height], "text");
    ValidateInput("posted_by", L_POSTED_BY, &$posted_by, &$err, $needed[posted_by], "text");
    ValidateInput("e_posted_by", L_E_POSTED_BY, &$e_posted_by, &$err, $needed[e_posted_by], "email");
    ValidateInput("edit_note", L_EDIT_NOTE, &$edit_note, &$err, $needed[edit_note], "text");
    ValidateInput("language_code", L_LANGUAGE_CODE, &$language_code, &$err, $needed[language_code], "text");
    ValidateInput("cp_code", L_CP_CODE, &$cp_code, &$err, $needed[cp_code], "text");
    ValidateInput("status_code", L_STATUS_CODE, &$status_code, &$err, $needed[status_code], "number");
    ValidateInput("category_id", L_CATEGORY_ID, &$category_id, &$err, $needed[category_id], "id");
  
    $publishdate->ValidateDate (L_PUBLISH_DATE, &$err);
    $expirydate->ValidateDate (L_EXPIRY_DATE, &$err);

    if( count($err) > 1)
      break;

    if(($img_download <> "none")&&($img_download <> "")){        // see if the file exists downloaded picture;
      $dest_file = $img_download_name;
      if( file_exists(IMG_DOWNLOAD_PATH.$img_download_name) )
        $dest_file = new_id().substr(strrchr($img_download_name, "." ), 0 );

      if(!copy($img_download,IMG_DOWNLOAD_PATH.$dest_file)){     // copy the file from the temp directory to the upload directory, and test for success
        $err["Image"] = L_CANT_UPLOAD;          // error array (Init - just for initializing variable
        break;
      }   
      $img_src = IMG_DOWNLOAD_URL.$dest_file;
    }
  
    if( count($err) > 1)
      break;
    $varset->add("headline", "quoted", $headline);
    $varset->add("abstract", "quoted", $abstract);              //full_text is removed from this table - moved to fultexts table
    $varset->add("publish_date", "text", $publishdate->getdatetime());
    $varset->add("expiry_date", "text", $expirydate->getdate());
    $varset->add("category_id", "unpacked", $category_id);
    $varset->add("status_code", "number", $status_code);
    $varset->add("cp_code", "quoted", $cp_code);
    $varset->add("link_only", "number", $link_only);
    $varset->add("hl_href", "quoted", $hl_href);
    $varset->add("img_src", "quoted", $img_src);
    $varset->add("language_code", "quoted", $language_code);
    $varset->add("img_width", "quoted", $img_width);
    $varset->add("img_height", "quoted", $img_height);
    $varset->add("html_formatted", "number", $html_formatted);
    $varset->add("source", "quoted", $source);
    $varset->add("source_href", "quoted", $source_href);
    $varset->add("place", "quoted", $place);
    $varset->add("highlight", "number", $highlight);
    $varset->add("posted_by", "quoted", $posted_by);
    $varset->add("e_posted_by", "quoted", $e_posted_by);
    $varset->add("edited_by", "quoted", $auth->auth["uid"]);
    $varset->add("last_edit", "quoted", now());
    $varset->add("edit_note", "quoted", $edit_note);
    $varset->add("contact1", "unpacked", $contact1);
    $varset->add("contact2", "unpacked", $contact2);
    $varset->add("contact3", "unpacked", $contact3);
    
    if( $update )
    {
      $varset->add("created_by", "quoted", $created_by);
      $SQL = "UPDATE items SET ". $varset->makeUPDATE() . " WHERE id='". q_pack_id($id). "'";
      $db->query($SQL);
      if ($db->affected_rows() == 0) {
        $err["DB"] = "<div class=err>". L_ITEM_NOT_CHANGED ."</div>";
        break;
      }     
      $db->query("update fulltexts set full_text = '". $full_text ."' where ft_id='".q_pack_id($id)."'");
      if( $status_code == 1 )  //Approved bin
        FeedItem($id, $db);
    }    
    else { // insert
      $id = new_id();
      $varset->add("id", "unpacked", $id);    
      $varset->add("master_id", "unpacked", $id);    
      $varset->add("slice_id", "unpacked", $slice_id);
      $varset->add("created_by", "text", $auth->auth["uid"]);
      $varset->add("post_date", "quoted", $post_date);
  
      $db->query("INSERT INTO items " . $varset->makeINSERT() );
      if ($db->affected_rows() == 0) {
        $err["DB"] .= "<div class=err>". L_CANT_ADD_ITEM ."</div>";
        break;
      }   
      $SQL="INSERT INTO fulltexts (ft_id, full_text) 
            VALUES ('". q_pack_id($id) ."', '". $full_text ."')";
    	$db->query($SQL);
      $added_to_db = true;
      FeedItem($id, $db);
    }
  } while(false);
  if( count($err) <= 1) {
    if( $anonymous )  // anonymous login
      go_url( $r_slice_view_url );
     elseif( $ins_preview OR $upd_preview ) 
      go_url( con_url($sess->url(self_base() .  "preview.php3"), "slice_id=$slice_id&sh_itm=$id"));
     else 
      go_url( con_url($sess->url(self_base() .  "index.php3"), "slice_id=$slice_id"));
  }  
}

// override Memo with File if provided
//if($Ffile_size > 0) { $Fmemo = (join("", file($Ffile))); } 

// lookup (categories) 
$SQL= " SELECT name, id FROM categories LEFT JOIN catbinds ON categories.id = catbinds.category_id WHERE catbinds.slice_id='".q_pack_id($slice_id)."'";
$db->query($SQL);
while($db->next_record()) 
  $categories[unpack_id($db->f(id))] = $db->f(name);

// lookup (contacts) 
// not coded yet 

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

if($edit) {
  $SQL = "select items.*, fulltexts.full_text from items, fulltexts 
                where fulltexts.ft_id = items.master_id AND id='".q_pack_id($id)."'";
	$db->query($SQL);
	if($db->next_record()) {
    $tmp_slice_id = $slice_id;  
    $tmp_id = $id;  
    while (list($key,$val,,) = each($db->Record)) {  
      if( EReg("^[0-9]*$", $key))
        continue;
      $$key = $val; // there are the same name for variables and database atributs => fill variables
    }               // replaces $id and $slice_id !!!
    $slice_id = $tmp_slice_id;  
    $id = $tmp_id;  
		$publishdate->setdate($db->f("publish_date"));
		$expirydate->setdate($db->f("expiry_date"));
    $category_id = unpack_id($db->f("category_id")); 
  }
}

if( !$encap ) {
  HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
  echo '<title>'.( $edit=="" ? L_A_ITEM_ADD : L_A_ITEM_EDT). '</title>
      </head>
      <body>
       <H1><B>' . ( $edit=="" ? L_A_ITEM_ADD : L_A_ITEM_EDT) . '</B></H1>';
}       
PrintArray($err);
echo $Msg;  

/*
$Log$
Revision 1.1  2000/06/21 18:39:57  madebeer
Initial revision

Revision 1.1.1.1  2000/06/12 21:49:46  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.19  2000/06/12 19:58:23  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.18  2000/06/09 15:14:09  honzama
New configurable admin interface

Revision 1.17  2000/06/06 23:12:59  pepturro
Fixed img upload: properly set img_src field

Revision 1.16  2000/04/28 09:48:13  honzama
Small bug in user/group search fixed.

Revision 1.15  2000/04/24 16:38:13  honzama
Anonymous item posting.

Revision 1.14  2000/03/29 14:33:04  honzama
Fixed bug of adding slashes before ' " and \ characters in fulltext.

Revision 1.13  2000/03/22 09:36:43  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/
?>
<center>
<form enctype="multipart/form-data" method=post action="<?php echo $sess->url( ($DOCUMENT_URI != "") ? $DOCUMENT_URI : $PHP_SELF) ?>">

<table border="0" cellspacing="0" cellpadding="1" bgcolor="#584011" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_ITEM_HDR?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<?
  FrmInputText("headline", L_HEADLINE, safe($headline), 254, 60, true);
  if($show[abstract])
    FrmTextarea("abstract", L_ABSTRACT, safe($abstract), 4, 60, $needed[abstract]);

  if( ($db->f(id) == $db->f(master_id)) AND $show[full_text])  {  // base item or new item
    if($show[html_formatted]) {  ?>
      <tr>
        <td valign=top class=tabtxt><b><?php 
          echo L_FT_FORMATTING;
          Needed($needed[html_formatted]);
          ?></b></td>
        <?php 
          $foo=$html_formatted;           
          $checkhtml=($foo>0)?"checked":""; $checkplain=(!($foo>0))?"checked":"";              
        ?>
        <td><input type=radio name=html_formatted value=1 <?php echo $checkhtml ?>><?php echo L_FT_FORMATTING_HTML?>
            <input type=radio name=html_formatted value=0 <?php echo $checkplain?>><?php echo L_FT_FORMATTING_PLAIN?>   
        </td>
      </tr><?php
    }  
    FrmTextarea("full_text", L_FULL_TEXT, safe($full_text), 8, 60, $needed[full_text]);
  }
  if($show[highlight])
    FrmInputChBox("highlight", L_HIGHLIGHT, $highlight, false, "", 1, $needed[highlight]);
  if($show[link_only])
    FrmInputChBox("link_only", L_LINK_ONLY, $link_only, false, "", 1, $needed[link_only]);
  if($show[hl_href])
    FrmInputText("hl_href", L_HL_HREF, safe($hl_href), 254, 60, $needed[hl_href]);
  if($show[place])
    FrmInputText("place", L_PLACE, safe($place), 254, 60, $needed[place]);
  if($show[source])
    FrmInputText("source", L_SOURCE, safe($source), 254, 60, $needed[source]);
  if($show[source_href])
    FrmInputText("source_href", L_SOURCE_HREF, safe($source_href), 254, 60, $needed[source_href]);
  if($show[status_code]) {?>
    <tr>
      <td class=tabtxt><b> <?php
         echo L_STATUS_CODE;
         Needed($needed[status_code]);
       ?></b></td>
      <?php
       $foo=$status_code;           
       $check1=($foo==1)?"checked":"";
       $check2=($foo==2)?"checked":"";
       $check3=($foo==3)?"checked":"";              
      ?>
      <td><input type=radio name=status_code value=1 <?php echo $check1 ?>><?php echo L_ACTIVE_BIN ?><br><input type=radio name=status_code value=2 <?php echo $check2 ?>><?php echo L_HOLDING_BIN ?><br><input type=radio name=status_code value=3 <?php echo $check3 ?>><?php echo L_TRASH_BIN ?>
      </td>
    </tr><?php
  }  
  if($show[language_code])
    FrmInputSelect("language_code", L_LANGUAGE_CODE, $languages, $language_code, $needed[language_code]); 
  if($show[cp_code])
    FrmInputSelect("cp_code", L_CP_CODE, $codepages, $cp_code, $needed[cp_code]); 
  if( isset($categories) AND is_array($categories) AND $show[category_id])
    FrmInputSelect("category_id", L_CATEGORY, $categories, $category_id, $needed[category_id]);

  if($show[img_src])
    FrmInputText("img_src", L_IMG_SRC, safe($img_src), 254, 60, $needed[img_src]);
  if($show[img_width])
    FrmInputText("img_width", L_IMG_WIDTH, safe($img_width), 254, 60, $needed[img_width]);
  if($show[img_height])
    FrmInputText("img_height", L_IMG_HEIGHT, safe($img_height), 254, 60, $needed[img_height]);
  if($show[img_download])
    FrmInputFile("img_download", L_IMG_DOWNLOAD, 40, $needed[img_download]);
  if($show[posted_by])
    FrmInputText("posted_by", L_POSTED_BY, safe($posted_by), 254, 60, $needed[posted_by]);
  if($show[e_posted_by])
    FrmInputText("e_posted_by", L_E_POSTED_BY, safe($e_posted_by), 254, 60, $needed[e_posted_by]);
  if($show[publish_date]) { ?>
    <tr>
      <td class=tabtxt><b><?php
        echo L_PUBLISH_DATE;
        Needed($needed[publish_date]) ?></b></td>
      <td><?php echo $publishdate->pselect(); ?><?php echo $err["publish_date"] ?>
      </td>
    </tr><?php
  }
  if($show[expiry_date]) { ?>
    <tr>
      <td class=tabtxt><b><?php 
        echo L_EXPIRY_DATE;
        Needed($needed[expiry_date]) ?></b></td>
      <td><?php echo $expirydate->pselect(); ?><?php echo $err["expiry_date"] ?>
      <div class=tabhlp>Expiry date is date, when item stops to be viewed on web</div> <!-- This is an example of help (for Klara) -->
      </td>
    </tr><?php
  }    
  if($show[edit_note]) 
    FrmTextarea("edit_note", L_EDIT_NOTE, safe($edit_note), 4, 60, $needed[edit_note]); ?>
<tr>
  <td colspan=2>
  <?php 
  if(DEBUG_FLAG) {
    echo '<I>';
    echo L_POSTDATE.": ".(datetime2date(dequote($post_date)));
    echo  " ".L_CREATED_BY.": $created_by";
    echo "<br>";
    echo  L_LASTEDIT." $edited_by";
    echo " ".L_AT." ". dequote($last_edit); 
    echo '</I>';
  }  
  if( ($db->f(id) != $db->f(master_id)) ) 
    echo "<input type=hidden name=feeded value=1>";
  ?>
  <input type=hidden name=post_date value="<?php echo $post_date ?>">
  <input type=hidden name=created_by value="<?php echo $created_by ?>">
  <input type=hidden name=edited_by value="<?php echo $edited_by ?>">
  <input type=hidden name=last_edit value="<?php echo $last_edit ?>">
  <input type=hidden name=slice_id value="<?php echo $slice_id ?>">
  <input type=hidden name=anonymous value="<?php echo (($free OR $anonymous) ? true : "") ?>">
  <input type=hidden name="MAX_FILE_SIZE" value="<?php echo IMAGE_DOWNLOAD_MAX_SIZE ?>">
  </td>
</tr>
</table></td></tr>
<tr><td align=center>
<?php if($edit || $update || ($insert && $added_to_db)) { ?>
   <input type=submit name=update value="<?php echo L_POST ?>">
   <input type=submit name=upd_preview value="<?php echo L_POST_PREV ?>">
   <input type=reset value="<?php echo L_RESET ?>">
   <input type=hidden name=id value="<?php echo $id ?>">
<?php } else { ?>
   <input type=submit name=insert value="<?php echo L_INSERT ?>">
   <input type=submit name=ins_preview value="<?php echo L_POST_PREV ?>">
<?php } ?>
<input type=submit name=cancel value="<?php echo L_CANCEL ?>">
</td>
</tr>
</table>
<input type=hidden name=encap value="<?php echo ($encap ? "true" : "false")?>">
</form>
</center>
<?php
if( !$encap ) 
  echo '</body></html>';
page_close(); ?>
