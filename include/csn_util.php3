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

define("HTML", 0);
define("PLAIN",1);

define("FEEDTYPE_RSS",1);
define("FEEDTYPE_APC",0);

// server module's error message
define("ERR_NO_SLICE","Error 1");
define("ERR_PASSWORD","Error 2");

$CONTENT_FORMATS = array("http://www.isi.edu/in-notes/iana/assignments/media-types/text/html" => HTML,
                         "http://www.isi.edu/in-notes/iana/assignments/media-types/text/plain"=> PLAIN);

/** Each slice can use different character encoding. The encoding is based on
 *  selected language for the slice
 */
function getSliceEncoding($slice_id) {
    $db = getDB();
    $p_slice_id = q_pack_id($slice_id);
    $db->query("SELECT lang_file FROM module WHERE id='$p_slice_id'");
    $db->next_record();
    $lang = substr($db->f("lang_file"),0,2);
    freeDB($db);
    return $GLOBALS["LANGUAGE_CHARSETS"][$lang];
}

/** Get categories from table ef_categories
 *  ef_categories is used only for APC type of feedin (APC RSS) and the change
 *  would by tricky, since theer could unfortunatelly be two feeds with the same
 *  id (one APC and one other RSS)
 */
function GetExternalCategories($feed_id) {
    global $db;
    $db->query("SELECT category_id, category, category_name, target_category_id, approved
                  FROM ef_categories
                 WHERE feed_id='$feed_id' ORDER BY category_name");
    while ($db->next_record()) {
        $ext_categs[unpack_id($db->f('category_id'))] = array(
            "value"              => $db->f('category'),
            "name"               => $db->f('category_name'),
            "approved"           => $db->f('approved'),
            "target_category_id" => unpack_id128($db->f('target_category_id')));
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
      case FEEDMAP_FLAG_RSS:
      case FEEDMAP_FLAG_VALUE :  $v = $db->f(value); break;
      case FEEDMAP_FLAG_EMPTY :  $v = ""; break;
    }
    $map_from[$db->f(to_field_id)] = array("feedmap_flag"=>$f,"value"=>$v,
                                            "from_field_name"=>$db->f(from_field_name));
  }

  return array($map_to,$map_from);
}

/** Returns category_id of category with cat_group $cat_group and value $value
 */
function GetCategoryIdFromValue($cat_group, $value) {
    global $debugfeed;
    if (!$cat_group || !is_array($cat_group)) {
        return;
    }
    $SQL = "SELECT id FROM constant WHERE group_id='$group_id' AND value='".addslashes($value)."'";
    if ($debugfeed >= 8) print("\n<br>$SQL");
    $db = getDB();
    $db->query($SQL);
    if ($db->next_record()) {
        $ret = unpack_id128($db->f('id'));
    }
    freeDB($db);
    return $ret;
}

/** Update the slice categories in the ef_categories table,
 *  that is, if the set of possible slice categories has changed
 */
function updateCategories($feed_id, &$l_categs, &$ext_categs, &$cat_refs, &$categs) {
    global $debugfeed;
    $db = getDB();
    // add new categories or update categories' fields
    if (isset($cat_refs) && is_array($cat_refs)) {
        foreach ($cat_refs as $r_cat_id => $v) {
            $category = $categs[$r_cat_id];

            if ($ext_categs[$r_cat_id])  {
                // remote category is in the ef_categories table, so update name and value
                $SQL = "UPDATE ef_categories SET category_name='".addslashes($category['name'])."', category='".addslashes($category['value'])."'
                         WHERE feed_id='$feed_id' AND category_id='".q_pack_id($r_cat_id)."'";
                if ($debugfeed >= 8) print("\n<br>$SQL");
                $db->query($SQL);
            } else {
                $l_cat_id = MapDefaultCategory($l_categs,$category['value'], $category['catparent']);
                $SQL = "INSERT INTO ef_categories VALUES ('".addslashes($category['value'])."','".addslashes($category['name'])."',
                           '".q_pack_id($category['id'])."','".$feed_id."','".q_pack_id($l_cat_id)."','0')";
                if ($debugfeed >= 8) print("\n<br>$SQL");
                $db->query($SQL);
            }
        }
    }

    // remove the categories from table, which were not sent
    if (isset($ext_categs) && is_array($ext_categs)) {
        foreach ( $ext_categs as $r_cat_id => $v ) {
            // 'AA_Other_Categor' and 'AA_The_Same_Cate' are keywords - do not delete
            if (isset($cat_refs[$r_cat_id]) OR (q_pack_id($r_cat_id)=='AA_Other_Categor')) {
                continue;
            }
            $SQL = "DELETE FROM ef_categories WHERE feed_id='$feed_id' AND category_id='".q_pack_id($r_cat_id)."'";
            if ($debugfeed >= 8) print("\n<br>$SQL");
            $db->query($SQL);
        }
    }
    freeDB($db);
}

