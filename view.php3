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

#expected  vid      # id of view
#optionaly cmd[]    # command to modify the view
                    # cmd[23]=v-25 means: show view id 25 in place of id 23 
                    # cmd[23]=i-24-7464674747 means view 
                    #   number 23 has to display item 74.. in format defined
                    #   in view 24
                    # cmd[23]=c-1-Environment means display view no 23 in place 
                    #   of view no 23 (that's normal), but change value for 
                    #   condition 1 to "Environment".
                    # cmd[23]=c-1-Environment-2-Jane means the same as above, 
                    #   but there are redefined two conditions
                    # cmd[23]=d-headline........-LIKE-Profit-publish_date....-m:>-86400
                    #   generalized version of cmd[]-c
                    #      - fields and operators specifed
                    #      - unlimited number of conditions
                    #      - all default conditions from view definition are 
                    #        completely redefined by the specified ones
#optionaly set[]    # setings to modify view behavior (can be combined with cmd)
                    # set[23]=listlen-20 
                    #   - sets maximal number of viewed items in view 23 to 20
                    #   - there can be more settings (future) - comma separated 
#optionaly als[]    # user alias - see slice.php3 for more details

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
require $GLOBALS[AA_INC_PATH]."easy_scroller.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";
require $GLOBALS[AA_INC_PATH]."view.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."searchlib.php3";
require $GLOBALS[AA_INC_PATH]."locsessi.php3";    # DB_AA object definition

$p_slice_id= q_pack_id($slice_id);
$db = new DB_AA; 	   	 // open BD	
$db2 = new DB_AA; 		 // open BD	
$db3 = new DB_AA; 		 // open BD	

echo GetView(ParseViewParameters());
exit;

/*
$Log$
Revision 1.11  2001/12/18 11:37:38  honzam
scripts are now "magic_quotes" independent - no matter how it is set

Revision 1.10  2001/09/27 16:13:41  honzam
New listlen parameter, New view alias, New discussion support

Revision 1.9  2001/09/14 19:02:45  madebeer
view static - now allows aliases from URL
checkin of sql statements to use RSS

Revision 1.8  2001/09/12 06:19:01  madebeer
Added ability to generate RSS views.
Added f_q to item.php3, to grab 'blurbs' from another slice using aliases

Revision 1.7  2001/08/02 20:04:53  honzam
new - stronger - view condition redefining parameter cmd[]-d

Revision 1.6  2001/07/31 16:32:50  honzam
Added '-' operator modifier for relative time conditions. The operator was implemented to view definition too (se_view.php3)

Revision 1.5  2001/07/31 15:20:12  honzam
new - display condition redefining parameter to view.php3 (cmd[]=c)

Revision 1.4  2001/07/09 17:43:53  honzam
url passed user aliases

Revision 1.3  2001/06/24 16:46:22  honzam
new sort and search possibility in admin interface

Revision 1.2  2001/05/23 23:04:54  honzam
fixed bug of not updated list of item in Item manager after item edit

Revision 1.1  2001/05/18 13:41:02  honzam
New View feature, new and improved search function (QueryIDs)

*/
?>
