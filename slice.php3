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
#optionaly listlen   // change number of listed items in compact view
                     // (aplicable in compact viewe only) 

$encap = ( ($encap=="false") ? false : true );

require "./include/config.php3";
//require $GLOBALS[AA_INC_PATH]."en_common_lang.php3";  // we need sec2userdate function
require $GLOBALS[AA_INC_PATH]."easy_scroller.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";
require $GLOBALS[AA_INC_PATH]."view.php3";
require $GLOBALS[AA_INC_PATH]."searchlib.php3";

if ($encap){require $GLOBALS[AA_INC_PATH]."locsessi.php3";}
else {require $GLOBALS[AA_INC_PATH]."locsess.php3";} 
page_open(array("sess" => "AA_SL_Session"));
$sess->register(r_highlight); 
$sess->register(r_category); 
$sess->register(r_item_ids); 

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
 $SQL= " SELECT name, value FROM constant WHERE group_id='".$p_slice_id."'";
 $db->query($SQL);
 while ($db->next_record()){
   $unpacked=unpack_id($db->f("value"));  
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

//-----------------------------End of functions definition------------------------------------------ 

if ($encap) $sess->add_vars(); # adds values from QUERY_STRING_UNESCAPED 
                               #       and REDIRECT_STRING_UNESCAPED

  # url posted command to display another file
if( $inc ) {                   # this section must be after $sess->add_vars()
  if( !eregi("^([0-9a-z_])+(\.[0-9a-z]*)?$", $inc) ) {
    echo L_BAD_INC. " $inc";
    page_close();
    exit;
  } else {  
    $fp = @fopen( shtml_base().$inc, "r");    #   if encapsulated
    if( !$fp )
      echo L_NO_SUCH_FILE ." $inc";
     else
      FPassThru($fp); 
    exit;
  }  
}  

$p_slice_id= q_pack_id($slice_id);
$db = new DB_AA; 		 // open BD	
$db2 = new DB_AA; 	 // open BD	(for subqueries in order to fullfill fulltext in feeded items)
$cur_cats=GetCategories($db,$p_slice_id);     // get list of categories 

  # get fields info
$fields = GetTable2Array("SELECT * FROM field WHERE slice_id='$p_slice_id'", $db);

  # get slice info
$SQL= " SELECT * FROM slice WHERE id='".$p_slice_id."' AND deleted<1";
//huh($SQL);

$db->query($SQL);
if ($db->next_record()) {
  $slice_info = $db->Record;
  include $GLOBALS[AA_INC_PATH] . $db->f(lang_file);  // language constants (used in searchform...)
}
else {
  echo L_SLICE_INACCESSIBLE . " (ID: $slice_id)";
  if (!$encap)
    Page_HTML_End();
  page_close();
  exit;
}  
  
if( !$slice_info[even_odd_differ] )
  $slice_info[even_row_format] = "";

if (!$encap)
  Page_HTML_Begin("iso-8859-1", $slice_info[name] );  // TODO codepage

if( $bigsrch ) {      // big search form --------------------------------------
  $show = Array("slice"=>true, "category"=>true, "author"=>true, "lang"=>true, "headline"=>true,
                "full_text"=>true, "abstract"=>true, "from"=>true, "to"=>true, "edit_note"=>true);
  require $GLOBALS[AA_INC_PATH]."big_srch.php3";
}
elseif( $sh_itm ) {   // fulltext view ----------------------------------------
  $aliases = GetAliasesFromFields($fields);
  $itemview = new itemview( $db, $slice_info, $fields, $aliases, $sh_itm, "","", $sess->MyUrl($slice_id, $encap));
  $itemview->print_item();
}
else {               //compact view -------------------------------------------
  if(!is_object($scr)) {
    $sess->register(scr); 
    $scr = new easy_scroller("scr",$sess->MyUrl($slice_id, $encap)."&", $slice_info[d_listlen]);	
  }
  if( $listlen )    // change number of listed items
    $scr->metapage = $listlen;
  
  if($srch) {
    $r_category_id = "";
    $r_highlight = "";
    if( !$big )      // posted by bigsrch form -------------------
      $search[slice] = $slice_id;
    $r_item_ids = unpack_id(SearchWhere($search, $s_col));  // it is problem to store packed slice_id in session variable
    $scr->current = 1;
  }
  else {
    if( $cat_id ) {         // optional parameter cat_id ---------
      $r_category = ( $cat_id == "all" ? "" : $cat_id );
      $r_highlight = $highlight;
      $scr->current = 1;
    }  
    elseif ( $cat_name ) {  // optional parameter cat_name -------
      $SQL = "SELECT value FROM constant
               WHERE group = '$p_slice_id'
                 AND name LIKE '%$cat_name%'";
      $db->query($SQL);
      $r_category = ( $db->next_record() ? $db->f(value) : "" );
      $r_highlight = $highlight;
      $scr->current = 1;
    }
    elseif( $scrl ) {      // comes from easy_scroller -----------
      if (is_object($scr)) 
        $scr->update();
    }    
    else {                 // no parameters - initial settings ---
      $r_category_id = "";
      $r_highlight = $highlight;
      $scr->current = 1;
    }  
    if( $r_category != "" )
      $conditions['category........'] = $r_category;
    if( $r_highlight != "" )
      $conditions['highlight.......'] = 1;
    $r_item_ids = GetItemAppIds($fields, $db, $p_slice_id, $conditions, 
                   "DESC", $slice_info[category_sort] ? "category........" : "", "" );
  }    

  if(!$encap) 
    echo '<a href="'. $sess->MyUrl($slice_id, $encap). '&bigsrch=1">Search form</a><br>';
  if( !$srch AND !$encap )
    pCatSelector($sess->name,$sess->id,$sess->MyUrl($slice_id, $encap, true),$cur_cats,$scr->filters[category_id][value], $slice_id, $encap);

  if( count( $r_item_ids ) > 0 ) {
    $aliases = GetAliasesFromFields($fields);
    $itemview = new itemview( $db, $slice_info, $fields, $aliases, $r_item_ids,
                $scr->metapage * ($scr->current - 1), $scr->metapage, $sess->MyUrl($slice_id, $encap) );
    $itemview->print_view();
      
    $scr->countPages( count( $r_item_ids ) );
  	if($scr->pageCount() > 1)
      $scr->pnavbar();
  }  
  else 
    echo "<div>". L_NO_ITEM ."</div>";
}
?>
 <br>
<?php 
  //<a href= $sess->MyUrl($slice_id, $encap)> Reload this</a> 
if (!$encap)
  Page_HTML_End();
  //$debugtimes[]=microtime();
page_close();
//    p_arr_m($debugtimes);
/*
$Log$
Revision 1.9  2000/12/21 16:39:33  honzam
New data structure and many changes due to version 1.5.x

Revision 1.8  2000/08/23 12:29:57  honzam
fixed security problem with inc parameter to slice.php3

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

