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
 * @param mixed $value the variable or array to quote (add slashes)
 * @return mixed the quoted variables (with added slashes)
 */
function AddslashesDeep($value) {
    return is_array($value) ? array_map('AddslashesDeep', $value) : addslashes($value);
}

if (!get_magic_quotes_gpc()) {
    // Overrides GPC variables
    foreach ($_GET as $k => $v) {
        $kk = AddslashesDeep($v);
    }
    foreach ($_POST as $k => $v) {
        $kk = AddslashesDeep($v);
    }
    foreach ($_COOKIE as $k => $v) {
        $kk = AddslashesDeep($v);
    }
}

/** APC-AA configuration file */
require_once "./include/config.php3";
/** Set of useful functions used on most pages */
require_once AA_INC_PATH. "util.php3";
/** Main include file for using session management function on a page */
require_once AA_INC_PATH. "locsess.php3";

if ( !$sh_itm )
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
    if ( $db->next_record() ) {
      $item = unpack_id($db->f(source_id));
      $slice_url = ($db->f(slice_url));
    }
    else { // if this item is not fed - give its own id
      $SQL = "SELECT slice_url FROM slice, item
               WHERE item.slice_id=slice.id
                 AND item.id = '$p_id'";
      $db->query($SQL);
      if ( $db->next_record() ) {
        $item = $sh_itm;
        $slice_url = ($db->f(slice_url));
      }
    }
}

if ( !$url )   // url can be given by parameter
  $url = $slice_url;

if ( !$url )   // url can be given by parameter
  $url = $slice_url;

go_url(con_url($url,"sh_itm=$item"));
?>