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


require "../include/config.php3";
require $GLOBALS[AA_INC_PATH]."locsess.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."csn_util.php3"; // defines HTML and PLAIN as well as other functions
require $GLOBALS[AA_INC_PATH]."xml_fetch.php3";
require $GLOBALS[AA_INC_PATH]."xml_rssparse.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."itemfunc.php3";
require $GLOBALS[AA_INC_PATH]."notify.php3";

//---------------------------------------------------------

define("FEEDTYPE_RSS",1);
define("FEEDTYPE_APC",0);

$default_rss_map = array (
	// Note this matches code in xml_rssparse.php3 for parsing DC fields
	// Can change the names without affecting anything
		"author.........." => array("feedmap_flag"=>FEEDMAP_FLAG_EXTMAP,"value"=>"DC/creator","from_field_name"=>"DC:creator"),
		"abstract........" => array("feedmap_flag"=>FEEDMAP_FLAG_EXTMAP,"value"=>"ITEM/description|DC/description|DC/subject","from_field_name"=>"Any abstract"),
		"publish_date...." => array("feedmap_flag"=>FEEDMAP_FLAG_EXTMAP,"value"=>"DATE(DC/date)","from_field_name"=>"DC:date"),
		"source.........." => array("feedmap_flag"=>FEEDMAP_FLAG_EXTMAP,"value"=>"DC/source","from_field_name"=>"DC:source"),
		"lang_code......." => array("feedmap_flag"=>FEEDMAP_FLAG_EXTMAP,"value"=>"DC/language","from_field_name"=>"DC:language"),
		"source_href....." => array("feedmap_flag"=>FEEDMAP_FLAG_EXTMAP,"value"=>"DC/relation","from_field_name"=>"DC:relation"),
		"place..........." => array("feedmap_flag"=>FEEDMAP_FLAG_EXTMAP,"value"=>"DC/coverage","from_field_name"=>"DC:coverage"),
		"headline........" => array("feedmap_flag"=>FEEDMAP_FLAG_EXTMAP,"value"=>"DC/title|ITEM/title","from_field_name"=>"DC:title"),
		"full_text......." => array("feedmap_flag"=>FEEDMAP_FLAG_EXTMAP,"value"=>"CONTENT","from_field_name"=>"Content"),
//		"status_code....." => array("feedmap_flag"=>FEEDMAP_FLAG_VALUE,"value"=>1,"from_field_name"=>"Approved"),
		"status_code....." => array("feedmap_flag"=>FEEDMAP_FLAG_VALUE,"value"=>2,"from_field_name"=>"Approved"),
		"hl_href........." => array("feedmap_flag"=>FEEDMAP_FLAG_EXTMAP,"value"=>"ITEM/link","from_field_name"=>"ITEM:link"),
		"expiry_date....." => array("feedmap_flag"=>FEEDMAP_FLAG_VALUE,"value"=>(time()+2000*24*60*60),"from_field_name"=>"Expiry Date")
	);

// return category_id with cat_group $cat_group and value $value
function GetCategoryIdFromValue($cat_group, $value) {
  global $db,$debugfeed;

  if (!$cat_group || !is_array($cat_group))
    return;

  $SQL = "SELECT id FROM constant WHERE group_id='$group_id' AND value='".addslashes($value)."'";
  if ($debugfeed >= 9) print("\n<br>$SQL");
  $db->query($SQL);
  if ($db->next_record()) {
    return unpack_id($db->f(id));
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
		if ($debugfeed >= 9) print("\n<br>$SQL");
		$db->query($SQL);
      } else {
        $l_cat_id = MapDefaultCategory($l_categs,$category[value], $category[catparent]);
        $SQL = "INSERT INTO ef_categories VALUES ('".$category[value]."','".$category[name]."',
                  '".q_pack_id($category[id])."','".$feed_id."','".q_pack_id($l_cat_id)."','0')";
		if ($debugfeed >= 9) print("\n<br>$SQL");
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
	  if ($debugfeed >= 9) print("\n<br>$SQL");
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
		if ($debugfeed >= 9) print("\n<br>$SQL");
       	$db->query($SQL);
	  }
    } else {
      $SQL = "INSERT INTO feedmap VALUES('$p_r_slice_id','$r_field_id','$p_l_slice_id','$r_field_id',
                   '".FEEDMAP_FLAG_EXTMAP ."','','".quote($fields[$r_field_id][name])."')";
	  if ($debugfeed >= 9) print("\n<br>$SQL");
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
		if ($debugfeed >= 9) print("\n<br>$SQL");
      	$db->query($SQL);
    }
  }
}

// Extract the content from where the parser put it, and return as a value array. 
function contentvalue ($item) {
		        $flag="";
		        if (isset($item[content][HTML])) {             // choose HTML content first
        			$flag = FLAG_HTML;
			        $cont_flag = HTML;
		        } else {                                       // otherwise PLAIN. Other formats are not supported,
			        $cont_flag= PLAIN;                           // but they can be added in future
		        }
				return array("value"=>$item[content][$cont_flag], "flag"=>$flag);
}

