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

# script for anonymous filling

# Parameters:
#   slice_id     - id of slice into which the item is added
#   notvalidate  - if true, data input validation is skipped
#   ok_url       - url where to go, if item is successfully sored in database
#   err_url      - url where to go, if item is not sored in database (due to
#                  validation of data, ...)

# handle with PHP magic quotes - quote the variables if quoting is set off
function Myaddslashes($val, $n=1) {
  if (!is_array($val)) {
    return addslashes($val);
  }  
  for (reset($val); list($k, $v) = each($val); )
    $ret[$k] = Myaddslashes($v, $n+1);
  return $ret;
}    

if (!get_magic_quotes_gpc()) { 
  // Overrides GPC variables 
  for (reset($HTTP_GET_VARS); list($k, $v) = each($HTTP_GET_VARS); ) 
  $$k = Myaddslashes($v); 
  for (reset($HTTP_POST_VARS); list($k, $v) = each($HTTP_POST_VARS); ) 
  $$k = Myaddslashes($v); 
  for (reset($HTTP_COOKIE_VARS); list($k, $v) = each($HTTP_COOKIE_VARS); ) 
  $$k = Myaddslashes($v); 
}

require "./include/config.php3";
require $GLOBALS[AA_INC_PATH]."locsess.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."itemfunc.php3";
require $GLOBALS[AA_INC_PATH]."notify.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."date.php3";
require $GLOBALS[AA_INC_PATH]."feeding.php3";

function SendErrorPage($txt) {
  if( $GLOBALS["err_url"] )
    go_url($GLOBALS["err_url"]);
  echo (L_OFFLINE_ERR_BEGIN);
  if( isset( $txt ) AND is_array( $txt ) )
    PrintArray($txt);    
   else 
    echo $txt;
  echo (L_OFFLINE_ERR_END );
  exit;
}  

function SendOkPage($txt) {
  if( $GLOBALS["ok_url"] )
    go_url($GLOBALS["ok_url"]);
  go_url($GLOBALS[HTTP_REFERER]);
  exit;
}  

  # init used objects
$db = new DB_AA;
$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$itemvarset = new Cvarset();

if( !$slice_id )
  SendErrorPage(L_NO_SLICE_ID);

$error = "";
$ok = "";

$p_slice_id = q_pack_id($slice_id);
$slice_info = GetSliceInfo($slice_id);

if( !$slice_info )
  SendErrorPage(L_NO_SUCH_SLICE);

if( $slice_info["permit_anonymous_post"] < 1 )
  SendErrorPage(L_ANONYMOUS_POST_ADMITED);
 else
  $bin2fill = $slice_info["permit_anonymous_post"]; 

  # get slice fields and its priorities in inputform
list($fields,$prifields) = GetSliceFields($slice_id);   

if( !(isset($prifields) AND is_array($prifields)) )
  SendErrorPage(L_NO_FIELDS);
  
# get defaults 
reset($prifields);
while(list(,$pri_field_id) = each($prifields)) {
  $f = $fields[$pri_field_id];
  $varname = 'v'. unpack_id($pri_field_id);  # "v" prefix - database field var
  $htmlvarname = $varname."html";
  if( !$$varname ) {
    $$varname = GetDefault($f);
    $$htmlvarname = GetDefaultHTML($f);
  }    
  if( $f[input_validate]=='date') {            # get date from special variables
    $datectrl_name = new datectrl($varname);
    if( !$datectrl_name->update())             # updates datectrl
      # if not set - load from defaults
      $datectrl_name->setdate_int($$varname);
    $$varname = $datectrl_name->get_date();    # write to var
  }  
  
    # validate input data
  if( !$notvalidate )
  {
    if( $f[input_show] AND !$f[feed] ) {
      switch( $f[input_validate] ) {
        case 'text': 
        case 'url':  
        case 'email':  
        case 'number':  
        case 'id':  
          ValidateInput($varname, $f[name], $$varname, &$err,
                        $f[required] ? 1 : 0, $f[input_validate]);
          break;
        case 'date':  
          $datectrl_name->ValidateDate($f[name], &$err);
          break;
        case 'bool':  
          $$varname = ($$varname ? 1 : 0);
          break;
      }
    }
  }   
}

if( count($err)>1 )
  SendErrorPage( $err );

  # prepare content4id array before call StoreItem function
$content4id = GetContentFromForm( $fields, $prifields );

  # put an item to the right bin
$content4id["status_code....."][0][value] = ($bin2fill==1 ? 1 : 2);

// p_arr_m( $content4id );

  # update database
$added_to_db = StoreItem( new_id(), $slice_id, $content4id, $fields, true, 
                          true, true );     # insert, invalidatecache, feed

if( count($err) > 1)
  SendErrorPage( $err );
 else
  SendOkPage( L_ANONYMOUS_FILL_OK );

/*
$Log$
Revision 1.5  2001/12/21 11:44:55  honzam
fixed bug of includes in e-mail notify

Revision 1.4  2001/12/18 11:37:38  honzam
scripts are now "magic_quotes" independent - no matter how it is set

Revision 1.3  2001/04/09 20:42:29  honzam
fixed bug in selecting bin, where to put item in filler.php3

Revision 1.1  2001/03/20 15:23:09  honzam
standardized content management for items - filler, itemedit, offline, feeding

*/
?>