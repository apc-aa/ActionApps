<?php
require_once $GLOBALS[AA_INC_PATH]."searchlib.php3";
require_once "./constants.php3";

if (!defined ("LINKS_LINKSEARCH_INCLUDED"))
   	  define ("LINKS_LINKSEARCH_INCLUDED",1);
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


// -------------------------------------------------------------------------------------------

/** Checks if the field is 'link description field' and not field used for
 *  relation
 */
function IsLinkDataField($field) {
    return (substr($field, 0, 12) =='links_links.') OR
           (substr($field, 0, 14) =='links_regions.') OR
           (substr($field, 0, 15) =='links_link_reg.') OR
           (substr($field, 0, 16) =='links_languages.') OR
           (substr($field, 0, 16) =='links_link_lang.') OR
           (substr($field, 0, 14) =='links_changes.');
}


/* Function: Links_QueryZIDs
   Purpose:  Finds link IDs for links according to given  conditions
   Params:   $conds -- search conditions (see FAQ)
             $sort -- sort fields (see FAQ)

   Globals:  $debug=1 -- many debug messages
             $debugfields=1 -- useful mainly for multiple slices mode -- views info about field_ids
                used in conds[] but not existing in some of the slices
             $QueryIDsCount -- set to the count of IDs returned
             $nocache -- do not use cache, even if use_cache is set
*/

