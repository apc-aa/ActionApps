<?php
/**
 * Does what??
 *
 * Parameters:
 * <pre>
 * expected  type      // = fed  - go to item, where the item is fed from
 * expected  sh_itm    // id of item
 * optionaly url       // show found item on url (if not specified, the url is
 *                     // taken from slice
 * </pre>
 * @package UserOutput
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications 
*/                      
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

/** 
 * Handle with PHP magic quotes - quote the variables if quoting is set off 
 * @param mixed $val the variable or array to quote (add slashes)
 * @return mixed the quoted variables (with added slashes)
 */
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
  if( isset($HTTP_GET_VARS) AND is_array($HTTP_GET_VARS))
    for (reset($HTTP_GET_VARS); list($k, $v) = each($HTTP_GET_VARS); ) 
      $$k = Myaddslashes($v); 
  if( isset($HTTP_POST_VARS) AND is_array($HTTP_POST_VARS))
    for (reset($HTTP_POST_VARS); list($k, $v) = each($HTTP_POST_VARS); ) 
      $$k = Myaddslashes($v); 
  if( isset($HTTP_COOKIE_VARS) AND is_array($HTTP_COOKIE_VARS))
    for (reset($HTTP_COOKIE_VARS); list($k, $v) = each($HTTP_COOKIE_VARS); ) 
      $$k = Myaddslashes($v); 
}

/** APC-AA configuration file */
require "./include/config.php3";
/** Set of useful functions used on most pages */
require $GLOBALS[AA_INC_PATH]. "util.php3";
/** Main include file for using session management function on a page */
require $GLOBALS[AA_INC_PATH]. "locsess.php3";

if( !$sh_itm )
  exit;

$db  = new DB_AA;
$p_id = q_pack_id($sh_itm);

switch( $type ) {
  case "fed":
  default:
    // get source item id and slice

    $SQL = "SELECT source_id, slice_url
              FROM slice, relation, item 
             WHERE relation.destination_id='$p_id'
               AND relation.source_id=item.id
               AND slice.id = item.slice_id
               AND relation.flag = '". REL_FLAG_FEED ."'";  // feed bit

    $db->query($SQL);
    if( $db->next_record() ) {
      $item = unpack_id($db->f(source_id));
      $slice_url = ($db->f(slice_url));
    }
    else { // if this item is not fed - give its own id
      $SQL = "SELECT slice_url FROM slice, item 
               WHERE item.slice_id=slice.id
                 AND item.id = '$p_id'";
      $db->query($SQL);
      if( $db->next_record() ) {
        $item = $sh_itm;
        $slice_url = ($db->f(slice_url));
      }
    }  
}

if( !$url )   // url can be given by parameter
  $url = $slice_url;

if( !$url )   // url can be given by parameter
  $url = $slice_url;
  
go_url(con_url($url,"sh_itm=$item"));

/*
$Log$
Revision 1.4  2002/12/18 13:32:14  drifta
Just changes in comments - moving to phpdoc style.

Revision 1.3  2002/06/17 22:09:19  honzam
removed call-time passed-by-reference variables in function calls; better variable handling if magic_qoutes are not set (no more warning displayed)

Revision 1.2  2001/12/18 11:37:38  honzam
scripts are now "magic_quotes" independent - no matter how it is set

Revision 1.1  2001/04/17 19:18:20  honzam
no message

*/
?>