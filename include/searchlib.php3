<?php
define("SEARCHLIB_PHP3_INC",1);

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

function GetWhereExp( $field, $operator, $querystring ) {

  # search operator for functions (some operators can be in function:operator
  # fomat - the function is called to $querystring (good for date transform ...)
  if ( $pos = strpos($operator,":") ) {  # not ==
    $func = substr($operator,0,$pos);
    $operator = substr($operator,$pos+1);
    
    switch( $func ) {
      case 'd': # english style datum (like '12/31/2001' or '10 September 2000')
                $querystring = strtotime($querystring);
                break;
      case 'e': # european datum style (like 24. 12. 2001)
                if( !ereg("^ *([0-9]{1,2}) *\. *([0-9]{1,2}) *\. *([0-9]{4}) *$", $querystring, $part))
                  if( !ereg("^ *([[0-9]]{1,2}) *\. *([0-9]{1,2}) *\. *([0-9]{2}) *$", $querystring, $part))
                    if( !ereg("^ *([[0-9]]{1,2}) *\. *([0-9]{1,2}) *$", $querystring, $part)) {
                      $querystring = time();
                      break;
                    }  
                if( ($operator == "<=") or ($operator == ">") )
                  # end of day used for some operators
                  $querystring = mktime(23,59,59,$part[2],$part[1],$part[3]);
                 else 
                  $querystring = mktime(0,0,0,$part[2],$part[1],$part[3]);
                break;
      case 'm':
      case '-': $querystring = time() - $querystring;
                break;
    }
  }               

  switch( $operator ) {
    case 'LIKE':   $bgn='%';  $end='%';  $op='LIKE';    break;
    case 'RLIKE':  $bgn='%';  $end='';   $op='LIKE';    break;
    case 'LLIKE':  $bgn='';   $end='%';  $op='LIKE';    break;
    case 'XLIKE':  $bgn='';   $end='';   $op='LIKE';    break;
    case 'BETWEEN': 
                   $arr = explode( ",", $querystring );
                   return ( " (($field >= $arr[0]) AND ($field <= $arr[1])) ");
    
    default:       $bgn='';  $end='';  $op=$operator; break;
  }  

  $arr = explode( " OR ", $querystring );
  $delim = "";
  while( list( ,$v) = each($arr) ) {
    $str = ( ($v[0] == '"') OR ($v[0] == "'") ) ? substr( $v, 1, -1 ) : $v ;
    $ret .= "$delim ($field $op '$bgn$str$end')";
    $delim = ' OR ';
  }
    
  return $ret;
}  

