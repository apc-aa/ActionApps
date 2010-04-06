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

$show_needed_fields = array( abstract=>L_ABSTRACT, html_formatted=>L_HTML_FORMATTED, full_text=>L_FULL_TEXT, highlight=>L_HIGHLIGHT, hl_href=>L_HL_HREF, link_only=>L_LINK_ONLY, place=>L_PLACE, source=>L_SOURCE, source_href=>L_SOURCE_HREF, status_code=>L_STATUS_CODE, language_code=>L_LANGUAGE_CODE, cp_code=>L_CP_CODE, category_id=>_m("Category ID"), img_src=>L_IMG_SRC, img_width=>L_IMG_WIDTH, img_height=>L_IMG_HEIGHT, posted_by=>L_POSTED_BY, e_posted_by=>L_E_POSTED_BY, publish_date=>L_PUBLISH_DATE, expiry_date=>L_EXPIRY_DATE, edit_note=>L_EDIT_NOTE, img_src=>L_IMG_UPLOAD, redirect=>L_REDIRECT );


if ( defined("EXTENDED_ITEM_TABLE") ) {
//  $item_fields_text = array("id", "master_id", "slice_id", "category_id", "language_code", "cp_code", "headline", "hl_href", "post_date", "publish_date", "expiry_date", "abstract", "img_src", "source", "source_href", "place", "posted_by", "e_posted_by", "created_by", "edited_by", "last_edit", "contact1", "contact2", "contact3", "edit_note", "img_width", "img_height", "redirect", "source_desc", "source_address", "source_city", "source_prov", "source_country", "start_date", "end_date", "time", "con_name", "con_email", "con_phone", "con_fax", "loc_name", "loc_address", "loc_city", "loc_prov", "loc_country");
//  $item_fields_num  = array("status_code", "link_only", "html_formatted", "highlight" );
    // list of fields in packed array of shown fields in itemedit.php3
  $itemedit_fields  = array( headline=>array(L_HEADLINE, "",     "headline........"),
                             post_date=>array(L_POST_DATE, "",   "post_date.......", "dte"),
                             created_by=>array(L_CERATED_BY, "", "posted_by......."),
                             edited_by=>array(_m("Edited by"), "",   "edited_by......."),
                             last_edit=>array(L_LAST_EDIT, "",   "last_edit.......", "dte"),
                             abstract=>array(L_ABSTRACT, "", "abstract........"),
                             html_formatted=>array(L_HTML_FORMATTED, "", ""),
                             full_text=>array(L_FULL_TEXT, "", "full_text......."),
                             highlight=>array(L_HIGHLIGHT, "d_highlight", "highlight......."), 
                             hl_href=>array(L_HL_HREF, "d_hl_href", "hl_href........."), 
                             link_only=>array(L_LINK_ONLY, "d_link_only", "link_only......."), 
                             place=>array(L_PLACE, "d_place", "place..........."), 
                             source=>array(L_SOURCE, "d_source", "source.........."), 
                             source_href=>array(L_SOURCE_HREF, "d_source_href", "source_href....."), 
                             status_code=>array(L_STATUS_CODE, "d_status_code", "status_code....."), 
                             language_code=>array(L_LANGUAGE_CODE, "d_language_code", "lang_code......."), 
                             cp_code=>array(L_CP_CODE, "d_cp_code", "cp_code........."), 
                             category_id=>array(_m("Category ID"), "d_category_id", "category........"), 
                             img_src=>array(L_IMG_SRC, "d_img_src", "file............"), 
                             img_width=>array(L_IMG_WIDTH, "d_img_width", "img_width......."), 
                             img_height=>array(L_IMG_HEIGHT, "d_img_height", "img_height......"), 
                             posted_by=>array(L_POSTED_BY, "", "created_by......"), 
                             e_posted_by=>array(L_E_POSTED_BY, "d_e_posted_by", "e_posted_by....."), 
                             publish_date=>array(L_PUBLISH_DATE, "", "publish_date....", "dte"), 
                             expiry_date=>array(L_EXPIRY_DATE, "d_expiry_limit", "expiry_date.....", "dte"), 
                             edit_note=>array(L_EDIT_NOTE, "", "edit_note......."), 
                             img_upload=>array(L_IMG_UPLOAD, "", ""), 
                             redirect=>array(L_REDIRECT, "d_redirect", "url............."), 
                             con_name=>array(L_CON_NAME, "", "con_name"), 
                             con_email=>array(L_CON_EMAIL, "", "con_email"), 
                             con_phone=>array(L_CON_PHONE, "", "con_phone"), 
                             con_fax=>array(L_CON_FAX, "", "con_fax"), 
                             source_desc=>array(L_SOURCE_DESC, "", "source_desc"), 
                             source_address=>array(L_SOURCE_ADDRESS, "", "source_address"), 
                             source_city=>array(L_SOURCE_CITY, "", "source_city"), 
                             source_prov=>array(L_SOURCE_PROV, "", "source_prov"), 
                             source_country=>array(L_SOURCE_COUNTRY, "", "source_country"), 
                             start_date=>array(L_START_DATE, "", "start_date", "dte"), 
                             end_date=>array(L_END_DATE, "", "end_date", "dte"), 
                             time=>array(L_TIME, "", "time"), 
                             loc_name=>array(L_LOC_NAME, "", "loc_name"), 
                             loc_address=>array(L_LOC_ADDRESS, "", "loc_address"), 
                             loc_city=>array(L_LOC_CITY, "", "loc_city"), 
                             loc_prov=>array(L_LOC_PROV, "", "loc_prov"), 
                             loc_country=>array(L_LOC_COUNTRY, "", "loc_country"));
} else {
    // list of text fields in items table (used in feeding.php3 for inserting into database)
//  $item_fields_text = array("id", "master_id", "slice_id", "category_id", "language_code", "cp_code", "headline", "hl_href", "post_date", "publish_date", "expiry_date", "abstract", "img_src", "source", "source_href", "place", "posted_by", "e_posted_by", "created_by", "edited_by", "last_edit", "contact1", "contact2", "contact3", "edit_note", "img_width", "img_height", "redirect");
//  $item_fields_num  = array("status_code", "link_only", "html_formatted", "highlight" );
    // list of fields in packed array of shown fields in itemedit.php3
  $itemedit_fields  = array( headline=>array(L_HEADLINE, "",     "headline........"),
                             post_date=>array(L_POST_DATE, "",   "post_date.......", "dte"),
                             created_by=>array(L_CERATED_BY, "", "posted_by......."),
                             edited_by=>array(_m("Edited by"), "",   "edited_by......."),
                             last_edit=>array(L_LAST_EDIT, "",   "last_edit.......", "dte"),
                             abstract=>array(L_ABSTRACT, "", "abstract........"),
                             html_formatted=>array(L_HTML_FORMATTED, "", ""),
                             full_text=>array(L_FULL_TEXT, "", "full_text......."),
                             highlight=>array(L_HIGHLIGHT, "d_highlight", "highlight......."), 
                             hl_href=>array(L_HL_HREF, "d_hl_href", "hl_href........."), 
                             link_only=>array(L_LINK_ONLY, "d_link_only", "link_only......."), 
                             place=>array(L_PLACE, "d_place", "place..........."), 
                             source=>array(L_SOURCE, "d_source", "source.........."), 
                             source_href=>array(L_SOURCE_HREF, "d_source_href", "source_href....."), 
                             status_code=>array(L_STATUS_CODE, "d_status_code", "status_code....."), 
                             language_code=>array(L_LANGUAGE_CODE, "d_language_code", "lang_code......."), 
                             cp_code=>array(L_CP_CODE, "d_cp_code", "cp_code........."), 
                             category_id=>array(_m("Category ID"), "d_category_id", "category........"), 
                             img_src=>array(L_IMG_SRC, "d_img_src", "file............"), 
                             img_width=>array(L_IMG_WIDTH, "d_img_width", "img_width......."), 
                             img_height=>array(L_IMG_HEIGHT, "d_img_height", "img_height......"), 
                             posted_by=>array(L_POSTED_BY, "d_posted_by", "posted_by......."), 
                             e_posted_by=>array(L_E_POSTED_BY, "d_e_posted_by", "e_posted_by....."), 
                             publish_date=>array(L_PUBLISH_DATE, "", "publish_date....", "dte"), 
                             expiry_date=>array(L_EXPIRY_DATE, "d_expiry_limit", "expiry_date.....", "dte"), 
                             edit_note=>array(L_EDIT_NOTE, "", "edit_note......."), 
                             img_upload=>array(L_IMG_UPLOAD, "", ""), 
                             redirect=>array(L_REDIRECT, "d_redirect", "url............."));
}

