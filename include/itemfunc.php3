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

require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."notify.php3";
require_once $GLOBALS["AA_INC_PATH"]."imagefunc.php3";
require_once $GLOBALS["AA_INC_PATH"]."javascript.php3";
require_once $GLOBALS["AA_INC_PATH"]."date.php3";
require_once $GLOBALS["AA_INC_PATH"]."item_content.php3";
require_once $GLOBALS["AA_INC_PATH"]."event_handler.php3";

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

function default_fnc_rnd($param) {
    global $slice_id;

    $params = explode (":", $param);
    list ($len, $field_id) = $params;
    if (!$len) $len = 5;
    if (count ($params) < 3)
        $slice_only = true;
    else $slice_only = $params[2];

    $db = getDB();
    do {
        srand((double) microtime() * 1000000);
        $salt_chars = "abcdefghijklmnoprstuvwxABCDEFGHIJKLMNOPQRSTUVWX0123456789";
        for ($i = 0; $i < $len; $i ++)
            $salt .= $salt_chars [rand (0,strlen($salt_chars)-1)];

        if (strlen ($field_id) != 16)
            break;
        if ($slice_only)
            $SQL = "SELECT * FROM content INNER JOIN item ON content.item_id = item.id
                    WHERE item.slice_id='".q_pack_id($slice_id)."'
                    AND field_id='".addslashes($field_id)."'
                    AND text='$salt'";
        else $SQL = "SELECT * FROM content WHERE field_id='".addslashes($field_id)
                    ."' AND text='$salt'";
        $db->query ($SQL);
    } while ($db->next_record());
    freeDB($db);

    return $salt;
}

function default_fnc_($param) {
  global $err;
  $err["default_fnc"] = "No default function defined for parameter '$param'- default_fnc_()";
  return "";
}

/*
//Originally used by Mitra/Setu/Ram in PTS, but Commented out because of
//security risk, if required should be rewritten with a list of variables
//permitted

function default_fnc_variable($param) {
  return ($GLOBALS[$param]);
}
*/
# ----------------------- insert functions ------------------------------------

