<?php
/**
 *
 * PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

require_once __DIR__."/searchlib.php3";
require_once __DIR__."/itemview.php3";
require_once __DIR__."/item.php3";

define("HTML", 0);
define("PLAIN",1);

define("FEEDTYPE_RSS",  1);
define("FEEDTYPE_APC",  2);
define("FEEDTYPE_EXACT",3);

// server module's error message
define("ERR_NO_SLICE","Error 1");
define("ERR_PASSWORD","Error 2");

define( 'UNPACKED_AA_THE_SAME_CATE', unpack_id('AA_The_Same_Cate') );
define( 'UNPACKED_AA_OTHER_CATEGOR', unpack_id('AA_Other_Categor') );

$CONTENT_FORMATS = [
    "http://www.isi.edu/in-notes/iana/assignments/media-types/text/html" => HTML,
                         "http://www.isi.edu/in-notes/iana/assignments/media-types/text/plain"=> PLAIN
];

/** GetExternalCategories function
 *  Get categories from table ef_categories
 *  ef_categories is used only for APC type of feedin (APC RSS) and the change
 *  would by tricky, since there could unfortunatelly be two feeds with the same
 *  id (one APC and one other RSS)
 * @param $feed_id
 * @param $add_other - if there are external categories it will add default
 *                      (AA_Other_Categor) category to the array (if not present
 *                      already
 * @return array
 */
function GetExternalCategories($feed_id, $add_other=false) {
    $db = getDB();
    $ext_categs = [];
    $db->query("SELECT category_id, category, category_name, target_category_id, approved FROM ef_categories WHERE feed_id='$feed_id' ORDER BY category_name");
    while ($db->next_record()) {
        $ext_categs[unpack_id($db->f('category_id'))] = [
            "value"              => $db->f('category'),
            "name"               => $db->f('category_name'),
            "approved"           => $db->f('approved'),
            "target_category_id" => unpack_id($db->f('target_category_id'))
        ];
    }
    freeDB($db);
    if ( $add_other AND (count($ext_categs)>0) AND !isset($ext_categs[unpack_id('AA_Other_Categor')])) {
        $ext_categs[UNPACKED_AA_OTHER_CATEGOR] = [
            "value"              => 'AA_Other_Categor',
            "name"               => _m('Other categories'),
            "approved"           => false,
            "target_category_id" => ''  // default is umpapped, not UNPACKED_AA_THE_SAME_CATE
        ];
    }
    return $ext_categs;
}

/** UseAllCategoriesOption
 *  Returns true if filters are set for 'All categories' option
 *  (and not separately for each category)
 * @param $ext_categs (by link)
 * @return bool
 */
function UseAllCategoriesOption( $ext_categs ) {
    foreach ( $ext_categs as $k => $v ) {
        if ( $v['target_category_id'] AND ($k != UNPACKED_AA_OTHER_CATEGOR) ) {
            return false;
        }
    }
    return true;
}


/** GetExternalMapping function
 *  Get external mapping from remote slice to local slice = returns two array
 *  map_to = from_field_id -> from_field_name  (but just for fields with flag = FEEDMAP_FLAG_MAP
 *  map_from = to_field_id -> { feedmap_flag => flag, value => from_field_id|value, from_field_name
 * @param $l_slice_id
 * @param $r_slice_id
 * @return array
 */
 function GetExternalMapping($l_slice_id, $r_slice_id) {
     $map_to   = [];
     $map_from = [];

     $db = getDB();
     $db->query("SELECT * FROM feedmap WHERE from_slice_id='".q_pack_id($r_slice_id)."' AND to_slice_id='".q_pack_id($l_slice_id)."' ORDER BY from_field_name");
     while ($db->next_record()) {
         switch ($f = $db->f('flag')) {
             case FEEDMAP_FLAG_EXTMAP :
             case FEEDMAP_FLAG_MAP:
                $v = $db->f('from_field_id');
                $map_to[$v] = $db->f('from_field_name') ;
                break;
             case FEEDMAP_FLAG_JOIN:
             case FEEDMAP_FLAG_RSS:
             case FEEDMAP_FLAG_VALUE :  $v = $db->f('value'); break;
             case FEEDMAP_FLAG_EMPTY :  $v = ""; break;
         }
         $map_from[$db->f('to_field_id')] = ["feedmap_flag"=>$f,"value"=>$v,"from_field_name"=>$db->f('from_field_name')];
     }
     freeDB($db);
     return [$map_to,$map_from];
 }