/** Update the fields mapping from the remote slice to the local slice
 *  Updates the field names and adds new fields
 */
function updateFieldsMapping($feed_id, &$l_slice_fields, $l_slice_id, $r_slice_id, &$field_refs, &$fields) {
    global $debugfeed;

    list($ext_map,$field_map) = GetExternalMapping($l_slice_id, $r_slice_id);
    $p_l_slice_id             = q_pack_id($l_slice_id);  // local slice id
    $p_r_slice_id             = q_pack_id($r_slice_id);  // remote slice id

    // add new ones
    $db = getDB();
    if ( isset($field_refs) AND is_array($field_refs) ) {
        foreach( $field_refs as $r_field_id => $val ) {
            if ($ext_map && $ext_map[$r_field_id]) {
                // remote field is in the feedmap table => update name
                $new_name = quote($fields[$r_field_id]['name']);

                // update if field name changed on remote AA
                if ($ext_map[$r_field_id] != $new_name) {
                    $SQL = "UPDATE feedmap SET from_field_name='".quote($fields[$r_field_id]['name'])."'
                             WHERE from_slice_id='$p_r_slice_id'
                               AND to_slice_id='$p_l_slice_id'
                               AND from_field_id='$r_field_id'";
                    if ($debugfeed >= 8) print("\n<br>$SQL");
                    $db->query($SQL);
                }
            } else {
                // add new ones
                $SQL = "INSERT INTO feedmap VALUES('$p_r_slice_id','$r_field_id','$p_l_slice_id','$r_field_id',
                           '".FEEDMAP_FLAG_EXTMAP ."','','".quote($fields[$r_field_id]['name'])."')";
                if ($debugfeed >= 8) print("\n<br>$SQL");
                $db->query($SQL);
            }
        }
    }
    if (!$ext_map) {
        freeDB($db);
        return;
    }
    foreach ( $ext_map as $r_field_id => $v ) {
        if (!$field_refs[$r_field_id]) {
            $SQL = "DELETE FROM feedmap WHERE from_slice_id='$p_r_slice_id'
                       AND to_slice_id='$p_l_slice_id'
                       AND from_field_id='$r_field_id'";
            if ($debugfeed >= 8) print("\n<br>$SQL");
            $db->query($SQL);
        }
    }
    freeDB($db);
}

/** Returns firs field id of specified type */
function GetBaseFieldId( &$fields, $ftype ) {
    $no = 10000;
    if( isset($fields) AND is_array($fields) ) {
        foreach ( $fields as  $k => $val ) {
            if(!strstr($val['id'],$ftype)) {
                continue;
            }
            $last = GetFieldNo($val['id']);
            $no   = min( $no, ( ($last=='') ? -1 : (integer)$last) );
        }
    }
    return ($no == 10000) ? false :
                            CreateFieldId($ftype, ($no==-1) ? '.' : (string)$no);
}


/** Returns Category definitions for specified slice
 *  $cat[<unpacked_id>] = array( 'name'=> , 'value'=> 'parent_id'=> );
 */
