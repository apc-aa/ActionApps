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


# Cross-Server Networking - client module

/* Debugging
	There is a lot of debugging code in here, since this tends to be hard to debug
	Call with debugfeed=n parameter for different levels
	1	just errors that indicate a malfunction somewhere
	2	a list of feeds as they are processed
	3	+ list of messages recieved
	4	+ a list of messages rejected
	9	lots and lots more

    This program can be called as for example:
    apc-aa/admin/xmlclient.php3?debugfeed=9&rssfeed_id=16
*/

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


require_once "../include/config.php3";
require_once $GLOBALS["AA_INC_PATH"]."locsess.php3";
require_once $GLOBALS["AA_INC_PATH"]."util.php3";
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
require_once $GLOBALS["AA_INC_PATH"]."csn_util.php3"; // defines HTML and PLAIN as well as other functions
require_once $GLOBALS["AA_INC_PATH"]."xml_fetch.php3";
require_once $GLOBALS["AA_INC_PATH"]."xml_rssparse.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."itemfunc.php3";
require_once $GLOBALS["AA_INC_PATH"]."notify.php3";
require_once $GLOBALS["AA_INC_PATH"]."feeding.php3";
require_once $GLOBALS["AA_INC_PATH"]."sliceobj.php3";

//---------------------------------------------------------

define("FEEDTYPE_RSS",1);
define("FEEDTYPE_APC",0);

// return category_id with cat_group $cat_group and value $value
function GetCategoryIdFromValue($cat_group, $value) {
  global $db,$debugfeed;

  if (!$cat_group || !is_array($cat_group))
    return;

  $SQL = "SELECT id FROM constant WHERE group_id='$group_id' AND value='".addslashes($value)."'";
  if ($debugfeed >= 8) print("\n<br>$SQL");
  $db->query($SQL);
  if ($db->next_record()) {
    return unpack_id128($db->f(id));
  }
}

# update the slice categories in the ef_categories table, that is, if the set of possible slice
# categories has changed
function updateCategories($feed_id, &$l_categs, &$ext_categs,&$cat_refs, &$categs) {
  global $db,$debugfeed;

  // add new categories or update categories' fields
  if (isset($cat_refs) && is_array($cat_refs)) {
    reset($cat_refs);
    while (list ($r_cat_id,) = each($cat_refs)) {
      $category = $categs[$r_cat_id];

      if ($ext_categs[$r_cat_id])  {
      // remote category is in the ef_categories table, so update name and value
        $SQL = "UPDATE ef_categories SET category_name='".$category[name]."',
                                             category='".$category[value]."'
                                         WHERE feed_id='$feed_id' AND category_id='".q_pack_id($r_cat_id)."'";
		if ($debugfeed >= 8) print("\n<br>$SQL");
		$db->query($SQL);
      } else {
        $l_cat_id = MapDefaultCategory($l_categs,$category[value], $category[catparent]);
        $SQL = "INSERT INTO ef_categories VALUES ('".$category[value]."','".$category[name]."',
                  '".q_pack_id($category[id])."','".$feed_id."','".q_pack_id($l_cat_id)."','0')";
		if ($debugfeed >= 8) print("\n<br>$SQL");
        $db->query($SQL);
      }
    }
  }
  // remove the categories from table, which were not sent
  if (isset($ext_categs) && is_array($ext_categs)) {
    reset($ext_categs);
    while (list($r_cat_id, ) = each($ext_categs)) {
      if (isset($cat_refs[$r_cat_id]))
        continue;
	  $SQL = "DELETE FROM ef_categories WHERE feed_id='$feed_id' AND category_id='".q_pack_id($r_cat_id)."'";
	  if ($debugfeed >= 8) print("\n<br>$SQL");
      $db->query($SQL);
    }
  }
}

