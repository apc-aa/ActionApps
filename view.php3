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
                    # set[23]=mlx-EN-FR-DE
                    #   - sets maximal number of viewed items in view 23 to 20
                    #   - there can be more settings (future) - comma separated
#optionaly als[]    # user alias - see slice.php3 for more details
# for more info see AA FAQ: http://apc-aa.sourceforge.net/faq/index.shtml#219


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

require_once "./include/config.php3";
require_once $GLOBALS["AA_INC_PATH"]."easy_scroller.php3";
require_once $GLOBALS["AA_INC_PATH"]."util.php3";
require_once $GLOBALS["AA_INC_PATH"]."item.php3";
require_once $GLOBALS["AA_INC_PATH"]."view.php3";
require_once $GLOBALS["AA_INC_PATH"]."discussion.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."searchlib.php3";
require_once $GLOBALS["AA_INC_PATH"]."locsessi.php3";    # DB_AA object definition

add_vars();

if (isset($slice_id)) $p_slice_id= q_pack_id($slice_id);
$db = new DB_AA; 	   	 // open BD
$db2 = new DB_AA; 		 // open BD

if ($time_limit) set_time_limit($time_limit);
if ($debug) huhl("Starting view");

// Need to be able to set content-type for RSS, cannot tdo it in the view
// because the cache wont reflect this
if ($contenttype) {
	header("Content-type: $contenttype");
}
echo GetView(ParseViewParameters());

if ($debug) huhl("Completed view");

exit;

?>