// What are the parameters to this function - $field must be an array with values [input_show_func] etc
function insert_fnc_qte($item_id, $field, $value, $param) {
    global $varset, $itemvarset, $slice_id ;

    // if input function is 'selectbox with presets' and add2connstant flag is set,
    // store filled value to constants
    $fnc = ParseFnc($field["input_show_func"]);   # input show function
    if( $fnc AND ($fnc['fnc']=='pre') ) {
        // get add2constant and constgroup (other parameters are irrelevant in here)
        list($constgroup, $maxlength, $fieldsize,$slice_field, $usevalue, $adding,
             $secondfield, $add2constant) = explode(':', $fnc['param']);
        // add2constant is used in insert_fnc_qte - adds new value to constant table
        if( $add2constant AND $constgroup AND (substr($constgroup,0,7) != "#sLiCe-") ) {
            $db = getDB();
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
            freeDB($db);
        }
    }

    if( $field["in_item_tbl"] ) {
        // Mitra thinks that this might want to be 'expiry_date.....' ...
        // ... which is not correct because in 'in_item_tbl' database field
        // we store REAL database field names from aadb.item table (honzam)
        if( ($field["in_item_tbl"] == 'expiry_date') &&
            (date("Hi",$value['value']) == "0000") )

        // $value['value'] += 86399;
        // if time is not specified, take end of day 23:59:59
        // !!it is not working for daylight saving change days !!!
        $value['value'] = mktime(23,59,59,date("m",$value['value']),date("d",$value['value']),date("Y",$value['value']));

        // field in item table
        $itemvarset->add( $field["in_item_tbl"], "quoted", $value['value']);
        return;
    }

    // field in content table
    $varset->clear();
    if( $field["text_stored"] )
        $varset->add("text", "quoted", $value['value']);
    else
        $varset->add("number", "quoted", $value['value']);
    $varset->add("flag", "quoted", $value['flag']);

    // insert item but new field
    $varset->add("item_id", "unpacked", $item_id);
    $varset->add("field_id", "quoted", $field["id"]);
    $SQL =  "INSERT INTO content" . $varset->makeINSERT();
    $db = getDB();
    $db->query( $SQL );
    freeDB($db);
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
  global $varset, $itemvarset;

//echo "<script> alert( 'insert_fnc_ids($item_id, $field, $value, $param), ". $value['value'] ." ".substr($value['value'],0,1)."');</script>";
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
                 AND field_id = '". $field["id"] ."'
                 AND ". ($field["text_stored"] ? "text" : "number") ."= '". $value['value'] ."'";
      $db = getDB();
      $db->query( $SQL );
      if( !$db->next_record() )  # not found
        insert_fnc_qte($reverse_id, $field, $value, $param);
      freeDB($db);
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
// There are three cases here
// 1: uploaded - overwrites any existing value, does resampling etc
// 2: file name left over from existing record, just stores the value
// 3: newly entered URL, this is not distinguishable from case #2 so
//    its just stored, and no thumbnails etc generated, this could be
//    fixed later (mtira)
function insert_fnc_fil($item_id, $field, $value, $param, $fields="")
{
    global $FILEMAN_MODE_FILE, $FILEMAN_MODE_DIR, $debugupload;
#$debugupload=1;
    if ($debugupload) huhl("insert_fnc_fil:field=",$field,"value=",$value,"param=",$param);
    if ($debugupload >= 5) huhl("Globals=",$GLOBALS);
    $filevarname = "v".unpack_id($field["id"])."x";
    if ($debugupload) huhl("filevarname=",$filevarname);
#    if ($debugupload) huhl("fields=",$fields);

  // look if the uploaded picture existsnn
  if (!($GLOBALS[$filevarname."_name"] == "none" || $GLOBALS[$filevarname."_name"] == "")) {
    $params=explode(":",$param);

    // look if type of file is allowed
    if (substr($params[0],-1)=="*")
        $file_type=substr($params[0],0,strpos($params[0],'/'));
    else
        $file_type=$params[0];
    if ($debugupload) huhl("uploaded type:".$GLOBALS[$filevarname."_type"].", allowed type:".$file_type);
    if (@strstr($GLOBALS[$filevarname."_type"],$file_type)==false && $params[0]!="") {
        $err = "type of uploaded file not allowed";
        huhe($err);
        if ($debugupload) exit;
        return $err;
    }
    // get filename and replace bad characters
    $dest_file = eregi_replace("[^a-z0-9_.~]","_",$GLOBALS[$filevarname."_name"]);

    // new behavior, added by Jakub on 2.8.2002 -- related to File Manager
    // fill $dirname with the destination directory for storing uploaded files
    $db = getDB();
    $db->tquery("SELECT fileman_dir FROM slice WHERE id='".q_pack_id($GLOBALS["slice_id"])."'");

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
    freeDB($db);
    // end of new behavior
    if (!$dirname) {
        // images are copied to subdirectory of IMG_UPLOAD_PATH named as slice_id
        $dirname = IMG_UPLOAD_PATH. $GLOBALS["slice_id"];
        $dirurl  = IMG_UPLOAD_URL. $GLOBALS["slice_id"];

        if( !is_dir( $dirname )) {
          if ($debugupload) huhl("Creating directory ".$dirname);
          if( !mkdir( $dirname, IMG_UPLOAD_DIR_MODE ) )
            return _m("Can't create directory for image uploads");
          }
    }

    $dest_file = GetDestinationFileName($dirname, $dest_file);
    if ($debugupload) huhl("Moving $filevarname to $dirname fileman=? $dest_file");

    // copy the file from the temp directory to the upload directory, and test for success
    $err = aa_move_uploaded_file ($filevarname, $dirname,
        $fileman_used ? $FILEMAN_MODE_FILE : 0, $dest_file);
    if ($debugupload) huhl("File moved to $dirname/$dest_file: ",($err ? "err=$err" : "Success"));
    if ($err) { if ($debugupload) exit; return $err; }

    // ---------------------------------------------------------------------
    // Create thumbnails (image miniature) into fields identified in this
    // field's parameters if file type is supported by GD library.

    // This has been considerable simplified, by making ResampleImage
    // return true for unsupported types IF they are already small enough
    // and also making ResampleImage copy the files if small enough

    if ($err =  ResampleImage("$dirname/$dest_file","$dirname/$dest_file",
            $params[1],$params[2])) {
    	if ($debugupload) huhl("Resample returned err='$err'");
        if ($debugupload) exit;
        return $err;
    }
    if ($params[3]!="") {
            // get ids of field store thumbnails
            $thumb_arr=explode("##",$params[3]);

            reset($thumb_arr);
            while(list(,$thumb) = each($thumb_arr)) {
                if($debugupload) huhl("Working on thumb=$thumb");
                $num ++; // Note sets it initially to 1
                //copy thumbnail
                $f = $fields[$thumb];       // Array from fields

                $fncpar = ParseFnc($f["input_insert_func"]);
                $thumb_params=explode(":",$fncpar);  // (fnctn, type, width, height)

                $dest_file_tmb=substr($dest_file,0,strrpos($dest_file,"."))
                    ."_thumb$num".substr($dest_file,strrpos($dest_file,".")); // xxx_thumb1.jpg

                if ($err = ResampleImage("$dirname/$dest_file","$dirname/$dest_file_tmb",
                        $thumb_params[2],$thumb_params[3])) {
                    if ($debugupload) { huhl("Resample error on $thumb err=$err"); exit; }
                   return $err;
                }

            // delete content just for displayed fields
            $SQL = "DELETE FROM content WHERE item_id='". q_pack_id($item_id). "'
                            AND field_id = '".$f[id]."'";
            if ($debugupload) huhl("insert_fnc_fil:$SQL");
            $db = getDB(); $db->tquery($SQL); freeDB($db);

                // store link to thumbnail
                $val["value"] = "$dirurl/$dest_file_tmb";
                if($debugupload) huhl("insert_fnc_fil:Setting thumbnail field ", $f[id], " to ", $val[value]);
                insert_fnc_qte( $item_id, $f, $val, "");
            }
    } // params[3]

    $value["value"] = "$dirurl/$dest_file";
  } // File uploaded
  // store link to uploaded file or specified file URL if nothing was uploaded
  if($debugupload) huhl("insert_fnc_fil:Setting field ", $field[id], " to ", $value[value]);
  insert_fnc_qte( $item_id, $field, $value, "");

  // return array with fields that were filled with thumbnails  (why?)
  if ($debugupload) huhl("insert_fnc_fil: returning thumb_arr=",$thumb_arr);
  return $thumb_arr;
} // end of insert_fnc_fil

// -----------------------------------------------------------------------------

function insert_fnc_pwd($item_id, $field, $value, $param)
{
    $change_varname = "v".unpack_id($field["id"])."a";
    $retype_varname = "v".unpack_id($field["id"])."b";
    // "c" created in ValidateContent4Id:
    $original_varname="v".unpack_id($field["id"])."c";
    $delete_varname = "v".unpack_id($field["id"])."d";
    global $$change_varname, $$retype_varname, $$delete_varname, $$original_varname;

    if ($$change_varname && $$change_varname == $$retype_varname)
        $value["value"] = md5 ($$change_varname);
    else if ($$delete_varname)
        $value["value"] = "";
    else $value["value"] = $$original_varname;

    insert_fnc_qte($item_id, $field, $value, $param);
}

// -----------------------------------------------------------------------------

function insert_fnc_nul($item_id, $field, $value, $param) {
}

# not defined insert func in field table (it is better to use insert_fnc_nul)
function insert_fnc_($item_id, $field, $value, $param) {
}

# ----------------------- show functions --------------------------------------

function show_fnc_chb($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  FrmInputChBox($varname, $field['name'], $value[0]['value'], false, "", 1,
                $field["required"], $field["input_help"], $field["input_morehlp"] );
}

function show_fnc_freeze_chb($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  FrmStaticText($field['name'], $value[0]['value'] ? _m("set") : _m("unset") );
}

function show_fnc_txt($varname, $field, $value, $param, $html){
  echo $field["input_before"];
  $rows      = ($param ? $param : 4);
  $htmlstate = ( !$field["html_show"] ? 0 : ( $html ? 1 : 2 ));
  FrmTextarea($varname, $field['name'], $value[0]['value'],
   $rows, 60, $field["required"], $field["input_help"], $field["input_morehlp"],
   false, $htmlstate );
}

function show_fnc_freeze_txt($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  FrmStaticText($field['name'], $value[0]['value']);
}

function show_fnc_edt($varname, $field, $value, $param, $html){
  echo $field["input_before"];
  list($rows, $cols, $type) = explode(':', $param);
  if ($rows == 0) $rows = 10;
  if ($cols == 0) $cols = 70;
  if ($type == "") $type = "class";
  $htmlstate = ( !$field["html_show"] ? 0 : ( $html ? 1 : 2 ));

  FrmRichEditTextarea($varname, $field['name'], $value[0]['value'],
   $rows, $cols, $type, $field["required"], $field["input_help"], $field["input_morehlp"],
   false, $htmlstate );

	global $list_fnc_edt;
	$list_fnc_edt[] = $varname;
}

function show_fnc_freeze_edt($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  FrmStaticText($field['name'], $value[0]['value']);
}

function show_fnc_fld($varname, $field, $value, $param, $html) {
   echo $field["input_before"];
   $maxlength = 255;
   $fieldsize = 60;
   if (!empty($param))
     list($maxlength, $fieldsize) = split('[ ,:]+', $param, 2);

   $htmlstate = ( !$field["html_show"] ? 0 : ( $html ? 1 : 2 ));
   FrmInputText($varname, $field['name'], $value[0]['value'], $maxlength,
                $fieldsize, $field["required"], $field["input_help"],
                $field["input_morehlp"], $htmlstate );
}

function show_fnc_freeze_fld($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  FrmStaticText($field['name'], $value[0]['value']);
}

function show_fnc_rio($varname, $field, $value, $param, $html) {

  if (!empty($param))
    list($constgroup, $ncols, $move_right) = explode(':', $param);

  if( substr($constgroup,0,7) == "#sLiCe-" )  # prefix indicates select from items
    $arr = GetItemHeadlines( substr($constgroup, 7), "" );
   else
    $arr = GetConstants($constgroup);

  echo $field["input_before"];
  FrmInputRadio($varname, $field['name'], $arr, $value[0]['value'],
                $field["required"], $field["input_help"], $field["input_morehlp"],
                $ncols, $move_right);
}

function show_fnc_freeze_rio($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  FrmStaticText($field['name'], $value[0]['value']);
}

function show_fnc_mch($varname, $field, $value, $param, $html) {

  if (!empty($param))
    list($constgroup, $ncols, $move_right) = explode(':', $param);

  if( substr($constgroup,0,7) == "#sLiCe-" )  # prefix indicates select from items
    $arr = GetItemHeadlines( substr($constgroup, 7), "" );
   else
    $arr = GetConstants($constgroup);

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

  echo $field["input_before"];
  FrmInputMultiChBox($varname."[]", $field['name'], $arr, $selected,
    $field["required"], $field["input_help"], $field["input_morehlp"],
    $ncols, $move_right);
}

function show_fnc_freeze_mch($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  FrmStaticText($field['name'], implode (", ", $value));
}

function show_fnc_mse($varname, $field, $value, $param, $html) {

  if (!empty($param))
    list($constgroup, $selectsize) = explode(':', $param);

  if( $selectsize < 1 )   # default size
    $selectsize = 5;

  if( substr($param,0,7) == "#sLiCe-" ) {  # prefix indicates select from items
    $arr = GetItemHeadlines( substr($constgroup, 7), "" );
    #add blank selection for not required field
    if( !$field["required"] )
      $arr[''] = " ";
   } else
    $arr = GetConstants($constgroup);

  # fill selected array from value
  if( isset($value) AND is_array($value) ) {
    reset($value);
    while( list( ,$x ) = each( $value )) {
      if( $x['value'] )
        $selected[$x['value']] = true;
    }
  }

  echo $field["input_before"];
  FrmInputMultiSelect($varname."[]", $field['name'], $arr, $selected, $selectsize,
    false, $field["required"], $field["input_help"], $field["input_morehlp"]);
}

function show_fnc_freeze_mse($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  FrmStaticText($field['name'], implode (", ", $value));
}

function show_fnc_sel($varname, $field, $value, $param, $html) {
   list($constgroup,$slice_field, $usevalue, $allitems) =explode(':', $param);
  if( substr($param,0,7) == "#sLiCe-" ) { # prefix indicates select from items
    $arr = GetItemHeadlines( substr($constgroup, 7),$slice_field,'','all',
                             null, ($allitems==1) ? 'all':'normal' );
    #add blank selection for not required field
    if( !$field["required"] )
      $arr[''] = " ";
  } else
    $arr = GetConstants($constgroup);
  echo $field["input_before"];
  FrmInputSelect($varname, $field['name'], $arr, $value[0]['value'],
                 $field["required"], $field["input_help"], $field["input_morehlp"], $usevalue );
}

function show_fnc_freeze_sel($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  FrmStaticText($field['name'], $value[0]['value']);
}

# $param is uploaded_file_type:field_name:help (like "image/*::Select image")
# if no $param specified, no file upload field is displayed
function show_fnc_fil($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  FrmInputText($varname, $field['name'], $value[0]['value'], 255,60,
               $field["required"], $field["input_help"], $field["input_morehlp"], 0);
  if( !$param )
    return;                       # no upload field displayed
  $arr = explode(":",$param);

  FrmInputFile($varname."x", $arr[1], 60, $field["required"],
               $arr[0], $arr[2], false );
}

function show_fnc_freeze_fil($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  FrmStaticText($field['name'], $value[0]['value']);
}

function show_fnc_dte($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  if( strstr($param, "'"))
    $arr = explode("'",$param);  // old format
   else
    $arr = explode(":",$param);  // new format
  $datectrl = new datectrl($varname, $arr[0], $arr[1], $arr[2], $arr[3]);
  $datectrl->setdate_int($value[0]['value']);
  FrmStaticText($field['name'], $datectrl->getselect(), $field["required"],
                $field["input_help"], $field["input_morehlp"], "0" );
}

function show_fnc_freeze_dte($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  $datectrl->setdate_int($value[0]['value']);
  FrmStaticText($field['name'], $datectrl->get_datestring());
}

function show_fnc_pre($varname, $field, $value, $param, $html) {

  if (!empty($param))
    list($constgroup, $maxlength, $fieldsize,$slice_field, $usevalue, $adding,
         $secondfield, $add2constant) = explode(':', $param);
    # add2constant is used in insert_fnc_qte - adds new value to constant table

  if( substr($param,0,7) == "#sLiCe-" )  # prefix indicates select from items
    $arr = GetItemHeadlines( substr($constgroup, 7),$slice_field);
   else
    $arr = GetConstants($constgroup);
  echo $field["input_before"];
  FrmInputPreSelect($varname, $field['name'], $arr, $value[0]['value'], $maxlength,
    $fieldsize, $field["required"], $field["input_help"], $field["input_morehlp"], $adding,
	$secondfield, $usevalue );
}

function show_fnc_freeze_pre($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  FrmStaticText($field['name'], $value[0]['value']);
}

function show_fnc_tpr($varname, $field, $value, $param, $html) {

  if (!empty($param))
    list($constgroup, $rows, $cols) = explode(':', $param);
  $rows  = ($rows ? $rows : 4);
  $cols = ($cols ? $cols : 60);

  if( substr($param,0,7) == "#sLiCe-" )  # prefix indicates select from items
    $arr = GetItemHeadlines( substr($constgroup, 7), "" );
   else
    $arr = GetConstants($constgroup);
  echo $field["input_before"];
  FrmTextareaPreSelect($varname, $field['name'], $arr, $value[0]['value'],
    $field["required"], $field["input_help"], $field["input_morehlp"], $rows, $cols);
}

function show_fnc_freeze_tpr($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
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
global $tps;
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
  global $debug, $tps;

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


  $items = GetItemHeadlines($sid, "headline.", $value, "ids",$tagprefix);
  FrmRelated($varname."[]", $field['name'], $items, $selectsize, $sid, $mode,
          $design, $field["required"], $field["input_help"], $field["input_morehlp"]);
}

function show_fnc_freeze_iso($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  if( substr($param,0,7) == "#sLiCe-" )  # prefix indicates select from items
    $sid = substr($constgroup, 7);
   else
    return;                              # wrong - there must be slice selected
  $items = GetItemHeadlines($sid, "headline.", $value, "ids");
  FrmStaticText($field['name'], implode ("<br>", $items));
}

function show_fnc_hco($varname, $field, $value, $param, $html) {
  if (!empty($param))
    list($constgroup, $levelCount, $boxWidth, $size, $horizontalLevels, $firstSelectable, $levelNames) = explode(':', $param);

  FrmHierarchicalConstant ($varname."[]", $field['name'], $value, $constgroup, $levelCount, $boxWidth,
  	$size, $horizontalLevels, $firstSelectable, $field["required"],$field["input_help"], $field["input_morehlp"], split("~",$levelNames));
}

function show_fnc_wi2($varname, $field, $value, $param, $html) {

  if (!empty($param))
    list($constgroup, $size, $wi2_offer, $wi2_selected) = explode(':', $param);

  // default size is 5 rows
  if( !$size ) {
      $size = 5;
  }

  if( substr($param,0,7) == "#sLiCe-" ) {  # prefix indicates select from items
    $arr = GetItemHeadlines( substr($constgroup, 7) );
     #add blank selection for not required field
     #    if( !$field["required"] )
     #      $arr[''] = " ";
   } else {
    $arr = GetConstants($constgroup); }

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
            $field["required"], $wi2_offer, $wi2_selected,$field["input_help"],
            $field["input_morehlp"]);
}

