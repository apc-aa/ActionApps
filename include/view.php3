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

define("VIEW_PHP3_INC",1);

class itemview{
  var $db;
  var $sort_order;
  var $ids;                      # ids to show
  var $from_record;              # from which index begin showing items
  var $num_records;              # number of shown records 
  var $slice_info;               # record from slice database for current slice
  var $fields;                   # array of fields used in this slice
  var $aliases;                  # array of alias definitions
  var $clean_url;                # url of slice page with session id and encap..

  function itemview( $db, $slice_info, $fields, $aliases, $ids, $from, $number, $clean_url){                   #constructor 
    $this->db = $db;
    $this->slice_info = $slice_info;  # $slice_info is array with this fields:
                                      #      - print_view() function:
                                      #   grab_len, compact_top, category_sort,
                                      #   category_format, category_top,
                                      #   category_bottom, even_odd_differ,
                                      #   even_row_format, odd_row_format,
                                      #   compact_remove, compact_bottom,
                                      #      - print_item() function:
                                      #   fulltext_format, fulltext_remove,
                                      #   fulltext_format_top, 
                                      #   fulltext_format_bottom, 
    $this->aliases = $aliases;
    $this->fields = $fields;
    $this->ids = $ids;
    $this->from_record = $from;
    $this->num_records = $number;
    $this->clean_url = $clean_url;
  }  
    
    #view_type used internaly for fulltext view  
  function print_view($view_type="") {  
    $db = $this->db;
    if( !( isset($this->ids) AND is_array($this->ids) ))
      return;


    $sel_in = "(";
    $delim = "";
    for( $i=$this->from_record; $i<$this->from_record+$this->num_records; $i++){
      if( $this->ids[$i] !="" ) {
        $sel_in .= $delim. "'".q_pack_id($this->ids[$i])."'";
        $delim = ",";
      }  
    }  
    $sel_in .= ( ($delim=="") ? "'')" : ")"); 

    
       # get content from item table
    $SQL = "SELECT * FROM item WHERE id IN $sel_in";
    $db->query($SQL);
    while( $db->next_record() ) {
      reset( $db->Record );
      while( list( $key, $val ) = each( $db->Record )) {
        if( EReg("^[0-9]*$", $key))
          continue;
        $content[unpack_id($db->f(id))][$key][] = array("value" => $val);
      }  
    }  

       # get content from content table
       # feeding - don't worry about it - when fed item is updated, informations
       # in content table is updated too

/* Constants are directly in fields - we don't need this join
    $db->query("SELECT * FROM content LEFT JOIN constant 
                    ON content.text=constant.value
                    WHERE item_id IN $sel_in");  # usable just for constants
    while( $db->next_record() ) {
      $content[unpack_id($db->f(item_id))][$db->f(field_id)][] = 
        array( "value"=>( ($db->f(text)=="") ? $db->f(number) : $db->f(text)),
               "name"=> $db->f(name) );
    }
*/    

    $db->query("SELECT * FROM content 
                 WHERE item_id IN $sel_in");  # usable just for constants
    while( $db->next_record() ) {
      $content[unpack_id($db->f(item_id))][$db->f(field_id)][] = 
        array( "value"=>( ($db->f(text)=="") ? $db->f(number) : $db->f(text)),
               "flag"=> $db->f(flag) );
    }

    $CurItem = new item("", "", $this->aliases, $this->clean_url, "",
                        $this->slice_info[grab_len]);   # just prepare

//p_arr_m($content);

    switch ( $view_type ) {
      case "fulltext":  
        $iid = $this->ids[0];      # unpacked item id
        $CurItem->columns = $content[$iid];   # set right content for aliases
  
        # print item
        $CurItem->setformat( $this->slice_info[fulltext_format],
                             $this->slice_info[fulltext_remove],
                             $this->slice_info[fulltext_format_top],
                             $this->slice_info[fulltext_format_bottom]);
        $CurItem->print_item();
        break;
      case "itemlist":          # multiple items as fulltext one after one
        for( $i=0; $i<$this->num_records; $i++ ) {
          $iid = $this->ids[$this->from_record+$i];
          if( !$iid )
            continue;                                     # iid = unpacked item id 

          $CurItem->columns = $content[$iid];   # set right content for aliases
          
            # print item
          $CurItem->setformat( $this->slice_info[fulltext_format],
                               $this->slice_info[fulltext_remove],
                               $this->slice_info[fulltext_format_top],
                               $this->slice_info[fulltext_format_bottom]);
          $CurItem->print_item();
        }
        break;
      default:                         # compact view
        $oldcat = "_No CaTeg";
        echo $this->slice_info[compact_top];
        for( $i=0; $i<$this->num_records; $i++ ) {
          $iid = $this->ids[$this->from_record+$i];
          if( !$iid )
            continue;                                     # iid = unpacked item id 
          $catname = $content[$iid]["category........"][0][name];
              
          $CurItem->columns = $content[$iid];   # set right content for aliases
          
            # print category name if needed
          if($this->slice_info[category_sort] AND ($catname != $oldcat)) {
            $oldcat = $catname;
            $CurItem->setformat( $this->slice_info[category_format], "",
                                 $this->slice_info[category_top],
                                 $this->slice_info[category_bottom] );
            $CurItem->print_item();
          }  
          
            # print item
          $CurItem->setformat( 
             (!($i%2) AND $this->slice_info[even_odd_differ]) ?
             $this->slice_info[even_row_format] : $this->slice_info[odd_row_format],
             $this->slice_info[compact_remove], "", "");

          $CurItem->print_item();
        }
        echo $this->slice_info[compact_bottom];
    }  
  }
    
  function print_item() {
    $this->print_view("fulltext");
  }

  function print_itemlist() {
    $this->print_view("itemlist");
  }
  
};


/*
$Log$
Revision 1.4  2000/12/23 19:56:50  honzam
Multiple fulltext item view on one page, bugfixes from merge v1.2.3 to v1.5.2

Revision 1.3  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.2  2000/08/03 12:39:35  honzam
Bug in sort order fixed

Revision 1.1.1.1  2000/06/21 18:40:50  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:27  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.2  2000/06/12 21:41:24  madebeer
removed whitespace from config-ecn.inc
added $Id $Log and $Copyright to some stray files

Revision 1.1  2000/06/09 15:14:12  honzama
New configurable admin interface

Revision 1.12  2000/04/24 16:50:34  honzama
New usermanagement interface.

Revision 1.11  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>
