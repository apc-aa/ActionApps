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
 
#
# Used constants. Do not edit if you are not developer.
#

  // list of text fields in items table (used in feeding.php3 for inserting into database)
$ITEM_FIELDS_TEXT = array("id", "master_id", "slice_id", "category_id", "language_code", "cp_code", "headline", "hl_href", "post_date", "publish_date", "expiry_date", "abstract", "img_src", "source", "source_href", "place", "posted_by", "e_posted_by", "created_by", "edited_by", "last_edit", "contact1", "contact2", "contact3", "edit_note", "img_width", "img_height");
$ITEM_FIELDS_NUM  = array("status_code", "link_only", "html_formatted", "highlight" );

  // list of fields in packed array of shown fields in itemedit.php3
$ITEMEDIT_FIELDS = array( abstract=>L_ABSTRACT, html_formatted=>L_HTML_FORMATTED, full_text=>L_FULL_TEXT, highlight=>L_HIGHLIGHT, hl_href=>L_HL_HREF, link_only=>L_LINK_ONLY, place=>L_PLACE, source=>L_SOURCE, source_href=>L_SOURCE_HREF, status_code=>L_STATUS_CODE, language_code=>L_LANGUAGE_CODE, cp_code=>L_CP_CODE, category_id=>L_CATEGORY_ID, img_src=>L_IMG_SRC, img_width=>L_IMG_WIDTH, img_height=>L_IMG_HEIGHT, posted_by=>L_POSTED_BY, e_posted_by=>L_E_POSTED_BY, publish_date=>L_PUBLISH_DATE, expiry_date=>L_EXPIRY_DATE, edit_note=>L_EDIT_NOTE, img_upload=>L_IMG_UPLOAD);

  // list of fields in packed array of shown fields in big_srch.php3
$SHOWN_SEARCH_FIELDS = array( slice=>L_SRCH_SLICE, category=>L_SRCH_CATEGORY, author=>L_SRCH_AUTHOR, language=>L_SRCH_LANGUAGE, from=>L_SRCH_FROM, to=>L_SRCH_TO, headline=>L_SRCH_HEADLINE, abstract=>L_SRCH_ABSTRACT, full_text=>L_SRCH_FULL_TEXT, edit_note=>L_SRCH_EDIT_NOTE);
  // list of fields in packed array of default values in big_srch.php3
$DEFAULT_SEARCH_IN = array( headline=>L_SRCH_HEADLINE, abstract=>L_SRCH_ABSTRACT, full_text=>L_SRCH_FULL_TEXT, edit_note=>L_SRCH_EDIT_NOTE);


// - new --

  # There we can mention $FIELD_TYPES, but they are not defined in this file, 
  # but in database as slecial slice with id 'AA_Core_Fields..'
  
  # Field types - each field in slice is one of this type. 
  # The types are defined APC wide for easy item interchanging between APC nodes
  # (on the other hand, new type can be added just by placing new fileld 
  # in database table fields as for 'AA_Core_Fields..' slice).

# ---  

$LANGUAGE_FILES = array( "en_news_lang.php3" => "en_news_lang.php3",
                         "cz_news_lang.php3" => "cz_news_lang.php3");
  
$SLICE_FIELDS_TEXT = array("id", "name", "owner", "created_by", "created_at",
   "type", "fulltext_format_top", "fulltext_format", "fulltext_format_bottom",
   "odd_row_format", "even_row_format", "compact_top", "compact_bottom",
   "category_top", "category_format", "category_bottom", "config", "slice_url",
   "lang_file", "fulltext_remove", "compact_remove", "notify_sh_offer", 
   "notify_sh_accept", "notify_sh_remove", "notify_holding_item",
   "admin_format_top", "admin_format", "admin_format_bottom", "admin_remove");

$SLICE_FIELDS_NUM  = array( "deleted", "export_to_all", "template", 
   "even_odd_differ", "category_sort", "d_expiry_limit", "d_listlen",  
   "email_sub_enable", "exclude_from_dir");

