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

function SearchWhere ($search, $srchflds) {
   // $search[keyword]  .. searched string
   // $search[type]     .. AND | OR
   // $search[slice]    .. search in slice id (0 => all)
   // $search[category] .. search in category id 
   // $search[author]   .. search for author id
   // $search[lang]     .. search in language (language_code)
   // $search[from]     .. search items from this time was published
   // $search[to]       .. search items to this time was published
   // $srchflds         .. array of columns to include in search (headline, abstract, full_text, edit_note)

   // Select the search type (True Boolean search comes later)

   $boo = (eregi("^and$", $search[type]) ? "AND" : "OR");
      
   // Escape '%' and '_' (and \n and \?), replace '*' by '%'
   // Probably MySQL specific
   // And - shouldn't be ESCAPE char defined in SQLquery?
   
   
   $search[keyword] = str_replace("%", "\%", $search[keyword]);
   $search[keyword] = str_replace("_", "\_", $search[keyword]);
   $search[keyword] = str_replace("*", "%", $search[keyword]);
   
   // Create the keywords array, words can be grouped by double quotes
   // PHP auto escapes double quotes
   
   // echo ("k: $search[keyword]<br>");
   
   while (preg_match('/^\s*(\\\"[^\\\"]*\\\"|\S+)(.*)/', $search[keyword], $parts)) {
        $keywords[] = str_replace('\"', '', $parts[1]);
        $search[keyword] = $parts[2];
        // echo ("p: $parts[1]<br>");
   }
   
   // Check the rows, that should be searched?
   // Here or outside the function?
   
   // Get counts of array elements
 
   $count["keywords"] = count($keywords);
   $count["srchflds"] = count($srchflds);
   
   if ($count["keywords"] AND !$count["srchflds"]) {
      echo L_ERR_NO_SRCHFLDS."<br>";
      return 0;
   }

   // Build the WHERE parameters for SQL
   if ($count["keywords"] AND $count["srchflds"]) {
     $where = "((status_code=1) AND ((";
     $oprator = "";
     reset($srchflds);
     while( list(,$field) = each($srchflds)) {
       $where .= $operator;
       for ($j = 0; $j < $count["keywords"]; $j++) {
         $where .= $field . " LIKE '%" . $keywords[$j] . "%' ";
         if ($count["keywords"] - $j > 1) {
            $where .= $boo . " ";
         }
       }
       $operator = ") OR (";
     }
     $where .="))) ";
   }  
   else
     $where ="(status_code=1)";

   if($search[category] == "0") $search[category]="";
   if($search[slice] == "0") $search[slice]="";
   if($search[author] == "0") $search[author]="";
   if($search[lang] == "0") $search[lang]="";

   if( eregi("^[0-9a-f]{1,32}$", $search[slice]) AND $search[slice] != "" )
     $where .= " AND slice_id = '". q_pack_id($search[slice]) ."'";
   if( eregi("^[0-9a-f]{1,32}$", $search[category]) AND $search[category] != "" )
     $where .= " AND category_id = '". q_pack_id($search[category]) ."'";
   if( $search[author] != "" )
     $where .= " AND created_by = '". $search[author] ."'";
   if( $search[lang] != "" )
     $where .= " AND language_code = '". $search[lang] ."'";
   if( ($foo=userdate2sec($search[from])) != "" )
     $where .= " AND publish_date >= '$foo'";
   if( ($foo=userdate2sec($search[to],"23:59:59")) != "" )
     $where .= " AND publish_date <= '$foo'";
   return $where;
}


// function called from slice.php3 and make_rss.php3
// makes where clause for compact view of items
function MakeWhere($p_slice_id, $cat, $high) {
  $de = getdate(time());
  $where = "(slice_id='$p_slice_id' AND ".  
           "publish_date <= '". mktime(23,59,59,$de[mon],$de[mday],$de[year]). "' AND ".  //if you change bounds, change it in table.php3 too
           "expiry_date > '". mktime(0,0,0,$de[mon],$de[mday],$de[year]). "' AND ".             //if you change bounds, change it in table.php3 too
           "status_code=1)"; // construct SQL query
  if($high)
    $where .= " AND (highlight=1) ";
  if($cat)
    $where .= " AND (category_id='". q_pack_id($cat) ."') ";
  return $where;  
}

/*function aa_search_db ($where, $retflds) {
  if( !isset($retflds) OR !is_array($retflds))
    echo "No fields in SQL query (searchlib.php3)"; 
  $sql = Join($retflds, ", ");
  $sql = "SELECT " . $sql . " FROM items, fulltexts WHERE fultexts.id = items.master_id AND " . $where;
  return $sql;
}
*/

