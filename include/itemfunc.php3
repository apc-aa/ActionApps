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
  global $auth;                                  #  9999999999 for anonymous
  return quote(isset($auth) ? $auth->auth["uid"] : "9999999999");
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
    $itemvarset->add( $field[in_item_tbl], "quoted", $value[value]);
    return;
  }  

    # field in content table
  $varset->clear();
  if( $field[text_stored] )
    $varset->add("text", "quoted", $value[value]);
   else 
    $varset->add("number", "quoted", $value[value]);
  $varset->add("flag", "quoted", $value[flag]);

  if( !$insert ) {
    $where = " item_id='". q_pack_id($item_id). "'
                   AND field_id='". $field[id] . "'";
       # if updating database with changed structure (new field added),
       # the UPDATE is not enough - we must INSERT
    $db->query("SELECT item_id FROM content WHERE $where");
    if( $db->next_record() ) {
      $db->query("UPDATE content SET ". $varset->makeUPDATE() ." WHERE $where");
      return;
    } # else continue to insert field
  }    
  # insert or update item but new field
  $varset->add("item_id", "unpacked", $item_id);
  $varset->add("field_id", "quoted", $field[id]);
  $SQL =  "INSERT INTO content" . $varset->makeINSERT();
  $db->query( $SQL );
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
  $value[value] = ( $value[value] ? 1 : 0 );
  insert_fnc_qte($item_id, $field, $value, $param, $insert);
}

function insert_fnc_uid($item_id, $field, $value, $param, $insert=true) {
  global $auth;
  # if not $auth, it is from anonymous posting - 9999999999 is anonymous user
  $val = (isset($auth) ?  $auth->auth["uid"] : "9999999999");
  insert_fnc_qte($item_id, $field, array("value"=>$val) , $param, $insert);
}

function insert_fnc_now($item_id, $field, $value, $param, $insert=true) {
  insert_fnc_qte($item_id, $field, array("value"=>now()), $param, $insert);
}

  # File upload
function insert_fnc_fil($item_id, $field, $value, $param, $insert=true) {
  $varname = 'v'.unpack_id($field[id]);
  $filevarname = $varname."x";
    
  # look if the uploaded picture exists
  if(($GLOBALS[$filevarname] <> "none")&&($GLOBALS[$filevarname] <> "")) {
    $dest_file = $GLOBALS[$filevarname . "_name"];

    # images are copied to subdirectory of IMG_UPLOAD_PATH named as slice_id
    $dirname = IMG_UPLOAD_PATH. $GLOBALS["slice_id"];
    $dirurl  = IMG_UPLOAD_URL. $GLOBALS["slice_id"];

    if( !is_dir( $dirname ))
      if( !mkdir( $dirname, IMG_UPLOAD_DIR_MODE ) ){
        return L_CANT_CREATE_IMG_DIR;
      }    

    if( file_exists("$dirname/$dest_file") )
      $dest_file = new_id().substr(strrchr($dest_file, "." ), 0 );

    # copy the file from the temp directory to the upload directory, and test for success    
    if(!copy($GLOBALS[$filevarname],"$dirname/$dest_file")) {
      return L_CANT_UPLOAD;
    }  
    $value["value"] = "$dirurl/$dest_file";
  }
  # store link to uploaded file or specified file URL if nothing was uploaded
  insert_fnc_qte($item_id, $field, $value, "", $insert);
}    

function insert_fnc_nul($item_id, $field, $value, $param, $insert=true) {
}

# not defined insert func in field table (it is better to use insert_fnc_nul)
function insert_fnc_($item_id, $field, $value, $param, $insert=true) {
}

# ----------------------- show functions --------------------------------------

function show_fnc_chb($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmInputChBox($varname, $field[name], $value[0][value], false, "", 1,
                $field[required], $field[input_help], $field[input_morehlp] );
}

