<?php
/**
 * File contains definition of storable_class class - abstract class which
 * implements two methods for storing and restoring class data (used in
 * searchbar class, manager class, ...
 *
 * Should be included to other scripts (as /include/searchbar.class.php3)
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
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

/**
 * storable_class - abstract class which implements methods for storing and
 * restoring class data (used in searchbar class, manager class, ...).
 *
 * If you want to use strable methods in your class, you should derive the new
 * class from storable_class. Then you should define $persistent_slots array,
 * where you specify all the variables you want to store. Then you just call
 * getState() and setFromState() methods for storing and restoring object's data
 */
class storable_class {
    /**
     * Restores the object's data from $state
     * @param  array $state state array which stores object's data. The array
     *                      you will get by getState() method.
     */
    function setFromState(&$state) {
        if (!isset($this->persistent_slots) OR !is_array($this->persistent_slots)) {
            return false;
        }
        foreach ($this->persistent_slots as $v) {
            if (is_object($this->$v)) {
                $this->$v->setFromState($state[$v]);
            } else {
                $this->$v = $state[$v];
            }
        }
    }

    /**
     * Returns state array of the object - stores object's data for leter
     * restoring (by setFromState() method)
     */
    function getState() {
        if (!isset($this->persistent_slots) OR !is_array($this->persistent_slots)) {
            return false;
        }
        foreach ($this->persistent_slots as $v) {
            $ret[$v] = (is_object($this->$v) ? $this->$v->getState() : $this->$v);
        }
        return $ret;
    }
}