function QueryIDs($fields, $slice_id, $conds, $sort="", $group_by="", $type="ACTIVE" ) {
  # parameter format example:  
  # conds[0][fulltext........] = 1;   // returns id of items where word 'Prague'
  # conds[0][abstract........] = 1;   // is in fulltext, absract or keywords
  # conds[0][keywords........] = 1;          
  # conds[0][operator] = "=";
  # conds[0][value] = "Prague";
  # conds[1][source..........] = 1;   // and source field of that item is 
  # conds[1][operator] = "=";         // 'Econnect' 
  # conds[1][value] = "Econnect";
  # sort[0][category........]='a';    // order items by category ascending
  # sort[1][publish_date....]='d';    // and publish_date descending (secondary)
  # group_by[category........]=1;     // group by category
  
  # type sets status, pub_date and expiry_date according to specified type:
  # ACTIVE | EXPIRED | PENDING | HOLDING | TRASH | ALL
  # if you want specify it yourselves in conds, set type to ALL

  # select * from item, content as c1, content as c2 where item.id=c1.item_id AND item.id=c2.item_id AND       c1.field_id IN ('fulltext........', 'abstract..........') AND c2.field_id = 'keywords........' AND c1.text like '%eufonie%' AND c2.text like '%eufonie%' AND item.highlight = '1';

  global $db, $debug;

if( $debug ) {
  p_arr_m($conds);p_arr_m($sort);p_arr_m($group_by);
}
  
  # parse conditions ----------------------------------
  if( isset($conds) AND is_array($conds)) {
    reset($conds); 
    $tbl_count=0;
    while( list( , $cond) = each( $conds )) {
      if( !isset($cond) OR !is_array($cond) 
                        OR !$cond['operator'] OR !$cond['value'])
        continue;             # bad condition - ignore

      # fill arrays according to this condition
      reset($cond); 
      $field_count = 0;
      $cond_flds   = '';
      while( list( $fid, $v) = each( $cond )) {
        if( ($fid=='operator') OR ($fid=='value') )
          continue;           # it is not field_id parameters - skip it for now
          
        if( !$fields[$fid] )  # bad field_id - skip
          continue;
          
        if( $fields[$fid]['in_item_tbl'] ) {   # field is stored in table 'item'
          $select_conds[] = GetWhereExp( 'item.'.$fields[$fid]['in_item_tbl'],
                                          $cond['operator'], $cond['value'] );
          if( $fid == 'expiry_date.....' )
            $ignore_expiry_date = true;
        } else {
          $cond_flds .= ( ($field_count++>0) ? ',' : "" ). "'$fid'";
          $store = ($fields[$fid]['text_stored'] ? "text" : "number");
        }  
      }    
      if( $cond_flds != '' ) {
        $tbl = 'c'.$tbl_count++;
        # fill arrays to be able construce select command
        $select_tables[] = "content as $tbl";          
        $select_conds[] = GetWhereExp( "$tbl.$store",
                                          $cond['operator'], $cond['value'] );
        if ($field_count>1) 
          $select_join[] = "$tbl.item_id=item.id AND $tbl.field_id IN ($cond_flds)";
         else {
          $select_join[] = "$tbl.item_id=item.id AND $tbl.field_id=$cond_flds";
                      # mark this field as sortable (store without apostrofs)
          $sortable[ str_replace( "'", "", $cond_flds) ] = $tbl;  
        }  
      }
    }
  }  

  # parse sort order ----------------------------
  if( !(isset($sort) AND is_array($sort)))
    $select_order = 'item.publish_date DESC';   # default item order
  else {
    reset($sort);
    $delim='';
    while( list( , $srt) = each( $sort )) {
      $fid = key($srt);
      if( !$fields[$fid] )  # bad field_id - skip
        continue;
        
      if( $fields[$fid]['in_item_tbl'] ) {   # field is stored in table 'item'
        $select_order .= $delim . 'item.' . $fields[$fid]['in_item_tbl'];
        if( stristr(current( $srt ), 'd'))
          $select_order .= " DESC";
        $delim=',';
      } else {
        if( !$sortable[ $fid ] ) {           # this field is not joined, yet
          $tbl = 'c'.$tbl_count++;
          # fill arrays to be able construce select command
          $select_tables[] = "content as $tbl";          
          $select_join[] = "$tbl.item_id=item.id AND $tbl.field_id='$fid'";
                        # mark this field as sortable (store without apostrofs)
          $sortable[$fid] = $tbl;
        }  

        $store = ($fields[$fid]['text_stored'] ? "text" : "number");
        # fill arrays according to this sort specification
        $select_order .= $delim .$sortable[$fid]. ".$store";
        if( stristr(current( $srt ), 'd'))
          $select_order .= " DESC";
        $delim=',';
      }  
    }
  }      

  # parse group by parameter ----------------------------
  if( isset($group_by) AND is_array($group_by)) {
    reset ($group_by);
    $delim='';
    while( list( ,$fid ) = each( $group_by )) {
      if( !$fields[$fid] )  # bad field_id - skip
        continue;

      if( $fields[$fid]['in_item_tbl'] ) {   # field is stored in table 'item'
        $select_group .= $delim . 'item.' . $fields[$fid]['in_item_tbl'];
        $delim=',';
      } else {
        if( !$sortable[ $fid ] ) {           # this field is not joined, yet
          $tbl = 'c'.$tbl_count++;
          # fill arrays to be able construce select command
          $select_tables[] = "content as $tbl";          
          $select_join[] = "$tbl.item_id=item.id AND $tbl.field_id='$fid'";
                        # mark this field as sortable (store without apostrofs)
          $sortable[$fid] = $tbl;
        }  

        $store = ($fields[$fid]['text_stored'] ? "text" : "number");
        # fill arrays according to this sort specification
        $select_group .= $delim .$sortable[$fid]. ".$store";
        $delim=',';
      }  
    }
  }      

  # construct query --------------------------
  $SQL = "SELECT DISTINCT item.id FROM item ";
  if( isset($select_tables) AND is_array($select_tables))
    $SQL .= ", ". implode (", ", $select_tables);

  $SQL .= " WHERE ";                                         # slice ----------
  if( $slice_id )
    $SQL .= " item.slice_id = '". q_pack_id($slice_id) ."' AND ";

  $now = now();                                              # select bin -----
  switch( $type ) {
    case 'ACTIVE':  $SQL .= " item.status_code=1 AND 
                              item.publish_date <= '$now' ";
                    # condition can specify expiry date (good for archives)
                    if( !( $ignore_expiry_date && 
                           defined("ALLOW_DISPLAY_EXPIRED_ITEMS") &&
                           ALLOW_DISPLAY_EXPIRED_ITEMS) )
                      $SQL .= " AND item.expiry_date > '$now' ";
                    break;
    case 'EXPIRED': $SQL .= " item.status_code=1 AND 
                              item.expiry_date <= '$now' ";
                              break;
    case 'PENDING': $SQL .= " item.status_code=1 AND 
                              item.publish_date > '$now' ";
                              break;
    case 'HOLDING': $SQL .= " item.status_code=2 ";
                              break;
    case 'TRASH':   $SQL .= " item.status_code=3 ";
                              break;
    default:        $SQL .= ' 1=1 ';    # default = ALL - no specific condition
  }  
  
  if( isset($select_join) AND is_array($select_join))        # join -----------
    $SQL .= " AND ". implode (" AND ", $select_join);
    
  if( isset($select_conds) AND is_array($select_conds))      # conditions -----
    $SQL .= " AND (" . implode (") AND (", $select_conds) .") ";

  if( isset($select_order) )                                 # order ----------
    $SQL .= " ORDER BY $select_order";

  if( isset($select_group) )                                 # group by -------
    $SQL .= " GROUP BY $select_group";

if( $debug ) {
 huh($SQL);
}


  # get result --------------------------
  $db->query($SQL);

  while( $db->next_record() ) 
    $arr[] = unpack_id($db->f(id));
  
//p_arr_m($arr);
  return $arr;           
}  
  