function show_fnc_freeze_wi2($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  FrmStaticText($field['name'], implode (", ", $value));
}

function show_fnc_hid($varname, $field, $value, $param, $html) {
  echo $field['input_before'];
  FrmHidden($varname, $value[0]['value'] );
}

function show_fnc_freeze_hid($varname, $field, $value, $param, $html) {
  echo $field['input_before'];
  FrmHidden($varname, $value[0]['value'] );
}

function show_fnc_pwd($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  list ($fieldsize, $change_pwd_label, $retype_pwd_label, $delete_pwd_label,
    $change_pwd_help, $retype_pwd_help) = explode (":", $param);

  if (!$change_pwd_label) $change_pwd_label = _m("Change Password");
  if (!$retype_pwd_label) $retype_pwd_label = _m("Retype New Password");
  if (!$delete_pwd_label) $delete_pwd_label = _m("Delete Password");
  if (!$fieldsize) $fieldsize = 60;

  $name = $field['name'];
  if ($field['required']) $name .= " *";
  FrmStaticText($name, $value[0]['value'] ? "*****" : _m("not set"));

  if (!$field["required"])
      FrmInputChBox($varname."d", $delete_pwd_label, 0, false, "", 1,
                    0, $delete_pwd_help, $field["input_morehlp"] );

  FrmInputText($varname."a", $change_pwd_label, "", 255, $fieldsize, 0,
               $change_pwd_help, $field["input_morehlp"], 0, "PASSWORD");

  FrmInputText($varname."b", $retype_pwd_label, "", 255, $fieldsize, 0,
               $retype_pwd_help, $field["input_morehlp"], 0, "PASSWORD");
}

