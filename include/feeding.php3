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

function FeedItemTo($item_id, $destination, $approved, $tocategory=0) {
  global $ITEM_FIELDS_TEXT, $ITEM_FIELDS_NUM, $db;
//huh("FeedItemTo($item_id, $destination, $approved, $tocategory =0)");
   

kouknout, zda uz tam neni feedly - pak jen update


--    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
--    $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values


  $varset = new Cvarset;
  $varset->addArray( $ITEM_FIELDS_TEXT, $ITEM_FIELDS_NUM );
  $SQL = "SELECT items.* FROM items WHERE id='". q_pack_id($item_id) ."'";
  $db->query($SQL);
  if( $db->next_record() )
    $varset->setFromArray($db->Record);
   else
    return false;
  
  $p_slice_id = $varset->value(slice_id);
  $slice_id = unpack_id($p_slice_id);

  $SQL = "SELECT id, slice_id, master_id FROM items 
           WHERE master_id='". quote($varset->value(master_id)). "' AND slice_id='". q_pack_id($slice). "'";
  $SQL = "SELECT id, slice_id, master_id FROM items 
           WHERE master_id='". quote($varset->value(master_id)). "' AND slice_id='". q_pack_id($destination). "'";
  $db->query($SQL);
  if( !$db->next_record() ) { // this condition is enough - we can import
//huh("Pass feeding condition => feeding<br>");
    $p_new_id = pack_id(new_id()); 
    $varset->set("id", $p_new_id );
    $varset->set("slice_id", pack_id($destination));  //this is "text" type of varset variable - quote is done in makeInsert()
   // master_id is the same
    $varset->set("status_code", ($approved ? 1 : 2));    // to active bin/holding bin
    if( (string)$tocategory != "0" )
      $varset->set("category_id", pack_id($tocategory) );    // to category setted in filters
    $insertSQL = "INSERT INTO items" . $varset->makeINSERT();
//      $db->query("INSERT INTO fulltexts (ft_id, full_text) VALUES ('$q_p_new_id', '')");  // added to keep 1:1 relation between items and fulltexts - (why???)
    if (!$db->query($insertSQL))   # not necessary - we have set the halt_on_error
      return false;
    return true;  
  }
  else return false;
}


# Find all slices, into which we should propagate the item
function GetSlicesIntoExportItem($slice_id, $from_category_id) {
  global $db;
  
  $slices[$slice_id] = array( approved=>"y",  # two purpose array - 1) set of feeding slices
                                              #                     2) hold if import to approved
                              category=>"0"); # stores categories we should import to
  reset($slices);
  while( $akt=key($slices) ) {
//    huh("<br>slices:<br>");
//    p_arr($slices);
//    huh("<br>--$akt---------<br>");
    
    if ( $slices[$akt][approved] == "y" ) {   // if yes then continue feeding down
      $SQL = "SELECT to_id, category_id, all_categories, to_approved, to_category_id 
                FROM feeds 
              WHERE from_id='". q_pack_id($akt) ."'";
      $db->query($SQL);
//      huh("akt == y<br>");
      while($db->next_record()) {
        $to_id = unpack_id($db->f(to_id));
//        huh("try from: $akt to: $to_id<br>");
        if( $slices[$to_id][approved] != "y" )   // condition is necessary for multi feeding to this slice
          if( $from_category_id == $db->f(category_id)) OR $db->f(all_categories) ) {
//            huh("add to slices: $to_id<br>");
            $slices[$to_id][approved] = ($db->f(to_approved) ? "y" : "n");  // add new feed slice
            $slices[$to_id][category] = unpack_id($db->f(to_category_id));
          }  
      }      
    }  
    next($slices);
  }
  return $slices;
}

# Feeds item to all apropriate slices
# item_id is unpacked id of feeded item
function FeedItem($item_id, $fields) {     //TODO  - category problem when you feed down and down, the category can change
  global $ITEM_FIELDS_TEXT, $ITEM_FIELDS_NUM, $db;    //      - it is no so big problem (19.11.99) 

  # get item field definition
nee - parametr  $fields = GetTable2Array("SELECT * FROM field WHERE slice_id='$p_slice_id'", $db);
nacist obsah feedovaneho clanku

neni-li approved - exit








   




  // select slices where item should be exported
  $varset = new Cvarset;
  $varset->addArray( $ITEM_FIELDS_TEXT, $ITEM_FIELDS_NUM );
  $SQL = "SELECT items.* FROM items WHERE id='". q_pack_id($item_id) ."'";
  $db->query($SQL);
  if( $db->next_record() ){
    $varset->setFromArray($db->Record);
  }
  
  GetSlicesIntoExportItem($slice_id, $from_category_id) {

# now we have in $slices array set of slices to export the item 
# with destination category and state (approved or not)
//  huh("xxxxxxxxxxx All feed slices<br>");
//  p_arr($slices);

  // do not import the item twice
  reset( $slices );
  while( list($slice,$atribs) = each($slices) )
    FeedItemTo($item_id, $slice, $atribs[approved]=="y", $atribs[category]);
}
/*
$Log$
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