function GetGroupConstants($slice_id) {
    global $db;
    $cat_group = GetCategoryGroup($slice_id);
    if (!$cat_group) {
        return false;
    }

    $SQL = "SELECT id, name, value, class FROM constant WHERE group_id = '$cat_group' ORDER BY pri";
    $db->query($SQL);         // get all categories
    while ($db->next_record()) {
        $cat_ids[unpack_id128($db->f('id'))] = array("name"     => $db->f('name'),
                                                     "value"    => $db->f('value'),
                                                     "parent_id"=> $db->f('class'));
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
$default_rss_map = array (
    // Note this matches code in xml_rssparse.php3 for parsing DC fields
    // Can change the names without affecting anything
        "author.........." => array("feedmap_flag"=>FEEDMAP_FLAG_RSS,"value"=>"DC/creator","from_field_name"=>"DC:creator"),
        "abstract........" => array("feedmap_flag"=>FEEDMAP_FLAG_RSS,"value"=>"ITEM/description|DC/description|DC/subject","from_field_name"=>"Any abstract"),
        "publish_date...." => array("feedmap_flag"=>FEEDMAP_FLAG_RSS,"value"=>"DATE(DC/date)|DATE(ITEM/pubdate)|NOW","from_field_name"=>"DC:date"),
        "source.........." => array("feedmap_flag"=>FEEDMAP_FLAG_RSS,"value"=>"DC/source|CHANNEL/title","from_field_name"=>"DC:source"),
        "lang_code......." => array("feedmap_flag"=>FEEDMAP_FLAG_RSS,"value"=>"DC/language","from_field_name"=>"DC:language"),
        "source_href....." => array("feedmap_flag"=>FEEDMAP_FLAG_RSS,"value"=>"DC/relation|CHANNEL/link","from_field_name"=>"DC:relation"),
        "place..........." => array("feedmap_flag"=>FEEDMAP_FLAG_RSS,"value"=>"DC/coverage","from_field_name"=>"DC:coverage"),
        "headline........" => array("feedmap_flag"=>FEEDMAP_FLAG_RSS,"value"=>"DC/title|ITEM/title","from_field_name"=>"DC:title"),
        "full_text......." => array("feedmap_flag"=>FEEDMAP_FLAG_RSS,"value"=>"CONTENT","from_field_name"=>"Content"),
//		"status_code....." => array("feedmap_flag"=>FEEDMAP_FLAG_VALUE,"value"=>1,"from_field_name"=>"Approved"),
        "status_code....." => array("feedmap_flag"=>FEEDMAP_FLAG_VALUE,"value"=>2,"from_field_name"=>"Approved"),
        "hl_href........." => array("feedmap_flag"=>FEEDMAP_FLAG_RSS,"value"=>"ITEM/link|ITEM/guid","from_field_name"=>"ITEM:link"),
        "expiry_date....." => array("feedmap_flag"=>FEEDMAP_FLAG_VALUE,"value"=>(time()+2000*24*60*60),"from_field_name"=>"Expiry Date")
    );

// This function converts an attribute string to a unique id,
// this function must: always return the same result; and not contain 00 or 27
// the tricky part is that APC attribute strings contain a prefix and 32 digits, while
// non APC strings need the whole string hashed.
function attr2id($str) {
    if (ereg("/(items|cat|slices)/([0-9a-f]{32})",$str,$regs)) { // Looks like an APC id
        return $regs[2]; // Maybe this should be 0 ?
    } else {
        return(string2id($str));
    }
}

function name2rssfeed($slice_id,$name) {
    $db = getDB();
    $db->query("SELECT * FROM rssfeeds WHERE name='$name' AND slice_id = '".q_pack_id($slice_id)."'");
    if ($db->next_record()) {
      $res = $db->Record;
      $res["feed_type"] = FEEDTYPE_RSS;
      $res["remote_slice_id"] = attr2id($db->f(server_url));
    }
    freeDB($db);
    return $res;
}

?>