<?php
if (!defined ("SEARCHLIB_INCLUDED"))
   	  define ("SEARCHLIB_INCLUDED",1);
else return;

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

require $GLOBALS[AA_INC_PATH]."sql_parser.php3";
require $GLOBALS[AA_INC_PATH]."zids.php3";

function GetWhereExp( $field, $operator, $querystring ) {

  # query string could be slashed - sometimes :-(
  $querystring = stripslashes( $querystring );

  if( $GLOBALS['debug'] )
    echo "<br>GetWhereExp( $field, $operator, $querystring )";

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

  $querystring =  (string) $querystring;   // to be able to do string operations

  switch( $operator ) {
    case 'LIKE':   
    case 'RLIKE':  
    case 'LLIKE':  
    case 'XLIKE':
    case '=':
      $querystring = str_replace('*', '%', trim($querystring)); 
      $querystring = str_replace('?', '_', $querystring); 
    	$syntax = new Syntax($field, $operator, lex( trim($querystring) ) );
      $ret = $syntax->S();
      if( $ret == "_SYNTAX_ERROR" ) {
        if( $GLOBALS['debug'] )
          echo "<br>Query syntax error: ". $GLOBALS['syntax_error'];
        return "1=1";
      }
      return ( $ret ? " ($ret) " : "1=1" );    
    case 'BETWEEN': 
      $arr = explode( ",", $querystring );
      return ( " (($field >= $arr[0]) AND ($field <= $arr[1])) ");
    case 'ISNULL': 
      return ( " (($field IS NULL) OR ($field='0')) ");
    case 'NOTNULL':
      return ( " (($field IS NOT NULL) AND ($field<>'')) ");
    default:
      $str = ( ($querystring[0] == '"') OR ($querystring[0] == "'") ) ? 
                                 substr( $querystring, 1, -1 ) : $querystring ;
      return " ($field $operator '$str') ";
  }  
}  

// -------------------------------------------------------------------------------------------

// show info about non-existing fields in all given slices
function ProoveFieldNames ($slices, $conds) {

    if( ! (isset($slices) AND is_array($slices) AND isset($conds) AND is_array($conds)) )
      return;

    global $conds_not_field_names;
    if (!is_array ($slices) || !is_array ($conds)) return;
    $db = new DB_AA;
    reset ($slices);
    while (list(,$slice_id) = each ($slices)) {
        $db->query("SELECT * FROM field WHERE slice_id='".q_pack_id($slice_id)."'");
        while ($db->next_record())
            $slicefields[$db->f("id")] = 1;
        reset ($conds);
        while (list (,$cond) = each ($conds)) {
            if( ! (isset($cond) AND is_array($cond)) )
              continue;
            reset ($cond);
            while (list ($key) = each ($cond)) 
                if (!$conds_not_field_names [$key] && !isset ($slicefields[$key]))
                    echo "Field <b>$key</b> does not exist in slice <b>$slice_id</b> (".q_pack_id($slice_id).").<br>";
        }
    }
}   

// -------------------------------------------------------------------------------------------

/* parses the conds from a multiple select box: e.g.
    conds[1][value][0] = 'apple'
    conds[1][value][1] = 'cherry'
    conds[1][valuejoin] = 'AND'
    
    => creates two conds: conds[7] and conds[8] for example,
        fill conds[7][value] = 'apple', conds[8][value] = 'cherry'
        
    with conds[1][valuejoin] = 'OR' only changes conds[1][value] to '"apple" OR "cherry"'
    (c) Jakub, May 2002
*/