// gets slice fields
function GetOldSliceInfo($old_slice_id) {
  global $odb;
  $p_slice_id = q_pack_id($old_slice_id);
  $SQL = "SELECT * FROM slices WHERE id='$p_slice_id'";
  huhu($SQL);
  $odb->query( $SQL );
  return  ($odb->next_record() ? $odb->Record : false);
}  

function ExitScript($txt) {
  if ( isset($txt) AND is_array($txt) )
    print_r($txt);
  else
    echo $txt;
  exit;    
}
  
function UpdateNewField( $field_id, $default, $show, $needed ) {
  global $db, $oldinfo, $p_slice_id;
  
  $fce = (( $field_id == 'expiry_date.....') ? 'dte' : 'qte');

  $SQL = "UPDATE field set required = ". ($needed ? 1 : 0) . ", 
                           input_show = ". ($show ? 1 : 0);
  if ($default)
    $SQL .=             ", input_default = '$fce:$default'";
    
  $SQL .= " WHERE id='$field_id'
             AND slice_id = '$p_slice_id'";
  
  huhu( $SQL );
  if ( $GLOBALS['fire'] ) {
    if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
      $err["DB"] .= MsgErr("Can't copy constant");
      return false;
    }
  }
  
  echo "<br>Definition of $field_id field updated";
}  
                           