// returns array of item ids matching the conditions in right order
  # items_cond - SQL string added to WHERE clause to item table query
function GetItemAppIds($fields, $db, $slice_id, $conditions, 
                       $pubdate_order="DESC", $order_fld="", $order_dir="", 
                       $items_cond="", $exact=true ) {

  $p_slice_id = q_pack_id($slice_id);
  
  if( isset($conditions) AND is_array($conditions)) {
    $set=0;
    $where = "";
    reset( $conditions );

    while( list( $fid, $val ) = each($conditions) ) {
      if( !$fields[$fid] )    // bad condition - field not exist
        continue;

# huh("list( $fid, $val )");
//p_arr_m($fields);
      if( $fields[$fid][in_item_tbl] )
        $where .= " AND (". $fields[$fid][in_item_tbl] .
                  ($exact ? "='$val')": " LIKE '%$val%')");
       else {
        $content_fld = ($fields[$fid][text_stored] ? "text" : "number");
        $SQL="SELECT item_id FROM content, item 
               WHERE content.item_id = item.id
                 AND item.slice_id = '$p_slice_id'
                 AND content.field_id = '". $fields[$fid][id] ."'
                 AND ($content_fld". ($exact ? "='$val')": " LIKE '%$val%')");
# huh( $SQL );
        $db->query($SQL);
        while( $db->next_record() ) {
          $posible[unpack_id($db->f(item_id))] .= '.';
        }  
        $set++;
      }  
    }

# p_arr_m($posible);
    
# huh("WHERE: $where");
//    if($where != "" )
//      $where .= ")";
  }    
      
  $de = getdate(time());
  $item_SQL = "SELECT item.id as id FROM item ";
  if( $order_fld AND !$fields[$order_fld][in_item_tbl] ) {
    $order_content_fld = ($fields[$order_fld][text_stored] ? "text" : "number");
    $item_SQL .= " LEFT JOIN content ON item.id=content.item_id ";
    $add_where = " content.field_id='$order_fld' AND ";
    
#    $item_SQL .= " LEFT JOIN content ON item.id=content.item_id
#                   LEFT JOIN constant ON content.$order_content_fld=constant.value ";
  }                 
  $item_SQL .= " WHERE $add_where (slice_id='$p_slice_id' AND ";
  $item_SQL .= ( $items_cond ? $items_cond.")" : 
                "publish_date <= '". mktime(23,59,59,$de[mon],$de[mday],$de[year]). "' AND ".  //if you change bounds, change it in table.php3 too
                "expiry_date > '". mktime(0,0,0,$de[mon],$de[mday],$de[year]). "' AND ".             //if you change bounds, change it in table.php3 too
                "status_code=1) "); // construct SQL query
  $item_SQL .= $where;
                

  if( $order_fld AND !$fields[$order_fld][in_item_tbl] )
    $item_SQL .= " ORDER BY content.$order_content_fld $order_dir, publish_date $pubdate_order";
   else 
    $item_SQL .= " ORDER BY " . ($order_fld ? "$order_fld $order_dir," : ""). " publish_date $pubdate_order";