function ParseMultiSelectConds (&$conds) 
{
    if (!is_array ($conds)) return;
    reset ($conds);
    while (list ($icond, $cond) = each ($conds)) {
        if (is_array ($cond['value'])) {
            if (!$cond['valuejoin']) {
                echo "ERROR in conds: when using [value][], you must use [valuejoin]='OR'|'AND' also!";
                return;
            }
            if ($cond['valuejoin'] == 'OR') {
                $values = "";
                reset ($cond['value']);
                while (list (,$val) = each ($cond['value'])) {
                    if ($values != "") $values .= " OR ";
                    $values .= '"'.addslashes ($val).'"';
                }
                unset ($conds[$icond]['valuejoin']);
                $conds[$icond]['value'] = $values;
            }
            else if ($cond['valuejoin'] == 'AND') {
                reset ($cond['value']);
                while (list (,$val) = each ($cond['value'])) {
                    $newcond = $cond;
                    $newcond['value'] = $val;
                    unset ($newcond['valuejoin']);
                    $conds[] = $newcond;
                }
                unset ($conds[$icond]);
            }
            else echo "ERROR in conds: [valuejoin] must be set to 'OR' or 'AND'.";
        }
    }
}            

# function finds group_id in field.input_show_func parameter
function GetConstantGroup( $input_show_func ) {
  $INPUT_SHOW_FUNC_TYPES = inputShowFuncTypes();
  list($fnc,$constgroup) = explode(':', $input_show_func);

  # does this field use constants?
  if( strstr($INPUT_SHOW_FUNC_TYPES[$fnc]['paramformat'], 'const') AND
      (substr($constgroup,0,7) != "#sLiCe-") )  # prefix indicates select from items, not constants
    return $constgroup;          # yes
  return false;
}  


// -------------------------------------------------------------------------------------------

/* Function: QueryIDs
   Purpose:  Finds item IDs for items to be shown in a slice / view
   Params:   $conds -- search conditions (see FAQ)
             $sort -- sort fields (see FAQ)
             $slices -- array of slices in which to look for items
             $slice_id -- older parameter, used only when $slices is not set,
                          translated to $slices = array($slice_id)
             $neverAllItems -- if no conds[] apply (all are wrong formatted or empty),
                               generates an empty set
             $restrict_ids -- if you want to choose only from a set of items
                              (used by E-mail Alerts and related item view (for
                               sorting and eliminating of expired items))
                              ids are packed but not quoted in $restrict_ids or short
             $defaultCondsOperator
             $use_cache -- if set, the cache is searched , if the result isn't 
                           already known. If not, the result is found and stored into 
                           cache.

   Globals:  $debug=1 -- many debug messages
             $debugfields=1 -- useful mainly for multiple slices mode -- views info about field_ids
                used in conds[] but not existing in some of the slices
             $QueryIDsCount -- set to the count of IDs returned
             $nocache -- do not use cache, even if use_cache is set
*/