class AA_Object {
    function getNameArray($obj_type) {
        return GetTable2Array("SELECT o1.object_id, o2.text FROM object_text as o1 INNER JOIN object_text as o2 ON o1.object_id=o2.object_id
                                     WHERE o1.property = 'type' AND o1.text = '$obj_type' AND o2.property = 'name'", 'object_id', 'text');
    }

    function getProperty($id, $property) {
        return GetTable2Array("SELECT text FROM object_text WHERE object_id = '$id' AND property = '$property'", 'aa_first', 'text');
    }

    function getObjectType($id) {
        return AA_Object::getProperty($id, 'type');
    }

    function &load($id, $type=null) {
        if ( !$type ) {
            $type = getObjectType($id);
        }
        $object = call_user_func(array($type, 'loadFromDb'), $id);
        return $object;
    }

    function saveProperty($obj_id, $property, $value) {
        $varset = new CVarset();
        $varset->add('object_id', 'text', $obj_id);
        $varset->add('property',  'text', $property);
        $varset->add('text',      'text', $value);
        $varset->doInsert('object_text');
    }

    /** Finds object IDs for objects given by conditions
    *
    *   @param string        $type   - object type
    *   @param AA_Slices     $slices - search only objects ownad by those slices
    *   @param AA_Conditions $conds  - search conditions
    *   @param AA_Sortorder  $sort   - sort fields (see FAQ)
    *   @param zids          $restrict_zids - use it if you want to choose only from a set of ids
    *   @return A zids object with a list of the ids that match the query.
    *
    *   @global  bool $debug (in) many debug messages
    *   @global  bool $nocache (in) do not use cache, even if use_cache is set
    */
    function query($type, $slices=null, $conds=null, $sort=null, $restrict_zids=null) {
      // select * from item, content as c1, content as c2 where item.id=c1.item_id AND item.id=c2.item_id AND       c1.field_id IN ('fulltext........', 'abstract..........') AND c2.field_id = 'keywords........' AND c1.text like '%eufonie%' AND c2.text like '%eufonie%' AND item.highlight = '1';
      global $debug;                 // displays debug messages
      global $nocache;               // do not use cache, if set

      // todo !!! - rewrite it. This is just copu of Queryids below


      $db = new DB_AA;

      //create keystring from values, which exactly identifies resulting content
      $keystr = serialize($slices). serialize($conds). serialize($sort). $group_by. $type.
                serialize($slices). $neverAllItems.
                ((isset($restrict_zids) && is_object($restrict_zids)) ? serialize($restrict_zids) : "").
                $defaultCondsOperator;
      $cache_condition = ($use_cache AND !$nocache);

      if ( $res = CachedSearch( $cache_condition, $keystr )) {
          return $res;
      }

      if (!$slices) {
          if ($slice_id)
               $slices = array($slice_id);
      }

      if ($GLOBALS[debugfields] || $debug) {
          if ($slices) ProoveFieldNames($slices, $conds);
      }

      ParseMultiSelectConds($conds);
      ParseEasyConds($conds, $defaultCondsOperator);

      if ( $debug ) huhl("Conds=",$conds,"Sort=",$sort, "Slices=",$slices);

      // parse conditions ----------------------------------
      if ( is_array($conds)) {
        $tbl_count=0;
        foreach ($conds as $cond) {

          // fill arrays according to this condition
          $field_count = 0;
          $cond_flds   = '';
          foreach ( $cond as $fid => $v ) {
              // search in all content table fields (new in AA v2.8)
              switch ( strtolower($fid) ) {
                  case 'all_fields':          $cond_flds = 'all_fields';
                                              $store     = 'text';
                                              continue;
                  case 'all_fields_numeric':  $cond_flds = 'all_fields';
                                              $store     = 'number';
                                              continue;
              }
              if ( $CONDS_NOT_FIELD_NAMES[$fid] ) {
                  continue;      // it is not field_id parameters - skip it for now
              }
              if ( !$fields[$fid] OR $v=="") {
                  if ($debug) echo "Skipping $fid in conds[]: not known.<br>"; {
                      continue;            // bad field_id or not defined condition - skip
                  }
              }

              if ( $fields[$fid]['in_item_tbl'] ) {   // field is stored in table 'item'
                  // Long ID in conds should be specified as unpacked, but in db it is packed
                  if (($fid == 'id..............') AND (guesstype($cond['value'])=='l')) {
                      $cond['value'] = q_pack_id($cond['value']);
                  }
                  $select_conds[] = GetWhereExp( 'item.'.$fields[$fid]['in_item_tbl'], $cond['operator'], $cond['value'] );
                  if ( $fid == 'expiry_date.....' ) {
                      $ignore_expiry_date = true;
                  }
              } else {
                  $cond_flds .= ( ($field_count++>0) ? ',' : "" ). "'$fid'";
                  // will not work with one condition for text and number fields
                  $store      = ($fields[$fid]['text_stored'] ? "text" : "number");
              }
          }
          if ( $cond_flds != '' ) {
            $tbl = 'c'.$tbl_count++;
            // fill arrays to be able construct select command
            $select_conds[] = GetWhereExp( "$tbl.$store",
                                              $cond['operator'], $cond['value'] );

            if ( strpos($cond_flds, 'all_fields')!== false ) {  // we are searching all fields in content table
                $select_tabs[] = "LEFT JOIN content as $tbl
                                         ON ($tbl.item_id=item.id)";
            } elseif ($field_count>1) {
                $select_tabs[] = "LEFT JOIN content as $tbl
                                         ON ($tbl.item_id=item.id
                                         AND ($tbl.field_id IN ($cond_flds) OR $tbl.field_id is NULL))";
            } else {
                $select_tabs[] = "LEFT JOIN content as $tbl
                                         ON ($tbl.item_id=item.id
                                         AND ($tbl.field_id=$cond_flds OR $tbl.field_id is NULL))";
                          // mark this field as sortable (store without apostrofs)
                $sortable[ str_replace( "'", "", $cond_flds) ] = $tbl;
            }
          }
        }
      }

      if ( !is_array($sort) OR count($sort)<1 ) {
        $select_order =  is_object($restrict_zids) ? '' : 'item.publish_date DESC';   // default item order
      } else {
        $delim='';
        foreach ($sort as  $sort_no => $srt) {
          if (key($srt)=='limit') {
            next($srt);       // skip the 'limit' record in the array
          }
          $fid = key($srt);
          if ( !$fields[$fid] ) { // bad field_id - skip
            if ($debug) echo "Skipping sort[x][$fid], don't know $fid.<br>";
            continue;
          }

          if ( $fields[$fid]['in_item_tbl'] ) {   // field is stored in table 'item'
            $fieldId          = 'item.' . $fields[$fid]['in_item_tbl'];
            $select_order    .= $delim  . $fieldId;
            // select_distinct added in order we can group by multiple value fields
            // (items are shown more times)
            $select_distinct .= ", $fieldId as s$sort_no";
            if ( stristr(current( $srt ), 'd'))
              $select_order .= " DESC";
            $delim=',';
          } else {
            if ( !$sortable[ $fid ] ) {           // this field is not joined, yet
              $tbl = 'c'.$tbl_count++;
              // fill arrays to be able construct select command
              $select_tabs[] = "LEFT JOIN content as $tbl
                                       ON ($tbl.item_id=item.id
                                       AND ($tbl.field_id='$fid' OR $tbl.field_id is NULL))";
                            // mark this field as sortable (store without apostrofs)
              $sortable[$fid] = $tbl;
            }

            // join constant table if we want to sort by priority
            $direction = current( $srt );
            if ( stristr($direction,'1') OR stristr($direction,'9') ) { // sort by priority
              if ( !($constgroup = GetConstantGroup($fields[$fid]['input_show_func']) ))
                continue;   // no constant group defined - can't assign priority

              $tbl = 'o'.$tbl_count++;
              // fill arrays to be able construct select command
              $select_tabs[] = "LEFT JOIN constant as $tbl
                                       ON ($tbl.value=". $sortable[$fid] .".text
                                       AND ($tbl.group_id='$constgroup'
                                            OR $tbl.group_id is NULL))";
                            // mark this field as sortable (store without apostrofs)

              // fill arrays according to this sort specification
              $fieldId          = $tbl. ".pri";
              $select_order    .= $delim . $fieldId;
              $select_distinct .= ", $fieldId as s$sort_no";
              if ( stristr($direction,'9') )
                $select_order  .= " DESC";
            } else {                                                   // sort by value
              $store = ($fields[$fid]['text_stored'] ? "text" : "number");
              // fill arrays according to this sort specification
              $fieldId          = $sortable[$fid]. ".$store";
              $select_order    .= $delim . $fieldId;
              $select_distinct .= ", $fieldId as s$sort_no";
              if ( stristr(current( $srt ), 'd'))
                $select_order  .= " DESC";
            }
            $delim=',';
          }
          if ($srt['limit']) {
            $select_limit_field = array('field' => "s$sort_no", 'limit' => $srt['limit']);
          }
        }
      }

      // parse group by parameter ----------------------------
      // .. removed 2/27/2005 Honza (was never used)
      // ---

      if ( $debug )
        huhl("QueryZIDs:slice_id=",$slice_id,"  select_tabs=",$select_tabs,
            "  select_conds=",$select_conds,"  select_order=",$select_order );

      // construct query --------------------------
      $SQL = "SELECT DISTINCT item.id as itemid $select_distinct FROM item ";
      if ( isset($select_tabs) AND is_array($select_tabs))
        $SQL .= " ". implode (" ", $select_tabs);

      $SQL .= " WHERE ";                                         // slice ----------

      if ( is_array($slices) AND (count($slices) >= 1) ) {
          reset ($slices);
          $slicesText = "";
          while (list (,$slice) = each ($slices)) {
              if ($slicesText != "") $slicesText .= ",";
              $slicesText .= "'".q_pack_id($slice)."'";
          }
          $SQL .= 'item.slice_id' . ((count($slices) == 1) ? " = $slicesText AND " :
                                          " IN ($slicesText) AND ");
      }
      if (is_object($restrict_zids)) {
        if ($restrict_zids->count() == 0) {
            return new zids(); // restrict_zids defined but empty - no result
        } else {
           $SQL .= " ".$restrict_zids->sqlin() ." AND ";
        }
      } else {
        // slice(s) or item_ids MUST be specified (in order we can get answer in limited time)
        if (!$slicesText) return new zids();
      }

      // now is rounded in order the time is in steps - it is better for search
      // caching - SQL is THE SAME during one time step
      $now = now('step');            // round up

      /* new version of bin selecting, now we use type of bin from constants.php3 */
      if (is_numeric($type)) { /* $type is numeric constant */
          $numeric_type = max(1,$type);
      } elseif (is_string($type)) { /* for backward compatibility */
          switch ($type) { /* assign to string type it's numeric constant */
              case 'ACTIVE'  : $numeric_type = AA_BIN_ACTIVE;  break;  // 1
              case 'PENDING' : $numeric_type = AA_BIN_PENDING; break;  // 2
              case 'EXPIRED' : $numeric_type = AA_BIN_EXPIRED; break;  // 4
              case 'HOLDING' : $numeric_type = AA_BIN_HOLDING; break;  // 8
              case 'TRASH'   : $numeric_type = AA_BIN_TRASH;   break;  // 16
              case 'ALL'     : $numeric_type = (AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING | AA_BIN_TRASH); break;
              default        : $numeric_type = AA_BIN_ACTIVE;  break;  // 1
          }
      } else { /* strange case, I think never possible :) */
          $numeric_type = AA_BIN_ACTIVE;
      }
    /* create SQL query for different types of numeric constants */
        if ($numeric_type == (AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING | AA_BIN_TRASH)) {
          $SQL .= " 1=1 ";
        } elseif ($numeric_type == (AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING)) {
          $SQL .= " item.status_code=1 ";
        } elseif ($numeric_type == (AA_BIN_ACTIVE | AA_BIN_PENDING)) {
    //      $SQL .= " item.status_code=1 AND (item.expiry_date > '$now' OR item.expiry_date IS NULL) ";
          $SQL .= " item.status_code=1 AND (item.expiry_date > '$now') ";
        } else {
            $SQL2 = "";
            if ($numeric_type & AA_BIN_ACTIVE) {
    //          $SQL2 .= " ( item.status_code=1 AND (  item.publish_date <= '$now' OR item.publish_date IS NULL ) ";
              $SQL2 .= " ( item.status_code=1 AND (item.publish_date <= '$now') ";
                /* condition can specify expiry date (good for archives) */
                if ( !( $ignore_expiry_date &&
                       defined("ALLOW_DISPLAY_EXPIRED_ITEMS") && ALLOW_DISPLAY_EXPIRED_ITEMS) ) {
    //              $SQL2 .= " AND (item.expiry_date > '$now' OR item.expiry_date IS NULL) ";
                  $SQL2 .= " AND (item.expiry_date > '$now') ";
                }
              $SQL2 .= ' )';
            }
            if ($numeric_type & AA_BIN_EXPIRED) {
                if ($SQL2 != "") { $SQL2 .= ' OR '; }
                $SQL2 .= " (item.status_code=1 AND item.expiry_date <= '$now') ";
            }
            if ($numeric_type & AA_BIN_PENDING) {
                if ($SQL2 != "") { $SQL2 .= ' OR '; }
                $SQL2 .= " (item.status_code=1 AND item.publish_date > '$now') ";
            }
            if ($numeric_type & AA_BIN_HOLDING) {
                if ($SQL2 != "") { $SQL2 .= ' OR '; }
                $SQL2 .= " (item.status_code=2) ";
            }
            if ($numeric_type & AA_BIN_TRASH) {
                if ($SQL2 != "") { $SQL2 .= ' OR '; }
                $SQL2 .= " (item.status_code=3) ";
            }
            $SQL .= " ( ".$SQL2 ." ) ";
        }

      if ( isset($select_conds) AND is_array($select_conds))      // conditions -----
        $SQL .= " AND (" . implode (") AND (", $select_conds) .") ";

      if ( $select_order )                                 // order ----------
        $SQL .= " ORDER BY $select_order";

      // add comment to the SQL command (for debug purposes)
      $SQL .= " -- AA slice_id: $slice_id";
      if ($GLOBALS['slice_info']) $SQL .= ", slice_name: ". $GLOBALS['slice_info']['name'];
      if ($GLOBALS['vid'])        $SQL .= ", vid: ".        $GLOBALS['vid'];
      if ($GLOBALS['view_info'])  $SQL .= ", view_name: ".  $GLOBALS['view_info']['name'];

      // if neverAllItems is set, return empty set if no conds[] are used
      $str2find = new CacheStr2find($slices, 'slice_id');
      $str2find->add($slice_id, 'slice_id');
      return GetZidsFromSQL($SQL, 'itemid', $cache_condition, $keystr, $str2find, 'p',
                      !is_array($select_conds) && $neverAllItems,
                      // last parameter is used for sorting zids to right order
                      // - if no order specified and restrict_zids are specified,
                      // return zids in unchanged order
                      (is_object($restrict_zids) AND !$select_order) ? $restrict_zids : null, $select_limit_field);
    }
}
?>