function show_fnc_pwd_anonym($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  list ($fieldsize, $change_pwd_label, $retype_pwd_label, $delete_pwd_label,
    $change_pwd_help, $retype_pwd_help) = explode (":", $param);

  if (!$change_pwd_label) $change_pwd_label = _m("Change Password");
  if (!$retype_pwd_label) $retype_pwd_label = _m("Retype New Password");
  if (!$delete_pwd_label) $delete_pwd_label = _m("Delete Password");
  if (!$fieldsize) $fieldsize = 60;

  FrmInputText($varname, $field['name'], "", 255, $fieldsize, $field['required'],
               $field['input_help'], $field["input_morehlp"], 0, "PASSWORD");

  if (!$field["required"])
      FrmInputChBox($varname."d", $delete_pwd_label, 0, false, "", 1,
                    0, $delete_pwd_help, $field["input_morehlp"] );

  FrmInputText($varname."a", $change_pwd_label, "", 255, $fieldsize, 0,
               $change_pwd_help, $field["input_morehlp"], 0, "PASSWORD");

  FrmInputText($varname."b", $retype_pwd_label, "", 255, $fieldsize, 0,
               $retype_pwd_help, $field["input_morehlp"], 0, "PASSWORD");
}

function show_fnc_freeze_pwd($varname, $field, $value, $param, $html) {
  echo $field["input_before"];
  FrmStaticText ($field['name'], $value[0] ? "*****" : "");
}

