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

#
# Functions for feeding
#

# Find fields mapping. If not found apropriate fields, map is blank
function GetFieldMap($slice_id, $destination, $fields_from) {
  global $db;
  
  $p_destination = q_pack_id($destination);
  $p_slice_id = q_pack_id($slice_id);

  list($fields_to,) = GetSliceFields($p_destination);

  $SQL = "SELECT from_field_id, to_field_id from feedmap WHERE from_slice_id = '$p_slice_id'
                                  AND to_slice_id = '$p_destination'";
  $db->query($SQL);
  while( $db->next_record() )
    $map[$db->f(from_field_id)] = $db->f(to_field_id);
  
  if( isset($fields_from) AND is_array($fields_from) ) {
    reset( $fields_from ) ;
    while( list( $k, $v ) = each( $fields_from ) ) {
      if( $map[$k] )
        $pair[$k] = $map[$k];               # set if mapped
       else                                 # if not mapped - store in the same       
        $pair[$k] = ( $fields_to[$k] ? $k : "" );  # if not exist - leave blank
    }
  }
  return $pair;  
}

function FeedItemTo($item_id, $destination, $fields, $approved, $tocategory=0, 
                    $content="") {
  global $db, $slice_id, $varset, $itemvarset;
#echo "  global $slice_id<br>";
#huh("FeedItemTo($item_id, $destination, $fields, $approved, $tocategory =0, $content)");

  if( $destination == $slice_id )  # don't feed into the same slice
    return false;
       
  $p_item_id = q_pack_id($item_id);
  $p_destination = q_pack_id($destination);

  # is item already fed?
  $SQL = "SELECT destination_id FROM relation, item
           WHERE relation.destination_id = item.id
             AND item.slice_id = '$p_destination'
             AND source_id='$p_item_id'
             AND flag & 1";   // 1. bit - feed
  $db->query($SQL);
  if( $db->next_record() )    // the item is already fed
    return false;           // maybe we can update the item somehow

  $map = GetFieldMap($slice_id, $destination, $fields);

  #echo "map:<br>";
  #p_arr_m($map);
  
  if( !$content )
    $content = GetItemContent("('".q_pack_id($item_id)."')");

  $catfieldid = GetCategoryFieldId( $fields );

  if( $catfieldid AND ( (string)$tocategory != "0" ) ) {
    $SQL = "SELECT value FROM constant WHERE id='".q_pack_id($tocategory)."'";
    $db->query($SQL);
    if( $db->next_record() )
      $destinationcat = $db->f(value);
  }    

  #huh( "Catfield: $catfieldid - $destinationcat");
      
  $varset = new Cvarset;
  $itemvarset = new Cvarset;  // must be defined before insert_fnc_qte
  $id = new_id();
  $p_id = q_pack_id($id);

  reset($content);
  while(list($key,$val) = each($content[$item_id])) {
    # add to content table or prepare itemvarset for addition in item table
    
    if( $map[$key] ) {
      if( $map[$key] == $catfieldid ) {     # category mapping
        if( (string)$tocategory != "0" )    # if 0 - don't change category
          $val[0][value] = $destinationcat;
      }    
      insert_fnc_qte($id, $fields[$map[$key]], quote($val[0][value]), "", true); 
    }  
  }                                                          
  
  # store prepared data to item table 
  $itemvarset->add("id", "unpacked", $id);
  $itemvarset->set("slice_id", $destination, "unpacked");
  $itemvarset->set("status_code", ($approved=='y' ? 1 : 2), "quoted");
  $itemvarset->ifnoset("post_date", $content[$item_id][post_date][0][value], "quoted");
  $itemvarset->ifnoset("publish_date", $content[$item_id][publish_date][0][value], "quoted");
  $itemvarset->ifnoset("expiry_date", $content[$item_id][expiry_date][0][value], "quoted");
  $itemvarset->ifnoset("highlight", $content[$item_id][highlight][0][value], "quoted");
  $itemvarset->ifnoset("posted_by", $content[$item_id][posted_by][0][value], "quoted");
  $itemvarset->ifnoset("edited_by", $content[$item_id][edited_by][0][value], "quoted");
  $itemvarset->ifnoset("last_edit", $content[$item_id][last_edit][0][value], "quoted");

  $SQL = "INSERT INTO item " . $itemvarset->makeINSERT();
  $db->query($SQL);
  
  # update relation table - stores where is what fed
  $SQL = "INSERT INTO relation SET destination_id='$p_id',
                                   source_id='$p_item_id',
                                   flag = '1'";            // 1. bit - feed
  $db->query($SQL);

  $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
  $cache->invalidateFor("slice_id=$destination");  # invalidate cached values

  return true;
  
}