//  $item_SQL .= " LIMIT 0,100";

# huh($item_SQL);
  $db->query($item_SQL);     

  if( $set ) {    # just for speed up the processing
    while( $db->next_record() ) {
      if( strlen($posible[$unid = unpack_id($db->f(id))]) == $set)   #all conditions passed
        $arr[] = $unid;
    }
  } else  {
    while( $db->next_record() ) 
      $arr[] = unpack_id($db->f(id));
  }    
  
  return $arr;           
}

# ----------- Easy query -------- parse query functions first

# test for closed parenthes
function test_for_closed($search) {
  $zavorky=0;
  $uvozovky=0;
  for ($i=0; $i< strlen($search); $i++){
    switch ($search[$i]) {
      case "(" : $zavorky++;
                 break;
      case ")" : $zavorky--;
            		 break;
      case "\"" : 
                  if ($uvozovky==1)
                    $uvozovky--;
     		          else
                    $uvozovky++;
      		        break;	
    }
  }
  $retval = $zavorky+$uvozovky;
  return $retval;
}

# Prepares query
#   - replaces + and - sing with AND and NOT
#   - replaces wildcards * and ?
function arrange_query($search) {
  # make case insenzitive
  $search = eregi_replace(" AND "," and ", $search);
  $search = eregi_replace(" OR "," or ", $search);
  $search = eregi_replace(" NOT "," not ",$search);
  $retstr = "";
  for ($i=0; $i<strlen($search); $i++) {
    switch($search[$i]) {
      case "\"" : if ($uvozovky == 1) { $uvozovky--; }
                  else { $uvozovky++; }
                  $retstr = $retstr . $search[$i]; 
                  break;  
      case "+" : if ($uvozovky == 0) { $retstr = $retstr . " and "; }
                  else { $retstr = $retstr . $search[$i]; }
                  break;
      case "-" : if ($uvozovky == 0) 
                    { $retstr = $retstr . " not "; }
                  else { $retstr = $retstr . $search[$i]; }
                  break;                                    
      case "(" : break;
      case ")" : break;
      case "*" : if ($uvozovky == 0) { $retstr = $retstr . "%"; }
                 else { $retstr = $retstr . "*"; }
                 break;
      case "?" : if ($uvozovky == 0) { $retstr = $retstr . "_"; }
                 else { $retstr = $retstr . "?"; }
                 break;
      default : $retstr = $retstr . $search[$i];
    }
  }
  $retstr = ereg_replace("([[:blank:]]+)"," ", $retstr);
  return $retstr;
}