function show_fnc_nul($varname, $field, $value, $param, $html) {
}

function show_fnc_freeze_nul($varname, $field, $value, $param, $html) {
}

# -----------------------------------------------------------------------------

function IsEditable($fieldcontent, $field) {
  return (!($fieldcontent[0]['flag'] & FLAG_FREEZE)
       AND $field["input_show"]
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

    global $$varname;
    $var = $$varname;
    if( !is_array($var) )
        $var = array (0=>$var);

    # fill the multivalues
    reset($var);
    for ($i=0; list(,$v) = each($var); $i ++) {
        $content4id[$pri_field_id][$i]['value'] = $v;
        $content4id[$pri_field_id][$i]['flag'] =
            $f["html_show"]
                ? ($GLOBALS[$htmlvarname] == "h" ? FLAG_HTML : 0)
                : ($f["html_default"] > 0 ? FLAG_HTML : 0);
    }
  }

  # the status_code must be set in order we can use email_notify()
  # in StoreItem() function.
  if( !$insert AND !$content4id['status_code.....'][0]['value'] )
    $content4id['status_code.....'][0]['value'] = max(1,$oldcontent4id['status_code.....'][0]['value']);

  if (!$insert)
    $content4id["flags..........."][0]['value'] = $oldcontent4id["flags..........."][0]['value'];

    if ($debugupload)    exit;
  return $content4id;
}

