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

#expected  slice_id 
#expected  encap     // determines wheather this file is ssi included or called directly  
#optionaly sh_itm    // if specified - selected item is shown in full text
#optionaly srch      // true if this script have to show search results
#optionaly highlight // when true, shows only highlighted items in compact view
#optionaly bigsrch   // true, if this script have to show big search form
#optionaly cat_id    // select only items in category with id cat_id
#optionaly cat_name  // select only items in category with name cat_name
#optionaly inc       // for dispalying another file instead of slice data 
                     // (like static html file - inc=/contact.html)

$encap = ( ($encap=="false") ? false : true );

require "./include/config.php3";
//require $GLOBALS[AA_INC_PATH]."en_common_lang.php3";  // we need datetime2date function
require $GLOBALS[AA_INC_PATH]."easy_scroller.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";
require $GLOBALS[AA_INC_PATH]."searchlib.php3";

if ($encap){require $GLOBALS[AA_INC_PATH]."locsessi.php3";}
else {require $GLOBALS[AA_INC_PATH]."locsess.php3";} 
page_open(array("sess" => "AA_SL_Session"));
$sess->register(r_highlight); 
$sess->register(r_category); 
$sess->register(r_unpacked_where); 

//-----------------------------Functions definition--------------------------------------------------- 

function Page_HTML_Begin($cp, $title="") {  ?>
  <!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 3.2 Final//EN">
  <HTML>
  <HEAD>
	  <TITLE><?php echo $title ?></TITLE>
    <LINK rel=StyleSheet href="<?php echo ADM_SLICE_CSS ?>" type="text/css" title="SliceCS">
  <?php  
  if ($cp) 
    echo '<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset='. $cp. '">';
  ?>
  </HEAD>
  <BODY>
<?php        
}

# print closing HTML tags for page
function Page_HTML_End(){ ?>
  </BODY>
  </HTML><?php
}

function GetCategories($db,$p_slice_id){
 $SQL= " SELECT name, id FROM categories, catbinds WHERE categories.id = catbinds.category_id AND catbinds.slice_id='".$p_slice_id."'";
 $db->query($SQL);
 while ($db->next_record()){
   $unpacked=unpack_id($db->f("id"));  
   $arr[$unpacked]=$db->f("name");  
 }
 return $arr;  
} 
 
function pCatSelector($sess_name,$sess_id,$url,$cats,$selected,$sli_id=0,$encaps=true){
 if (sizeof($cats)>0)
 {   
   echo "<form action=$url method=get>";
   echo "<input type=hidden name=$sess_name value=$sess_id>";
   if( !$encaps )    // not encapsulated - need to send slice_id
   { echo "<input type=hidden name=slice_id value=$sli_id>";
     echo "<input type=hidden name=encap value=".($encaps ? "true":"false").">";
   }
   echo L_SELECT_CATEGORY . "<select name=cat_id>";
   $seloption=(($selected=="")?"selected":"");
   echo '<option value="all" $seloption>'.L_ALLCTGS.'</option>';
   while (list($id,$name)= each($cats)) {
     $seloption=(($selected==$id)?"selected":"");
     echo "<option value=$id $seloption>".htmlspecialchars($name)."</option>";  
   }
   echo "<input type=hidden name=scr_".$scr_name."_Go value=1>";
   echo "<input type=submit name=Go value=Go>";
   echo "</select>"; 
   echo "</form>";  
 }
}    

function SubstFulltext($arr) {
  global $db2;
  if( $arr[id] == $arr[master_id] )
    return;
  $SQL = "SELECT full_text FROM fulltexts WHERE ft_id = '". $arr[master_id]. "'";
  $db2->query($SQL);
  if( $db2->next_record() )
    $arr[full_text] = $db2->f(full_text); 
  return;
}    