function PrepareContent4id($source) {
  global $itemedit_fields;
  
  reset($itemedit_fields);
  while ( list( $ofld, $arr ) = each( $itemedit_fields )) {
    if ( $arr[2] ) {
      $res[$arr[2]][0]['value'] = (( $arr[3] == 'dte' ) ? strtotime($source[$ofld]) : addslashes($source[$ofld]));
      $res[$arr[2]][0]['flag'] = 0;
    }  
  }
   
  $res['category........'][0]['value'] = addslashes($source['category']);
  $res['full_text.......'][0]['flag'] = ($source['html_formatted'] ? FLAG_HTML : 0);
  
  return $res;
}       
   
function GetOldCategories() {
  global $odb, $p_slice_id;
  
  $SQL= " SELECT name, id FROM categories, catbinds WHERE categories.id = catbinds.category_id AND catbinds.slice_id='".$p_slice_id."'";
  huhu( $SQL );
  $odb->query($SQL);
  while ($odb->next_record()){
    $unpacked=unpack_id($odb->f("id"));  
    $arr[$unpacked]=$odb->f("name");  
  }
  return $arr;  
} 

function huhu($txt) {
  echo "<br>";
  $txt = htmlspecialchars($txt);
  if ( $GLOBALS['fire'] )
    echo "<font color='red'>$txt</font>";
   else 
    echo "$txt";
}  


/*
$Log: move_util.php3,v $
Revision 1.4  2005/04/25 11:46:21  honzam
a bit more beauty code - some coding standards setting applied

Revision 1.3  2003/01/27 13:51:04  jakubadamek
fixed language constants

Revision 1.2  2003/01/21 07:02:05  mitraearth
*** empty log message ***

Revision 1.1  2001/12/18 11:29:40  honzam
database conversion script - working version

*/
?>