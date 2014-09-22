<?php
/**
 *
 *
 * PHP versions 4 and 5
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
 * @link      http://www.apc.org/ APC
 *
 *
 *  Cross-Server Networking - module server
 *
 * expected:
 *    $node_name           - the name of the node making the request
 *    $password            - the password of the node
 *    $user                - a user at the remote node. This is the user who is trying
 *                           to establish a feed or who established the feed
 *    $slice_id            - The id of the local slice from which a feed is requested
 *    $start_timestamp     - a timestamp which indicates the creation time of the first item to be sent.
 *                           (www.w3.org/TR/NOTE-datetime format)
 *    $categories          - a list of local categories ids separated by space (can be empty)
 */

// ----- input variables normalization - start --------------------------------

// This code handles with "magic quotes" and "register globals" PHP (<5.4) setting
// It make us sure, taht
//  1) in $_POST,$_GET,$_COOKIE,$_REQUEST variables the values are not quoted
//  2) the variables are imported in global scope and is quoted
// We are trying to remove any dependecy on the point 2) and use only $_* superglobals
function AddslashesDeep($value)   { return is_array($value) ? array_map('AddslashesDeep',   $value) : addslashes($value);   }
function StripslashesDeep($value) { return is_array($value) ? array_map('StripslashesDeep', $value) : stripslashes($value); }

if ( get_magic_quotes_gpc() ) {
    $_POST    = StripslashesDeep($_POST);
    $_GET     = StripslashesDeep($_GET);
    $_COOKIE  = StripslashesDeep($_COOKIE);
    $_REQUEST = StripslashesDeep($_REQUEST);
}

if (!ini_get('register_globals') OR !get_magic_quotes_gpc()) {
    foreach ($_REQUEST as $k => $v) {
        $$k = AddslashesDeep($v);
    }
}
// ----- input variables normalization - end ----------------------------------


require_once "../include/config.php3";
require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."csn_util.php3";
require_once AA_INC_PATH."convert_charset.class.php3";
require_once AA_INC_PATH."slice.class.php3";

//-------------------------- Constants -----------------------------------------

$FORMATS = array("HTML"  => "http://www.isi.edu/in-notes/iana/assignments/media-types/text/html",
                 "PLAIN" => "http://www.isi.edu/in-notes/iana/assignments/media-types/text/plain");

$MAP_DC2AA = array("title"       => "headline",
                   "creator"     => "author",
                   "subject"     => "abstract",
                   "description" => "abstract",
                   "date"        => "publish_date",
                   "source"      => "source",
                   "language"    => "lang_code",
                   "relation"    => "source_href",
                   "coverage"    => "place");

$XML_BEGIN = '<'.'?xml version="1.0" encoding="UTF-8"?'. ">\n".
"<rdf:RDF
        xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\"
        xmlns:aa=\"http://www.apc.org/rss/aa-module.html\"
        xmlns:dc=\"http://purl.org/dc/elements/1.1/\"
        xmlns:content=\"http://purl.org/rss/1.0/modules/content/\"
        xmlns=\"http://purl.org/rss/1.0/\">\n";

//-------------------------- Function definitons -------------------------------

/** The output must be in utf-8. Unfortunatelly PHP do not support conversion
  * form other than iso-8895-1 charsets to UTF-8, yet, so we have to use special
  * another conversion class for it
  */
/** code function
 * @param $v
 * @param $cdata=false
 * @return converted string
 */
function code($v, $cdata=false) {
    static $encoder;
    if ( !$encoder ) {
        $encoder = new ConvertCharset;
    }
    if ( !$cdata ) {
        return $encoder->Convert(myspecialchars($v), $GLOBALS['g_slice_encoding'], 'utf-8');
    }
    // no htmlspecialchars, here
    return '<![CDATA['.$encoder->Convert($v, $GLOBALS['g_slice_encoding'], 'utf-8').']]>';
}
/** GetFlagFormat function
 * @param $flag
 * @return "HTML"/"PLAIN"
 */