# Get a zids object that contains a list of the ids that match the query.
function QueryZIDs($fields, $slice_id, $conds, $sort="", $group_by="", 
    $type="ACTIVE", $slices="", $neverAllItems=0, $restrict_zids=false, 
    $defaultCondsOperator = "LIKE", $use_cache=false ) {
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
  # sort[0][category........]='1';    // order items by category priority - ascending
  # sort[0][category........]='9';    // order items by category priority - descending

  # type sets status, pub_date and expiry_date according to specified type:
  # ACTIVE | EXPIRED | PENDING | HOLDING | TRASH | ALL
  # if you want specify it yourselves in conds, set type to ALL

  # select * from item, content as c1, content as c2 where item.id=c1.item_id AND item.id=c2.item_id AND       c1.field_id IN ('fulltext........', 'abstract..........') AND c2.field_id = 'keywords........' AND c1.text like '%eufonie%' AND c2.text like '%eufonie%' AND item.highlight = '1';

  global $debug;                 # displays debug messages
  global $nocache;               # do not use cache, if set
  global $conds_not_field_names; # list of special conds[] indexes (defined in constants.php3)
  global $QueryIDsCount;

  $db = new DB_AA;

  $cache = new PageCache($db, CACHE_TTL, CACHE_PURGE_FREQ);


  if( $use_cache AND !$nocache ) {
    #create keystring from values, which exactly identifies resulting content
    $keystr = $slice_id .
              serialize($conds).
              serialize($sort).
              $group_by. $type.
              serialize($slices).
              $neverAllItems.
//              ((isset($restrict_ids) && is_array($restrict_ids)) ? serialize($restrict_ids) : "").
              ((isset($restrict_zids) && is_object($restrict_zids)) ? serialize($restrict_zids) : "").
              $defaultCondsOperator;

    if( $res = $cache->get($keystr)) {
      $zids = unserialize($res);
      $QueryIDsCount = $zids->count();
      if( $debug )
        echo "<br>Cache HIT - return $QueryIDsCount IDs<br>";
      return $zids;
    }
  }

  if ($GLOBALS[debugfields] || $debug) {
      if ($slices) ProoveFieldNames ($slices, $conds);
      else ProoveFieldNames (array ($slice_id), $conds);
  }

  ParseMultiSelectConds ($conds);
  ParseEasyConds ($conds, $defaultCondsOperator);

  if( $debug ) {
	huhl("Conds=",$conds,"Sort=",$sort,
		"Group by=",$group_by,"Slices=",$slices);
  }

  # parse conditions ----------------------------------
  if( is_array($conds)) {
    reset($conds);
    $tbl_count=0;
    while( list( , $cond) = each( $conds )) {

      # fill arrays according to this condition
      reset($cond);
      $field_count = 0;
      $cond_flds   = '';
      while( list( $fid, $v) = each( $cond )) {
        if( $conds_not_field_names[$fid] )
          continue;           # it is not field_id parameters - skip it for now

        if( !$fields[$fid] OR $v=="")
          continue;            # bad field_id or not defined condition - skip

        if( $fields[$fid]['in_item_tbl'] ) {   # field is stored in table 'item'
          $select_conds[] = GetWhereExp( 'item.'.$fields[$fid]['in_item_tbl'],
                                          $cond['operator'], $cond['value'] );
          if( $fid == 'expiry_date.....' )
            $ignore_expiry_date = true;
        } else {
          $cond_flds .= ( ($field_count++>0) ? ',' : "" ). "'$fid'";
          // will not work with one condition for text and number fields
          $store = ($fields[$fid]['text_stored'] ? "text" : "number");
        }
      }
      if( $cond_flds != '' ) {
        $tbl = 'c'.$tbl_count++;
        # fill arrays to be able construct select command
        $select_conds[] = GetWhereExp( "$tbl.$store",
                                          $cond['operator'], $cond['value'] );
        if ($field_count>1) {
          $select_tabs[] = "LEFT JOIN content as $tbl
                                   ON ($tbl.item_id=item.id
                                   AND ($tbl.field_id IN ($cond_flds) OR $tbl.field_id is NULL))";
        } else {
          $select_tabs[] = "LEFT JOIN content as $tbl
                                   ON ($tbl.item_id=item.id
                                   AND ($tbl.field_id=$cond_flds OR $tbl.field_id is NULL))";
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
          # fill arrays to be able construct select command
          $select_tabs[] = "LEFT JOIN content as $tbl
                                   ON ($tbl.item_id=item.id
                                   AND ($tbl.field_id='$fid' OR $tbl.field_id is NULL))";
                        # mark this field as sortable (store without apostrofs)
          $sortable[$fid] = $tbl;
        }

        # join constant table if we want to sort by priority
        $direction = current( $srt );
        if( stristr($direction,'1') OR stristr($direction,'9') ) { # sort by priority
          if ( !($constgroup = GetConstantGroup($fields[$fid]['input_show_func']) ))
            continue;   # no constant group defined - can't assign priority

          $tbl = 'o'.$tbl_count++;
          # fill arrays to be able construct select command
          $select_tabs[] = "LEFT JOIN constant as $tbl
                                   ON ($tbl.value=". $sortable[$fid] .".text
                                   AND ($tbl.group_id='$constgroup'
                                        OR $tbl.group_id is NULL))";
                        # mark this field as sortable (store without apostrofs)

          # fill arrays according to this sort specification
          $select_order .= $delim .$tbl. ".pri";
          if( stristr($direction,'9') )
            $select_order .= " DESC";
        } else {                                                   # sort by value
          $store = ($fields[$fid]['text_stored'] ? "text" : "number");
          # fill arrays according to this sort specification
          $select_order .= $delim .$sortable[$fid]. ".$store";
          if( stristr(current( $srt ), 'd'))
            $select_order .= " DESC";
        }
        $delim=',';
      }
    }
  }

  # parse group by parameter ----------------------------
  if( isset($group_by) AND is_array($group_by)) {
    reset ($group_by);
    $delim='';

  if( $debug ) echo "<br>Group<br>";

      while( list( $fid, ) = each( $group_by )) {
  if( $debug ) echo "<br>-$fid-<br>";
        if( !$fields[$fid] )  # bad field_id - skip
          continue;
  if( $debug ) echo "<br>OK<br>";

      if( $fields[$fid]['in_item_tbl'] ) {   # field is stored in table 'item'
        $select_group .= $delim . 'item.' . $fields[$fid]['in_item_tbl'];
        $delim=',';
      } else {
        if( !$sortable[ $fid ] ) {           # this field is not joined, yet
          $tbl = 'c'.$tbl_count++;
          # fill arrays to be able construce select command
          $select_tabs[] = "LEFT JOIN content as $tbl
                                   ON ($tbl.item_id=item.id
                                   AND ($tbl.field_id='$fid' OR $tbl.field_id is NULL))";
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

  if( $debug )
	huhl("QueryZIDs:slice_id=",$slice_id,"  select_tabs=",$select_tabs,
		"  select_conds=",$select_conds,"  select_order",$select_order,
		"  select_group=",$select_group);

  # construct query --------------------------
  $SQL = "SELECT DISTINCT item.id FROM item ";
  if( isset($select_tabs) AND is_array($select_tabs))
    $SQL .= " ". implode (" ", $select_tabs);

  $SQL .= " WHERE ";                                         # slice ----------

  if (!$slices) {
      if ($slice_id)
           $slices = array ($slice_id);
  }

  if( count($slices) >= 1 ) {
      reset ($slices);
      $slicesText = "";
      while (list (,$slice) = each ($slices)) {
          if ($slicesText != "") $slicesText .= ",";
          $slicesText .= "'".q_pack_id($slice)."'";
      }
      $SQL .= " item.slice_id IN ( $slicesText ) AND ";
  }
  if (is_object($restrict_zids)) {
    if ($restrict_zids->count() == 0) 
        return new zids(); # restrict_zids defined but empty - no result

    $SQL .= " ".$restrict_zids->sqlin() ." AND ";
  } else {
    # slice(s) or item_ids MUST be specified (in order we can get answer in limited time)
    if (!$slicesText) return new zids();  
  }


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

  if( isset($select_conds) AND is_array($select_conds))      # conditions -----
    $SQL .= " AND (" . implode (") AND (", $select_conds) .") ";

  if( isset($select_order) )                                 # order ----------
    $SQL .= " ORDER BY $select_order";

  if( isset($select_group) )                                 # group by -------
    $SQL .= " GROUP BY $select_group";

  // if neverAllItems is set, return empty set if no conds[] are used
  if (!is_array ($select_conds) && $neverAllItems)
    $arr = array ();

  else {
  # get result --------------------------
    if( $debug )
      $db->dquery($SQL);
    else
      $db->query($SQL);

    while( $db->next_record() )
//      $arr[] = unpack_id128($db->f(id));
      $arr[] = $db->f(id);
  }

  $QueryIDsCount = count($arr);

  $zids = new zids($arr,"p");

  if( $use_cache AND !$nocache )
    $cache->store($keystr, serialize($zids), "slice_id=$slice_id,slice_id=".
                                         join(',slice_id=', $slices));

  return $zids;
}

// -------------------------------------------------------------------------------------------

/* Function: QueryDiscIDs
   Purpose:  Finds discussion items IDs to be shown by the aa/discussion.php3 script
*/

function QueryDiscIDs($slice_id, $conds, $sort, $slices ) {
  # parameter format example:
  # conds[0][discussion][subject] = 1;   // discussion fields are preceded by [discussion]
  # sort[0][category........]='a';    // order items by category ascending

    if (!$slice_id && !$slices) return;

    $fields = array ("date","subject","author","e_mail","body","state","flag","url_address",
        "url_description", "remote_addr", "free1", "free2");

    global $debug;          # displays debug messages

    $db = new DB_AA;
    if( $debug ) {
      echo "<br>Conds:<br>";
      p_arr_m($conds);
      echo "<br><br>Sort:<br>";
      p_arr_m($sort);
      echo "<br><br>Slices:<br>";
      p_arr_m($slices);
    }
  
    # parse conditions ----------------------------------
    if (is_array($conds)) {
        reset($conds); 
        $tbl_count=0;
        while( list( , $cond) = each( $conds )) {
            if( !is_array($cond) OR !$cond['discussion']
                              OR !$cond['operator'] OR ($cond['value']==""))
              continue;             # bad condition - ignore
    
            # fill arrays according to this condition
            reset($cond); 
            while( list( $fid, $vv) = each( $cond )) 
                if( $fid == 'discussion' ) {
                    unset ($select_cond);
                    while( list ($fid) = each ($vv)) {
                        if( my_in_array ($fid,$fields) AND $cond['value'] > "" ) {
                            $select_cond[] = GetWhereExp( "discussion.$fid",
                                              $cond['operator'], $cond['value'] );
                        }
                    }
                    if (is_array ($select_cond))
                        $select_conds[] = join ($select_cond, " OR ");
                }
        }
    }  
/*
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
          $select_tabs[] = "LEFT JOIN content as $tbl 
                                   ON ($tbl.item_id=item.id 
                                   AND ($tbl.field_id='$fid' OR $tbl.field_id is NULL))";
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
*/

if( $debug ) {
  echo "<br><br>select_conds:";
  print_r($select_conds);
  echo "<br><br>select_order:";
  print_r($select_order);
}  
  
    # construct query --------------------------
    $SQL = "SELECT discussion.id 
            FROM discussion INNER JOIN item ON item.id = discussion.item_id
            WHERE ";
    if( $slices ) {
        $slicesText = "";
        for ($islice = 0; $islice < count($slices); ++$islice) {
            if ($islice) $slicesText .= ",";
            $slicesText .= "'".q_pack_id($slices[$islice])."'";
        }
        $SQL .= " item.slice_id IN ( $slicesText )";
    }
    else if( $slice_id )
        $SQL .= " item.slice_id = '". q_pack_id($slice_id) ."'";
  
    if( isset($select_conds) AND is_array($select_conds))      # conditions -----
        $SQL .= " AND (" . implode (") AND (", $select_conds) .") ";

    if( isset($select_order) )                                 # order ----------
        $SQL .= " ORDER BY $select_order";

    # get result --------------------------
    if( $debug ) 
        $db->dquery($SQL);
    else 
        $db->query($SQL);

    while( $db->next_record() ) 
        $arr[] = unpack_id128($db->f(id));

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
          $posible[unpack_id128($db->f(item_id))] .= '.';
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
      if( strlen($posible[$unid = unpack_id128($db->f(id))]) == $set)   #all conditions passed
        $arr[] = $unid;
    }
  } else  {
    while( $db->next_record() ) 
      $arr[] = unpack_id128($db->f(id));
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
        $tmp[$count][] = unpack_id128($db->f(id));
        $oldid = $db->f(id);
        $count=0;
      }
      else  {
        $count++;
      }  
    }    
    $tmp[$count][] = unpack_id128($oldid);  # last value isn't stored

//print_r($tmp);

    # put the array one after one - first goes the best one
    for( $i=$field_no; $i > 0; $i-- )
      $ret = array_merge( $tmp[$i], $tmp[$i-1] );
  } else {
    while( $db->next_record() )
      $ret[] = unpack_id128($db->f(id));
  }    
    
  return $ret;  
}

?>
