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
#optionaly x         // the same as sh_itm, but short_id is used instead
                     // implemented for shorter item url (see _#SITEM_ID alias)
#optionaly srch      // true if this script have to show search results
#optionaly highlight // when true, shows only highlighted items in compact view
#optionaly bigsrch   // NOT SUPPORTED IN AA v 1.5+
#optionaly cat_id    // select only items in category with id cat_id
#optionaly cat_name  // select only items in category with name cat_name
#optionaly inc       // for dispalying another file instead of slice data 
                     // (like static html file - inc=/contact.html)
#optionaly listlen   // change number of listed items in compact view
                     // (aplicable in compact viewe only) 
#optionaly items[x]  // array of items to show one after one as fulltext 
                     // the array format is 
#easy_query          // for easiest form of query
#order               // order field id - if other than publish date
                     // add minus sign for descending order (like "headline.......1-"); 
#timeorder           // rev - reverse publish date order 
                     // (less priority than "order")
#no_scr              // if true, no scroller is displayed                     
#scr_go              // sets scroller to specified page
#scr_url             // redefines teh page where the scroller should go (it is 
                     // usefull if you include slice.php3 from another php script)
#restrict            // field id used with "res_val" and "exact" for restricted
                     // output (display only items with 
                     //       "restrict" field = "res_val"
#res_val             // see restrict
#exact               // = 1: "restrict" field must match res_val exactly (=)
                     // undefined: substring is sufficient (LIKE '%res_val%')
                     // = 2: the same as 1, but the res_val is taken as 
                     // expression (like: "environment or (toxic and not fuel)")
#als[]               // user alias definition. Parameter 'als[MY_ALIAS]=Summary'
                     // defines alias _#MY_ALIAS. If used, it prints 'Summary'.
#lock                // used in join with "key" for multiple slices on one page
                     // display. each slice have to have its lock, so commands 
                     // (like sh_itm, scr_go, ...) will be executed only if key
                     // is the same as lock. key is send automaticaly with all 
                     // links generated in slice (at this time just prepared)
#key                 // see lock (at this time just prepared)
#slicetext           // displays just the text instead of any output
                     // can be used for hiding the output of slice.hp3

                      //Discussion parameters
#optionaly add_disc   // if set, discussion comment will be added
#optionaly parent_id  // parent id of added disc. comment
#optionaly sel_ids    // if set, show only discussion comments in $ids[] array
#optionaly ids[]      // array of discussion comments to show in fulltext mode (ids['x'.$id])
#optionaly all_ids    // if set, show all discussion comments
#optionally hideFulltext // if set, don't show fulltext part

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

$encap = ( ($encap=="false") ? false : true );

require "./include/config.php3";
require $GLOBALS[AA_INC_PATH]."easy_scroller.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";
require $GLOBALS[AA_INC_PATH]."view.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."searchlib.php3";
require $GLOBALS[AA_INC_PATH]."discussion.php3";

# $debugtimes[]=microtime();

if ($encap){require $GLOBALS[AA_INC_PATH]."locsessi.php3";}
else {require $GLOBALS[AA_INC_PATH]."locsess.php3";} 

page_open(array("sess" => "AA_SL_Session"));

$sess->register(r_packed_state_vars); 
$sess->register(slices);
$sess->register(mapslices);

$r_state_vars = unserialize($r_packed_state_vars);

# there was problems with storing too much ids in session veriable, 
# so I commented it out. It is not necessary to have it in session. The only
# reason to have it there is the display speed, but because of impementing
# pagecache.php3, it is not so big problem now

//$sess->register(item_ids);    

//-----------------------------Functions definition--------------------------------------------------- 

function Page_HTML_Begin($cp, $title="") {  
    echo '
    <!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 3.2 Final//EN">
    <HTML>
    <HEAD>
    <TITLE>'.$title.'</TITLE>
    <LINK rel=StyleSheet href="<?php echo ADM_SLICE_CSS ?>" type="text/css" title="SliceCS">';
    if ($cp) 
        echo '<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset='. $cp. '">';
    echo '
    </HEAD>
    <BODY>';
}

