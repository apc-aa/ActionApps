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

require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."notify.php3";
require $GLOBALS[AA_INC_PATH]."imagefunc.php3";
 
# ----------------------- functions for default item values -------------------
function default_fnc_now($param) {
  return now();
}  

function default_fnc_uid($param) {
  global $auth;                                  #  9999999999 for anonymous
  return quote(isset($auth) ? $auth->auth["uid"] : "9999999999");
}  

function default_fnc_log($param) {
  global $auth;                                  #  9999999999 for anonymous
  return quote(isset($auth) ? $auth->auth["uname"] : "anonymous");
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

/** @author Ram Prasad, 05-March-2002 */      
function default_fnc_variable($param) {
  return ($GLOBALS[$param]);
}
# ----------------------- insert functions ------------------------------------

function insert_fnc_qte($item_id, $field, $value, $param) {
    global $varset, $itemvarset, $db, $slice_id ;
    
    // if input function is 'selectbox with presets' and add2connstant flag is set,
    // store filled value to constants
    $fnc = ParseFnc($field[input_show_func]);   # input show function
    if( $fnc AND ($fnc['fnc']=='pre') ) {
        // get add2constant and constgroup (other parameters are irrelevant in here)
        list($constgroup, $maxlength, $fieldsize,$slice_field, $usevalue, $adding,
             $secondfield, $add2constant) = explode(':', $fnc['param']);
        // add2constant is used in insert_fnc_qte - adds new value to constant table
        if( $add2constant AND $constgroup AND (substr($constgroup,0,7) != "#sLiCe-") ) {

            // does this constant already exist?
            $constgroup=quote($constgroup);
            $SQL = "SELECT * FROM constant
                     WHERE group_id='$constgroup'
                       AND value='". $value['value'] ."'";
            $db->query($SQL);
            if (!$db->next_record()) {
                // constant is not in database yet => add it
                
                // first we have to get max priority in order we can add new constant
                // with bigger number
                $SQL = "SELECT max(pri) as max_pri FROM constant
                        WHERE group_id='$constgroup'";
                $db->query($SQL);
                $new_pri = ($db->next_record() ? $db->f('max_pri') + 10 : 1000);
                
                // we have priority - we can add
                $varset->clear();
                $varset->set("name",  $value['value'], "quoted");
                $varset->set("value", $value['value'], "quoted");
                $varset->set("pri",   $new_pri, "number");
                $varset->set("id", new_id(), "unpacked" );
                $varset->set("group_id", $constgroup, "quoted" );
                $db->query("INSERT INTO constant " . $varset->makeINSERT() );
            }
        }
    }
    
    if( $field[in_item_tbl] ) {
        // Mitra thinks that this might want to be 'expiry_date.....' ...
        // ... which is not correct because in 'in_item_tbl' database field 
        // we store REAL database field names from aadb.item table (honzam)
        if( ($field[in_item_tbl] == 'expiry_date') && 
            (date("Hi",$value['value']) == "0000") )

        // $value['value'] += 86399;  
        // if time is not specified, take end of day 23:59:59 
        // !!it is not working for daylight saving change days !!!
        $value['value'] = mktime(23,59,59,date("m",$value['value']),date("d",$value['value']),date("Y",$value['value']));
        
        // field in item table
        $itemvarset->add( $field[in_item_tbl], "quoted", $value['value']);
        return;
    }
    
    // field in content table
    $varset->clear();
    if( $field[text_stored] ) 
        $varset->add("text", "quoted", $value['value']);
    else 
        $varset->add("number", "quoted", $value['value']);
    $varset->add("flag", "quoted", $value['flag']);
    
    // insert item but new field
    $varset->add("item_id", "unpacked", $item_id);
    $varset->add("field_id", "quoted", $field[id]);
    $SQL =  "INSERT INTO content" . $varset->makeINSERT();
    $db->query( $SQL );
}

function insert_fnc_dte($item_id, $field, $value, $param) {
  insert_fnc_qte($item_id, $field, $value, $param);
}

function insert_fnc_cns($item_id, $field, $value, $param) {
  insert_fnc_qte($item_id, $field, $value, $param);
}

function insert_fnc_num($item_id, $field, $value, $param) {
  insert_fnc_qte($item_id, $field, $value, $param);
}

function insert_fnc_boo($item_id, $field, $value, $param) {
  $value['value'] = ( $value['value'] ? 1 : 0 );
  insert_fnc_qte($item_id, $field, $value, $param);
}

function insert_fnc_ids($item_id, $field, $value, $param) {
  global $varset, $itemvarset, $db;

/*echo "<script> alert( 'insert_fnc_ids($item_id, $field, $value, $param), ". $value['value'] ." ".substr($value['value'],0,1)."');</script>";*/
#flush();  
  $add_mode = substr($value['value'],0,1);      # x=add, y=add mutual, z=add backward
  if( ($add_mode == 'x') || ($add_mode == 'y') || ($add_mode == 'z') ) 
    $value['value'] = substr($value['value'],1);  # remove x, y or z

  switch( $add_mode ) {
    case 'x':   // just filling character - remove it
      insert_fnc_qte($item_id, $field, $value, $param);
      return;
    case 'y':   // y means 2way related item id - we have to store it for both
      insert_fnc_qte($item_id, $field, $value, $param);
      # !!!!! there is no break or return - CONTINUE with 'z' case !!!!!
    case 'z':   // z means backward related item id - store it only backward
        # add reverse related
      $reverse_id = $value['value'];
      $value['value'] = $item_id;
        # is reverse relation already set?
      $SQL = "SELECT * FROM content 
               WHERE item_id = '". q_pack_id($reverse_id) ."' 
                 AND field_id = '". $field[id] ."' 
                 AND ". ($field[text_stored] ? "text" : "number") ."= '". $value['value'] ."'";
      $db->query( $SQL );
      if( !$db->next_record() )  # not found
        insert_fnc_qte($reverse_id, $field, $value, $param);
      return;
    default:
      insert_fnc_qte($item_id, $field, $value, $param);
  }    
}

function insert_fnc_uid($item_id, $field, $value, $param) {
  global $auth;
  # if not $auth, it is from anonymous posting - 9999999999 is anonymous user
  $val = (isset($auth) ?  $auth->auth["uid"] : ( (strlen($value['value'])>0) ? 
                                              $value['value'] : "9999999999"));
  insert_fnc_qte($item_id, $field, array("value"=>$val) , $param);
}

function insert_fnc_log($item_id, $field, $value, $param) {
  global $auth;
  # if not $auth, it is from anonymous posting
  $val = (isset($auth) ?  $auth->auth["uname"] : ( (strlen($value['value'])>0) ? 
                                              $value['value'] : "anonymous"));
  insert_fnc_qte($item_id, $field, array("value"=>$val) , $param);
}

function insert_fnc_now($item_id, $field, $value, $param) {
  insert_fnc_qte($item_id, $field, array("value"=>now()), $param);
}

function GetDestinationFileName($dirname, $uploaded_name) {
  $i=1;
  $base = strchr($uploaded_name,'.') ? $uploaded_name : $uploaded_name.'.'; # we need dot in name
  $dest_file = $uploaded_name;
  while( file_exists("$dirname/$dest_file") )
    $dest_file = str_replace('.', '_'.$i++.'.', $base);
  return $dest_file;
}
  
// -----------------------------------------------------------------------------
/** Insert function for File Upload.
*   @return Array of fields stored inside this function as thumbnails.
*/
function insert_fnc_fil($item_id, $field, $value, $param, $fields="") 
{
    global $FILEMAN_MODE_FILE, $FILEMAN_MODE_DIR;

    $filevarname = "v".unpack_id($field[id])."x";
    
    // look if the uploaded picture exists
    if($GLOBALS[$filevarname."_name"] == "none" || $GLOBALS[$filevarname."_name"] == "") 
        return; # was continue: but that doesn't make sense

    $params=explode(":",$param);
  
    // look if type of file is allowed
    if (substr($params[0],-1)=="*")
        $file_type=substr($params[0],0,strpos($params[0],'/'));
    else	
        $file_type=$params[0];
    //echo "uploaded type:".$GLOBALS[$filevarname."_type"].", allowed type:".$file_type;exit;
    if (@strstr($GLOBALS[$filevarname."_type"],$file_type)==false && $params[0]!="")
        return "type of uploaded file not allowed";
 
    // get filename and replace bad characters
    $dest_file = eregi_replace("[^a-z0-9_.~]","_",$GLOBALS[$filevarname."_name"]);

    // new behavior, added by Jakub on 2.8.2002 -- related to File Manager
    // fill $dirname with the destination directory for storing uploaded files
    $db = new DB_AA;
    $db->query("SELECT fileman_dir FROM slice WHERE id='".q_pack_id($GLOBALS["slice_id"])."'");

    if ($db->num_rows() == 1) {
        $db->next_record();
        $fileman_dir = $db->f("fileman_dir");
        if ($fileman_dir && is_dir (FILEMAN_BASE_DIR.$fileman_dir)) {
            $dirname = FILEMAN_BASE_DIR.$fileman_dir."/items";
            $dirurl = FILEMAN_BASE_URL.$fileman_dir."/items";
            if (!is_dir ($dirname)) 
               mkdir ($dirname, $FILEMAN_MODE_DIR);
            $fileman_used = true;
        }
    }
    // end of new behavior
    
    if (!$dirname) {
        // images are copied to subdirectory of IMG_UPLOAD_PATH named as slice_id
        $dirname = IMG_UPLOAD_PATH. $GLOBALS["slice_id"];
        $dirurl  = IMG_UPLOAD_URL. $GLOBALS["slice_id"];

        if( !is_dir( $dirname ))
          if( !mkdir( $dirname, IMG_UPLOAD_DIR_MODE ) )
            return _m("Can't create directory for image uploads");
    }

    $dest_file = GetDestinationFileName($dirname, $dest_file);
    
    // copy the file from the temp directory to the upload directory, and test for success    
    $err = aa_move_uploaded_file ($filevarname, $dirname, 
        $fileman_used ? $FILEMAN_MODE_FILE : 0, $dest_file);
    if ($err) return $err;
    
    //echo "type:"; echo $filevarname; print_r($GLOBALS);
    
    // ------------------------------------------------------------------------------------
    // Create thumbnails (image miniature) into fields identified in this field's parameters
    // if file type is supported by GD library.
    
    $imageInfo=GetImageSize("$dirname/$dest_file");
    $type_supported=GetSupportedTypes($imageInfo[2]);
        
    if ($type_supported && !is_array($type_supported)) {   
        // resample image if max dimensions are set
        if ($params[1] || $params[2]) 
        	ResampleImage("$dirname/$dest_file","$dirname/$dest_file",$params[1],$params[2]);
        // make thumbnails  if fields for are set
        //echo $params[3]; exit;
    
        if ($params[3]!="")	{
    	    // get ids of field store thumbnails
    	    $thumb_arr=explode("##",$params[3]);
    	    	    	    
    	    reset($thumb_arr);
    	    while(list(,$fid) = each($thumb_arr)) {	     
        		$num ++;
        		//copy thumbnail
   				$thumbnail = $fields[$fid];
    		    $fncpar = ParseFnc($f[input_insert_func]);
    		    $thumb_params=explode(":",$fncpar[param]);
    		    //echo $thumb_params[0].$thumb_params[1];
    		    $dest_file_tmb=substr($dest_file,0,strrpos($dest_file,"."))
                    ."_thumb$num".substr($dest_file,strrpos($dest_file,"."));
    		
    		    if (ResampleImage("$dirname/$dest_file","$dirname/$dest_file_tmb",
                        $thumb_params[0],$thumb_params[1]))
    		        // store link to thumbnail 
    		        $val["value"] = "$dirurl/$dest_file_tmb";
    		    else
    		        // if picture cannot be resampled or it is not necessary 
                    // thumb fields will store path to original file
        		    $val["value"] = "$dirurl/$dest_file";    		    
        		//exit;
    
        		insert_fnc_qte( $item_id, $thumbnail, $val, "");    		
       	    }    	    
    	}
    }

    $value["value"] = "$dirurl/$dest_file";    

    // store link to uploaded file or specified file URL if nothing was uploaded
    //print_r($value);exit;
    insert_fnc_qte( $item_id, $field, $value, "");
   
    // return array with fields that were filled with thumbnails 
    return $thumb_arr;
} // end of insert_fnc_fil

// -----------------------------------------------------------------------------

function insert_fnc_nul($item_id, $field, $value, $param) {
}

# not defined insert func in field table (it is better to use insert_fnc_nul)
function insert_fnc_($item_id, $field, $value, $param) {
}

# ----------------------- show functions --------------------------------------

function show_fnc_chb($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmInputChBox($varname, $field['name'], $value[0]['value'], false, "", 1,
                $field[required], $field[input_help], $field[input_morehlp] );
}

function show_fnc_freeze_chb($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmStaticText($field['name'], $value[0]['value'] ? _m("set") : _m("unset") );
}

function show_fnc_txt($varname, $field, $value, $param, $html){
  echo $field[input_before];
  $rows      = ($param ? $param : 4);
  $htmlstate = ( !$field[html_show] ? 0 : ( $html ? 1 : 2 ));
  FrmTextarea($varname, $field['name'], $value[0]['value'], 
   $rows, 60, $field[required], $field[input_help], $field[input_morehlp], 
   false, $htmlstate );
}

function show_fnc_freeze_txt($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmStaticText($field['name'], $value[0]['value']);
}

function show_fnc_edt($varname, $field, $value, $param, $html){
  echo $field[input_before];
  list($rows, $cols, $type) = explode(':', $param);
  if ($rows == 0) $rows = 10;
  if ($cols == 0) $cols = 70;
  if ($type == "") $type = "class";
  $htmlstate = ( !$field[html_show] ? 0 : ( $html ? 1 : 2 ));
	$list_fnc_edt [] = $field['name'];
  FrmRichEditTextarea($varname, $field['name'], $value[0]['value'], 
   $rows, $cols, $type, $field[required], $field[input_help], $field[input_morehlp], 
   false, $htmlstate );
	global $list_fnc_edt;
	$list_fnc_edt[] = $varname;
}

function show_fnc_freeze_edt($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmStaticText($field['name'], $value[0]['value']);
}

function show_fnc_fld($varname, $field, $value, $param, $html) {
   echo $field[input_before];
   $maxlength = 255;
   $fieldsize = 60;
   if (!empty($param)) 
     list($maxlength, $fieldsize) = split('[ ,:]+', $param, 2);

   $htmlstate = ( !$field[html_show] ? 0 : ( $html ? 1 : 2 ));
   FrmInputText($varname, $field['name'], $value[0]['value'], $maxlength,
                $fieldsize, $field[required], $field[input_help],
                $field[input_morehlp], $htmlstate );
}

function show_fnc_freeze_fld($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmStaticText($field['name'], $value[0]['value']);
}

function show_fnc_rio($varname, $field, $value, $param, $html) {
  global $db;

  if (!empty($param))     # there are no parameters now, but 1) may be in future
    list($constgroup, ) = explode(':', $param); # 2) sometimes there is ':' at the end as parameter separation 

  if( substr($constgroup,0,7) == "#sLiCe-" )  # prefix indicates select from items
    $arr = GetItemHeadlines( $db, substr($constgroup, 7), "" );
   else 
    $arr = GetConstants($constgroup, $db);
  
  echo $field[input_before];
  FrmInputRadio($varname, $field['name'], $arr, $value[0]['value'],
                $field[required], $field[input_help], $field[input_morehlp] );
}

function show_fnc_freeze_rio($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmStaticText($field['name'], $value[0]['value']);
}

function show_fnc_mch($varname, $field, $value, $param, $html) {
  global $db;

  if (!empty($param))     # there are no parameters now, but 1) may be in future
    list($constgroup, ) = explode(':', $param); # 2) sometimes there is ':' at the end as parameter separation 

  if( substr($constgroup,0,7) == "#sLiCe-" )  # prefix indicates select from items
    $arr = GetItemHeadlines( $db, substr($constgroup, 7), "" );
   else 
    $arr = GetConstants($constgroup, $db);

  # fill selected array from value
  if( isset($value) AND is_array($value) ) {
    reset($value);   
    while( list( ,$x ) = each( $value )) {
      if( $x['value'] )
        $selected[$x['value']] = true;
    }
  }  
  
  if( $GLOBALS['debug'] ) {
    echo "$varname, $field, $value, $param, $html";
    print_r($arr);
  }  
    
  echo $field[input_before];
  FrmInputMultiChBox($varname."[]", $field['name'], $arr, $selected, 
    $field[required], $field[input_help], $field[input_morehlp]);
}
  
function show_fnc_freeze_mch($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmStaticText($field['name'], implode (", ", $value));
}

function show_fnc_mse($varname, $field, $value, $param, $html) {
  global $db;

  if (!empty($param)) 
    list($constgroup, $selectsize) = explode(':', $param);

  if( $selectsize < 1 )   # default size
    $selectsize = 5;
      
  if( substr($param,0,7) == "#sLiCe-" ) {  # prefix indicates select from items
    $arr = GetItemHeadlines( $db, substr($constgroup, 7), "" );
    #add blank selection for not required field
    if( !$field[required] )
      $arr[''] = " ";
   } else 
    $arr = GetConstants($constgroup, $db);

  # fill selected array from value
  if( isset($value) AND is_array($value) ) {
    reset($value);
    while( list( ,$x ) = each( $value )) {
      if( $x['value'] )
        $selected[$x['value']] = true;
    }
  }

  echo $field[input_before];
  FrmInputMultiSelect($varname."[]", $field['name'], $arr, $selected, $selectsize,
    false, $field[required], $field[input_help], $field[input_morehlp]);
}

function show_fnc_freeze_mse($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmStaticText($field['name'], implode (", ", $value));
}

function show_fnc_sel($varname, $field, $value, $param, $html) {
  global $db;
   list($constgroup,$slice_field, $usevalue) =explode(':', $param);
  if( substr($param,0,7) == "#sLiCe-" ) { # prefix indicates select from items
    $arr = GetItemHeadlines( $db, substr($constgroup, 7),$slice_field);
    #add blank selection for not required field
    if( !$field[required] )
      $arr[''] = " ";
  } else
    $arr = GetConstants($constgroup, $db);
  echo $field[input_before];
  FrmInputSelect($varname, $field['name'], $arr, $value[0]['value'],
                 $field[required], $field[input_help], $field[input_morehlp], $usevalue );
}

function show_fnc_freeze_sel($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmStaticText($field['name'], $value[0]['value']);
}

# $param is uploaded_file_type:field_name:help (like "image/*::Select image")
# if no $param specified, no file upload field is displayed
function show_fnc_fil($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmInputText($varname, $field['name'], $value[0]['value'], 255,60,
               $field[required], $field[input_help], $field[input_morehlp], 0);
  if( !$param )
    return;                       # no upload field displayed
  $arr = explode(":",$param);

  FrmInputFile($varname."x", $arr[1], 60, $field[required],
               $arr[0], $arr[2], false );
}

function show_fnc_freeze_fil($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmStaticText($field['name'], $value[0]['value']);
}

function show_fnc_dte($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  if( strstr($param, "'"))
    $arr = explode("'",$param);  // old format
   else
    $arr = explode(":",$param);  // new format
  $datectrl = new datectrl($varname, $arr[0], $arr[1], $arr[2], $arr[3]);
  $datectrl->setdate_int($value[0]['value']);
  FrmStaticText($field['name'], $datectrl->getselect(), $field[required],
                $field[input_help], $field[input_morehlp], "0" );
}

function show_fnc_freeze_dte($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  $datectrl->setdate_int($value[0]['value']);
  FrmStaticText($field['name'], $datectrl->get_datestring());
}

function show_fnc_pre($varname, $field, $value, $param, $html) {
  global $db;

  if (!empty($param))
    list($constgroup, $maxlength, $fieldsize,$slice_field, $usevalue, $adding,
         $secondfield, $add2constant) = explode(':', $param);
    # add2constant is used in insert_fnc_qte - adds new value to constant table

  if( substr($param,0,7) == "#sLiCe-" )  # prefix indicates select from items
    $arr = GetItemHeadlines( $db, substr($constgroup, 7),$slice_field);
   else
    $arr = GetConstants($constgroup, $db);
  echo $field[input_before];
  FrmInputPreSelect($varname, $field['name'], $arr, $value[0]['value'], $maxlength,
    $fieldsize, $field[required], $field[input_help], $field[input_morehlp], $adding,
	$secondfield, $usevalue );
}

function show_fnc_freeze_pre($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmStaticText($field['name'], $value[0]['value']);
}

function show_fnc_tpr($varname, $field, $value, $param, $html) {
  global $db;

  if (!empty($param))
    list($constgroup, $rows, $cols) = explode(':', $param);
  $rows  = ($rows ? $rows : 4);
  $cols = ($cols ? $cols : 60);

  if( substr($param,0,7) == "#sLiCe-" )  # prefix indicates select from items
    $arr = GetItemHeadlines( $db, substr($constgroup, 7), "" );
   else
    $arr = GetConstants($constgroup, $db);
  echo $field[input_before];
  FrmTextareaPreSelect($varname, $field['name'], $arr, $value[0]['value'],
    $field[required], $field[input_help], $field[input_morehlp], $rows, $cols);
}

function show_fnc_freeze_tpr($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmStaticText($field['name'], $value[0]['value']);
}

# Easy to redefine this functionality by changing the array below
# prefix is what goes in the selection box in "Edit Item", 
# tag is what goes on the front of the id as stored in the database
# str is the string to display in the Related Items window
# Note that A M B are hard-coded in the Related Items Window param wizard, 
# but any letters can be used, i.e. this table can be extended.
# Next step might be to extend parameter recognition to load this table
# Retaining backward compatability with "[AMB]+" recognition
global $tp;
$tps = array ( 
  AMB => array (
    A => array ( prefix => '>> ', tag => 'x', str => _m("Add") ),
    M => array ( prefix => '<> ', tag => 'y', str => _m("Add&nbsp;Mutual") ),
    B => array ( prefix => '<< ', tag => 'z', str => _m("Backward") ) ),
  GYR => array (
    G => array ( prefix => 'Good:', tag => 'x', str => _m("Good") ),
    Y => array ( prefix => 'OK  :', tag => 'y', str => _m("OK") ),
    R => array ( prefix => 'Bad :', tag => 'z', str => _m("Bad") ) ) );

function show_fnc_iso($varname, $field, $value, $param, $html) {
  global $db, $debug, $tps;

  # if ($debug) huhl("show_fnc_iso:parm=",$param,"html=",$html);
  if (!empty($param))
    list($constgroup, $selectsize, $mode, $design, $tp) = explode(':', $param);

  if (!$tp)         # Default to use the AMP table
    $tp = 'AMB';

  if (isset($tps[$tp])) 
    $tagprefix = $tps[$tp];
  elseif (isset($apc_state['tps'][$tp]))
    $tagprefix = $apc_state['tps'][$tp];
  else    
    print("Unable to find tagprefix table $tp");

  if( !$mode )     # AMB - show 'Add', 'Add mutual' and 'Add backward' buttons
    $mode = 'AMB';

  if( substr($param,0,7) == "#sLiCe-" )  # prefix indicates select from items
    $sid = substr($constgroup, 7);
   else
    return;                              # wrong - there must be slice selected


  $items = GetItemHeadlines($db, $sid, "headline.", $value, "ids",$tagprefix);
  FrmRelated($varname."[]", $field['name'], $items, $selectsize, $sid, $mode,
          $design, $field[required], $field[input_help], $field[input_morehlp]);
}

function show_fnc_freeze_iso($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  if( substr($param,0,7) == "#sLiCe-" )  # prefix indicates select from items
    $sid = substr($constgroup, 7);
   else
    return;                              # wrong - there must be slice selected
  $items = GetItemHeadlines($db, $sid, "headline.", $value, "ids");
  FrmStaticText($field['name'], implode ("<br>", $items));
}

function show_fnc_hco($varname, $field, $value, $param, $html) {
  global $db;
  if (!empty($param))
    list($constgroup, $levelCount, $boxWidth, $size, $horizontalLevels, $firstSelectable, $levelNames) = explode(':', $param);

  FrmHierarchicalConstant ($varname."[]", $field['name'], $value, $constgroup, $levelCount, $boxWidth,
  	$size, $horizontalLevels, $firstSelectable, $field[required],$field[input_help], $field[input_morehlp], split("~",$levelNames));
}

function show_fnc_wi2($varname, $field, $value, $param, $html) {
  global $db;

  if (!empty($param))
    list($constgroup, $size, $wi2_offer, $wi2_selected) = explode(':', $param);

  if( substr($param,0,7) == "#sLiCe-" ) {  # prefix indicates select from items
    $arr = GetItemHeadlines( $db, substr($constgroup, 7) );
     #add blank selection for not required field
     #    if( !$field[required] )
     #      $arr[''] = " ";
   } else {
    $arr = GetConstants($constgroup, $db); }

  # fill selected array from value
  if( isset($value) AND is_array($value) ) {
    reset($value);
    while( list( ,$x ) = each( $value )) {
      if( $x['value'] ) {
        $selected[$x['value']] = $x['value'];
        }
    }
  }

  FrmTwoBox($varname, $field['name'], $arr, $constgroup, $size, $selected,
            $field[required], $wi2_offer, $wi2_selected,$field[input_help],
            $field[input_morehlp]);
}

function show_fnc_freeze_wi2($varname, $field, $value, $param, $html) {
  echo $field[input_before];
  FrmStaticText($field['name'], implode (", ", $value));
}

function show_fnc_nul($varname, $field, $value, $param, $html) {
}

function show_fnc_freeze_nul($varname, $field, $value, $param, $html) {
}

# -----------------------------------------------------------------------------

function IsEditable($fieldcontent, $field) {
  return (!($fieldcontent[0]['flag'] & FLAG_FREEZE)
       AND $field[input_show]
       AND !GetProfileProperty('hide',$field['id'])
       AND !GetProfileProperty('hide&fill',$field['id'])
       AND !GetProfileProperty('fill',$field['id']));
}  
  
function GetContentFromForm( $fields, $prifields, $oldcontent4id="", $insert=true ) {
  if( !isset($prifields) OR !is_array($prifields) )
    return false;
  
  // print_r($fields); exit;
  reset($prifields);
  while(list(,$pri_field_id) = each($prifields)) {
    $f = $fields[$pri_field_id];

      # to content4id array add just displayed fields (see ShowForm())
    if( !IsEditable($oldcontent4id[$pri_field_id], $f) AND !$insert )
	    continue;

    $varname = 'v'. unpack_id($pri_field_id); # "v" prefix - database field var
    $htmlvarname = $varname."html";
    
    # if there are predefined values in user profile, fill it. 
    # Fill it only if $insert (new item). Otherwise left there filled value

    $profile_value = GetProfileProperty('hide&fill',$f['id']);
    if( !$profile_value )
      $profile_value = GetProfileProperty('fill',$f['id']);
  
    if( $profile_value ) {
      $x = GetFromProfile($profile_value);
      $GLOBALS[$varname] = $x[0];
      $GLOBALS[$htmlvarname] = $x[1];
    }
    
        if( isset($GLOBALS[$varname]) and is_array($GLOBALS[$varname]) ) {
        # fill the multivalues    
      reset($GLOBALS[$varname]);
      $i=0;
      while( list(,$v) = each($GLOBALS[$varname]) ) {
        $content4id[$pri_field_id][$i]['value'] = $v;    # add to content array
        $content4id[$pri_field_id][$i]['flag'] = ( $f[html_show] ? 
                             (((string)$GLOBALS[$htmlvarname]=="h") ? FLAG_HTML : 0) :
                             (($f[html_default]>0) ? FLAG_HTML : 0));
        $i++;                     
      }  
    } else {
      $content4id[$pri_field_id][0]['value'] = $GLOBALS[$varname];
      $content4id[$pri_field_id][0]['flag'] = ( $f[html_show] ? 
                             (((string)$GLOBALS[$htmlvarname]=="h") ? FLAG_HTML : 0) :
                             (($f[html_default]>0) ? FLAG_HTML : 0));
    }  
  }

  # the status_code must be set in order we can use email_notify()
  # in StoreItem() function.
  if( !$insert AND !$content4id['status_code.....'][0]['value'] )
    $content4id['status_code.....'][0]['value'] = max(1,$oldcontent4id['status_code.....'][0]['value']);

  if (!$insert)
    $content4id["flags..........."][0]['value'] = $oldcontent4id["flags..........."][0]['value'];

    
  return $content4id; 
}

// -----------------------------------------------------------------------------
/** Basic function for changing contents of items. 
*   Use always this function, not direct SQL queries.
*   Updates the tables @c item and @c content.
*
*   @param array $content4id   array (field_id => array of values 
*						      (usually just a single value, but still an array))
*/
function StoreItem( $id, $slice_id, $content4id, $fields, $insert,
                    $invalidatecache=true, $feed=true ) 
{
    global $db, $varset, $itemvarset;
    
    if (!is_object ($db)) $db = new DB_AA;
    if (!is_object ($varset)) $varset = new CVarset();
    if (!is_object ($itemvarset)) $itemvarset = new CVarset();
    
    if( !( $id AND isset($fields) AND is_array($fields)
            AND isset($content4id) AND is_array($content4id)) )
        return false;
    
    // remove old content first (just in content table - item is updated)
    if( !$insert ) {  
        reset($content4id);
        $delim="";
        while(list($fid,) = each($content4id)) {
            if ( !$fields[$fid]['in_item_tbl']) {
                $in .= $delim."'$fid'";
                $delim = ",";
            }
        }
        if ( $in ) { 
            // delete content just for displayed fields
            $SQL = "DELETE FROM content WHERE item_id='". q_pack_id($id). "'
                            AND field_id IN ($in)";
            $db->query($SQL);
        }
    }
    
    reset($content4id);
    while(list($fid,$cont) = each($content4id)) {
        $f = $fields[$fid];
        //print_r($f);
        // input insert function
        $fnc = ParseFnc($f[input_insert_func]);   
        // input insert function parameters of field
        $fncpar = ParseFnc($f[input_insert_func]);   
        if( $fnc ) {                  
            $fncname = 'insert_fnc_' . $fnc[fnc];
            // update content table or fill $itemvarset
            if( !is_array($cont))
                continue;
            // serve multiple values for one field                
            reset($cont);               
            while(list(,$v) = each($cont)) {
                // file upload needs the $fields array, because it stores
                // some other fields as thumbnails
                if ($fnc[fnc]=="fil") 
                {    
                    //print_r($arr_stop);                    
                    if (is_array($thumbnails)){
                        reset($thumbnails);
                        while(list(,$v_stop) = each($thumbnails))	    
                            if ($v_stop==$fid) $stop=true;
                    };
                    
                    if (!$stop)	  
                        $thumbnails = $fncname($id, $f, $v, $fncpar[param], $fields);
                }
                else
                    $fncname($id, $f, $v, $fncpar[param]);
                //    print_r($fnc);
                // do not store multiple values if field is not marked as multiple
                // ERRORNOUS
                //if( !$f[multiple]!=1 ) 
                    //continue;
            }
        }
    }

    // update item table    
    if( !$insert ) {
        $itemvarset->add("slice_id", "unpacked", $slice_id);
        $itemvarset->add("last_edit", "quoted", default_fnc_now(""));
        $itemvarset->add("edited_by", "quoted", default_fnc_uid(""));
        $SQL = "UPDATE item SET ". $itemvarset->makeUPDATE() 
            . " WHERE id='". q_pack_id($id). "'";
    } else {
        if( $itemvarset->get('status_code') < 1 )
            $itemvarset->set('status_code', 1);
        $itemvarset->add("id", "unpacked", $id);
        $itemvarset->add("slice_id", "unpacked", $slice_id);
        $itemvarset->add("display_count", "quoted", "0");
        
        /* Alerts module uses moved2active as the time when
           an item was moved to the active bin */
        $itemvarset->add("moved2active", "number", 
            $itemvarset->get('status_code') == 1 ? time () : 0);
        $SQL = "INSERT INTO item " . $itemvarset->makeINSERT();
    }  
    $db->query($SQL);
    if( $invalidatecache ) {
        $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
        $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
    }  

    if( $feed )
        FeedItem($id, $fields);

    // notifications 
    $status_id = 'status_code.....'; 
    
    $status_code = $content4id[$status_id][0]['value']; 
    
    if( $insert ) {                          // new + 
        if ($status_code == '1')             //    active 
            email_notify($slice_id, 3, $id); // notify function 3) 
        elseif($status_code == '2')          // holding bin   
            email_notify($slice_id, 1, $id); // notify function 1 
    } else {                                 // changed + 
        if ($status_code == '1')             //    active 
            email_notify($slice_id, 4, $id); // = notify-function 4 
        elseif ($status_code == '2')         // hodling bin 
            email_notify($slice_id, 2, $id); // =  notify-function 2 
    }
    return true;
} // end of StoreItem

// -----------------------------------------------------------------------------

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

function GetFromProfile($value) {
  # profile value format:  <html_flag>:<default_fnc_* function>:<parameter>
  $fnc = ParseFnc(substr($value,2));  # all default should have fnc:param format
  if( $fnc ) {                        # call function
    $fncname = 'default_fnc_' . $fnc[fnc];
    $x= array( $fncname($fnc[param]), ($value[0] == '1') );
    return $x;
  } else
    return array();
}

function ShowForm($content4id, $fields, $prifields, $edit) {
  if( !isset($prifields) OR !is_array($prifields) )
    return MsgErr(_m("No fields defined for this slice"));

	global $list_fnc_edt;
	$list_fnc_edt = array();
		
	reset($prifields);
	while(list(,$pri_field_id) = each($prifields)) {
    $f = $fields[$pri_field_id];

      #get varname - name of field in inputform
    $varname = 'v'. unpack_id($pri_field_id); # "v" prefix - database field var
    $htmlvarname = $varname."html";

    if( !IsEditable($content4id[$pri_field_id], $f) ) # if fed as unchangeable 
      $show_fnc_prefix = 'show_fnc_freeze_';          # display it only
     else 
      $show_fnc_prefix = 'show_fnc_';

	  if( !$f[input_show]                      # if set to not show - do not show
        OR GetProfileProperty('hide',$f['id'])
        OR GetProfileProperty('hide&fill',$f['id']) )
	    continue;

	  $fnc = ParseFnc($f[input_show_func]);   # input show function
	  if( $fnc ) {                     # call function
	    $fncname = $show_fnc_prefix . $fnc[fnc];
	      # updates content table or fills $itemvarset
      if( !$edit ) {
        # insert or new reload of form after error in inserting

        # first get values from profile, if there are some predefined value
        $foo = GetProfileProperty('predefine',$f['id']);
        if( $foo AND !$GLOBALS[$varname]) {
          $x = GetFromProfile($foo);
          $GLOBALS[$varname] = $x[0];
          $GLOBALS[$htmlvarname] = $x[1];
        }

        # get values from form (values are filled when error on form ocures
        if( $f[multiple] AND isset($GLOBALS[$varname])
                         AND is_array($GLOBALS[$varname]) ) {
              # get the multivalues
          reset($GLOBALS[$varname]);
          $i=0;
          while( list(,$v) = each($GLOBALS[$varname]) )
            $arr[$i++]['value'] = $v;
        } else
          $arr[0]['value'] = $GLOBALS[$varname];
  	    $fncname($varname, $f, $arr, $fnc[param], ((string)$GLOBALS[$htmlvarname]=='h') || ($GLOBALS[$htmlvarname]==1));
      } else
   	    $fncname($varname, $f, $content4id[$pri_field_id],
                 $fnc[param], $content4id[$pri_field_id][0]['flag'] & FLAG_HTML );
    }
	}

	if (richEditShowable()) {
		echo '
		<script language="JavaScript">
		<!--
			function saveRichEdits () {';
			reset ($list_fnc_edt);
			while (list(,$name) = each($list_fnc_edt)) {
				echo "document.inputform.$name.value = get_text('edt$name');";
			}
			echo '
			}
		// -->
		</script>';
	}
}

?>