function CompactView($where, $catsel=false) {
  global $scr, $db, $sess, $slice_id, $encap, $cur_cats, $highlight,
         $category_sort, $category_format, $sort_order,
         $compact_top, $compact_bottom, $fulltext_format, $odd_row_format, 
         $even_row_format, $grab_len, $compact_remove, $fulltext_remove,
         $debugtimes;

// the complexity of sql query significantly changes the time of execution
// test: 
//  select just from items:                                                      0.3 s
//  select items left join categories order by category_id                       0.3 s
//  select items left join categories order by categories.name                   0.9 s
//  select items,fulltext left join categories order by category_id              0.4 s
//  select items,fulltext left join categories order by category_id bez limit    0.9 s

// -----------------------------------------------------------------
// Test of OPTIMIZE_FOR_MYSQL
// There is no difference between OPTIMIZE_FOR_MYSQL and not OPTIMIZE_FOR_MYSQL set in
// execution time (both 0.4 s). That's because even we use LIMIT we have to count number of entries
// for scroller (we do two queries for OPTIMIZE_FOR_MYSQL). The advantage is, that second query
// is after items are dislayed, so user see what he want to see - items.
//$debugtimes[]="> ".microtime();

  $SQL = "SELECT items.*, fulltexts.full_text, categories.name as category FROM items, fulltexts LEFT JOIN categories ON categories.id=items.category_id".
         " WHERE $where AND fulltexts.ft_id=items.master_id";
  // $SQLcount used in OPTIMIZE_FOR_MYSQL for counting of items
  $SQLcount = "SELECT count(*) as numrows FROM items, fulltexts LEFT JOIN categories ON categories.id=items.category_id WHERE $where AND fulltexts.ft_id=items.master_id";
  if( $category_sort )
    $SQL .= " ORDER BY category_id, publish_date DESC";
   else 
    $SQL .= " ORDER BY publish_date DESC";

  if( OPTIMIZE_FOR_MYSQL ) {                 // mySQL - use LIMIT
    $SQL .= " LIMIT ". $scr->metapage * ($scr->current - 1). ", ". $scr->metapage;
  }  

#huh("$SQL");
  $db->query($SQL);
#huh("nf:". $db->nf());
  
  if ($db->nf()>0) {    
    if(!$encap) 
      echo '<a href="'. $sess->MyUrl($slice_id, $encap). '&bigsrch=1">Search form</a><br>';
    if( $catsel )
      pCatSelector($sess->name,$sess->id,$sess->MyUrl($slice_id, $encap, true),$cur_cats,$scr->filters[category_id][value], $slice_id, $encap);
    echo $compact_top;
    
    $CurItem = new item($foo,false,1,$sess->MyUrl($slice_id, $encap), $fulltext_format, $odd_row_format, $even_row_format, $category_format, $grab_len, 
                        $compact_remove, $fulltext_remove);
    $oldcat = "_No CaTeg";

    if( !OPTIMIZE_FOR_MYSQL )                // no mySQL - seek in result set
      $db->seek($scr->metapage * ($scr->current - 1));
      
    while($db->next_record()){ 
      $CurItem->odd = $i%2;
      $CurItem->columns = $db->Record; # active row 
      $catname = $db->f("category");
      SubstFulltext(&$CurItem->columns);   //changes $db2 !!
      if($category_sort AND ($catname != $oldcat)) {
        $oldcat = $catname;
        $CurItem->print_category();
      }  
      $CurItem->print_item();
      if(++$i >= $scr->metapage) break; 
    }

    if( !OPTIMIZE_FOR_MYSQL )                  // if no mySQL - go to item no (mySQL use LIMIT)
      $scr->countPages($db->nf());
     else { 
      $db->query($SQLcount);
      $db->next_record();
      $scr->countPages($db->f(numrows));      // count rows
    }  

  	if($scr->pageCount() > 1)
      $scr->pnavbar();
    echo $compact_bottom;
  }  
  else 
    echo "<div>". L_NO_ITEM ."</div>";
}