# Find all slices, into which we should propagate the item
function GetSlicesIntoExportItem($slice_id, $from_category_id) {
  global $db;
  
  if( $from_category_id )
    $p_from_cat_id = q_pack_id($from_category_id);

  $slices[$slice_id] = array( approved=>"y",  # two purpose array - 1) set of feeding slices
                                              #                     2) hold if import to approved
                              category=>"0"); # stores categories we should import to
  reset($slices);
  while( $akt=key($slices) ) { 
  #    huh("<br>slices:<br>");
  #    p_arr($slices);
  #    huh("<br>--$akt-----From: $from_category_id----<br>");
    
    if ( $slices[$akt][approved] == "y" ) {   // if yes then continue feeding down
      $SQL = "SELECT to_id, category_id, all_categories, to_approved, to_category_id 
                FROM feeds 
              WHERE from_id='". q_pack_id($akt) ."'";
      $db->query($SQL);
  #    huh("akt == y<br>");
      while($db->next_record()) {
        $to_id = unpack_id($db->f(to_id));
  #      huh("try from: $akt to: $to_id<br>");
        if( $slices[$to_id][approved] != "y" ) { // condition is necessary for multi feeding to this slice
          if( ($p_from_cat_id == $db->f(category_id)) OR $db->f(all_categories) ) {
  #          huh("add to slices: $to_id<br>");
            $slices[$to_id][approved] = ($db->f(to_approved) ? "y" : "n");  // add new feed slice
            $slices[$to_id][category] = unpack_id($db->f(to_category_id));
          }  
        }  
      }      
    }  
    next($slices);
  }
  return $slices;
}

# Feeds item to all apropriate slices
# item_id is unpacked id of feeded item
function FeedItem($item_id, $fields) {   
  global $db, $slice_id;    

  # get item field definition
  $content = GetItemContent("('".q_pack_id($item_id)."')");

  # if not approved - exit
  if( $content[$item_id][status_code][0][value] != '1' )
    return false;

  # get this item category_id
  $cat_group = GetCategoryGroup($slice_id);
  $cat_field = GetCategoryFieldId( $fields );

  if($cat_group AND $cat_field) {
    $SQL = "SELECT id FROM constant 
             WHERE group_id = '$cat_group' 
               AND value = '". $content[$item_id][$cat_field][0][value] ."'";
    $db->query($SQL);
    if( $db->next_record() )
      $cat_id = unpack_id($db->f(id));
  }    

  # select slices where item should be exported
  $slices = GetSlicesIntoExportItem($slice_id, $cat_id);

  # now we have in $slices array set of slices to export the item 
  # with destination category and state (approved or not) 
  #    huh("xxxxxxxxxxx All feed slices<br>");
  #    p_arr($slices);

  // do not import the item twice
  reset( $slices );
  while( list($slice,$atribs) = each($slices) )
    FeedItemTo($item_id, $slice, $fields, $atribs[approved], $atribs[category], $content);
}
/*
$Log$
Revision 1.7  2001/03/07 14:34:01  honzam
fixed bug with radiobuttons dispaly

Revision 1.6  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...

Revision 1.5  2001/01/22 17:32:48  honzam
pagecache, logs, bugfixes (see CHANGES from v1.5.2 to v1.5.3)

Revision 1.2  2000/07/07 21:28:17  honzam
Both manual and automatical feeding bug fixed

Revision 1.1.1.1  2000/06/21 18:40:38  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:23  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.4  2000/06/12 21:41:24  madebeer
removed whitespace from config-ecn.inc
added $Id $Log and $Copyright to some stray files

*/
?>