function map1field($value,$item) {
	global $debugfeed;
		  if ($debugfeed >= 9) print("\n<br>xmlclient:map1field:$value");
		  if (ereg("(.*)\|(.*)",$value,$vals)) {  // Process alternatives, first if non-blank else second
		 	$try1 = map1field($vals[1],$item);
			if ($try1[0][value]) { return $try1; }
			return map1field($vals[2],$item);
		  } elseif (ereg("^DATE\((.*)\)$",$value,$vals)) { // Postprocess to turn into unix
		  	$try1 = map1field($vals[1],$item);
			if (isset($try1) && is_array($try1))
				$try1[0][value] =  iso8601_to_unixstamp($try1[0][value]);
	  		return $try1;
		  } elseif (ereg("ITEM/(.*)",$value,$vals)) {
		    //TODO - could extend this to understand format of imported field, will be needed for 
			// example if dc:description can be html
		  	return array ( 0 => array ( value => $item[$vals[1]], flag => 0, format => 1 ));
		  } elseif (ereg("DC/(.*)",$value,$vals)) {
		    //TODO - could extend this to understand format of imported field, will be needed for 
			// example if dc:description can be html
		  	return array ( 0 => array ( value => $item[dc][$vals[1]], flag => 0, format => 1 ));
		  } elseif ($value == "CONTENT") {
	   // Note this code is repeated above in map1field
	   			return array (0 => contentvalue($item));
		  } else {
          	return $item[fields_content][$value];
		  }
}

// stores items to the table item
function updateItems($feed_id, &$feed, &$aa_rss, $l_slice_id, $r_slice_id, $l_slice_fields, &$ext_categs, &$l_categs) {
  global $db, $varset, $itemvarset, $default_rss_map, $debugfeed;
  if ($debugfeed >= 9) print("\n<br>updateItems");
  while (list($item_id,) = each($aa_rss[items])) {
    $db->query("SELECT id FROM item WHERE id='".q_pack_id($item_id)."'");    // Only store items that have an id which i
    if ($db->next_record()) {                                   // not already contained in the items table
		if ($debugfeed >= 4) print("\n<br>skipping duplicate: ".$aa_rss[items][$item_id][title]);
      continue;
    }
    $varset=new Cvarset;
    $itemvarset = new CVarset;

    $item = $aa_rss[items][$item_id];
	
	// A series of steps to make field specific edits
    // set fulltext field back from the content field, where it was put by APC for RSS compatability
     if ($fulltext_field_id = GetBaseFieldId($aa_rss[fields],"full_text")) {
	 		        $item[fields_content][$fulltext_field_id][0] = contentvalue($item);
	 }
	
  if ($feed[feed_type] == FEEDTYPE_APC) { // Use the APC specific fields from the item 
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
	  $status_code_id = GetBaseFieldId( $aa_rss[fields], "status_code" );
	  // set status_code - according to the settings of ef_categories table
	  // RSS feeds have approved set from default_rss_map
      $item[fields_content][$status_code_id][0][value] = $approved ? 1 : 2;
    } 
  } 

    // create $content4id from $item[fields_content]
	// note that each item in content4id is an array 
	if ($feed[feed_type] == FEEDTYPE_APC) {
	    list(,$map) = GetExternalMapping($l_slice_id,$r_slice_id);
	} else { // FEEDTYPE_RSS
		// TODO - allow RSS to use mapping
  		$map = $default_rss_map;
	}
    while (list($to_field_id,$v) = each($map)) {
      switch ($v[feedmap_flag]) {
        case FEEDMAP_FLAG_VALUE:
          $content4id[$to_field_id][0][value] = quote($v[value]);
          break;

        case FEEDMAP_FLAG_EXTMAP:
			$values = map1field($v[value],$item);
          	if (isset($values) && is_array($values)) {
			  	// quote all values
            	while (list($k,$v2) = each($values))
              		$values[$k][value] = quote($v2[value]);
				$content4id[$to_field_id] = $values;
			}
          break;
      } //switch
    } //while each($map)
	if ($debugfeed >= 3) print("\n<br>      " . $content4id['headline........'][0][value]);
	if ($debugfeed >= 9) { print("\n<br>updateItems:content4id="); print_r($content4id); }

   StoreItem( $item_id, $l_slice_id, $content4id, $l_slice_fields, true, true, false );
                                                    # insert, invalidatecache, not feed
    // set the item to be recevied from remote node (todo - set via content4id)
	$SQL = "UPDATE item SET externally_fed='".quote($feed[name])."' WHERE id='".q_pack_id($item_id)."'";
	
	if ($debugfeed >= 9) print("\n<br>$SQL");
    $db->query($SQL);
    } // while $aa_rss[items]

}