function parse_query($search, $default_op="and") {
  $terms=array();
  $dummy=$search;
  $strtype=1; 

  do {
    if ($dummy[0]=="\"") {
      $strtype=0;
      $dummy=substr($dummy, 1, strlen($dummy));
      $dummy2=substr($dummy, 0, strpos($dummy, "\"")+1);
      $dummy2="\"".$dummy2;
      if (strpos($dummy, "\"")+1 == strlen($dummy)) {
        $dummy2 = "\"". $dummy; $dummy = ""; 
      } else {
        $dummy=substr($dummy, strpos($dummy,  "\" ")+2, strlen($dummy));   
      }
      $dummy2 = ereg_replace("\"","",$dummy2);
# tady to ma nejaky problemy, s tema zavorkama to beha neunosne pomalu
//    } elseif ($dummy[0]=="(") {
//      $dummy=substr($dummy, 1, strlen($dummy));
//      $dummy2="(";
//    } elseif ($dummy[0]==")") {
//      $dummy=substr($dummy, 1, strlen($dummy));
//      $dummy2=")";
    } else {
      $dummy2=substr($dummy, 0, strpos($dummy, " "));
      switch ($dummy2) {
        case "and" :
        case "not" :
        case "or" : $strtype=1;
                      break;
        default : if ($strtype != 1) { $terms[]=$default_op; } else { $strtype = 0; } 
      }   
      if (strpos($dummy, " ") != false) {
        $dummy=substr($dummy, strpos($dummy, " ")+1, strlen($dummy));
      } else { $dummy2=$dummy; $dummy=""; }      
    }
    $terms[]=$dummy2;
  }
  while (strlen($dummy)!=0);
  return $terms;
}

# creates SQL query
function build_sql_query($searchterms, $field) {
  reset($searchterms);
  $retstr = "";
  $notcls = 0; $typecls=0;
  while (current($searchterms)) {
    switch (current($searchterms)) {
      case "and" : if ($typecls==0) { $retstr = $retstr . " AND "; $typecls=1; }
                 next($searchterms);
                 break;
      case "or" : if ($typecls==0) { $retstr = $retstr . " OR "; $typecls=1; }
                    next($searchterms);
                    break;
      case "not" : if ($typecls==0) { 
                    $retstr = $retstr . " AND "; $typecls=1;
                    $notcls = 1;
                  }
                  next($searchterms);
                  break;
      case "(" : $retstr = $retstr . "(";
                 break;
      case ")" : $retstr = $retstr . ")";
                 break; 
      default : if ($notcls==1) { $retstr = $retstr . "(". $field . " NOT LIKE '%" . current($searchterms) . "%')"; }
                else { $retstr = $retstr . "(". $field . " LIKE '%" . current($searchterms) . "%')"; }
                $notcls = 0; $typecls=0;
                next($searchterms); 
    }
  }
  if ($typecls != 0) { $retstr=""; }
  return  $retstr;
}


