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
  var $from_record;
  var $num_records;
  var $db;
  var $slice_id;
  var $highlight;
  var $category_id;
  var $category_sort;
  var $category_format;
  var $sort_order;
  var $compact_top;
  var $compact_bottom;
  var $fulltext_format;
  var $odd_row_format;
  var $even_row_format;
  var $grab_len;
  var $compact_remove;
  var $fulltext_remove;
  var $slice_url;

  function itemview( $db, $params){                   #constructor 
    $this->db = $db;
    $this->from_record = $params["from_record"];
    $this->num_records = $params["num_records"];
    $this->slice_id = $params["slice_id"];
    $this->highlight = $params["highlight"];
    $this->category_id = $params["category_id"];
    $this->category_sort = $params["category_sort"];
    $this->category_format = $params["category_format"];
    $this->sort_order = $params["sort_order"];
    $this->compact_top = $params["compact_top"];
    $this->compact_bottom = $params["compact_bottom"];
    $this->fulltext_format = $params["fulltext_format"];
    $this->odd_row_format = $params["odd_row_format"];
    $this->even_row_format = $params["even_row_format"];
    $this->grab_len = $params["grab_len"];
    $this->compact_remove = $params["compact_remove"];
    $this->fulltext_remove = $params["fulltext_remove"];
    $this->slice_url = $params["slice_url"];
  }  
    
  function print_view() {
    $where = MakeWhere(q_pack_id($this->slice_id), $this->category_id, $this->highlight);
    $SQL = "SELECT items.*, fulltexts.full_text, categories.name as category FROM items, fulltexts LEFT JOIN categories ON categories.id=items.category_id".
           " WHERE $where AND fulltexts.ft_id=items.master_id";

    if( $category_sort )
      $SQL .= " ORDER BY category_id, publish_date $this->sort_order";
     else 
      $SQL .= " ORDER BY publish_date $this->sort_order";
  
    if( OPTIMIZE_FOR_MYSQL )                             // if no mySQL - go to item no (mySQL use LIMIT)
      $SQL .= " LIMIT ". $this->from_record .", ". $this->num_records;
      
    $this->db->query($SQL);
    
    if ($this->db->nf()>0) {    
      echo $this->compact_top;
      
      $CurItem = new item($foo,false,1,$this->slice_url, $this->fulltext_format, $this->odd_row_format, $this->even_row_format, $this->category_format, $this->grab_len, 
                          $this->compact_remove, $this->fulltext_remove);
      $oldcat = "_No CaTeg";
  
      if( !OPTIMIZE_FOR_MYSQL )                             // if no mySQL - go to item no (mySQL use LIMIT)
        $this->db->seek(max(0,$this->from_record));
        
      while($this->db->next_record()){ 
        $CurItem->odd = $i%2;
        $CurItem->columns = $this->db->Record; # active row 
        $catname = $this->db->f("category");
//        SubstFulltext(&$CurItem->columns);   //changes $db2 !!
        if($this->category_sort AND ($catname != $oldcat)) {
          $oldcat = $catname;
          $CurItem->print_category();
        }  
        $CurItem->print_item();
        if(++$i >= $this->num_records) break; 
      }
      echo $this->compact_bottom;
    }  
    else 
      echo "<div>". L_NO_ITEM ."</div>";
  }
};

/*
$Log$
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