//-----------------------------End of functions definition------------------------------------------ 

  if ($encap) $sess->add_vars(); # adds values from QUERY_STRING_UNESCAPED 
                                 #       and REDIRECT_STRING_UNESCAPED

    # url posted command to display another file
  if( $inc ) {                   # this section must be after $sess->add_vars()
    $fp = @FOpen( $inc, "r");    #   if encapsulated
    if( !$fp )
      echo L_NO_SUCH_FILE ." $inc";
     else
      FPassThru($fp); 
    exit;
  }  

  $p_slice_id= q_pack_id($slice_id);
  $db = new DB_AA; 		 // open BD	
  $db2 = new DB_AA; 	 // open BD	(for subqueries in order to fullfill fulltext in feeded items)
  $cur_cats=GetCategories($db,$p_slice_id);     // get list of categories 

  $SQL= " SELECT headline, d_cp_code, d_listlen, fulltext_format, odd_row_format,
                 even_row_format, even_odd_differ, compact_top, compact_bottom,
                 category_sort, category_format, slice_url, grab_len, compact_remove,
                 fulltext_remove, type
          FROM slices WHERE id='".$p_slice_id."' AND deleted<1";
  $db->query($SQL);
  if ($db->next_record()) {
    $codepage = $db->f(d_cp_code);
    $fulltext_format = $db->f(fulltext_format);
    $odd_row_format = $db->f(odd_row_format);
    $even_row_format = $db->f(even_row_format);
    $even_odd_differ = $db->f(even_odd_differ);
    $compact_top = $db->f(compact_top);
    $compact_bottom = $db->f(compact_bottom);
    $category_sort = $db->f(category_sort);
    $category_format = $db->f(category_format);
    $headline = $db->f(headline);
    $listlen = $db->f(d_listlen);
    $slice_url = $db->f(slice_url);
    $grab_len = $db->f(grab_len);
    $compact_remove = $db->f(compact_remove);
    $fulltext_remove = $db->f(fulltext_remove);
    include $GLOBALS[AA_INC_PATH] . $ActionAppConfig[$db->f(type)][file];  // language constants (used in searchform...)
  }
  else {
    echo L_SLICE_INACCESSIBLE;
    if (!$encap)
      Page_HTML_End();
    page_close();
    exit;
  }  
    
  if( !$even_odd_differ )
    $even_row_format = "";
  
  if (!$encap)
    Page_HTML_Begin($codepage, $headline );

  if( $bigsrch ) {      // big search form
    $show = Array("slice"=>true, "category"=>true, "author"=>true, "lang"=>true, "headline"=>true,
                  "full_text"=>true, "abstract"=>true, "from"=>true, "to"=>true, "edit_note"=>true);
    require $GLOBALS[AA_INC_PATH]."big_srch.php3";
  }
  elseif( $sh_itm ) {   // fulltext view
    $SQL= "SELECT items.*, fulltexts.full_text, categories.name as category 
             FROM items, fulltexts 
             LEFT JOIN categories ON categories.id=items.category_id 
             WHERE fulltexts.ft_id=items.master_id AND (items.id='".q_pack_id($sh_itm)."')";
    $db->query($SQL);
    if ($db->next_record()) {
      $CurItem = new item($foo,true,1,$sess->MyUrl($slice_id, $encap), $fulltext_format, $odd_row_format, $even_row_format, "", $grab_len, $compact_remove, $fulltext_remove);
      $CurItem->columns = $db->Record; 
//p_arr_m($CurItem->columns);
      SubstFulltext(&$CurItem->columns);    //changes $db2 !!
      $CurItem->print_item();
      $CurItem->show_navigation($slice_url);
    }
    else 
      echo "are you sure about the existence of this ?";   
  }
  else {               //compact view
    if(!is_object($scr)) {
      $sess->register(scr); 
      $scr = new easy_scroller("scr",$sess->MyUrl($slice_id, $encap)."&", $listlen);	
    }  
    if($srch) {
      $r_category_id = "";
      $r_highlight = "";
      if( !$big )      // posted by bigsrch form
        $search[slice] = $slice_id;
      $r_unpacked_where = unpack_id(SearchWhere($search, $s_col));  // it is problem to store packed slice_id in session variable
      $scr->current = 1;
    }
    else {
      if( $cat_id ) {         // optional parameter cat_id
        $r_category = ( $cat_id == "all" ? "" : $cat_id );
        $r_highlight = $highlight;
        $r_unpacked_where = unpack_id(MakeWhere($p_slice_id, $r_category, $r_highlight));
        $scr->current = 1;
      }  
      elseif ( $cat_name ) {  // optional parameter cat_name
        $SQL = "SELECT categories.id FROM categories, catbinds 
                  WHERE categories.id=catbinds.category_id 
                    AND catbinds.slice_id = '$p_slice_id'
                    AND categories.name LIKE '%$cat_name%'";
        $db->query($SQL);
        $r_category = ( $db->next_record() ? unpack_id($db->f(id)) : "" );
        $r_highlight = $highlight;
        $r_unpacked_where = unpack_id(MakeWhere($p_slice_id, $r_category, $r_highlight));
        $scr->current = 1;
      }
      elseif( $scrl ) {      // comes from easy_scroller
        if (is_object($scr)) 
          $scr->update();
      }    
      else {                 // no parameters - initial settings 
        $r_category_id = "";
        $r_highlight = $highlight;
        $r_unpacked_where = unpack_id(MakeWhere($p_slice_id, $r_category, $r_highlight));
        $scr->current = 1;
      }  
    }    
    //$debugtimes["callcompact"]=microtime();
    CompactView(pack_id($r_unpacked_where), (!$srch AND !$encap) );
    //$debugtimes[]=microtime();
  }
?>
 <br>
<?php 
  //<a href= $sess->MyUrl($slice_id, $encap)> Reload this</a> 
 //p_arr_m($debugtimes);
  if (!$encap)
    Page_HTML_End();
    //$debugtimes["end"]=microtime();
  page_close();
    //$debugtimes["end2"]=microtime();
//    p_arr_m($debugtimes);
/*
$Log$
Revision 1.7  2000/08/22 12:30:06  honzam
fixed problem with lost session id AA_SL_Session in cgi (PHP4) instalation.

Revision 1.6  2000/08/19 11:53:31  kzajicek
Removed debugging output ()

Revision 1.5  2000/08/17 15:09:11  honzam
new inc parameter for displaying specified file instead of slice data

Revision 1.4  2000/07/12 16:53:09  kzajicek
No min-max games are necessary, scroller keeps us within boundaries.

Revision 1.3  2000/07/07 21:31:15  honzam
Wrong parameter count in min() - fixed

Revision 1.22  2000/06/12 19:57:51  madebeer
added GPL LICENSE file, added copyright notice to all files that
added GPL LICENSE
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.21  2000/05/30 09:11:13  honzama
MySQL permissions upadted and completed.

Revision 1.20  2000/04/24 16:35:18  honzama
Small changes in design.

Revision 1.19  2000/03/29 15:56:34  honzama
Encap=true is default parameter to this script.

Revision 1.18  2000/03/22 09:36:17  madebeer
config.inc now allows ecn and igc to have different .css files
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/
?>