function GetIDs_EasyQuery($fields, $db, $p_slice_id, $srch_fld, $from, $to,
                          $query, $relevance=false) {

  $in = "";
  $delim = "";
  $field_no = 0;

  # prepare query
  $search=trim(stripslashes(rawurldecode($query)));
	$query = str_replace("\\", "\\\\", $query);
	$query = str_replace("%", "\%", $query);
	$query = str_replace("_", "\_", $query);
	$query = str_replace("'", "\'", $query);
  
  if (test_for_closed($search) != 0) 
   return false;
  $search = arrange_query($search);
  $myqueryterms = parse_query($search);

  $sqlstring=build_sql_query($myqueryterms, "text"); // "concat(' ',text)"); // add space to begining for better word matching

  if( trim($sqlstring) == "" )
    $sqlstring = "1=1";
    
  if( !isset($srch_fld) OR !is_array($srch_fld) OR !$query )
    $field_id_cond = "1=1";               # no fields to search - all rows

  reset($srch_fld);
  while( list( $fid, $val ) = each($srch_fld) ) {
    if( !$fields[$fid] )     # bad condition - field not exist in this slice
      continue;
    $in .= $delim. "'$fid'";
    $delim=',';
    $field_no++;
  }

  if( $field_no == 0 )
    $field_id_cond = "1=1";  # bad condition - field not exist in this slice
   else
    $field_id_cond = "field_id IN ( $in )";
    
  # from date
  if( ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{4}) *$", $from, $part))
    $cond = " AND (publish_date >= '". mktime(0,0,0,$part[1],$part[2],$part[3]). "') ";
  elseif( ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{2}) *$", $from, $part))
    $cond = " AND (publish_date >= '". mktime(0,0,0,$part[1],$part[2],"20".$part[3]). "') ";

  # to date     
  if( ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{4}) *$", $to, $part))
    $cond .= " AND (publish_date <= '". mktime(23,59,59,$part[1],$part[2],$part[3]). "') ";
  elseif( ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{2}) *$", $to, $part))
    $cond .= " AND (publish_date <= '". mktime(23,59,59,$part[1],$part[2],"20".$part[3]). "') ";
       
  $distinct = ( $relevance ? "" : "DISTINCT" );
  
  $SQL = "SELECT $distinct id from item, content WHERE item.id=content.item_id
            AND slice_id='$p_slice_id'
            AND ($field_id_cond)
            AND ($sqlstring)
            AND status_code='1'
            AND expiry_date > '". time() ."'
            $cond 
            ORDER BY publish_date DESC";

  $db->query($SQL);

  # search by relevance? (not at all - just count the fields, where the word appears)
  if( $relevance ) {
    $count=0;
    if( $db->next_record() )      #preset first old id
      $oldid = $db->f(id);
      
    while( $db->next_record() ) {
      if( $oldid != $db->f(id)) {
        $tmp[$count][] = unpack_id($db->f(id));
        $oldid = $db->f(id);
        $count=0;
      }
      else  {
        $count++;
      }  
    }    
    $tmp[$count][] = unpack_id($oldid);  # last value isn't stored

//print_r($tmp);

    # put the array one after one - first goes the best one
    for( $i=$field_no; $i > 0; $i-- )
      $ret = array_merge( $tmp[$i], $tmp[$i-1] );
  } else {
    while( $db->next_record() )
      $ret[] = unpack_id($db->f(id));
  }    
    
  return $ret;  
    
}


# ----------- Massive query string function

# cuts quotations from begin and end
function CutQuote($foo) {
	if (SubStr($foo,0,1)=='"')
		$foo=SubStr($foo,1,StrLen($foo)-1);
	if (SubStr($foo,-1,1)=='"')
		$foo=SubStr($foo,0,StrLen($foo)-1);
	return $foo;	
}

# cuts quotations from begin and end
function CutHash($foo) {
	if (SubStr($foo,0,1)=='#')
		$foo=SubStr($foo,1,StrLen($foo)-1);
	if (SubStr($foo,-1,1)=='#')
		$foo=SubStr($foo,0,StrLen($foo)-1);
	return $foo;	
}

# search value for date begins and ends with '#'
function InputIsDate($foo) {
	if ((SubStr($foo,0,1)=='#') and (SubStr($foo,-1,1)=='#'))
		return true;
	else 
		return false;
}

# match number of query left and right brackets
function BracketsMatch ($query) {
	$leftq=$query;
	$left=0;
	while ($leftq=StrStr($leftq,'(')) {
		$left++;
		$leftq=SubStr($leftq,1,StrLen($leftq)-1);
	}
	$rightq=$query;
	$right=0;
	while ($rightq=StrStr($rightq,')')) {
		$right++;
		$rightq=SubStr($rightq,1,StrLen($rightq)-1);
	}
	if ($left==$right)	
		return true;
	else
		return false;
}
	