# -------------------- new functions 1.5.x -------------------------

// returns array of item ids matching the conditions in right order
  # items_cond - SQL string added to WHERE clause to item table query
function GetItemAppIds($fields, $db, $p_slice_id, $conditions, 
                       $pubdate_order="DESC", $odrer_fld="", $order_dir="", 
                       $items_cond="" ) {
  if( isset($conditions) AND is_array($conditions)) {
    $set=0;
    $where = "";
    reset( $conditions );

    while( list( $fid, $val ) = each($conditions) ) {
      if( !$fields[$fid] )    // bad condition - field not exist
        continue;

//huh("list( $fid, $val )");
//p_arr_m($fields);
      if( $fields[$fid][in_item_tbl] )
        $where .= " AND (". $fields[$fid][in_item_tbl] ."='$val')";
       else {
        $content_fld = ($fields[$fid][text_stored] ? "text" : "number");
        $SQL="SELECT item_id FROM content WHERE $content_fld ='$val'";
//huh( $SQL );
        $db->query($SQL);
        while( $db->next_record() ) {
          $posible[unpack_id($db->f(item_id))] .= '.';
        }  
        $set++;
      }  
    }
//huh("WHERE: $where");
//    if($where != "" )
//      $where .= ")";
  }    
      
  $de = getdate(time());
  $item_SQL = "SELECT id FROM item ";
  if( $order_fld AND !$fields[$order_fld][in_item_tbl] ) {
    $order_content_fld = ($fields[$order_fld][text_stored] ? "text" : "number");
    $item_SQL .= " LEFT JOIN content ON item.id=content.item_id
                   LEFT JOIN constant ON content.$order_content_fld=constant.value ";
  }                 
  $item_SQL .= " WHERE (slice_id='$p_slice_id' AND ";
  $item_SQL .= ( $items_cond ? $items_cond.")" : 
                "publish_date <= '". mktime(23,59,59,$de[mon],$de[mday],$de[year]). "' AND ".  //if you change bounds, change it in table.php3 too
                "expiry_date > '". mktime(0,0,0,$de[mon],$de[mday],$de[year]). "' AND ".             //if you change bounds, change it in table.php3 too
                "status_code=1) "); // construct SQL query
  $item_SQL .= $where;
                

  if( $order_fld AND !$fields[$order_fld][in_item_tbl] )
    $item_SQL .= " ORDER BY pri $order_dir, publish_date $pubdate_order";
   else 
    $item_SQL .= " ORDER BY " . ($order_fld ? "$order_fld $order_dir," : ""). " publish_date $pubdate_order";

//  $item_SQL .= " LIMIT 0,100";
                
  $db->query($item_SQL);     

  if( $set ) {    # just for speed up the processing
    while( $db->next_record() ) {
      if( strlen($posible[$unid = unpack_id($db->f(id))]) == $set);   #all conditions passed
        $arr[] = $unid;
    }
  } else  {
    while( $db->next_record() ) 
      $arr[] = unpack_id($db->f(id));
  }    
  
  return $arr;           
}


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

			if (InputIsDate($field[$i]["value"])) # conversion date value to seconds
				$field[$i]["value"]= userdate2sec( CutHash($field[$i]["value"]) );
			
		} else {
			return "Field ".$field[$i]["name"]." doesn't exist in this slice!";
		}
	}
  
  if ($debug)
    echo p_arr_m ($field)."<hr>";	
  
  # search id for each basis condition
  
	for ($i=0;$i<count($field);$i++) {
		if ($slicefield[$field[$i]["name"]]["table"]=='item')
			$sql="SELECT id FROM item WHERE slice_id='".$p_slice_id."' AND ".$slicefield[$field[$i]["name"]]["field"]." ".$field[$i]["matchop"]." '".$field[$i]["value"]."'";
		else 
			$sql="SELECT b.id FROM ".$slicefield[$field[$i]["name"]]["table"]." a,item b
				WHERE b.id=a.item_id AND b.slice_id='".$p_slice_id."' AND a.".$slicefield[$field[$i]["name"]]["field"]." ".$field[$i]["matchop"]." '".$field[$i]["value"]."' AND a.field_id='".$field[$i]["name"]."'";

		$db->query($sql);
		while ($db->next_record()) {
			$id=unpack_id($db->f(id));
			$possible[$id][$i]=1;
		}
		$db->free();
  
    if ($debug)
      echo $sql."<br>";		
	}
  
  if ($debug) echo "<hr>";
  if ($debug) echo p_arr_m ($possible)."<hr>";
  
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