function show_fnc_txt($varname, $field, $value, $param, $html){
  echo $field[input_before];
  $rows      = ($param ? $param : 4);
  $htmlstate = ( !$field[html_show] ? 0 : ( $html ? 1 : 2 ));
  FrmTextarea($varname, $field[name], $value[0][value], 
   $rows, 60, $field[required], $field[input_help], $field[input_morehlp], 
   false, $htmlstate );
}

function show_fnc_fld($varname, $field, $value, $param, $html) {
  echo $field[input_before];

  $htmlstate = ( !$field[html_show] ? 0 : ( $html ? 1 : 2 ));

  FrmInputText($varname, $field[name], $value[0][value], 255,60, 
               $field[required], $field[input_help], $field[input_morehlp], 
               $htmlstate );
}

function show_fnc_rio($varname, $field, $value, $param, $html) {
  global $db;
  $arr = GetConstants($param, $db); 
  echo $field[input_before];
  FrmInputRadio($varname, $field[name], $arr, $value[0][value],
                $field[required], $field[input_help], $field[input_morehlp] );
}

function show_fnc_mch($varname, $field, $value, $param, $html) {
  global $db;
  $arr = GetConstants($param, $db); 
  echo $field[input_before];
  FrmInputMultiChBox($varname, $field[name], $arr, $value, 
    $field[required], $field[input_help], $field[input_morehlp]);
}
  
function show_fnc_sel($varname, $field, $value, $param, $html) {
  global $db;
  $arr = GetConstants($param, $db); 
  echo $field[input_before];
  FrmInputSelect($varname, $field[name], $arr, $value[0][value],
                 $field[required], $field[input_help], $field[input_morehlp] );
}

# $param is uploaded_file_type:field_name:help (like "image/*::Select image")
# if no $param specified, no file upload field is displayed
function show_fnc_fil($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmInputText($varname, $field[name], $value[0][value], 255,60, 
               $field[required], $field[input_help], $field[input_morehlp], 0);
  if( !$param )
    return;                       # no upload field displayed
  $arr = explode(":",$param);  

  FrmInputFile($varname."x", $arr[1], 60, $field[required], 
               $arr[0], $arr[2], false );
}

function show_fnc_dte($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  if( strstr($param, "'"))
    $arr = explode("'",$param);  // old format
   else 
    $arr = explode(":",$param);  // new format
  $datectrl = new datectrl($varname, $arr[0], $arr[1], $arr[2]);
  $datectrl->setdate_int($value[0][value]);
  FrmStaticText($field[name], $datectrl->getselect(), $field[required], 
                $field[input_help], $field[input_morehlp], "0" );
}

function show_fnc_nul($varname, $field, $value, $param, $html) {
}

# -----------------------------------------------------------------------------

function GetContentFromForm( $fields, $prifields ) {
  if( !isset($prifields) OR !is_array($prifields) )
    return false;
  reset($prifields);
  while(list(,$pri_field_id) = each($prifields)) {
    $f = $fields[$pri_field_id];

    $varname = 'v'. unpack_id($pri_field_id); # "v" prefix - database field var
    $htmlvarname = $varname."html";

    if( isset($GLOBALS[$varname]) and is_array($GLOBALS[$varname]) ) {
        # fill the multivalues    
      reset($GLOBALS[$varname]);
      $i=0;
      while( list(,$v) = each($GLOBALS[$varname]) )
        $content4id[$pri_field_id][$i++][value] = $v;    # add to content array
    } else
      $content4id[$pri_field_id][0][value] = $GLOBALS[$varname];
  
    $content4id[$pri_field_id][0][flag] = ( $f[html_show] ? 
                             (($GLOBALS[$htmlvarname]=="h") ? FLAG_HTML : 0) :
                             (($f[html_default]>0) ? FLAG_HTML : 0));
  }
  return $content4id;
}                                             
                                              