# update the fields mapping from the remote slice to the local slice.
function updateFieldsMapping($feed_id, &$l_slice_fields, $l_slice_id,
                            $r_slice_id, &$field_refs, &$fields) {
  global $db, $debugfeed;

  list($ext_map,$field_map) = GetExternalMapping($l_slice_id, $r_slice_id);
  $p_l_slice_id = q_pack_id($l_slice_id);
  $p_r_slice_id = q_pack_id($r_slice_id);

  // add new ones
  reset($field_refs);
  while (list ($r_field_id,) = each($field_refs)) {
    if ($ext_map && $ext_map[$r_field_id]) {
      # remote field is in the feedmap table => update name
	  $new_name = quote($fields[$r_field_id][name]);
	  if ($ext_map[$r_field_id] != $new_name) { // update if field name changed on remote AA
	   	$SQL = "UPDATE feedmap SET from_field_name='".quote($fields[$r_field_id][name])."'
                    WHERE from_slice_id='$p_r_slice_id'
                      AND to_slice_id='$p_l_slice_id'
                      AND from_field_id='$r_field_id'";
		if ($debugfeed >= 8) print("\n<br>$SQL");
       	$db->query($SQL);
	  }
    } else {
      $SQL = "INSERT INTO feedmap VALUES('$p_r_slice_id','$r_field_id','$p_l_slice_id','$r_field_id',
                   '".FEEDMAP_FLAG_EXTMAP ."','','".quote($fields[$r_field_id][name])."')";
	  if ($debugfeed >= 8) print("\n<br>$SQL");
      $db->query($SQL); 
    }
  }

  if (!$ext_map)
    return;
  reset($ext_map);
  while (list($r_field_id, ) = each($ext_map)) {
    if (!$field_refs[$r_field_id]) {
		$SQL = "DELETE FROM feedmap WHERE from_slice_id='$p_r_slice_id'
                                      AND to_slice_id='$p_l_slice_id'
                                      AND from_field_id='$r_field_id'";
		if ($debugfeed >= 8) print("\n<br>$SQL");
      	$db->query($SQL);
    }
  }
}

//-----------------------------------------------------------------------------


function apcfeeds() {
	global $db, $debugfeed;
	// select all incoming feeds from table external_feeds
	$SQL="SELECT feed_id, password, server_url, name, slice_id, remote_slice_id, newest_item, user_id, remote_slice_name 
            FROM nodes, external_feeds WHERE nodes.name=external_feeds.node_name";
	if ($debugfeed >= 8) print("\n<br>$SQL");
	$db->query($SQL);
	
	$feeds=array();
	while ($db->next_record()) {
		$fi = $db->f(feed_id);
   		$feeds[$fi] = $db->Record;
   		$feeds[$fi][field_type] = FEEDTYPE_APC;
	}
	if ($debugfeed >= 8) { print("\n<br>feeds="); print_r($feeds); }
	return $feeds;
}

function rssfeeds() {
	global $db, $debugfeed;
	
	$SQL="SELECT feed_id, server_url, name, slice_id FROM rssfeeds";
	if ($debugfeed >= 8) print("\n<br>$SQL");
	$db->query($SQL);

	$rssfeeds=array();
	while ($db->next_record()) {
   		$fi = $db->f(feed_id);
   		$rssfeeds[$fi] = $db->Record;
   		$rssfeeds[$fi][feed_type] = FEEDTYPE_RSS;
   		$rssfeeds[$fi][remote_slice_id] = q_pack_id(attr2id($rssfeeds[$fi][server_url]));
	}
	if ($debugfeed >= 9) { print("\n<br>rssfeeds="); print_r($rssfeeds); }
	return $rssfeeds;
}

if ($debugfeed >= 8) print("\n<br>XMLCLIENT STARTING");

$db = new DB_AA;

if ($feed_id) {  // do one APC feed
	$feeds = apcfeeds();
	onefeed($feed_id,$feeds[$feed_id],$GLOBALS["debugfeed"],true);
} elseif ($rssfeed_id) { // do one RSS feed
	$rssfeeds = rssfeeds();
	onefeed($rssfeed_id, $rssfeeds[$rssfeed_id],$GLOBALS["debugfeed"],true);   // Not sure if its safe for feed_id to be same as for APC feeds
} else {
	$apcfeeds = apcfeeds();
	while (list ($feed_id,$feed) = each($apcfeeds)) {
		onefeed($feed_id,$feed,$GLOBALS["debugfeed"],true);
	}
	$rssfeeds = rssfeeds();
	while (list ($feed_id,$feed) = each($rssfeeds)) {
		onefeed($feed_id,$feed,$GLOBALS["debugfeed"],true);
	}
}


?>