$FIELD_FIELDS_TEXT = array(  "id", "type", "slice_id", "name", 
  "input_help", "input_morehlp", "input_default",
  "input_show_func", "content_id", "search_type", "search_help",
  "search_before", "search_more_help", "alias1", "alias1_func", "alias1_help",
  "alias2", "alias2_func", "alias2_help", "alias3","alias3_func", "alias3_help", 
  "input_before", "aditional", "input_validate", "input_insert_func", "in_item_tbl"); 

$FIELD_FIELDS_NUM = array( "input_pri", "required", "feed", "multiple",
  "search_pri", "search_show", "search_ft_show", "search_ft_default",
  "content_edit", "html_default", "html_show", "input_show", "text_stored"); 

  // array of default function description
$INPUT_DEFAULT_TYPES = array ("txt" => L_INPUT_DEFAULT_TXT,
                              "dte" => L_INPUT_DEFAULT_DTE, 
                              "uid" => L_INPUT_DEFAULT_UID,
                              "now" => L_INPUT_DEFAULT_NOW);
  
$INPUT_SHOW_FUNC_TYPES = array ("txt" => L_INPUT_SHOW_TXT,
                                "fld" => L_INPUT_SHOW_FLD, 
                                "sel" => L_INPUT_SHOW_SEL, 
                                "rio" => L_INPUT_SHOW_RIO, 
                                "dte" => L_INPUT_SHOW_DTE, 
                                "chb" => L_INPUT_SHOW_CHB, 
                                "fil" => L_INPUT_SHOW_FIL, 
                                "nul" => L_INPUT_SHOW_NUL);
                              
$INPUT_VALIDATE_TYPES = array ("text" => L_INPUT_VALIDATE_TEXT,
                               "url" => L_INPUT_VALIDATE_URL, 
                               "e-mail" => L_INPUT_VALIDATE_EMAIL, 
                               "number" => L_INPUT_VALIDATE_NUMBER, 
                               "id" => L_INPUT_VALIDATE_ID, 
                               "date" => L_INPUT_VALIDATE_DATE, 
                               "bool" => L_INPUT_VALIDATE_BOOL);

$INPUT_INSERT_TYPES = array ("qte" => L_INPUT_INSERT_QTE,
                             "boo" => L_INPUT_INSERT_BOO,
                             "fil" => L_INPUT_INSERT_FIL,
                             "uid" => L_INPUT_INSERT_UID, 
                             "now" => L_INPUT_INSERT_NOW
                           /*"dte" => L_INPUT_INSERT_DTE, 
                             "cns" => L_INPUT_INSERT_CNS, 
                             "num" => L_INPUT_INSERT_NUM,
                             "nul" => L_INPUT_INSERT_NUL*/);
                             
$ALIAS_FUNC_TYPES = array ( "f_a" => L_ALIAS_FUNC_A,
                            "f_d" => L_ALIAS_FUNC_D,
                            "f_e" => L_ALIAS_FUNC_E,
                            "f_f" => L_ALIAS_FUNC_F,
                            "f_g" => L_ALIAS_FUNC_G,
                            "f_h" => L_ALIAS_FUNC_H,
                            "f_i" => L_ALIAS_FUNC_I,
                            "f_l" => L_ALIAS_FUNC_L,
                            "f_n" => L_ALIAS_FUNC_N,
                            "f_s" => L_ALIAS_FUNC_S,
                            "f_t" => L_ALIAS_FUNC_T,
                            "f_w" => L_ALIAS_FUNC_W,
                            "f_0" => L_ALIAS_FUNC_0);

$LOG_EVENTS = array ( "0"   => LOG_EVENTS_UNDEFINED,
                      "1"   => LOG_EVENTS_,
                      "2"   => LOG_EVENTS_,
                      "3"   => LOG_EVENTS_,
                      "4"   => LOG_EVENTS_,
                      "5"   => LOG_EVENTS_,
                      "6"   => LOG_EVENTS_,
                      "7"   => LOG_EVENTS_,
                      "8"   => LOG_EVENTS_);
                                                   
/*
$Log$
Revision 1.5  2001/02/25 08:50:38  madebeer
removed some insert functions

Revision 1.4  2001/01/23 23:58:03  honzam
Aliases setings support, bug in permissions fixed (can't login not super user), help texts for aliases page

Revision 1.2  2001/01/08 13:31:58  honzam
Small bugfixes

*/
?>
