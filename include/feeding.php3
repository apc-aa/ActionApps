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

  list($fields_to,) = GetSliceFields($destination);

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
             AND flag & ". REL_FLAG_FEED;   //  feed
  $db->query($SQL);
  if( $db->next_record() )    // the item is already fed
    return false;           // maybe we can update the item somehow

  $map = GetFieldMap($slice_id, $destination, $fields);

#echo "map:<br>";
#p_arr_m($map);
  
  if( !$content )
    $content = GetItemContent($item_id);
    
  $content4id = $content[$item_id];   # shortcut
    
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

  # prepare new4id array before call StoreItem function
  while(list($key,$val) = each($content4id)) {
    $newfld = $map[$key];
    if( !$newfld OR                              # feed only mapped fields
        ($fields[$key][feed]==STATE_UNFEEDABLE)) # and fields with perms to feed
      continue;  
      
    $new4id[$newfld] = $val;

      # update flags
    $new4id[$newfld][0][flag] |= FLAG_FEED;      # mark as fed
    if( ($fields[$key][feed]==STATE_FEEDNOCHANGE ))
      $new4id[$newfld][0][flag] |= FLAG_FREEZE;  # don't allow to change 
    
      # category mapping    
    if( $newfld == $catfieldid ) {
      if( (string)$tocategory != "0" )    # if 0 - don't change category
        $new4id[$newfld][0][value] = $destinationcat;
    }
    $new4id[$newfld][0][value]=quote($new4id[$newfld][0][value]);
  }  

    # fill required fields if not set
  $new4id["status_code....."][0][value] = ($approved=='y' ? 1 : 2);
  if( !$new4id["post_date......."] )
    $new4id["post_date......."] = $content4id["post_date......."];
  if( !$new4id["publish_date...."] ) 
    $new4id["publish_date...."] = $content4id["publish_date...."];
  if( !$new4id["expiry_date....."] )
    $new4id["expiry_date....."] = $content4id["expiry_date....."];
  if( !$new4id["highlight......."] )
    $new4id["highlight......."] = $content4id["highlight......."];
  if( !$new4id["posted_by......."] ) 
    $new4id["posted_by......."] = $content4id["posted_by......."];
  if( !$new4id["edited_by......."] )
    $new4id["edited_by......."] = $content4id["edited_by......."];
  if( !$new4id["last_edit......."] ) 
    $new4id["last_edit......."] = $content4id["last_edit......."];

  StoreItem( $id, $destination, $new4id, $fields, true, true, false );
                                        # insert, invalidatecache, not feed
  
  # update relation table - stores where is what fed
  $SQL = "INSERT INTO relation ( destination_id, source_id,   flag )
               VALUES ( '$p_id', '$p_item_id', '". REL_FLAG_FEED ."' )";

  $db->query($SQL);

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
      $SQL = "SELECT feeds.to_id, feeds.category_id, feeds.all_categories,
                     feeds.to_approved, feeds.to_category_id 
                FROM feeds, slice LEFT JOIN feedperms ON feedperms.from_id=feeds.from_id
              WHERE feeds.from_id = slice.id
                AND feeds.from_id='". q_pack_id($akt) ."'
                AND (slice.export_to_all=1 
                 OR  feedperms.to_id = feeds.to_id)";  # check perms to feed, too
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
  $content = GetItemContent($item_id);
  $content4id = $content[$item_id];   # shortcut

  # if not approved - exit
  if( $content4id["status_code....."][0][value] != '1' )
    return false;

  # get this item category_id
  $cat_group = GetCategoryGroup($slice_id);
  $cat_field = GetCategoryFieldId( $fields );

  if($cat_group AND $cat_field) {
    $SQL = "SELECT id FROM constant 
             WHERE group_id = '$cat_group' 
               AND value = '". $content4id[$cat_field][0][value] ."'";
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

# completely deletes item content from database with all subsequencies
# but not deleted item from item table !!!
function DeleteItem($db, $id) {
  $p_itm_id = q_pack_id($id);

  # delete content
  $SQL = "DELETE LOW_PRIORITY FROM content WHERE item_id='$p_itm_id'";
  $db->query($SQL);

  # delete offline
  $SQL = "DELETE LOW_PRIORITY FROM offline WHERE id='$p_itm_id'";
  $db->query($SQL);

  # delete feeding relation
  $SQL = "DELETE LOW_PRIORITY FROM relation WHERE (source_id='$p_itm_id'
                                               OR destination_id='$p_itm_id')
                                              AND flag & ".REL_FLAG_FEED;
  $db->query($SQL);
}  

/*
$Log$
Revision 1.9  2001/04/17 21:32:08  honzam
New conditional alias. Fixed bug of not displayed top/bottom HTML code in fulltext and category

Revision 1.8  2001/03/20 16:10:37  honzam
Standardized content management for items - filler, itemedit, offline, feeding
Better feeding support

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