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
 
# ----------------------- functions for default item values -------------------
function default_fnc_now($param) {
  return now();
}  

function default_fnc_uid($param) {
  global $auth;
  return quote($auth->auth["uid"]);
}  

function default_fnc_dte($param) {
  return mktime(0,0,0,date("m"),date("d")+$param,date("Y"));
}

function default_fnc_qte($param) {
  return quote($param);
}

function default_fnc_txt($param) {
  return quote($param);
}

function default_fnc_($param) {
  global $err;
  $err[default_fnc] = "No default function defined for parameter '$param'- default_fnc_()";
  return "";
}

# ----------------------- insert functions ------------------------------------

function insert_fnc_qte($item_id, $field, $value, $param, $insert=true) {
  global $varset, $itemvarset, $db;

  #huh( "insert_fnc_qte($item_id, $field, $value, $param, $insert)"); 
  #p_arr_m($field);

  if( $field[in_item_tbl] ) {
    # field in item table
    $itemvarset->add( $field[in_item_tbl], "quoted", $value);
    return;
  }  

    # field in content table
  $varset->clear();
  if( $field[text_stored] )
    $varset->add("text", "quoted", $value);
   else 
    $varset->add("number", "quoted", $value);
  if( $insert ) {
    $varset->add("item_id", "unpacked", $item_id);
    $varset->add("field_id", "quoted", $field[id]);
    $SQL =  "INSERT INTO content" . $varset->makeINSERT();
    $db->query( $SQL );
  } else {
    $where = " item_id='". q_pack_id($item_id). "'
                   AND field_id='". $field[id] . "'";
       # if updating database with changed structure (new field added),
       # the UPDATE is not enough - we must INSERT
    $db->query("SELECT item_id FROM content WHERE $where");
    if( $db->next_record() )
      $db->query("UPDATE content SET ". $varset->makeUPDATE() ." WHERE $where");
     else 
      $db->query("INSERT INTO content" . $varset->makeINSERT());
  }           
}

function insert_fnc_dte($item_id, $field, $value, $param, $insert=true) {
  insert_fnc_qte($item_id, $field, $value, $param, $insert);
}

function insert_fnc_cns($item_id, $field, $value, $param, $insert=true) {
  insert_fnc_qte($item_id, $field, $value, $param, $insert);
}

function insert_fnc_num($item_id, $field, $value, $param, $insert=true) {
  insert_fnc_qte($item_id, $field, $value, $param, $insert);
}

function insert_fnc_boo($item_id, $field, $value, $param, $insert=true) {
  insert_fnc_qte($item_id, $field, $value ? 1:0, $param, $insert);
}

function insert_fnc_uid($item_id, $field, $value, $param, $insert=true) {
  global $auth;
  insert_fnc_qte($item_id, $field, $auth->auth["uid"], $param, $insert);
}

function insert_fnc_now($item_id, $field, $value, $param, $insert=true) {
  insert_fnc_qte($item_id, $field, now(), $param, $insert);
}

  # File upload
function insert_fnc_fil($item_id, $field, $value, $param, $insert=true) {
  $varname = 'v'.unpack_id($field[id]);
  
  if(($value <> "none")&&($value <> "")) {   # see if the uploaded file exists
    $dest_file = $GLOBALS[$varname . "_name"];
    if( file_exists(IMG_UPLOAD_PATH.$dest_file) )
      $dest_file = new_id().substr(strrchr($dest_file, "." ), 0 );

    if(!copy($value,IMG_UPLOAD_PATH.$dest_file)){     // copy the file from the temp directory to the upload directory, and test for success
      $err["Image"] = MsgErr(L_CANT_UPLOAD);          // error array (Init - just for initializing variable
      break;
    }   
    insert_fnc_qte($item_id, $field, IMG_UPLOAD_URL.$dest_file, $param, $insert);
  }
}    

function insert_fnc_nul($item_id, $field, $value, $param, $insert=true) {
}

# not defined insert func in field table (it is better to use insert_fnc_nul)
function insert_fnc_($item_id, $field, $value, $param, $insert=true) {
}

# ----------------------- show functions --------------------------------------

function show_fnc_chb($varname, $field, $content, $value, $param, $edit) {
  echo $field[input_before];
  FrmInputChBox($varname, $field[name], $edit ? $content[0] : $value, false,
    "", 1, $field[required], $field[input_help], $field[input_morehlp] );
}

function show_fnc_txt($varname, $field, $content, $value, $param, $edit) {
  echo $field[input_before];
  $rows = ($param ? $param : 4);
  FrmTextarea($varname, $field[name], $edit ? $content[0] : $value, $rows, 60,
   $field[required], $field[input_help], $field[input_morehlp] );
}

function show_fnc_fld($varname, $field, $content, $value, $param, $edit) {
  echo $field[input_before];
  FrmInputText($varname, $field[name], $edit ? $content[0]:$value, 255,60,
   $field[required], $field[input_help], $field[input_morehlp] );
}

function show_fnc_rio($varname, $field, $content, $value, $param, $edit) {
  global $db;
  $arr = GetConstants($param, $db); 
  echo $field[input_before];
  FrmInputRadio($varname, $field[name], $arr, $edit ? $content[0]:$value,
    $field[required], $field[input_help], $field[input_morehlp] );
}
  
function show_fnc_sel($varname, $field, $content, $value, $param, $edit) {
  global $db;
  $arr = GetConstants($param, $db); 
  echo $field[input_before];
  FrmInputSelect($varname, $field[name], $arr, $edit ? $content[0]:$value,
    $field[required], $field[input_help], $field[input_morehlp] );
}

  # $param is uploaded file type (like "image/*");
function show_fnc_fil($varname, $field, $content, $value, $param, $edit) {
  echo $field[input_before];
  FrmInputFile($varname, $field[name], $edit ? $content[0]:$value, 255,60,
       $field[required], $param, $field[input_help], $field[input_morehlp] );
}

function show_fnc_dte($varname, $field, $content, $value, $param, $edit) {
  echo $field[input_before];
  if( strstr($param, "'"))
    $arr = explode("'",$param);  // old format
   else 
    $arr = explode(":",$param);  // new format
  $datectrl = new datectrl($varname, $arr[0], $arr[1], $arr[2]);
  $datectrl->setdate_int($edit ? $content[0] : $value);
  FrmStaticText($field[name], $datectrl->getselect(), $field[required], 
                $field[input_help], $field[input_morehlp], "0" );
}

function show_fnc_nul($varname, $field, $content, $value, $param, $edit) {
}

/*
$Log$
Revision 1.5  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...

Revision 1.1  2001/01/22 17:32:48  honzam
pagecache, logs, bugfixes (see CHANGES from v1.5.2 to v1.5.3)

*/
?>
