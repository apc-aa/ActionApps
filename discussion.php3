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

// view discussions, parse search conditions (conds[discussion] array)

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

function PutSearchLog ()
{
    global $QUERY_STRING_UNESCAPED, $REDIRECT_QUERY_STRING_UNESCAPED, $HTTP_REMOTE_USER,
        $searchlog;
        
    $httpquery = $QUERY_STRING_UNESCAPED.$REDIRECT_QUERY_STRING_UNESCAPED;
    $httpquery = DeBackslash ($httpquery);
    $httpquery = str_replace ("'", "\\'", $httpquery);
    $db = new DB_AA;
    global $view_param;
    $found_count = count ($view_param["disc_ids"]);
    list($usec, $sec) = explode(" ",microtime()); 
    $slice_time = 1000 * ((float)$usec + (float)$sec - $GLOBALS[disc_starttime]); 
    $user = $GLOBALS[HTTP_SERVER_VARS]['REMOTE_USER'];
    $db->query (
    "INSERT INTO searchlog (date,query,user,found_count,search_time,additional1) 
    VALUES (".time().",'$httpquery','$user',$found_count,$slice_time,'discuss $searchlog')");
}

require "./include/config.php3";
require $GLOBALS[AA_INC_PATH]."easy_scroller.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";
require $GLOBALS[AA_INC_PATH]."view.php3";
require $GLOBALS[AA_INC_PATH]."discussion.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."searchlib.php3";
require $GLOBALS[AA_INC_PATH]."locsessi.php3";    # DB_AA object definition

add_vars();

$p_slice_id= q_pack_id($slice_id);
$db = new DB_AA; 	   	 // open BD	

list($usec, $sec) = explode(" ",microtime()); 
$disc_starttime = ((float)$usec + (float)$sec); 

$view_param = ParseViewParameters();
$view_param["disc_ids"] = QueryDiscIDs($slice_id, $conds, $sort, $slices );
$view_param["disc_type"] = "list";

if ($debug) {
    echo "Discussion item IDs:<br>";
    print_r ($view_param["disc_ids"]);
}

echo GetView($view_param);

if ($searchlog) PutSearchLog();
exit;

?>