function StoreItem( $id, $slice_id, $content4id, $fields, $insert, 
                    $invalidatecache=true, $feed=true ) {
  global $db, $varset, $itemvarset;

  if( !( $id AND isset($fields) AND is_array($fields)
        AND isset($content4id) AND is_array($content4id)) )
    return false;
    

  reset($content4id);
  while(list($fid,$cont) = each($content4id)) {
    $f = $fields[$fid];
    $fnc = ParseFnc($f[input_insert_func]);   # input insert function
    if( $fnc ) {                  # function to call
      $fncname = 'insert_fnc_' . $fnc[fnc];
        # updates content table or fills $itemvarset 
      if( !( isset($cont) AND is_array($cont)))    
        continue;
      reset($cont);               # it must serve multiple values for one field
      while(list(,$v) = each($cont)) {
 //       echo "$fncname ( $id, $f, $v , $insert)<br>";
          # add to content table or to itemvarset
        $fncname($id, $f, $v, $fnc[param], $insert); 
          # do not store multiple values if field is not marked as multiple
        if( !$f[multiple]!=1 ) 
          continue;
      } 
    }
  }
 
    # update item table
  if( !$insert )
    $SQL = "UPDATE item SET ". $itemvarset->makeUPDATE() . " WHERE id='". q_pack_id($id). "'";
   else {
    $itemvarset->add("id", "unpacked", $id);
    $itemvarset->add("slice_id", "unpacked", $slice_id);
    $SQL = "INSERT INTO item " . $itemvarset->makeINSERT();
  }  
  $db->query($SQL);

  if( $invalidatecache ) {
    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
    $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
  }  

  if( $feed )
    FeedItem($id, $fields);

  return true;
}

function GetDefault($f) {
  $fnc = ParseFnc($f[input_default]);    # all default should have fnc:param format
  if( $fnc ) {                     # call function
    $fncname = 'default_fnc_' . $fnc[fnc];
    return $fncname($fnc[param]);
  } else
    return false;
}    

function GetDefaultHTML($f) {
  return (($f[html_default]>0) ? FLAG_HTML : 0);
}  

function ShowForm($content4id, $fields, $prifields, $edit) {
  if( !isset($prifields) OR !is_array($prifields) )
    return MsgErr(L_NO_FIELDS);

	reset($prifields);
	while(list(,$pri_field_id) = each($prifields)) {
    $f = $fields[$pri_field_id];

      #get varname - name of field in inputform
    $varname = 'v'. unpack_id($pri_field_id); # "v" prefix - database field var
    $htmlvarname = $varname."html";

	  if(    ($content4id[$pri_field_id][0][flag] & FLAG_FREEZE)  
        OR !$f[input_show])      # fed as unchangeable or set to not show
	    continue;                  # fed fields or not shown fields do not show

	  $fnc = ParseFnc($f[input_show_func]);   # input show function
	  if( $fnc ) {                     # call function
	    $fncname = 'show_fnc_' . $fnc[fnc];
	      # updates content table or fills $itemvarset 
      if( !$edit ) {
        if( $f[multiple] AND isset($GLOBALS[$varname])
                         AND is_array($GLOBALS[$varname]) ) {
              # get the multivalues    
          reset($GLOBALS[$varname]);
          $i=0;
          while( list(,$v) = each($GLOBALS[$varname]) )
            $arr[$i++][value] = $v;
        } else
          $arr[0][value] = $GLOBALS[$varname];
  	    $fncname($varname, $f, $arr, $fnc[param], $GLOBALS[$htmlvarname]==1);
      } else
   	    $fncname($varname, $f, $content4id[$pri_field_id], 
                 $fnc[param], $content4id[$pri_field_id][0][flag] & FLAG_HTML );
    }
	}
}	


/*
$Log$
Revision 1.7  2001/03/30 11:54:35  honzam
offline filling bug and others small bugs fixed

Revision 1.6  2001/03/20 16:10:37  honzam
Standardized content management for items - filler, itemedit, offline, feeding
Better feeding support

Revision 1.5  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...

Revision 1.1  2001/01/22 17:32:48  honzam
pagecache, logs, bugfixes (see CHANGES from v1.5.2 to v1.5.3)

*/
?>