# print closing HTML tags for page
function Page_HTML_End(){ 
    echo '
    </BODY>
    </HTML>';
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

function ExitPage() {
  global $encap, $r_packed_state_vars, $r_state_vars;
  if (!$encap)
    Page_HTML_End();
  $r_packed_state_vars = serialize($r_state_vars);
  page_close();
  exit;
}  

function StoreVariables( $vars ) {
  if( isset($vars) AND is_array($vars) ) {
    reset($vars);
    while( list(,$v) = each( $vars ) )
      $state_vars[$v] = $GLOBALS[$v];
  }
  return $state_vars;
}  

function RestoreVariables() {
  global $r_state_vars;
  if( isset($r_state_vars) AND is_array($r_state_vars) ) {
    reset($r_state_vars);
    while( list($k,$v) = each( $r_state_vars ) )
      $GLOBALS[$k] = $v;
  }
}  

# two purpose function - it loggs item view and it translates short_id to id
function LogItem($id, $column) {
  global $db;

  CountHit($id, $column);

  if( $column == "id" )
    return $id;
    
  $SQL = "SELECT id, display_count FROM item WHERE short_id='$id'";
  $db->query($SQL);
  if( $db->next_record() )
    return unpack_id( $db->f('id') );
  return false;
}  

function GetSortArray( $sort ) {
  if( substr($sort,-1) == '-' )
    return array ( substr($sort,0,-1) => 'd' );
  if( substr($sort,-1) == '+' )
    return array ( substr($sort,0,-1) => 'a' );
  return array ( $sort => 'a' );
}    

function SubstituteAliases( $als, &$var ) {
  if( !isset( $als ) OR !is_array( $als ) )  # substitute url aliases in cmd
    return;
  reset( $als );
  while( list($k,$v) = each( $als ) )
    $var = str_replace ($k, $v, $var);
}    

function PutSearchLog ()
{
    global $QUERY_STRING_UNESCAPED, $REDIRECT_QUERY_STRING_UNESCAPED, 
        $searchlog;
        
    $httpquery = $QUERY_STRING_UNESCAPED.$REDIRECT_QUERY_STRING_UNESCAPED;
    $httpquery = DeBackslash ($httpquery);
    $httpquery = str_replace ("'", "\\'", $httpquery);
    $db = new DB_AA;
    $found_count = count ($GLOBALS[item_ids]);
    list($usec, $sec) = explode(" ",microtime()); 
    $slice_time = 1000 * ((float)$usec + (float)$sec - $GLOBALS[slice_starttime]); 
    $user = $GLOBALS[HTTP_SERVER_VARS]['REMOTE_USER'];
    $db->query (
    "INSERT INTO searchlog (date,query,user,found_count,search_time,additional1) 
    VALUES (".time().",'$httpquery','$user',$found_count,$slice_time,'$searchlog')");
}

//-----------------------------End of functions definition---------------------
# $debugtimes[]=microtime();

list($usec, $sec) = explode(" ",microtime()); 
$slice_starttime = ((float)$usec + (float)$sec); 

if ($encap) add_vars("");        # adds values from QUERY_STRING_UNESCAPED 
                                 #       and REDIRECT_STRING_UNESCAPED

if( $debug ) {
  echo "<br><br>Conds by:<br>";
  print_r($conds);
  
  echo "<br><br>Group by:<br>";
  print_r($group_by);
}

// p_arr_m( $r_state_vars );

if( ($key != $lock) OR $scrl ) # command is for other slice on page
  RestoreVariables();          # or scroller

# url posted command to display specified text instead of slice content -------
if($slicetext) {
  echo $slicetext;
  ExitPage();
}  

# url posted command to display another file ----------------------------------
if( $inc ) {                   # this section must be after add_vars()
//  StoreVariables(array("inc")); # store in session
  if( !eregi("^([0-9a-z_])+(\.[0-9a-z]*)?$", $inc) ) {
    echo L_BAD_INC. " $inc";
    ExitPage();
  } else {  
    $fp = @fopen( shtml_base().$inc, "r");    #   if encapsulated
    if( !$fp )
      echo L_NO_SUCH_FILE ." $inc";
     else
      FPassThru($fp); 
    ExitPage();
  }  
}  

$p_slice_id= q_pack_id($slice_id);
$db = new DB_AA; 		 // open BD	
$db2 = new DB_AA; 	 // open BD	(for subqueries in order to fullfill fulltext in feeded items)
$db3 = new DB_AA; 	 // open BD	(for another subqueries)

  # get fields info
list($fields,) = GetSliceFields($slice_id);

  # get slice info
$slice_info = GetSliceInfo($slice_id);
if ($slice_info AND ($slice_info[deleted]<1)) {
  include $GLOBALS[AA_INC_PATH] . $slice_info[lang_file];  // language constants (used in searchform...)
}
else if ($slice_id || !$slices) {
  echo L_SLICE_INACCESSIBLE . " (ID: $slice_id)";
  ExitPage();
}  

if( !$slice_info['even_odd_differ'] )
  $slice_info['even_row_format'] = "";

# it is possible to redefine the design of fulltext or compact view by the view 
# see fview and iview url parameters for this file (slice.php3)
if( $fview || $iview ) {
  if( $fview ) {                       # use formating from view for fulltext
    $fview_info = GetViewInfo($fview);
    if ($fview_info AND ($fview_info['deleted']<1)) {
      $slice_info['fulltext_format'] = $fview_info['odd'];
      $slice_info['fulltext_format_top'] = $fview_info['before'];
      $slice_info['fulltext_format_bottom'] = $fview_info['after'];
      $slice_info['fulltext_remove'] = $fview_info['remove_string'];
//      print_r( $slice_info );
    }  
  }  
  if( $iview ) {                       # use formating from view for index
    $iview_info = GetViewInfo($iview);
    if ($iview_info AND ($iview_info['deleted']<1)) {
      $slice_info['group_by'] = $iview_info['group_by1'];
      $slice_info['category_format'] = $iview_info['group_title'];
      $slice_info['category_bottom'] = $iview_info['group_bottom'];
      $slice_info['compact_top'] = $iview_info['before'];
      $slice_info['compact_bottom'] = $iview_info['after'];
      $slice_info['compact_remove'] = $iview_info['remove_string'];
      $slice_info['even_row_format'] = $iview_info['even'];
      $slice_info['odd_row_format'] = $iview_info['odd'];
      $slice_info['even_odd_differ'] = $iview_info['even_odd_differ'];
    }  
  }
}
  
if (!$encap)
  Page_HTML_Begin(DEFAULT_CODEPAGE, $slice_info[name] );  // TODO codepage


if( $bigsrch ) {  # big search form ------------------------------------------
   echo 'bigsrch parameter is NOT SUPPORTED IN AA v 1.5+ <br> See 
         <a href="http://apc-aa.sourceforge.net/faq/index.shtml#215">AA FAQ</a> 
         for more details.';
  ExitPage();
}

GetAliasesFromUrl();
$urlaliases = $aliases;

# get alias list from database and possibly from url
# if working with multi-slice, get aliases for all slices
if (!is_array ($slices)) {
    $aliases = GetAliasesFromFields($fields);
    if (is_array ($urlaliases)) 
        array_add ($urlaliases, $aliases);
}
else {
    reset($slices);
    while (list(,$slice) = each($slices)) {
        list($fields,) = GetSliceFields ($slice);
        $aliases[q_pack_id($slice)] = GetAliasesFromFields($fields,$als);
        if (is_array ($urlaliases))
            array_add ($urlaliases, $aliases[q_pack_id($slice)]);
    }
}

# fulltext view ---------------------------------------------------------------
if( $sh_itm OR $x ) {
//  $r_state_vars = StoreVariables(array("sh_itm")); # store in session
  if($sh_itm)
    LogItem($sh_itm, "id");
   else
    $sh_itm = LogItem($x,"short_id");
    
  $itemview = new itemview( $db, $slice_info, $fields, $aliases, array(0=>$sh_itm), 0,1, $sess->MyUrl($slice_id, $encap));
  if (!isset ($hideFulltext))
    $itemview->print_item();

  // show discussion if assigned
  if( $slice_info[vid] > 0 ) {
    $db->query("SELECT view.*, slice.flag
                FROM view, slice
                WHERE slice.id='".q_pack_id($slice_id)."' AND slice.vid=view.id");
    if( $db->next_record() ) {
      $view_info = $db->Record;
      // create array of parameters
      $disc = array('ids'=>$all_ids ? "" : $ids,
                    'type'=>$add_disc ? "adddisc" : (($sel_ids || $all_ids) ? "fulltext" : "thread"),
                    'item_id'=> $sh_itm,
                    'vid'=> $view_info[id],
                    'html_format' => ($view_info[flag] & DISCUS_HTML_FORMAT) ? true : false,
                    'parent_id' => $parent_id
                     );
      $aliases = GetDiscussionAliases();
  
      $format = GetDiscussionFormat($view_info);
      $format['id'] = $p_slice_id;                  // set slice_id because of caching
  
      $itemview = new itemview( $db, $format, "", $aliases, "","", "", $sess->MyUrl($slice_id, $encap), $disc);
      $itemview->print_discussion();
    }
  }  
  ExitPage();
}

# multiple items fulltext view ------------------------------------------------
if( $items AND is_array($items) ) {   # shows all $items[] as fulltext one after one
//  $r_state_vars = StoreVariables(array("items")); # store in session
  while(list($k,) = each( $items ))
    $ids[] = substr($k,1);    #delete starting character ('x') - used for interpretation of index as string, not number (by PHP)
  $itemview = new itemview( $db, $slice_info, $fields, $aliases, $ids, 0,count($ids), $sess->MyUrl($slice_id, $encap));
  $itemview->print_itemlist();
  ExitPage();
}

# compact view ----------------------------------------------------------------
if(!is_object($scr)) {
  $sess->register(scr); 
  $scr = new easy_scroller("scr",
     ($scr_url ? $sess->url("$scr_url") : $sess->MyUrl($slice_id, $encap))."&",
     $slice_info[d_listlen]);
}
if( $listlen )    // change number of listed items
  $scr->metapage = $listlen;

if( $scr_go )     // optional script parameter
  $scr->current = $scr_go;
  
if( $scrl ) {      // comes from easy_scroller -----------
  if (is_object($scr)) 
    $scr->update();
}    
  
if( (isset($conds) AND is_array($conds)) OR isset($group_by) OR isset($sort)) {     # posted by query form ----------------
  $r_state_vars = StoreVariables(array("listlen","no_scr","scr_go","conds", "sort", "group_by")); # store in session

  if(isset($conds) AND is_array($conds)) {
    reset($conds); 
    while( list( $k , $cond) = each( $conds )) {
      if( !isset($cond) OR !is_array($cond) ) {
        $conds[$k] = false;
        continue;             # bad condition - ignore
      }
      if( !isset($cond['value']) && count ($cond) == 1 )
        $conds[$k]['value'] = current($cond);
      if( !isset($cond['operator']) )
        $conds[$k]['operator'] = 'LIKE';
      SubstituteAliases( $als, $conds[$k]['value'] );
    }    
  }

  if( isset($group_by) ) {
    $foo = GetSortArray( $group_by );
    $sort_tmp[] = $foo;
    $slice_info["group_by"] = key($foo);
  }    

  if(isset($sort)) {
    if( !is_array($sort) )
      $sort_tmp[] = GetSortArray( $sort );
    else {  
      ksort( $sort, SORT_NUMERIC); # it is not sorted and the order is important
      reset($sort); 
      while( list( $k , $srt) = each( $sort )) {
        if( isset($srt))
          if( is_array($srt) )
            $sort_tmp[$k] = array( key($srt) => (( strtoupper(current($srt)) == "D" ) ? 'd' : 'a'));
           else
            $sort_tmp[] =  GetSortArray( $srt );
      }
    }
  }  
  if( isset($sort_tmp) )
    $sort = $sort_tmp;
   else 
    $sort[] = array ( 'publish_date....' => 'd' );

  $item_ids=QueryIDs($fields, $slice_id, $conds, $sort, "", "ACTIVE", $slices, $mapslices );

  if( isset($item_ids) AND !is_array($item_ids))
    echo "<div>$item_ids</div>";
  if( !$scrl )
    $scr->current = 1;
  $slice_info[category_sort] = false;      # do not sort by categories
}
elseif($easy_query) {     # posted by easy query form ----------------

  $r_state_vars = StoreVariables(array("listlen","no_scr","scr_go","srch_fld","srch_from", "srch_to",
                      "easy_query", "qry", "srch_relev")); # store in session

  $item_ids = GetIDs_EasyQuery($fields, $db, $p_slice_id, $srch_fld, 
                               $srch_from, $srch_to, $qry, $srch_relev);
  if( isset($item_ids) AND !is_array($item_ids))
    echo "<div>$item_ids</div>";
  if( !$scrl )
    $scr->current = 1;
}
elseif($srch) {            # posted by bigsrch form -------------------
  $r_state_vars = StoreVariables(array("listlen","no_scr","scr_go","big","search", "s_col")); # store in session
  if( !$big )
    $search[slice] = $slice_id;
  $item_ids = SearchWhere($search, $s_col);
  if( !$scrl )
    $scr->current = 1;
}
else {
  $r_state_vars = StoreVariables(array("listlen","no_scr","scr_go","order","cat_id", "cat_name",
                      "exact","restrict","res_val","highlight")); # store in session
  if( $cat_id ) {  // optional parameter cat_id - deprecated - slow ------
    $cat_group = GetCategoryGroup($slice_id);
    $SQL = "SELECT value FROM constant
             WHERE group_id = '$cat_group'
               AND id = '". q_pack_id($cat_id) ."'";
    $db->query($SQL);
    if( $db->next_record() ) {
      $res_field = GetCategoryFieldId( $fields );
      $res_value = $db->f(value);
      $exact = true;
    }  
  }  
  elseif ( $cat_name ) {  // optional parameter cat_name -------
    $res_field = GetCategoryFieldId( $fields );
    $res_value = $cat_name;
  }
  elseif ( $restrict ) { 
    $res_field = $restrict;
    $res_value = (( (($res_val[0] == '"') OR ($res_val[0] == "'" )) AND ($exact != 2)) ? $res_val : "'$res_val'");
  } else {             # no parameters - initial settings ---
    $res_field = "";
    $res_value = "";
  }  

  # order the fields in compact view - how?
  if( $order ) {
    if( substr($order,-1) == '-' ) {
      $orderdirection = "d";
      $order = substr($order,0,-1);
    }
    if( substr($order,-1) == '+' )   # just skip
      $order = substr($order,0,-1);
  }    
  
  if( $res_field != "" )
    $conditions[$res_field] = $res_value;
  if( $highlight != "" )
    $conditions['highlight.......'] = 1;

/*$debugtimes[]=microtime();
  $item_ids=GetItemAppIds($fields, $db, $slice_id, $conditions, $pubdate_order,    ($slice_info[category_sort] ? GetCategoryFieldId( $fields ) : $order),    $orderdirection, "", $exact );
$debugtimes[]=microtime();*/
  
  # prepare parameters for QueryIDs()
  if( isset($conditions) AND is_array($conditions) ) {
    reset($conditions);
    while( list( $k, $v) = each( $conditions ))
      $cnds[]=array( 'operator' => ($exact ? '=' : 'LIKE'),
                      'value' => $v,
                      $k => 1 );
  }                    

  if( $slice_info[category_sort] ) {
    $group_field = GetCategoryFieldId( $fields );
    $grp_odir = (($order==$group_field) AND ($orderdirection!='d')) ? 'a':'d';
    $srt[] = array ( $group_field => $grp_odir );
  }  
  else if ($slice_info[group_by]) 
  	$srt[] = array ( $slice_info[group_by] => ($slice_info[gb_direction] == 'd' ? 'd' : 'a'));

  if( $order )
    $srt[] = array ( $order => (( $orderdirection == 'd' ) ? 'd' : 'a'));

  # time order the fields in compact view
  $srt[] = array ( 'publish_date....' => (($timeorder == "rev") ? 'a' : 'd') );
   
  $item_ids=QueryIDs($fields, $slice_id, $cnds, $srt, $group_by, "ACTIVE", $slices, $mapslices );

// p_arr_m($debugtimes);
// echo "<br>old: ". (double)((double)($debugtimes[1]) - (double)($debugtimes[0]));
// echo "<br>new: ". (double)((double)($debugtimes[3]) - (double)($debugtimes[2]));

}    
if( !$srch AND !$encap AND !$easy_query ) {
  $cur_cats=GetCategories($db,$p_slice_id);     // get list of categories 
  pCatSelector($sess->name,$sess->id,$sess->MyUrl($slice_id, $encap, true),$cur_cats,$scr->filters[category_id][value], $slice_id, $encap);
}

$ids_cnt = count( $item_ids );
if( $ids_cnt > 0 ) {
  $scr->countPages( count( $item_ids ) );

  $itemview = new itemview( $db, $slice_info, $fields, $aliases, $item_ids,
              $scr->metapage * ($scr->current - 1), $scr->metapage, $sess->MyUrl($slice_id, $encap) );
  $itemview->print_view();
    
	if( ($scr->pageCount() > 1) AND !$no_scr)
    $scr->pnavbar();
}  
else 
  echo $slice_info['noitem_msg'] ? $slice_info['noitem_msg'] : ("<div>".L_NO_ITEM ."</div>");

if ($searchlog) PutSearchLog ();

ExitPage();

?>