// -----------------------------------------------------------------------------
/** Basic function for changing contents of items.
*   Use always this function, not direct SQL queries.
*   Updates the tables @c item and @c content.
*   $GLOBALS[err][field_id] should be set on error in function
*   It looks like it will return true even if inset_fnc_xxx fails
*
*   @param array $content4id   array (field_id => array of values
*						      (usually just a single value, but still an array))
*   @param array $oldcontent4id if not sent, StoreItem finds it
*   @return true on success, false otherwise
*/
function StoreItem( $id, $slice_id, $content4id, $fields, $insert,
                    $invalidatecache=true, $feed=true, $oldcontent4id="" )
{
    global $varset, $itemvarset;
    $debugsi=$GLOBALS[debugsi];
#$GLOBALS[debug] = 1;
    if ($debugsi) huhl("StoreItem id=$id, slice=$slice_id, fields size=",count($fields));
    if (!is_object ($varset)) $varset = new CVarset();
    if (!is_object ($itemvarset)) $itemvarset = new CVarset();

    if( !( $id AND is_array($fields) AND is_array($content4id)) ) {
        if ($GLOBALS[errcheck]) huhl("Warning: StoreItem failed parameter check");
        return false;
    }

    // remove old content first (just in content table - item is updated)
    if( !$insert ) {
        if ($debugsi) huhl("StoreItem: overwriting");
        if (!$oldcontent4id) {
            $oldcontent4id = GetItemContent ($id);
            $oldcontent4id = $oldcontent4id[$id];
        }
        if (!Event_ItemBeforeUpdate ($id, $slice_id, new ItemContent ($content4id),
            new ItemContent ($oldcontent4id)))
            return false;
        reset($content4id);
        $delim="";
        while(list($fid,) = each($content4id)) {
            if ( !$fields[$fid]['in_item_tbl']) {
                $in .= $delim."'$fid'";
                $delim = ",";
            }
        }
        if ($debugsi) huhl("StoreItem: in = $in");
        if ( $in ) {
            // delete content just for displayed fields
            $SQL = "DELETE FROM content WHERE item_id='". q_pack_id($id). "'
                            AND field_id IN ($in)";
            $db = getDB();
            if ($debugsi) huhl("StoreItem:$SQL");
            $db->tquery($SQL);
            freeDB($db);
            // note extra images deleted in insert_fnc_fil if needed
        }
    }
    else if (!Event_ItemBeforeInsert ($id, $slice_id, new ItemContent ($content4id))) {
        if ($debugsi) huhl("StoreItem: failed Event_ItemBeforeInsert");
        return false;
    }

    if ($debugsi >=6) huhl("StoreItem:Adding",$content4id);
    reset($content4id);
    while(list($fid,$cont) = each($content4id)) {
        if ($debugsi >= 5) huhl("StoreItem:fid=",$fid);
        $f = $fields[$fid];
        //print_r($f);
        // input insert function
        $fnc = ParseFnc($f["input_insert_func"]);
        // input insert function parameters of field
        $fncpar = ParseFnc($f["input_insert_func"]);
        if( $fnc ) {
            $fncname = 'insert_fnc_' . $fnc["fnc"];
            // update content table or fill $itemvarset
            if( !is_array($cont))
                continue;
            if ($debugsi >= 5 && (count($cont) > 1))
                huhl("StoreItem:count values=".count($cont));
            // serve multiple values for one field
            reset($cont);
            while(list(,$v) = each($cont)) {
                // file upload needs the $fields array, because it stores
                // some other fields as thumbnails
                if ($fnc["fnc"]=="fil")
                {
                    if ($debugsi >= 5) huhl("StoreItem: fil");
                    if ($debugsi >= 5) { $GLOBALS[debug] = 1; $GLOBALS[debugupload] = 1; }
                    //Note $thumbnails is undefined the first time in this loop
                    //print_r($arr_stop);
                    if (is_array($thumbnails)){
                        reset($thumbnails);
                        while(list(,$v_stop) = each($thumbnails))
                            if ($v_stop==$fid) $stop=true;
                    };

                    if (!$stop) {
                        if ($debugsi >= 5) huhl($fncname,"(",$id,$f,$v,$fncpar["param"],")");
                        $thumbnails = $fncname($id, $f, $v, $fncpar["param"], $fields);
                    }
                }
                else {
                    if ($debugsi >= 5) huhl($fncname,"(",$id,$f,$v,$fncpar["param"],")");
                    $fncname($id, $f, $v, $fncpar["param"]);
                }
                // do not store multiple values if field is not marked as multiple
                // ERRORNOUS
                //if( !$f["multiple"]!=1 )
                    //continue;
            }
        }
    }

    /* Alerts module uses moved2active as the time when
       an item was moved to the active bin */
    $oldItemContent = new ItemContent ($oldcontent4id);
    if( $insert ||
      ( $itemvarset->get('status_code') != $oldItemContent->getStatusCode()
        && $itemvarset->get('status_code') >= 1))
    {
        $itemvarset->add("moved2active", "number",
            $itemvarset->get('status_code') > 1 ? 0 : time ());
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

        $SQL = "INSERT INTO item " . $itemvarset->makeINSERT();
    }
    $db = getDB();
    $db->tquery($SQL);
    freeDB($db);
    if( $invalidatecache ) {
        $GLOBALS[pagecache]->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
    }

    if( $feed )
        FeedItem($id, $fields);

    if ($insert) {
        $itemContent = new ItemContent ($content4id);
        $itemContent->setStatusCode ($itemvarset->get('status_code'));
        Event_ItemAfterInsert ($id, $slice_id, $itemContent);
    }
    else Event_ItemAfterUpdate ($id, $slice_id, new ItemContent ($content4id),
        $oldItemContent);

    if ($debugsi) huhl("StoreItem err=",$err);
    return true;
} // end of StoreItem

// -----------------------------------------------------------------------------

function GetDefault($f) {
  $fnc = ParseFnc($f["input_default"]);    # all default should have fnc:param format
  if( $fnc ) {                     # call function
    $fncname = 'default_fnc_' . $fnc["fnc"];
    return $fncname($fnc["param"]);
  } else
    return false;
}

function GetDefaultHTML($f) {
  return (($f["html_default"]>0) ? FLAG_HTML : 0);
}

function GetFromProfile($value) {
  # profile value format:  <html_flag>:<default_fnc_* function>:<parameter>
  $fnc = ParseFnc(substr($value,2));  # all default should have fnc:param format
  if( $fnc ) {                        # call function
    $fncname = 'default_fnc_' . $fnc["fnc"];
    $x= array( $fncname($fnc["param"]), ($value[0] == '1') );
    return $x;
  } else
    return array();
}

