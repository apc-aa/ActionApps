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

// this allows to require_once this script any number of times - it will be read only once
if (!defined ("INIT_PAGE_INCLUDED"))
	define ("INIT_PAGE_INCLUDED",1);
else return;

 # handle with PHP magic quotes - quote the variables if quoting is set off
function Myaddslashes($val, $n=1) {
    if (!is_array($val))
        return addslashes($val);
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

if($encap == "false")    # used in itemedit for anonymous form
  $encap = false;        # it must be here, because the variable is rewriten
                         # if the get_magic_quotes_gpc()==false (see above)

# modules other than slices are in deeper directory -> $directory_depth
require_once "$directory_depth../../include/config.php3";

# should be set in config.php3 - used for relative path to image directory
if (!$AA_INSTAL_PATH) {
  $url_components = parse_url(AA_INSTAL_URL);
	$AA_INSTAL_PATH = $url_components['path'];
}

# should be set in config.php3 - base is better for modules
if (!$AA_BASE_PATH) {
  $AA_BASE_PATH = substr($AA_INC_PATH, 0, -8);
}

require_once $GLOBALS[AA_BASE_PATH] . "modules/alerts/uc_auth.php3";
require_once $GLOBALS["AA_INC_PATH"] . "scroller.php3";
require_once $GLOBALS[AA_BASE_PATH] . "modules/alerts/util.php3";

page_open(array("sess" => "AA_UC_Session", "auth" => "AA_UC_Auth"));

$auth->relogin_if($relogin); // relogin if requested

if( $slice_id )
  $p_slice_id = q_pack_id($slice_id);

?>