/** GetBaseFieldId function
 * Returns first field id of specified type
 * @param $fields[]
 * @param $ftype
 * @return bool|string
 */
function GetBaseFieldId( $fields, $ftype ) {
    $no = 10000;
    if ( isset($fields) AND is_array($fields) ) {
        foreach ( $fields as  $k => $val ) {
            if (!strstr($val['id'],$ftype)) {
                continue;
            }
            $last = AA_Fields::getFieldNo($val['id']);
            $no   = min($no, (($last=='') ? -1 : (integer)$last));
        }
    }
    return ($no == 10000) ? false : AA_Fields::createFieldId($ftype, ($no==-1) ? '.' : (string)$no);
}


/** GetGroupConstants function
 *  Returns Category definitions for specified slice
 *  $cat[<unpacked_id>] = array( 'name'=> , 'value'=> 'parent_id'=> );
 * @param $slice_id
 * @return array|bool
 */
function GetGroupConstants($slice_id) {
    $cat_ids   = [];
    $cat_group = GetCategoryGroup($slice_id);
    if (!$cat_group) {
        return false;
    }

    $db = getDB();
    $SQL = "SELECT id, name, value, class FROM constant WHERE group_id = '$cat_group' ORDER BY pri";
    $db->query($SQL);         // get all categories
    while ($db->next_record()) {
        $cat_ids[unpack_id($db->f('id'))] = [
            "name"     => $db->f('name'),
                                                     "value"    => $db->f('value'),
                                                     "parent_id"=> $db->f('class')
        ];
    }
    freeDB($db);
    return $cat_ids;
}

/** MapDefaultCategory function
 * @param $categories (by link)
 * @param $value
 * @param $parent_id
 * @return int|null|string
 */
function MapDefaultCategory(&$categories, $value, $parent_id) {
    // try to find the same category
    foreach ( $categories as $to_id => $v) {
        if ($v['value'] == $value) {
            return $to_id;
        }
    }
    // try to find the same parent category
    foreach ($categories as $to_id => $v) {
        if ($v['parent_id'] == $parent_id) {
            return $to_id;
        }
    }
    // return the first category
    reset($categories);
    return key($categories);
}

/** unixstamp_to_iso8601 function
 * @param $t
 * @return string
 */
function unixstamp_to_iso8601($t) {
    $tz = date("Z", $t)/60;
    $tm = $tz % 60;
    $tz /= 60;
    if ($tz<0) {
        $ts = "-";
        $tz = -$tz;
    } else {
        $ts = "+";
    }
    $tz=substr("0" . $tz, -2);
    $tm=substr("0" . $tm, -2);
    return date("Y-m-d\TH:i:s", $t). "${ts}${tz}:${tm}";
}

// It seems to be better to use strtotime() function instead custom parsing as
// it supports also RFC-822 date time (e.g. "Fri, 30 Jun 2006 00:00:00 +0200")
// used in rss feeds.
/** iso8601_to_unixstamp function
 * @param $t
 * @return false|int
 */
function iso8601_to_unixstamp($t) {
    return strtotime($t);
}