// -----------------------------------------------------------------------------
/** Shows the Add / Edit item form fields
*   @param $show is used by the Anonymous Form Wizard, it is an array
*                (packed field id => 1) of fields to show
*/
function ShowForm($content4id, $fields, $prifields, $edit, $show="")
{
    if( !isset($prifields) OR !is_array($prifields) )
        return MsgErr(_m("No fields defined for this slice"));

	reset($prifields);
	while(list(,$pri_field_id) = each($prifields)) {
        $f = $fields[$pri_field_id];

        if (is_array ($show))
            $showme = $show [$f['id']];

        else $showme = $f["input_show"]
            AND ! GetProfileProperty('hide',$f['id'])
            AND ! GetProfileProperty('hide&fill',$f['id']);

        if (!$showme)
    	    continue;

        $fnc = ParseFnc($f["input_show_func"]);   # input show function
    	if( ! $fnc )
            continue;

        #get varname - name of field in inputform
        $varname = 'v'. unpack_id($pri_field_id); # "v" prefix - database field var
        $htmlvarname = $varname."html";

        if( !IsEditable($content4id[$pri_field_id], $f) ) # if fed as unchangeable
            $show_fnc_prefix = 'show_fnc_freeze_';          # display it only
        else
            $show_fnc_prefix = 'show_fnc_';

	    $fncname = $show_fnc_prefix . $fnc["fnc"];

        // look for alternative function for Anonym Wizard (used for passwords)
        if (is_array ($show) && function_exists ($fncname."_anonym"))
            $fncname .= "_anonym";

	    # updates content table or fills $itemvarset
        if( $edit ) {
       	    $fncname($varname, $f, $content4id[$pri_field_id],
                     $fnc["param"], $content4id[$pri_field_id][0]['flag'] & FLAG_HTML );
        } else {
            # insert or new reload of form after error in inserting
            # first get values from profile, if there are some predefined value
            $foo = GetProfileProperty('predefine',$f['id']);
            if( $foo AND !$GLOBALS[$varname]) {
              $x = GetFromProfile($foo);
              $GLOBALS[$varname] = $x[0];
              $GLOBALS[$htmlvarname] = $x[1];
            }

            # get values from form (values are filled when error on form ocures
            if( $f["multiple"] AND is_array($GLOBALS[$varname]) ) {
                  # get the multivalues
                reset($GLOBALS[$varname]);
                $i=0;
                while( list(,$v) = each($GLOBALS[$varname]) )
                    $arr[$i++]['value'] = $v;
            } else
                $arr[0]['value'] = $GLOBALS[$varname];

      	    $fncname($varname, $f, $arr, $fnc["param"],
                ((string)$GLOBALS[$htmlvarname]=='h') || ($GLOBALS[$htmlvarname]==1));
        }
	}
}