function ExtSearch ($query,$p_slice_id,$debug=0) {
  set_time_limit(180);
  
  # 1) query preparation
  if ($debug)
    echo "query-$query<br>" ;

  
	if (!BracketsMatch($query))
 		return (L_BRACKETS_ERR . $query);
	$query = Trim($query);
	$query = str_replace("\\", "\\\\", $query);
	$query = str_replace("%", "\%", $query);
	$query = str_replace("*", "%", $query);	
	$query = str_replace("'", "\'", $query);
  	
  # 2) parsing query for basis conditions (bool operators, left bracket, field name, 
  #    comparison operator, value, right bracket)
  
  if ($debug)
    echo "queryprep-$query<br><hr>";
  
	$istrue=true;	
	for ($i=0;$istrue;$i++) {
		if (Eregi("^(.*)( and | or | not )(.*)$",$query,$part)) {
			$firstpart=Trim($part[3]);
			$field[$i]["boolop"]=Trim($part[2]);
			$query=$part[1];
		} else {
			$firstpart=Trim($query);
			$field[$i]["boolop"]='';			
			$istrue=false;	
		}

		if (Eregi("^([\(*|[[:space:]]*]*)[[:space:]]*([_\.1-9A-Za-z]{16})[[:space:]]*(<=|>=|<>|:|=|<|>)[[:space:]]*([^)]+)[[:space:]]*([\)*|[[:space:]]*]*)$",$firstpart,$part)) {
			$field[$i]["leftbrack"]=Trim($part[1]);
			$field[$i]["name"]=$part[2];
			$field[$i]["matchop"]=$part[3];
			$field[$i]["value"]=CutQuote(Trim($part[4]));
			$field[$i]["rightbrack"]=Trim($part[5]);
		} else {
			return "Bad syntax near $firstpart!";
		}
				
	}	
  
  if ($debug)
   echo p_arr_m ($field)."<hr>";
  
  # 3) find informations needed for search (table, text vs numerical)
  
	$db = new DB_AA;
	$sql="SELECT id,in_item_tbl,text_stored FROM field where slice_id='".$p_slice_id."'";
	$db->query($sql);
	while ($db->next_record()) {
		$slicefield[$db->f(id)]["id"]=$db->f(id);
		if ($db->f(in_item_tbl)=='') {
			$slicefield[$db->f(id)]["table"]= 'content';
			if ($db->f(text_stored)=='1') 
				$slicefield[$db->f(id)]["field"]= 'text';
			else
				$slicefield[$db->f(id)]["field"]= 'number';		
		} else {
			$slicefield[$db->f(id)]["table"]= 'item';
			$slicefield[$db->f(id)]["field"]= $db->f(in_item_tbl);
		}		
	}
	$db->free();
  	
  # 4) change of bool and comparison operators, field names,
  #    transformation date to number)
  
	for ($i=0;$i<count($field);$i++) {
		if ($slicefield[$field[$i]["name"]]["id"]) { # field name exists in this slice

			$field[$i]["value"] = str_replace("_", "\_", $field[$i]["value"]);

			if ($field[$i]["matchop"]==":") { # like expresion
				$field[$i]["matchop"]="like";
				$field[$i]["value"] = str_replace("?", "_", $field[$i]["value"]);
				$field[$i]["value"] = '%'.$field[$i]["value"].'%';
			}

			if (StrToUpper($field[$i]["boolop"])=="NOT") { # conversion to PHP negation
				$field[$i]["boolop"]="and !";
			}

			if (InputIsDate($field[$i]["value"])) { # conversion date value to seconds
        $time = "";
        if( strstr( $field[$i]["matchop"],"<" ))  # to search whole end day
          $time = "23:59:59";
				$field[$i]["value"]= userdate2sec( CutHash($field[$i]["value"]),$time );
      }  
			
		} else {
			return "Field ".$field[$i]["name"]." doesn't exist in this slice!";
		}
	}
  
  if ($debug)
    echo p_arr_m ($field)."<hr>";	
  
  # search id for each basis condition
  
	for ($i=0;$i<count($field);$i++) {
		if ($slicefield[$field[$i]["name"]]["table"]=='item')
			$sql="SELECT id FROM item 
             WHERE slice_id='".$p_slice_id."' 
               AND status_code=1
               AND ".$slicefield[$field[$i]["name"]]["field"]." ".$field[$i]["matchop"]." '".$field[$i]["value"]."' 
               ORDER BY publish_date desc";
		else 
			$sql="SELECT b.id FROM ".$slicefield[$field[$i]["name"]]["table"]." a,item b
				     WHERE b.id=a.item_id 
               AND b.slice_id='".$p_slice_id."' 
               AND b.status_code=1
               AND a.".$slicefield[$field[$i]["name"]]["field"]." ".$field[$i]["matchop"]." '".$field[$i]["value"]."' AND a.field_id='".$field[$i]["name"]."'
               ORDER BY b.publish_date desc";

		$db->query($sql);
		while ($db->next_record()) {
			$id=unpack_id($db->f(id));
			$possible[$id][$i]=1;
		}
		$db->free();
  
    if ($debug)
      echo $sql."<br>";		
	}
  
  if ($debug) echo "<hr>".p_arr_m ($possible)."<hr>";
  
  # search for ids matching all conditions
	if (Is_Array($possible)) {
		Reset($possible);
		While(Current($possible)) {
			$id=Key($possible);
			$condition="if (";
			for ($i=count($field)-1;$i>=0;$i--) {
				$condition.=" ".$field[$i][boolop]." ".$field[$i][leftbrack]." \$possible[\"".$id."\"][".$i."] ".$field[$i][rightbrack]." ";			
			}
			$condition.=") \$res[]=\$id;";
if ($debug) echo "$condition<br>";
			Eval($condition);
			Next($possible);
		}
		return $res;
	} else {
 		return false;   //  L_NO_ITEM;		
 	}
}; # search function end

