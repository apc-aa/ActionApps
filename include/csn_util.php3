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

define("HTML",0);
define("PLAIN",1);

// server module's error message
define("ERR_NO_SLICE","Error 1");
define("ERR_PASSWORD","Error 2");

$CONTENT_FORMATS = array("http://www.isi.edu/in-notes/iana/assignments/media-types/text/html" => HTML,
                         "http://www.isi.edu/in-notes/iana/assignments/media-types/text/plain"=> PLAIN);

// get categories from table ef_categories
function GetExternalCategories($feed_id) {
 global $db;

 $db->query("SELECT category_id, category, category_name, target_category_id, approved
               FROM ef_categories
               WHERE feed_id='$feed_id' ORDER BY category_name");
  while ($db->next_record()) {
    $ext_categs[unpack_id($db->f(category_id))] = array("value" => $db->f(category),
                                             "name" => $db->f(category_name),
                                             "approved" => $db->f(approved),
                                             "target_category_id" => unpack_id128($db->f(target_category_id)));
  }
  return $ext_categs;
}

// get external mapping from remote slice to local slice = returns two array
// map_to = from_field_id -> from_field_name  (but just for fields with flag = FEEDMAP_FLAG_MAP
// map_from = to_field_id -> { feedmap_flag => flag, value => from_field_id|value, from_field_name 
function GetExternalMapping($l_slice_id, $r_slice_id) {
  global $db;

  $db->query("SELECT * FROM feedmap WHERE from_slice_id='".q_pack_id($r_slice_id)."'
                                      AND to_slice_id='".q_pack_id($l_slice_id)."'
                                    ORDER BY from_field_name");
  while ($db->next_record()) {
    switch ($f = $db->f(flag)) {
      case FEEDMAP_FLAG_EXTMAP :
      case FEEDMAP_FLAG_MAP:
        $v = $db->f(from_field_id);
        $map_to[$v] = $db->f(from_field_name) ;
        break;
	  case FEEDMAP_FLAG_JOIN:
      case FEEDMAP_FLAG_VALUE :  $v = $db->f(value); break;
      case FEEDMAP_FLAG_EMPTY :  $v = ""; break;
    }
    $map_from[$db->f(to_field_id)] = array("feedmap_flag"=>$f,"value"=>$v,
                                            "from_field_name"=>$db->f(from_field_name));
  }
  return array($map_to,$map_from);
}

function GetBaseFieldId( &$fields, $ftype ) {
  $no = 10000;
  if( isset($fields) AND is_array($fields) ) {
    reset( $fields );
    while( list( $k,$val ) = each( $fields ) ) {
      if(!strstr($val[id],$ftype))
        continue;
      $last = GetFieldNo($val[id]);
      $no = min( $no, ( ($last=='') ? -1 : (integer)$last) );
    }
  }
  if($no==10000)
    return false;
  $no = ( ($no==-1) ? '.' : (string)$no);
  return CreateFieldId($ftype, $no);
}

function GetGroupConstants($slice_id) {
  global $db;
  $cat_group = GetCategoryGroup($slice_id);
   if (!$cat_group)
    return false;

  $SQL = "SELECT id, name, value, class FROM constant WHERE group_id = '$cat_group' ORDER BY pri";
  $db->query($SQL);         // get all categories
  while ($db->next_record()) {
    $cat_ids[unpack_id128($db->f(id))] = array("name"=>$db->f(name),
                                            "value"=>$db->f(value),
                                            "parent_id"=>$db->f('class')
                                            );
  }
  return $cat_ids;
}

function MapDefaultCategory(&$categories, $value, $parent_id) {

  reset($categories);       // try to find the same category
  while (list($to_id,$v) = each($categories)) {
    if ($v[value] == $value)
      return $to_id;
  }
  reset($categories);       // try to find the same parent category
  while (list($to_id,$v) = each($categories)) {
    if ($v[parent_id] == $parent_id)
      return $to_id;
  }
  // return the first category
  reset($categories);
  return key($categories);
}

function unixstamp_to_iso8601 ($t) {
  $tz=date("Z", $t)/60;
  $tm=$tz % 60;
  $tz=$tz/60;
  if ($tz<0) { $ts="-";
    $tz=-$tz;
  } else { $ts="+"; }
  $tz=substr("0" . $tz, -2);
  $tm=substr("0" . $tm, -2);
  return date("Y-m-d\TH:i:s", $t). "${ts}${tz}:${tm}";
 }

function iso8601_to_unixstamp($t) {
 ereg ("([0-9]{4})-([0-9]{2})-([0-9]{2})[T ]([0-9]{2})\:([0-9]{2})\:([0-9]{2})(\+|\-)([0-9]{2})\:([0-9]{2})", $t, $r);
 $tz = (int)$r[8]*3600+$r[9]*60;
 if ($r[7] == "+")
  $tz =-$tz;
 return gmmktime($r[4],$r[5],$r[6],$r[2],$r[3],$r[1])+$tz;
}

?>