function GetFlagFormat($flag) {
    return ($flag & HTML_FORMAT) ? "HTML" : "PLAIN";
}
/** Error function
 * @param $str
 * @return prints $str and exit()s
 */
function Error($str) {
    echo "$str";
    exit();
}

/** CheckNameAndPassword function
 * Check the node_name and password against the nodes table's data
 * @param $node_name
 * @param $password
 * @return bool
 */
function CheckNameAndPassword( $node_name, $password ) {
    global $db;
    $db->query("SELECT password FROM nodes WHERE name='$node_name'");
    return ($db->next_record() AND ($db->f('password') == $password));
}

/** GetFeedingSlices function
 * Find correct feeding slices
 * @param $node_name
 * @param $user
 * @return array of slice ids
 */
function GetFeedingSlices( $node_name, $user) {
    global $db;

    $db->query("SELECT slice_id FROM ef_permissions WHERE (node='$node_name' OR node='')
                                                    AND (user='$user' OR user='')");
    while ($db->next_record()) {
        $slices[] = unpack_id($db->f(slice_id));
    }
    return $slices;
}

/** CheckFeedingPermissions function
  * looks up permissions for the slice $slice_id,
  * the user $user of node node_name in the table of permissions
  * @param $slice_id
  * @param $node_name
  * @param $user
  * @return next_record from db of permissions
  */
function CheckFeedingPermissions( $slice_id, $node_name, $user ) {
    global $db;
    $db->query("SELECT slice_id FROM ef_permissions WHERE slice_id='".q_pack_id($slice_id)."'
                                                      AND (node='$node_name' OR node='')
                                                      AND (user='$user' OR user='')");
    return $db->next_record();
}
/** GetXMLFields function
 * @param $slice_id
 * @param $slice_fields (by link)
 * @param $xml_fields_refs (by link)
 * @param $xml_fields (by link)
 * @return modified vars above
 */
function GetXMLFields( $slice_id, &$slice_fields, &$xml_fields_refs, &$xml_fields) {
    $xml_fields_refs.="\t<aa:fields><rdf:Bag>\n";

    foreach ( $slice_fields as $k =>$v) {
        $xml_fields_refs.="\t\t<rdf:li rdf:resource=\"".AA_INSTAL_URL."field/$slice_id/$k\"/>\n";
        $xml_fields .= "<aa:field rdf:about=\"".AA_INSTAL_URL."field/$slice_id/$k\">\n".
                          "\t<aa:name>".code($v['name'])."</aa:name>\n".
                          "\t<aa:id>$k</aa:id>\n".
                       "</aa:field>\n";
    }
    $xml_fields_refs.="\t</rdf:Bag></aa:fields>\n";
}
/** GetXMLCategories
 * @param $slice_id
 * @param $xml_categories_refs (by link)
 * @param $xml_categories (by link)
 * @return modified vars above
 */
function GetXMLCategories($slice_id, &$xml_categories_refs, &$xml_categories) {
    global $db;

    $group_id = GetCategoryGroup($slice_id);
    if (!$group_id) {
        return;
    }
    $SQL = "SELECT id, name, value, class FROM constant WHERE group_id='$group_id'";
    $db->query($SQL);

    $xml_categories_refs.="\t<aa:categories><rdf:Bag>\n";
    while ($db->next_record()) {
        $id = unpack_id($db->f('id'));
        $xml_categories .= "<aa:category rdf:about=\"".AA_INSTAL_URL."cat/$id\">\n".
                              "\t<aa:name>".code($db->f('name'))."</aa:name>\n".
                              "\t<aa:value>".code($db->f('value'))."</aa:value>\n".
                              "\t<aa:id>$id</aa:id>\n".
                              "\t<aa:catparent>".code($db->f('class'))."</aa:catparent>\n".
                           "</aa:category>\n";
        $xml_categories_refs .="\t\t<rdf:li rdf:resource=\"".AA_INSTAL_URL."cat/$id\"/>\n";
    }
    $xml_categories_refs.="\t</rdf:Bag></aa:categories>\n";
}
/** GetXMLChannel function
 * @param $slice_id
 * @param $xml_fields_refs (by link)
 * @param $xml_categories_refs (by link)
 * @param $xml_items_refs (by link)
 * @param $time
 * @return prints xml <channel>
 */
function GetXMLChannel( $slice_id, &$xml_fields_refs, &$xml_categories_refs, &$xml_items_refs, $time) {
    $slice = AA_Slices::getSlice($slice_id);
    echo "\t<channel rdf:about=\"".AA_INSTAL_URL."slices/$slice_id\">\n".
                   "\t\t<title>".code($slice->getProperty('name'))."</title>\n".
                   "\t\t<description>".code($slice->getProperty('description'))."</description>\n".
                   "\t\t<link>".code($slice->getProperty('slice_url'))."</link>\n".
                   "\t\t<aa:newestitemtimestamp>$time</aa:newestitemtimestamp>\n".
                   "\t\t<dc:identifier>$slice_id</dc:identifier>\n".
                   $xml_fields_refs.
                   $xml_categories_refs.
                   $xml_items_refs.
                   "\t</channel>\n";
}
/** GetBaseFieldContent function
 * @param $slice_fields (by link)
 * @param $ftype
 * @param $content4id (by link)
 * @return content
 */
function GetBaseFieldContent(&$slice_fields, $ftype, &$content4id) {
    if ($ftype=="") {
        return "";
    }
    $f    = GetBaseFieldId($slice_fields,$ftype);
    $cont = $content4id[$f][0];
    return ($cont['flag'] & HTML_FLAG) ? strip_tags($cont['value']) : $cont['value'];
}
/** GetXMLFieldData function
 * @param $slice_id
 * @param $field_id
 * @param $content4id (by link)
 * @return fielddata xml tags
 */
function GetXMLFieldData($slice_id, $field_id, &$content4id) {
    global $FORMATS;

    $cont_vals = $content4id[$field_id];

    // the id should be unpacked for transmition
    if ( ($field_id == 'id..............') AND (guesstype($cont_vals[0]['value']) == 'p')) {
        $cont_vals[0]['value'] = unpack_id($cont_vals[0]['value']);
    }

    if (!$cont_vals || !is_array($cont_vals)) {
        return;
    }

    foreach ($cont_vals as $v) {
        $flag_format = GetFlagFormat($v['flag']);
        $out .= "\t\t<rdf:li><aa:fielddata>\n".
                   "\t\t\t<aa:field rdf:resource=\"".AA_INSTAL_URL."field/$slice_id/$field_id\"/>\n".
                   "\t\t\t<aa:fieldflags>".$v['flag']."</aa:fieldflags>\n".
                   "\t\t\t<aa:format rdf:resource=\"".$FORMATS[$flag_format]."\"/>\n".
                   "\t\t\t<rdf:value>". code($v['value'], $flag_format=="HTML"). "</rdf:value>\n".
                "\t\t</aa:fielddata></rdf:li>\n";
   }
   return $out;
}

/** GetXMLItem function
 * Get one item
 * @param $slice_id
 * @param $item_id
 * @param $content4id (by link)
 * @param $slice_fields (by link)
 * @return $xml_items
 */
function GetXMLItem($slice_id, $item_id, &$content4id, &$slice_fields) {
    global $FORMATS, $MAP_DC2AA;
    static $value2const_id;
    static $slice_url;

    // create RSS elements
    $title       = GetBaseFieldContent($slice_fields,"headline", $content4id);
    $description = GetBaseFieldContent($slice_fields,"abstract", $content4id);
    $hl_href     = GetBaseFieldContent($slice_fields,"hl_href",  $content4id);
    $link_only   = $hl_href;
    // older approach is to use link_only field. We do not use this approach for
    // for newer slices - link is extenal if hl_href is filled. Dot.
    // $link_only   = GetBaseFieldContent($slice_fields,"link_only",$content4id);

    // get slice url for current slice
    if ( !isset($slice_url[$slice_id]) ) {
        $slice = AA_Slices::getSlice($slice_id);
        $slice_url[$slice_id] = $slice->getProperty('slice_url');
    }

    $item_link = ($link_only ? $hl_href : con_url($slice_url[$slice_id],"x=".$content4id['short_id........'][0]['value']) );

    $xml_items .= "<item rdf:about=\"".AA_INSTAL_URL     ."items/$item_id\">\n".
                  "\t<title>"         .code($title)      ."</title>\n".
                  "\t<description>"   .code($description)."</description>\n".
                  "\t<link>"          .code($item_link)  ."</link>\n".
                  "\t<dc:identifier>$item_id</dc:identifier>\n";

    // create fulltext in the element <content:items>
    if (!$link_only) {
        $f = GetBaseFieldId($slice_fields, "full_text");
        if ($f) {
            $flag_format = GetFlagFormat($content4id[$f][0]['flag']);
            $xml_items .="\t<content:items><rdf:Bag>\n".
                           "\t\t<rdf:li><content:item>\n".
                              "\t\t\t<content:format rdf:resource=\"".$FORMATS[$flag_format]."\"/>\n".
                              "\t\t\t<rdf:value>". code($content4id[$f][0]['value'], $flag_format=="HTML"). "</rdf:value>\n".
                           "\t\t</content:item></rdf:li>\n".
                         "\t</rdf:Bag></content:items>\n";
        }
    }

    // create item's categories
    $item_categs = $content4id[GetBaseFieldId($slice_fields, "category")];

    if (is_array($item_categs)) {
        // get constants array from database ('val'=>'packed id')
        if ( !isset($value2const_id[$slice_id]) ) {
            // get and store it for later usage (it is static variable
            $value2const_id[$slice_id] = GetConstants( GetCategoryGroup($slice_id), '', 'id', 'value');
        }
        $xml_items.="\t<aa:categories><rdf:Bag>\n";
        foreach ($item_categs as $k => $v) {
            $p_cat_id = $value2const_id[$slice_id][$v['value']];
            if ( $p_cat_id ) {
                $xml_items .="\t\t<rdf:li rdf:resource=\"".AA_INSTAL_URL."cat/".unpack_id($p_cat_id)."\"/>\n";
            }
        }
        $xml_items.="\t</rdf:Bag></aa:categories>\n";
    }

    // create Dublin Core elements
    foreach ( $MAP_DC2AA as $k => $v) {
        $cont = GetBaseFieldContent($slice_fields,$v,$content4id);
        if ($v == "publish_date") { // convert publish date
            $cont = unixstamp_to_iso8601 ($cont);
        }
        $xml_items .= "\t<dc:$k>".code($cont)."</dc:$k>\n";
    }

    // create AA field data elements
    //  $f = array("headline", "abstract", "link_only", "hl_href", "full_text" ,"category", "slice_id");
    //  $f = array("full_text" ,"category", "slice_id");
    //  now we will send also category field (there could be (in special case)
    //  also values, which arn't in category definition (csv filled items, fed, ...)
    $f = array("full_text", "slice_id");

    foreach ( $f as $k => $v) {        // create array of elements, which will be skipped
        $rss[GetBaseFieldId($slice_fields,$v)] = $v;
    }
    $xml_items .= "\t<aa:fielddatacont><rdf:Bag>\n";
    foreach ($slice_fields as $k => $v) {
        if (isset($rss[$k])) {          // do not create rss elements
            continue;
        }
        $xml_items .= GetXMLFieldData($slice_id, $k, $content4id);

    }
    $xml_items .="\t</rdf:Bag></aa:fielddatacont>\n".
                 "\t</item>\n";
    return $xml_items;
}
/** CreateXMLItems function
 * @param $slice_id
 * @param $items_ids (by link)
 * @param $content (by link)
 * @param $slice_fields (by link)
 * @return prints a result of the GetXMLItem() function for all items
 *
 */
function CreateXMLItems($slice_id, &$items_ids, &$content, &$slice_fields) {
    foreach ($items_ids as $id)  {
        echo GetXMLItem($slice_id, $id, $content[$id], $slice_fields);
    }
}
/** GetXMLItemsRefs function
 * @param $item_ids (by link)
 * @return prints <items> and <rdf> tags for each item
 */
function GetXMLItemsRefs(&$items_ids) {
    $out .="\t<items><rdf:Seq>\n";
    foreach ( $items_ids as $id) {
        $out .="\t\t<rdf:li rdf:resource=\"".AA_INSTAL_URL."items/$id\"/>\n";
    }
    $out .="\t</rdf:Seq></items>\n";
    return $out;
}

/** RestrictIdsByCategory function
 * Takes array of item ids and returns only the item ids which belongs to any
 *  of specified categories
 * @param $ids (by link)
 * @param $categories (by link)
 * @param $slice_id
 * @param $content (by link)
 * @param $cat_field
 * @return ids
 */
function RestrictIdsByCategory( &$ids, &$categories, $slice_id, &$content, $cat_field ) {
    $new_ids = array();
    if ( !is_array($ids) OR !is_array($categories) ) {
        return $new_ids;                              // empty array
    }

    $consts = GetGroupConstants($slice_id);      // get categories belongs to $slice_id

    // create array of requested categories ids indexed by value
    foreach ( $categories as $cat ) {
        // special category used in AA>= 2.8 - if provided, all items are
        // returned and sent. The filtering is done on destination side.
        if ( $cat == UNPACKED_AA_OTHER_CATEGOR ) {
            return $ids;
        }
        if ($consts[$cat]) {
            $translate_val2id[$consts[$cat]['value']] = $cat;
        }
    }


    // find out all items, which belongs to requested categories - restrict
    foreach ( $ids as $k => $id ) {                   // for all items
        $item_categories = $content[$id][$cat_field];
        if ( is_array($item_categories) ) {           // test all categories
            foreach ( $item_categories as $v ) {
                if ($translate_val2id[$v['value']]) {
                    $new_ids[] = $id;
                    break;                            // next item
                }
            }
        }
    }
    return $new_ids;
}

//------------------------------------------------------------------------------

is_object( $db ) || ($db = getDB());

// check the node_name and password against the nodes table's data
if (!CheckNameAndPassword($node_name, $password)) {
    Error(ERR_PASSWORD);
}

$xml_channel = $used_fields = $xml_items = "";

if (!$slice_id) {

    /**  feed establishing mode --------------------------- */

    $slice_ids = GetFeedingSlices( $node_name, $user );
    if (!$slice_ids) {
        Error(ERR_NO_SLICE);
    }
    echo $XML_BEGIN;
    foreach ($slice_ids as $sl_id) {
        $GLOBALS['g_slice_encoding'] = getSliceEncoding($sl_id);
        list( $slice_fields,)        = GetSliceFields( $sl_id );
        $xml_categories_refs         = $xml_fields_refs = "";      // clear fields and categories for this channel
        GetXMLFields(     $sl_id, $slice_fields, $xml_fields_refs,  $xml_fields);   // get fields
        GetXMLCategories( $sl_id, $xml_categories_refs, $xml_categories ); //get categories
        echo GetXMLChannel( $sl_id, $xml_fields_refs, $xml_categories_refs, $xml_items_refs, $time); // echo channel
    }
    echo $xml_fields;
    echo $xml_categories;
    echo "</rdf:RDF>";

    exit;
}

/**  item feeding mode -------------------------------- */
if (!CheckFeedingPermissions($slice_id, $node_name, $user)) {
    Error("Invalid permissions - slice_id: $slice_id, node_name: $node_name, user:$user");
}
$GLOBALS['g_slice_encoding'] = getSliceEncoding($slice_id);

$slice = AA_Slices::getSlice($slice_id);

if ($exact AND !$ids) {

    /** newer - 'exact' feeding mode based on conditions
     *
     *  Initial stage - return list of items with last_edit date (id-last_edit,)
     *  Caller side then decides, which items it wants and send us ids (see
     *  below)
     */
    $list = new LastEditList();
    $list->setFromSlice($conds, $slice);  // no conditions - all items
    $list->printList();
    exit;
}

if ($ids) {

    /** newer - 'exact' feeding mode based on conditions - continue
     *
     *  Second step of feeding (see above) - Caller side selects ids it wants
     *  we will send it as normal apc rss feed
     */
    $ids           = explode('-',$ids);
    $restrict_zids = new zids($ids);
    $zids          = QueryZIDs( array($slice_id), $conds, '', 'ALL', 0, $restrict_zids);
    $ids           = $zids->longids();
    if ($ids) {
        $content        = GetItemContent($ids);     // get the content of all items
        $xml_items_refs = GetXMLItemsRefs($ids);
    }

} else {

    /** old good apc item feeding mode */

    // fix date (sometimes start_timestamp contains space (wrongly) instead of
    // plus '+' sign (besause wrong url where + is translated to space)
    $start_timestamp      = str_replace(' ', '+', trim($start_timestamp));
    $start_timestamp      = iso8601_to_unixstamp($start_timestamp);
    $tmpobj               = $slice->getFields();
    $cat_field            = $tmpobj->getCategoryFieldId();
    $p_slice_id           = q_pack_id($slice_id);

    $now   = now();
    $cond1 = "(item.status_code=1 AND item.publish_date <= '$now' AND item.expiry_date > '$now')";
    $cond2 = "(item.last_edit >'$start_timestamp' OR item.publish_date > '$start_timestamp')";

    $SQL   = "SELECT id, publish_date, last_edit FROM item
               WHERE slice_id = '$p_slice_id'
                 AND $cond1
                 AND $cond2";
    // AND (externally_fed='' OR externally_fed IS NULL)

    $db->query($SQL);

    $ids  = "";
    $time = 0;
    while ($db->next_record()) {
        $ids[] = unpack_id($db->f(id));
        $time  = max( $time, $db->f('publish_date'), $db->f('last_edit'));   // save time of the newest item
    }
    $time = unixstamp_to_iso8601($time);

    if ($ids) {
        $content = GetItemContent($ids);     // get the content of all items

        // if caller do not provide category[] array (where specified which
        // categories he wants) or slice has no category field, we send all
        // items. (in AA >=2.8 category[] array is sent with special
        // UNPACKED_AA_OTHER_CATEGOR which means "send all items")
        if ($categories && $cat_field) {
            // if we provide categories array, restrict the ids
            // special UNPACKED_AA_OTHER_CATEGOR category is just like joker
            $ids = RestrictIdsByCategory( $ids, explode(" ",$categories), $slice_id, $content, $cat_field );
        }
        $xml_items_refs = GetXMLItemsRefs($ids);
    }
}

echo $XML_BEGIN;
GetXMLFields(     $slice_id, $slice->fields('record'), $xml_fields_refs,  $xml_fields);   // get fields and fields refs
GetXMLCategories( $slice_id, $xml_categories_refs, $xml_categories ); //get categories and cat refs
echo GetXMLChannel( $slice_id, $xml_fields_refs, $xml_categories_refs,$xml_items_refs,$time); // echo channel

// Channel(s) was already printed, so print fields and categories and also items (feeding mode)
echo $xml_fields;
echo $xml_categories;

if ($slice_id && $ids) {        // feeding mode
    CreateXMLItems($slice_id, $ids, $content, $slice->fields('record'));
}

echo "</rdf:RDF>";

?>