/*
$Log$
Revision 1.19  2001/10/04 13:56:47  honzam
New BETWEEN operator, debug listings on demand

Revision 1.18  2001/09/27 16:08:51  honzam
Better support for dates in "<=", ">" comparison

Revision 1.17  2001/08/02 20:05:30  honzam
new possibility to display expired items (for archves, ...)

Revision 1.16  2001/07/31 16:32:51  honzam
Added '-' operator modifier for relative time conditions. The operator was implemented to view definition too (se_view.php3)

Revision 1.15  2001/07/09 17:47:41  honzam
Operator date modifiers fixed

Revision 1.14  2001/06/15 20:05:16  honzam
little search imrovements and bugfixes

Revision 1.13  2001/05/27 20:59:30  honzam
fixed problem with doubled item from search

Revision 1.12  2001/05/18 13:55:04  honzam
New View feature, new and improved search function (QueryIDs)

Revision 1.11  2001/05/10 09:44:35  honzam
Extended search end date problem fixed (now it searches whole end date)

Revision 1.10  2001/03/30 11:54:35  honzam
offline filling bug and others small bugs fixed

Revision 1.9  2001/03/20 16:10:37  honzam
Standardized content management for items - filler, itemedit, offline, feeding
Better feeding support

Revision 1.8  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...

Revision 1.7  2001/02/20 13:25:16  honzam
Better search functions, bugfix on show on alias, constant definitions ...

Revision 1.5  2001/01/22 17:32:49  honzam
pagecache, logs, bugfixes (see CHANGES from v1.5.2 to v1.5.3)

Revision 1.4  2000/12/23 19:56:50  honzam
Multiple fulltext item view on one page, bugfixes from merge v1.2.3 to v1.5.2

Revision 1.3  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.2  2000/08/17 15:07:27  honzam
Searching only in approved items

Revision 1.1.1.1  2000/06/21 18:40:47  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:26  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.10  2000/06/12 19:58:37  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.9  2000/05/30 09:11:39  honzama
MySQL permissions upadted and completed.

Revision 1.8  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>