// ----------------------------------------------------------------------------
/** Returns Javascript for Add / Edit item
*/
function GetFormJavascript ($show_func_used, $js_proove_fields) {
    global $sess;

    $retval = '
    <script language="JavaScript"><!--
      // array of listboxes where all selection should be selected
      var listboxes=Array();
      var myform = document.inputform;

      function SelectAllInBox( listbox ) {
          for (var i = 0; i < document.inputform[listbox].length; i++)
              // select all rows without the wIdThTor one, which is only for <select> size setting
             document.inputform[listbox].options[i].selected =
               ( document.inputform[listbox].options[i].value != "wIdThTor" );
      }

      // before submit the form we need to select all selections in some
      // listboxes (2window, relation) in order the rows are sent for processing
      function BeforeSubmit() {';
        if ( richEditShowable() && $show_func_used['edt']) $retval .= '
          SaveRichEdits();';
        $retval .= '
          for(var i = 0; i < listboxes.length; i++)
              SelectAllInBox( listboxes[i] );
          return proove_fields ();
      }
      ';

      if ($show_func_used['edt']) $retval .= '
      var richedits = Array();

      function SaveRichEdits () {
        for (var i = 0; i < richedits.length; i++)
          document.inputform[richedits[i]].value = get_text("edt"+richedits[i]);
      }
      ';

      if ($show_func_used['iso']) $retval .= '

      var relatedwindow;  // window for related stories

      function OpenRelated(varname, sid, mode, design) {
        if ((relatedwindow != null) && (!relatedwindow.closed)) {
          relatedwindow.close()    // in order to preview go on top after open
        }
        relatedwindow = open( "'. $sess->url("related_sel.php3") . '&sid=" + sid + "&var_id=" + varname + "&mode=" + mode + "&design=" + design, "relatedwindow", "scrollbars=1, resizable=1, width=500");
      }';

      if ($show_func_used['wi2']) $retval .= '

      function MoveSelected(left, right) {
        var i=eval(left).selectedIndex;
        if( !eval(left).disabled && ( i >= 0 ) )
        {
          var temptxt = eval(left).options[i].text;
          var tempval = eval(left).options[i].value;
          var length = eval(right).length;
          if( (length == 1) && (eval(right).options[0].value==\'wIdThTor\') ){  // blank rows are just for <select> size setting
            eval(right).options[0].text = temptxt;
            eval(right).options[0].value = tempval;
          } else
            eval(right).options[length] = new Option(temptxt, tempval);
          eval(left).options[i] = null;
          if( eval(left).length != 0 )
            if( i==0 )
              eval(left).selectedIndex=0;
            else
              eval(left).selectedIndex=i-1;
        }
      }';

      if ($show_func_used['pre'] || $show_func_used['tpr']) $retval .= '

      function add_to_line(inputbox, value) {
        if (inputbox.value.length != 0) {
          inputbox.value=inputbox.value+","+value;
        } else {
          inputbox.value=value;
        }
      }

      // This script invokes Word/Excel convertor (used in textareas on inputform)
      // You must have the convertor it installed
      // @param string aa_instal_path - relative path to AA on server (like"/apc-aa/")
      // @param string textarea_id    - textarea fomr id (like "v66756c6c5f746578742e2e2e2e2e2e31")
      function CallConvertor(aa_instal_path, textarea_id) {
        page = aa_instal_path + "misc/msconvert/index.php?inputid=" + textarea_id;
        conv = window.open(page,"convwindow","width=450,scrollbars=yes,menubar=no,hotkeys=no,resizable=yes");
        conv.focus();
      }
      ';

    $retval .= $js_proove_fields;

    // field javascript feature (see /include/javascript.php3)
    $javascript = getJavascript($GLOBALS["slice_id"]);
    if ($javascript)
        $retval .= $javascript;

    $retval .= '

    // -->
    </script>'."\n\n";

    if ($javascript) $retval .= '

    <script language="javascript" src="'.$GLOBALS['AA_INSTAL_PATH'].'javascript/fillform.js">
    </script>'."\n\n";

    return $retval;
}

// ----------------------------------------------------------------------------
/** Validates new content, sets defaults, reads dates from the 3-selectbox-AA-format,
*   sets global variables:
*       $show_func_used to a list of show func used in the form.
*       $js_proove_fields to complete JavaScript code for form validation
*       list ($fields, $prifields) = GetSliceFields ()
*       $oldcontent4id
*
*   This function is used in itemedit.php3, filler.php3 and file_import.php3.
*
*   @author Jakub Adamek, Econnect, January 2003
*           Most of the code is taken from itemedit.php3, created by Honza.
*
*   @param string $action should be one of:
*                         "add" .... a "new item" page (not form-called)
*                         "edit" ... an "edit item" page (not form-called)
*                         "insert" . call for inserting an item
*                         "update" . call for updating an item
*   @param id $id is useful only for "update"
*   @param bool $do_validate Should validate the fields?
*   @param array $notshown is an optional array ("field_id"=>1,...) of fields
*                          not shown in the anonymous form
*/
function ValidateContent4Id (&$err, $slice_id, $action, $id=0, $do_validate=true,
    $notshown="")
{
    global $show_func_used, $js_proove_fields, $fields, $prifields, $oldcontent4id;

    global $varset, $itemvarset;
    if (!is_object ($varset)) $varset = new Cvarset();
    if (!is_object ($itemvarset)) $itemvarset = new Cvarset();

    // error array (Init - just for initializing variable
    if (!is_array ($err))
        $err["Init"] = "";

      # get slice fields and its priorities in inputform
    list($fields, $prifields) = GetSliceFields($slice_id);

    if (!is_array($prifields))
        return;

    // javascript for input validation
    $js_proove_fields = get_javascript_field_validation (). "

        function proove_fields () {
            var myform = document.inputform;
            return true";

    #it is needed to call IsEditable() function and GetContentFromForm()
    if( $action == "update" ) {
        $oldcontent = GetItemContent($id);
        $oldcontent4id = $oldcontent[$id];   # shortcut
    }

	reset($prifields);
	while(list(,$pri_field_id) = each($prifields)) {
        $f = $fields[$pri_field_id];
        if( ($pri_field_id=='edited_by.......') ||
            ($pri_field_id=='posted_by.......') ||
            ($pri_field_id=='status_code.....') ) {
            continue;   // filed by AA - it could not be filled here
        }
        $varname = 'v'. unpack_id($pri_field_id);  # "v" prefix - database field var
        $htmlvarname = $varname."html";

        global $$varname, $$htmlvarname;

        $setdefault = $action == "add"
                || !$f["input_show"]
                || GetProfileProperty('hide',$pri_field_id)
                || ($action == "insert" && $notshown [$varname]);

        list ($validate) = split (":", $f["input_validate"]);

        if ($setdefault) {
            $$varname = GetDefault($f);
            $$htmlvarname = GetDefaultHTML($f);
        } elseif ($validate=='date') {         // we do not know at this moment,
            $default_val = GetDefault($f);     // if we have to use default
        }

        $editable = IsEditable ($oldcontent4id[$pri_field_id], $f)
                    && ! $notshown [$varname];
        if ($editable) {
            list ($show_func) = split (":", $f["input_show_func"]);
            $show_func_used [$show_func] = 1;
        }

        $js_proove_password_filled = $action != "edit"
            && $f["required"] && ! $oldcontent4id[$pri_field_id][0]["value"];

        $js_validate = $validate;
        if ($js_validate == 'e-unique')
            $js_validate = "email";

        # prepare javascript function for validation of the form
        if( $editable ) switch( $js_validate ) {
            case 'text':
            case 'url':
            case 'email':
            case 'number':
            case 'id':
            case 'pwd':
                $js_proove_fields .= "
            && validate (myform, '$varname', '$js_validate', "
                    .($f["required"] ? "1" : "0").", "
                    .($js_proove_password_filled ? "1" : "0").")";
                break;
        }

        // Run the "validation" which changes field values
        if ($editable && ($action == "insert" || $action == "update")) {
            switch( $validate ) {
            case 'date':
                $foo_datectrl_name = new datectrl($varname);
                $foo_datectrl_name->update();                   # updates datectrl
                if( $$varname != "")                            # loaded from defaults
                  $foo_datectrl_name->setdate_int($$varname);
                $foo_datectrl_name->ValidateDate($f["name"], $err, $f["required"], $default_val);
                $$varname = $foo_datectrl_name->get_date();  # write to var
                break;
            case 'bool':
                $$varname = ($$varname ? 1 : 0);
                break;
            case 'pwd':
                // store the original password to use it in
                // insert_fnc_pwd when it is not changed
                if ($action == "update")
                    $GLOBALS[$varname."c"] = $oldcontent4id [$pri_field_id][0]["value"];
                break;
            }
        }

        // Run the validation which really only validates
        if ($do_validate && ($action == "insert" || $action == "update")) {
            switch( $validate ) {
            case 'text':
            case 'url':
            case 'email':
            case 'number':
            case 'id':
                ValidateInput($varname, $f["name"], $$varname, $err,
                          $f["required"] ? 1 : 0, $f["input_validate"]);
                break;
            // necessary for 'unique' validation: do not validate if
            // the value did not change (otherwise would the value always
            // be found)
            case 'e-unique':
            case 'unique':
                if (addslashes ($oldcontent4id[$pri_field_id][0]["value"]) != $$varname)
                    ValidateInput($varname, $f["name"], $$varname, $err,
                              $f["required"] ? 1 : 0, $f["input_validate"]);
                break;
            case 'user':
                // this is under development.... setu, 2002-0301
                // value can be modified by $$varname = "new value";
                $$varname = usr_validate($varname, $f["name"], $$varname, $err, $f, $fields);
                ##	echo "ItemEdit- user value=".$$varname."<br>";
                break;
            }
        }
    }

    $js_proove_fields .= ";
        }\n";
}

?>