$DEFAULT_RSS_MAP = [
    // Note this matches code in xml_rssparse.php3 for parsing DC fields
    // Can change the names without affecting anything
        "author.........." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"DC/creator",                                "from_field_name"=>"DC:creator"],
        "abstract........" => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"ITEM/description|DC/description|DC/subject","from_field_name"=>"Any abstract"],
        "publish_date...." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"DATE(DC/date)|DATE(ITEM/pubdate)|NOW",      "from_field_name"=>"DC:date"],
        "source.........." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"DC/source|CHANNEL/title",                   "from_field_name"=>"DC:source"],
        "lang_code......." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"DC/language",                               "from_field_name"=>"DC:language"],
        "source_href....." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"DC/relation|CHANNEL/link",                  "from_field_name"=>"DC:relation"],
        "place..........." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"DC/coverage",                               "from_field_name"=>"DC:coverage"],
        "headline........" => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"DC/title|ITEM/title",                       "from_field_name"=>"DC:title"],
        "full_text......." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"CONTENT",                                   "from_field_name"=>"Content"],
        "status_code....." => ["feedmap_flag"=>FEEDMAP_FLAG_VALUE,"value"=>2,                                           "from_field_name"=>"Approved"],
        "hl_href........." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"ITEM/link|ITEM/guid",                       "from_field_name"=>"ITEM:link"],
        "expiry_date....." => ["feedmap_flag"=>FEEDMAP_FLAG_VALUE,"value"=>(time()+2000*24*60*60),                      "from_field_name"=>"Expiry Date"]
];

/** attr2id function
 * @param $str
 *
 * This function converts an attribute string to a unique id,
 * this function must: always return the same result; and not contain 00 or 27
 * the tricky part is that APC attribute strings contain a prefix and 32 digits,
 * while non APC strings need the whole string hashed.
 * @return string id
 */
function attr2id($str) {
    if (preg_match("~/(items|cat|slices)/([0-9a-f]{32})~",$str,$regs)) { // Looks like an APC id
        return $regs[2]; // Maybe this should be 0 ?
    } else {
        return(string2id($str));
    }
}

/** name2rssfeed function
 * @param string $slice_id
 * @param string $name
 * @return array
 */
function name2rssfeed($slice_id,$name) {
    $res = [];
    $db = getDB();
    $db->query("SELECT * FROM rssfeeds WHERE name='$name' AND slice_id = '".q_pack_id($slice_id)."'");
    if ($db->next_record()) {
      $res = $db->record();
      $res["feed_type"] = FEEDTYPE_RSS;
      $res["remote_slice_id"] = attr2id($db->f('server_url'));
    }
    freeDB($db);
    return $res;
}

/** getFeedTypeName function
 *  Translates feed type number to human readable name (1 -> RSS, ...)
 * @param $type
 * @return string
 */
function getFeedTypeName($type) {
    return (($type == 1) ? 'RSS' : (($type == 2) ? 'APC' : 'EXACT'));
}

/** used as parameter for $itemview in generating item_id-lastedit pair */
function GetXml_GetContent($zids) {
    return GetItemContentMinimal($zids, ['id', 'last_edit']);
}

class LastEditList {
    var $lastlist = null;

    /** setFromSlice function
     *  return list of items with last_edit date (id-last_edit,) used for 'exact'
     *  feeding between slices
     *  @param $conds
     *  @param $slice (by link)
     */
    function setFromSlice($conds, $slice) {
        ParseEasyConds($conds);
        $zids    = QueryZids( [$slice->getId()], $conds, '', 'ALL');
        $format  = [
            'odd_row_format' => '{id..............}-{last_edit.......}',
                         'row_delimiter'  => ','
        ];
        $itemview = new itemview($format, '', $zids, 0, $zids->count(), '', '', 'GetXml_GetContent');
        $this->lastlist = $itemview->get_output("view");
    }
    /** setList function
     * @param $list
     */
    function setList($list) {
        $this->lastlist = $list;
    }
    /** printList function
     *
     */
    function printList() {        // print() can't be name of the method :-( -
        echo $this->lastlist;     // parse error, unexpected T_PRINT, expecting
    }                             // T_STRING (php 4.3.10),
                                  // so I postfixed all functions with *List :-(
    /** getList function
     *
     */
    function getList() {
        return $this->lastlist;
    }
    /** getPairs function
     *
     */
    function getPairs() {
        $ret = [];
        foreach (explode(',', $this->lastlist) as $pair) {
            [$id,$time] = explode('-', $pair);
            if ($id) {
                $ret[$id]   = $time;
            }
        }
        return $ret;
    }
}


