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

require "../include/config.php3";
require $GLOBALS[AA_INC_PATH]."locsess.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."csn_util.php3";
require $GLOBALS[AA_INC_PATH]."xml_fetch.php3";
require $GLOBALS[AA_INC_PATH]."xml_rssparse.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."itemfunc.php3";
require $GLOBALS[AA_INC_PATH]."notify.php3";

//---------------------------------------------------------

// return category_id with cat_group $cat_group and value $value
function GetCategoryIdFromValue($cat_group, $value) {
  global $db;

  if (!$cat_group || !is_array($cat_group))
    return;

  $db->query("SELECT id FROM constant WHERE group_id='$group_id' AND value='$value'");
  if ($db->next_record()) {
    return unpack_id($db->f(id));
  }
}

# update the slice categories in the ef_categories table, that is, if the set of possible slice
# categories has changed
function updateCategories($feed_id, &$l_categs, &$ext_categs,&$cat_refs, &$categs) {
  global $db;

  // add new categories or update categories' fields
  if (isset($cat_refs) && is_array($cat_refs)) {
    reset($cat_refs);
    while (list ($r_cat_id,) = each($cat_refs)) {
      $category = $categs[$r_cat_id];

      if ($ext_categs[$r_cat_id])  {
      // remote category is in the ef_categories table, so update name and value
        $db->query("UPDATE ef_categories SET category_name='".$category[name]."',
                                             category='".$category[value]."'
                                         WHERE feed_id='$feed_id' AND category_id='".q_pack_id($r_cat_id)."'");
      } else {
        $l_cat_id = MapDefaultCategory($l_categs,$category[value], $category[catparent]);
        $SQL = "INSERT INTO ef_categories VALUES ('".$category[value]."','".$category[name]."',
                  '".q_pack_id($category[id])."','".$feed_id."','".q_pack_id($l_cat_id)."','0')";
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
      $db->query("DELETE FROM ef_categories WHERE feed_id='$feed_id' AND category_id='".q_pack_id($r_cat_id)."'");
    }
  }
}

# update the fields mapping from the remote slice to the local slice.
function updateFieldsMapping($feed_id, &$l_slice_fields, $l_slice_id,
                            $r_slice_id, &$field_refs, &$fields) {
  global $db;

  list($ext_map,$field_map) = GetExternalMapping($l_slice_id, $r_slice_id);
  $p_l_slice_id = q_pack_id($l_slice_id);
  $p_r_slice_id = q_pack_id($r_slice_id);

  // add new ones
  reset($field_refs);
  while (list ($r_field_id,) = each($field_refs)) {
    if ($ext_map && $ext_map[$r_field_id]) {
       // remote field is in the feedmap table => update name
       $db->query("UPDATE feedmap SET from_field_name='".$fields[$r_field_id][name]."'
                    WHERE from_slice_id='$p_r_slice_id'
                      AND to_slice_id='$p_l_slice_id'
                      AND from_field_id='$r_field_id'");

    } else {
      $SQL = "INSERT INTO feedmap VALUES('$p_r_slice_id','$r_field_id','$p_l_slice_id','$r_field_id',
                   '".FEEDMAP_FLAG_EXTMAP ."','','".$fields[$r_field_id][name]."')";
      $db->query($SQL);
    }
  }

  if (!$ext_map)
    return;
  reset($ext_map);
  while (list($r_field_id, ) = each($ext_map)) {
    if (!$field_refs[$r_field_id]) {
      $db->query("DELETE FROM feedmap WHERE from_slice_id='$p_r_slice_id'
                                      AND to_slice_id='$p_l_slice_id'
                                      AND from_field_id='$r_field_id'");
    }
  }
}

// stores items to the table item
function updateItems($feed_id, &$feed, &$aa_rss, $l_slice_id, $r_slice_id, $l_slice_fields, &$ext_categs, &$l_categs) {
  global $db, $varset, $itemvarset;

  while (list($item_id,) = each($aa_rss[items])) {
    $db->query("SELECT id FROM item WHERE id='".q_pack_id($item_id)."'");    // Only store items that have an id which i
    if ($db->next_record()) {                                   // not already contained in the items table

      continue;
    }
    $varset=new Cvarset;
    $itemvarset = new CVarset;

    $item = $aa_rss[items][$item_id];

    // set fulltext
    if ($fulltext_field_id = GetBaseFieldId($aa_rss[fields],"full_text")) {
      $flag="";
      if (isset($item[content][HTML])) {             // choose HTML content first
        $flag = FLAG_HTML;
        $cont_flag = HTML;
      } else {                                       // otherwise PLAIN. Other formats are not supported,
        $cont_flag= PLAIN;                           // but they can be added in future
      }
      $item[fields_content][$fulltext_field_id][0] = array("value"=>$item[content][$cont_flag],
                                                           "flag"=>$flag);
    }
    // set categories
    reset($ext_categs);
    $cat_field_id = GetBaseFieldId( $aa_rss[fields], "category" );

    if (!isset($item[categories]) ) {
      $first_cat = current($ext_categs);        // get first category (categories are sorted by name)
      $approved = $first_cat[approved];
      $item[fields_content][$cat_field_id][][value] = $l_categs[$first_cat[target_category_id]][value];

    } else {
      $approved = $ext_categs[$item[categories][0]][approved];
      reset( $item[categories] );
      while (list (,$cat_id) = each($item[categories])) {
        $item[fields_content][$cat_field_id][][value] = $l_categs[$ext_categs[$cat_id][target_category_id]][value];
        // flag ???
      }
    }
    // set status_code - according to the settings of ef_categories table
    $status_code_id = GetBaseFieldId( $aa_rss[fields], "status_code" );
    $item[fields_content][$status_code_id][0][value] = $approved ? 1 : 2;

    // if the item has no aa fields, use Dublin Core data fields - TODO
    // if the item has neither aa fields, nor Dublin Core data fields , use only RSS - TODO

    // create content from $item[fields_content]
    list(,$map) = GetExternalMapping($l_slice_id,$r_slice_id);
    while (list($to_field_id,$v) = each($map)) {
      switch ($v[feedmap_flag]) {
        case FEEDMAP_FLAG_VALUE:
          $content4id[$to_field_id][0][value] = quote($v[value]);
          break;

        case FEEDMAP_FLAG_EXTMAP:
          $values = $item[fields_content][$v[value]];
          // quote all values
          if (isset($values) && is_array($values)) {
            while (list($k,$v2) = each($values))
              $values[$k][value] = quote($v2[value]);

            $content4id[$to_field_id] = $values;
          }
          break;
      }
    }
    StoreItem( $item_id, $l_slice_id, $content4id, $l_slice_fields, true, true, false );
                                                    # insert, invalidatecache, not feed
    // set the item to be recevied from remote node (todo - set via content4id)

    $db->query("UPDATE item SET externally_fed='".$feed[name]."' WHERE id='".q_pack_id($item_id)."'");
  }
}
//-----------------------------------------------------------------------------

$db = new DB_AA;

// select all incoming feeds from table external_feeds
$db->query("SELECT feed_id, password, server_url, name, slice_id, remote_slice_id, newest_item, user_id
            FROM nodes, external_feeds WHERE nodes.name=external_feeds.node_name");

$feeds="";
while ($db->next_record()) {
   $feeds[$db->f(feed_id)] = $db->Record;
}
if (!$feeds)    // no feeds => quit
  exit;

while (list ($feed_id,$feed) = each($feeds)) {
  $l_slice_id = unpack_id($feed[slice_id]);
  $r_slice_id = unpack_id($feed[remote_slice_id]);

  //select external categories
  $ext_categs = GetExternalCategories($feed_id);
  $cat_ids=array();
  if ($ext_categs && is_array($ext_categs)) {
    while (list ($k, ) = each($ext_categs)) {
      if (!$ext_categs[$k][target_category_id])
        continue;
      $cat_ids[] = $k;
    }
  }
  if (!($xml_data = xml_fetch( $feed[server_url], ORG_NAME, $feed[password],
            $feed[user_id], $r_slice_id, $feed[newest_item], implode(" ",$cat_ids))))
     continue;

  if (substr($xml_data,0,1) != "<") {
    writeLog("CSN","Feeding mode: $xml_data");
    continue;
  }

  //print($xml_data);                 // for debugging
  if (!( $aa_rss = aa_rss_parse( $xml_data ))) {
    writeLog("CSN","Feeding mode: Unable to parse XML data");
    continue;
  }
  //print_r($aa_rss);                 // for debugging

  list($l_slice_fields,) = GetSliceFields($l_slice_id);
  $l_categs = GetGroupConstants( $l_slice_id );        // get all categories belong to slice

  updateCategories($feed_id, $l_categs, $ext_categs,
                       $aa_rss[channels][$r_slice_id][categories],$aa_rss[categories]);

  updateFieldsMapping($feed_id, $l_slice_fields, $l_slice_id, $r_slice_id,
                       $aa_rss[channels][$r_slice_id][fields],$aa_rss[fields]);

  // update items
  if (isset($aa_rss[items])) {
    updateItems($feed_id, $feed, $aa_rss, $l_slice_id, $r_slice_id, $l_slice_fields, $ext_categs, $l_categs);
    //update the newest item
    $db->query("UPDATE external_feeds SET newest_item='".$aa_rss[channels][$r_slice_id][timestamp]."'
               WHERE feed_id='$feed_id'");
  }
}
?>
