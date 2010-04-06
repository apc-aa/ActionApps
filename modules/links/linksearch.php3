<?php
require_once AA_INC_PATH."searchlib.php3";
require_once AA_BASE_PATH. "modules/links/constants.php3";
require_once AA_BASE_PATH. "modules/links/util.php3";

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
 *  relation, which is not allowed for 'unasigned' liks type
 */
function IsFieldSupported($field_info, $v, $param) {
    $field = $field_info['field'];
      // param is 'type' for this function
    return ( ($param != 'unasigned' ) OR
             ( (substr($field, 0, 12) =='links_links.') OR
               (substr($field, 0, 14) =='links_regions.') OR
               (substr($field, 0, 15) =='links_link_reg.') OR
               (substr($field, 0, 16) =='links_languages.') OR
               (substr($field, 0, 16) =='links_link_lang.') OR
               (substr($field, 0, 14) =='links_changes.') ) );
}


/** Links_QueryZIDs - Finds link IDs for links according to given  conditions
 *  @param string $cat_path - path to category (like '1,4,78' for category 78)
 *  @param array  $conds    - search conditions (see FAQ)
 *  @param array  $sort     - sort fields (see FAQ)
 *  @param bool   $subcat   - search in the specified category only, or search
 *                            also in all subcategories
 *  @param string $type     - type is something like bins as known from items
 *                            type is one of the following:
 *                            'app'       - approved (normal shown links)
 *                            'changed'   - links containing unapproved changes
 *                            'new'       - not approved links
 *                            'unasigned' - links which belongs to no category
 *                                          (cat_path param is not used here)
 *                            'folderX'   - links in folder X (where X is folder
 *                                          number) - links in folder > 1 are
 *                                          hidden to public users
 *                            'all'       - all links in any folder
 *  @global int  $QueryIDsCount - set to the count of IDs returned
 *  @global bool $debug=1       - many debug messages
 */
function Links_QueryZIDs($cat_path, $conds, $sort="", $subcat=false, $type="app") {
    global $debug;                 // displays debug messages

    if ( $debug ) huhl( "<br>Conds:", $conds, "<br>--<br>Sort:", $sort, "<br>--");

    $LINKS_FIELDS = GetLinkFields();

    $where_sql    = MakeSQLConditions($LINKS_FIELDS, $conds, $LINKS_FIELDS, $join_tables, 'IsFieldSupported', $type);
    $order_by_sql = MakeSQLOrderBy(   $LINKS_FIELDS, $sort,  $join_tables, 'IsFieldSupported', $type);

    $SQL = ( ($type=='unasigned') ?
           'SELECT  DISTINCT links_links.id  FROM links_links
              LEFT JOIN links_link_cat ON links_links.id = links_link_cat.what_id ' :
           'SELECT  DISTINCT links_links.id  FROM links_categories, links_link_cat, links_links ');

    if ( $type == 'changed' ) {
        $join_tables['changes'] = true;
    }

    if ( $join_tables['regions'] )
        $SQL .= ' LEFT JOIN links_link_reg ON links_links.id = links_link_reg.link_id
                  LEFT JOIN links_regions ON links_regions.id = links_link_reg.region_id ';
    if ( $join_tables['languages'] )
        $SQL .= ' LEFT JOIN links_link_lang ON links_links.id = links_link_lang.link_id
                  LEFT JOIN links_languages ON links_languages.id = links_link_lang.lang_id ';
    if ( $join_tables['changes'] )
        $SQL .= ' LEFT JOIN links_changes ON links_links.id = links_changes.changed_link_id ';


    if ( $type != 'unasigned' ) {
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
        case 'changed':   $SQL .= ' AND (   (     ((links_link_cat.proposal = \'y\')
                                                OR (links_link_cat.proposal_delete = \'y\'))
                                              AND (links_link_cat.state <> \'hidden\')
                                              AND (links_link_cat.base = \'n\'))
                                          OR
                                            (     (links_changes.rejected <>\'y\')
                                              AND (links_link_cat.base = \'y\')
                                              AND (links_link_cat.proposal = \'n\')))
                                    AND (links_links.folder < 2) ';
                          break;
        case 'unasigned': $SQL .= ' WHERE (links_link_cat.category_id IS NULL)';
                          break;
        case 'app':       $SQL .= " AND (links_link_cat.proposal = 'n') ";
        default:          $folder = Links_GetFolder($type);
                          // folder string (like folder3) contains folder number

                          // $SQL .= " AND (links_link_cat.proposal = 'n') ";
                          $SQL .= ($folder ?
                                      " AND (links_links.folder = $folder) " :
                                      " AND (links_links.folder < 2) " );
    }
    $SQL .=  $where_sql . $order_by_sql;

    // get result --------------------------
    return GetZidsFromSQL($SQL, 'id');
}


/** Links_QueryCatZIDs - Finds category IDs according to given conditions
 *  @param string $cat_path - path to category (like '1,4,78' for category 78)
 *  @param array  $conds    - search conditions (see FAQ)
 *  @param array  $sort     - sort fields (see FAQ)
 *  @param bool   $subcat   - search in the specified category only, or search
 *                            also in all subcategories
 *  @param string $type     - type is something like bins as known from items
 *                            type is one of the following:
 *                            'app'       - approved (normal shown categories)
 *                            'all'       - all categories in any folder
 *  @global int  $QueryIDsCount - set to the count of IDs returned
 *  @global bool $debug=1       - many debug messages
 */
function Links_QueryCatZIDs($cid, $conds, $sort="", $subcat=false, $type="app") {
    global $debug;                 // displays debug messages

    if ( $debug ) huhl( "<br>CatPath:", $cat_path, '<br>Subcat:', $subcat,"<br>Conds:", $conds, "<br>--<br>Sort:", $sort, "<br>--");

    $CATEGORY_FIELDS = GetCategoryFields();

    $where_sql    = MakeSQLConditions($CATEGORY_FIELDS, $conds, $CATEGORY_FIELDS, $foo);
    $order_by_sql = MakeSQLOrderBy(   $CATEGORY_FIELDS, $sort,  $foo);

    if ( $subcat ) {  // this is not obvious ussage - used for category search
        $cat_path = Links_GetCategoryColumn( $cid, 'path');
        $SQL  = "SELECT DISTINCT links_categories.id  FROM links_categories
                  WHERE ((path = '$cat_path') OR (path LIKE '$cat_path,%')) ";
    } else {         // standard ussage - looking for subcategories of given $cid (crossreferenced included)
        $SQL  = "SELECT DISTINCT links_categories.id  FROM links_categories, links_cat_cat
                  WHERE links_categories.id = links_cat_cat.what_id
                    AND links_cat_cat.category_id = $cid ";
    }
    $SQL .=  $where_sql . $order_by_sql;

    // get result --------------------------
    return GetZidsFromSQL($SQL, 'id');
}

?>