function onefeed($feed_id,$feed) {
  global $debugfeed, $db; 
  $l_slice_id = unpack_id($feed[slice_id]);
  $r_slice_id = unpack_id($feed[remote_slice_id]);
  set_time_limit(240); // Allow 4 minutes per feed
  if ($feed[feed_type] == FEEDTYPE_APC) {
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
  }

  if ($feed[feed_type] == FEEDTYPE_APC) {
	if ($debugfeed >= 1) print("\n<br>APC Feed: $feed[name] : $feed[remote_slice_name]");
	$xml_data = xml_fetch( $feed[server_url], ORG_NAME, $feed[password],
            $feed[user_id], $r_slice_id, $feed[newest_item], implode(" ",$cat_ids));
  } else {   // not FEEDTYPE_APC
	if ($debugfeed >= 1) print("\n<br>RSS Feed: $feed[name]");
    $xml_data = http_fetch($feed[server_url]);
  }
  if (!$xml_data) {
  		if ($debugfeed >= 1) print("\n<br>$feed[name]: no data returned");
		return;
  }

  if (substr($xml_data,0,1) != "<") {
    writeLog("CSN","Feeding mode: $xml_data");
  	if ($debugfeed >= 1) print("\n<br>$feed[name]:bad data returned: $xml_data");
    return;
  }

  if (!( $aa_rss = aa_rss_parse( $xml_data ))) {
    writeLog("CSN","Feeding mode: Unable to parse XML data");
  	if ($debugfeed >= 1) print("\n<br>$feed[name]:unparsable: $xml_data");
    continue;
  }

  if ($debugfeed >= 9) { print("\n<br>onefeed: aa_rss="); print_r($aa_rss); }

  $l_categs = GetGroupConstants( $l_slice_id );        // get all categories belong to slice
  if ($feed[feed_type] == FEEDTYPE_APC) {
	updateCategories($feed_id, $l_categs, $ext_categs,
                       $aa_rss[channels][$r_slice_id][categories],$aa_rss[categories]);
  }
  
  list($l_slice_fields,) = GetSliceFields($l_slice_id);
  if ($feed[feed_type] == FEEDTYPE_APC) {
	updateFieldsMapping($feed_id, $l_slice_fields, $l_slice_id, $r_slice_id,
                       $aa_rss[channels][$r_slice_id][fields],$aa_rss[fields]);
  }
  
  // update items
  if (isset($aa_rss[items])) {
    if ($debugfeed >= 9) print("\n<br>onefeed: there are some items to update");
    updateItems($feed_id, $feed, $aa_rss, $l_slice_id, $r_slice_id, $l_slice_fields, $ext_categs, $l_categs,$feed_type);
	if ($feed[feed_type] == FEEDTYPE_APC) {
	    //update the newest item
		$SQL = "UPDATE external_feeds SET newest_item='".$aa_rss[channels][$r_slice_id][timestamp]."' WHERE feed_id='$feed_id'";
	    if ($debugfeed >= 9) print("\n<br>$SQL");
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
	if ($debugfeed >= 9) print("\n<br>$SQL");
	$db->query($SQL);
	
	$feeds="";
	while ($db->next_record()) {
		$fi = $db->f(feed_id);
   		$feeds[$fi] = $db->Record;
   		$feeds[$fi][field_type] = FEEDTYPE_APC;
	}
	if ($debugfeed >= 9) { print("\n<br>feeds="); print_r($feeds); }
	return $feeds;
}

function rssfeeds() {
	global $db, $debugfeed;
	
	$SQL="SELECT feed_id, server_url, name, slice_id FROM rssfeeds";
	if ($debugfeed >= 9) print("\n<br>$SQL");
	$db->query($SQL);

	$rssfeeds="";
	while ($db->next_record()) {
   		$fi = $db->f(feed_id);
   		$rssfeeds[$fi] = $db->Record;
   		$rssfeeds[$fi][feed_type] = FEEDTYPE_RSS;
   		$rssfeeds[$fi][remote_slice_id] = q_pack_id(attr2id($rssfeeds[$fi][server_url]));
	}
	if ($debugfeed >= 9) { print("\n<br>rssfeeds="); print_r($rssfeeds); }
	return $rssfeeds;
}

if ($debugfeed >= 9) print("\n<br>XMLCLIENT STARTING");

$db = new DB_AA;

if ($feed_id) {  // do one APC feed
	$feeds = apcfeeds();
	onefeed($feed_id,$feeds[$feed_id]);
} elseif ($rssfeed_id) { // do one RSS feed
	$rssfeeds = rssfeeds();
	onefeed($rssfeed_id, $rssfeeds[$rssfeed_id]);   // Not sure if its safe for feed_id to be same as for APC feeds
} else {
	$apcfeeds = apcfeeds();
	while (list ($feed_id,$feed) = each($apcfeeds)) {
		onefeed($feed_id,$feed);
	}
	$rssfeeds = rssfeeds();
	while (list ($feed_id,$feed) = each($rssfeeds)) {
		onefeed($feed_id,$feed);
	}
}


?>