function Links_QueryZIDs($cat_path, $conds, $sort="", $subcat=false, $type="app") {
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

  # app | changed | new | unasigned | folderX | all

  # if you want specify it yourselves in conds, set type to ALL

  # select * from item, content as c1, content as c2 where item.id=c1.item_id AND item.id=c2.item_id AND       c1.field_id IN ('fulltext........', 'abstract..........') AND c2.field_id = 'keywords........' AND c1.text like '%eufonie%' AND c2.text like '%eufonie%' AND item.highlight = '1';

  global $debug;                 # displays debug messages
  global $nocache;               # do not use cache, if set
  global $LinksIDsCount;
  global $LINKS_FIELDS;          # link fields definitions

  $db = new DB_AA;

  if( $use_cache AND !$nocache ) {
    #create keystring from values, which exactly identifies resulting content
    $keystr = $cat_path . $subcat.
              serialize($conds).
              serialize($sort).
              $type;

    if( $res = $GLOBALS[pagecache]->get($keystr)) {
      $arr = unserialize($res);
      $LinksIDsCount = count($arr);
      if( $debug )
        echo "<br>Cache HIT - return $LinksIDsCount IDs<br>";
      return $arr;
    }
  }

if( $debug ) {
  echo "<br>Conds:"; print_r($conds);
  echo "<br>--";
  echo "<br>Sort:"; print_r($sort);
  echo "<br>--";
}

  ParseEasyConds($conds, $LINKS_FIELDS);

if( $debug ) {
  echo "<br>Conds:"; print_r($conds);
  echo "<br>--";
}

  # parse conditions ----------------------------------
  if( isset($conds) AND is_array($conds)) {
      reset($conds);
      while( list( , $cond) = each( $conds )) {
          if( !isset($cond) OR !is_array($cond) )
              continue;                              // bad condition
          reset($cond);
          while( list( $fid, $v) = each( $cond )) {
              $finfo = $LINKS_FIELDS[$fid];
              if ( !isset($finfo) OR !is_array($finfo) )
                  continue;                         // fid is not field
              if ( ($type=='unasigned') AND !IsLinkDataField($finfo['field']) )
                  continue;             // we take care about link table fields
                                        // only, if we want to search in unasigned
              $link_conds[] = GetWhereExp( $finfo['field'],
                                          $cond['operator'], $cond['value'] );
              if( $finfo['table'] )
                  $join_tables[$finfo['table']] = true;
          }
      }
  }

  # parse sort order ----------------------------
  if( isset($sort) AND is_array($sort)) {
      reset($sort);
      while( list( , $srt) = each( $sort )) {
          if( !isset($srt) OR !is_array($srt) )
              continue;                              // bad sort order
          $fid = key($srt);
          $finfo = $LINKS_FIELDS[$fid];
          if( !$finfo OR !is_array($finfo))  # bad field_id - skip
              continue;
          if ( ($type=='unasigned') AND !IsLinkDataField($finfo['field']) )
              continue;             // we take care about link table fields
                                    // only, if we want to search in unasigned

          $link_order[] = $finfo['field'] .
                          (stristr(current( $srt ), 'd') ? " DESC" : "");
      }
  }

/*- Aktivní
- Návrhy na zm_nu (jak od správc_, tak od vn_jích uivatel_  kritériem tohoto a následujícího foldru je, zda ji jsou vid_t na webu nebo nejsou)
- Nové odkazy (návrhy od vn_jích uivatel_ tak od správc_)
- Neza_azené odkazy (odkazy, které nemají ádnou kategorii, zcelého katalogu, kdo d_ív p_ijde a za_adí ten má, za_azují se sem automaticky)
- Kos (sem prijde jen to, co n_kdo skute_n_ vyhodil) */
  # app | changed | new | unasigned | trash | all | folderX (where X is folder number)


    $SQL = ( ($type=='unasigned') ?
           'SELECT  DISTINCT links_links.id  FROM links_links
              LEFT JOIN links_link_cat ON links_links.id = links_link_cat.what_id ' :
           'SELECT  DISTINCT links_links.id  FROM links_links, links_link_cat, links_categories ');

    if( $type == 'changed' ) {
        $join_tables['changes'] = true;
    }

    if( $join_tables['regions'] )
        $SQL .= ' LEFT JOIN links_link_reg ON links_links.id = links_link_reg.link_id
                  LEFT JOIN links_regions ON links_regions.id = links_link_reg.region_id ';
    if( $join_tables['languages'] )
        $SQL .= ' LEFT JOIN links_link_lang ON links_links.id = links_link_lang.link_id
                  LEFT JOIN links_languages ON links_languages.id = links_link_lang.lang_id ';
    if( $join_tables['changes'] )
        $SQL .= ' LEFT JOIN links_changes ON links_links.id = links_changes.changed_link_id ';


    if( $type != 'unasigned' ) {
        $SQL .= '  WHERE links_links.id = links_link_cat.what_id
                     AND links_link_cat.category_id = links_categories.id ';

        $SQL .= ( $subcat ? " AND ((path = '$cat_path') OR (path LIKE '$cat_path,%')) "
                          : " AND (path = '$cat_path') ");
    }

    switch ($type) {
        case 'all':       $SQL .= " AND (links_link_cat.proposal = 'n') ";
                          break;
        case 'new':       $SQL .= ' AND (links_link_cat.proposal = \'y\')
                                    AND (links_link_cat.base = \'y\')
                                    AND (links_links.folder < 2) ';
                          break;
        case 'changed':   $SQL .= ' AND (   (     (links_link_cat.proposal = \'y\')
                                              AND (links_link_cat.state <> \'hidden\')
                                              AND (links_link_cat.base = \'n\'))
                                          OR
                                            (     (links_changes.rejected =\'n\')
                                              AND (links_link_cat.proposal = \'n\')))
                                    AND (links_links.folder < 2) ';
                          break;
        case 'unasigned': $SQL .= ' WHERE (links_link_cat.category_id IS NULL)';
                          break;
        case 'app':
        default:          $folder = Links_GetFolder($type);
                          // folder string (like folder3) contains folder number

                          $SQL .= " AND (links_link_cat.proposal = 'n') ";
                          $SQL .= ($folder ?
                                      " AND (links_links.folder = $folder) " :
                                      " AND (links_links.folder < 2) " );
    }

    if( isset($link_conds) AND is_array($link_conds) )
        $SQL .= ' AND ' . join(' AND ', $link_conds );

    if( isset($link_order) AND is_array($link_order) )
        $SQL .= ' ORDER BY '. join(', ', $link_order );

  # get result --------------------------
    $db->tquery($SQL);

    while( $db->next_record() )
      $arr[] = $db->f('id');

  $LinksIDsCount = count($arr);

  $zids = new zids($arr,"s");

  if( $use_cache AND !$nocache )
    $GLOBALS['pagecache']->store($keystr, serialize($zids), "cat_path=$cat_path");

  return $zids;
}


/* Function: Links_QueryCatZIDs
   Purpose:  Finds category IDs for category according to given conditions
   Params:   $conds -- search conditions (see FAQ)
             $sort -- sort fields (see FAQ)

   Globals:  $debug=1 -- many debug messages
             $debugfields=1 -- useful mainly for multiple slices mode -- views info about field_ids
                used in conds[] but not existing in some of the slices
             $CategoryIDsCount -- set to the count of IDs returned
             $nocache -- do not use cache, even if use_cache is set
*/

function Links_QueryCatZIDs($cat_path, $conds, $sort="", $subcat=false, $type="app") {

  # type sets status, pub_date and expiry_date according to specified type:
  # app | changed | new | unasigned | trash | all
  # if you want specify it yourselves in conds, set type to ALL

  global $debug;                 # displays debug messages
  global $nocache;               # do not use cache, if set
  global $CategoryIDsCount;
  global $LINKS_FIELDS;          # link fields definitions

  $db = new DB_AA;

  if( $use_cache AND !$nocache ) {
    #create keystring from values, which exactly identifies resulting content
    $keystr = $cat_path . $subcat.
              serialize($conds).
              serialize($sort).
              $type;

    if( $res = $GLOBALS[pagecache]->get($keystr)) {
      $arr = unserialize($res);
      $LinksIDsCount = count($arr);
      if( $debug )
        echo "<br>Cache HIT - return $LinksIDsCount IDs<br>";
      return $arr;
    }
  }

if( $debug ) {
  echo "<br>Conds:"; print_r($conds);
  echo "<br>--";
  echo "<br>Sort:"; print_r($sort);
  echo "<br>--";
}

  ParseEasyConds($conds, $CATEGORY_FIELDS);

if( $debug ) {
  echo "<br>Conds:"; print_r($conds);
  echo "<br>--";
}

  # parse conditions ----------------------------------
  if( isset($conds) AND is_array($conds)) {
      reset($conds);
      while( list( , $cond) = each( $conds )) {
          if( !isset($cond) OR !is_array($cond) )
              continue;                              // bad condition
          reset($cond);
          while( list( $fid, $v) = each( $cond )) {
              $finfo = $CATEGORY_FIELDS[$fid];
              if ( !isset($finfo) OR !is_array($finfo) )
                  continue;                         // fid is not field
              $link_conds[] = GetWhereExp( $finfo['field'],
                                          $cond['operator'], $cond['value'] );
              if( $finfo['table'] )
                  $join_tables[$finfo['table']] = true;
          }
      }
  }

  # parse sort order ----------------------------
  if( isset($sort) AND is_array($sort)) {
      reset($sort);
      while( list( , $srt) = each( $sort )) {
          if( !isset($srt) OR !is_array($srt) )
              continue;                              // bad sort order
          $fid = key($srt);
          $finfo = $LINKS_FIELDS[$fid];
          if( !$finfo OR !is_array($finfo))  # bad field_id - skip
              continue;
          $link_order[] = $finfo['field'] .
                          (stristr(current( $srt ), 'd') ? " DESC" : "");
      }
  }



//------------------------------TODO   |   -----------------------------------
//                                     V   -----------------------------------


    $SQL = ( ($type=='unasigned') ?
           'SELECT  DISTINCT links_links.id  FROM links_links
              LEFT JOIN links_link_cat ON links_links.id = links_link_cat.what_id ' :
           'SELECT  DISTINCT links_links.id  FROM links_links, links_link_cat, links_categories ');

    if( $type == 'changed' ) {
        $join_tables['changes'] = true;
    }

    if( $join_tables['regions'] )
        $SQL .= ' LEFT JOIN links_link_reg ON links_links.id = links_link_reg.link_id
                  LEFT JOIN links_regions ON links_regions.id = links_link_reg.region_id ';
    if( $join_tables['languages'] )
        $SQL .= ' LEFT JOIN links_link_lang ON links_links.id = links_link_lang.link_id
                  LEFT JOIN links_languages ON links_languages.id = links_link_lang.lang_id ';
    if( $join_tables['changes'] )
        $SQL .= ' LEFT JOIN links_changes ON links_links.id = links_changes.changed_link_id ';


    if( $type != 'unasigned' ) {
        $SQL .= '  WHERE links_links.id = links_link_cat.what_id
                     AND links_link_cat.category_id = links_categories.id ';

        $SQL .= ( $subcat ? " AND ((path = '$cat_path') OR (path LIKE '$cat_path,%')) "
                          : " AND (path = '$cat_path') ");
    }

    switch ($type) {
        case 'all':       $SQL .= " AND (links_link_cat.proposal = 'n') ";
                          break;
        case 'new':       $SQL .= ' AND (links_link_cat.proposal = \'y\')
                                    AND (links_link_cat.base = \'y\')
                                    AND (links_links.folder < 2) ';
                          break;
        case 'changed':   $SQL .= ' AND (   (     (links_link_cat.proposal = \'y\')
                                              AND (links_link_cat.state <> \'hidden\')
                                              AND (links_link_cat.base = \'n\'))
                                          OR
                                            (     (links_changes.rejected =\'n\')
                                              AND (links_link_cat.proposal = \'n\')))
                                    AND (links_links.folder < 2) ';
                          break;
        case 'unasigned': $SQL .= ' WHERE (links_link_cat.category_id IS NULL)';
                          break;
        case 'app':
        default:          $folder = Links_GetFolder($type);
                          // folder string (like folder3) contains folder number

                          $SQL .= " AND (links_link_cat.proposal = 'n') ";
                          $SQL .= ($folder ?
                                      " AND (links_links.folder = $folder) " :
                                      " AND (links_links.folder < 2) " );
    }

    if( isset($link_conds) AND is_array($link_conds) )
        $SQL .= ' AND ' . join(' AND ', $link_conds );

    if( isset($link_order) AND is_array($link_order) )
        $SQL .= ' ORDER BY '. join(', ', $link_order );

  # get result --------------------------
    $db->tquery($SQL);

    while( $db->next_record() )
      $arr[] = $db->f('id');

  $LinksIDsCount = count($arr);

  $zids = new zids($arr,"s");

  if( $use_cache AND !$nocache )
    $GLOBALS['pagecache']->store($keystr, serialize($zids), "cat_path=$cat_path");

  return $zids;
}


?>
