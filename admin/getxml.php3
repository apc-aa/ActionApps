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

/**  Cross-Server Networking - module server
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
  if( isset($HTTP_GET_VARS) AND is_array($HTTP_GET_VARS))
    for (reset($HTTP_GET_VARS); list($k, $v) = each($HTTP_GET_VARS); )
      $$k = Myaddslashes($v);
  if( isset($HTTP_POST_VARS) AND is_array($HTTP_POST_VARS))
    for (reset($HTTP_POST_VARS); list($k, $v) = each($HTTP_POST_VARS); )
      $$k = Myaddslashes($v);
  if( isset($HTTP_COOKIE_VARS) AND is_array($HTTP_COOKIE_VARS))
    for (reset($HTTP_COOKIE_VARS); list($k, $v) = each($HTTP_COOKIE_VARS); )
      $$k = Myaddslashes($v);
}

require_once "../include/config.php3";
require_once $GLOBALS["AA_INC_PATH"]."locsess.php3";
require_once $GLOBALS["AA_INC_PATH"]."util.php3";
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
require_once $GLOBALS["AA_INC_PATH"]."csn_util.php3";
require_once $GLOBALS["AA_INC_PATH"]."convert_charset.class.php3";

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
function code($v, $html=true) {
    static $encoder;
    if ( !$encoder ) {
        $encoder = new ConvertCharset;
    }
    if ( $html ) {
        $v = htmlspecialchars($v);
    }
    return $encoder->Convert($v, $GLOBALS['g_slice_encoding'], 'utf-8');
}

function GetFlagFormat($flag) {
    return ($flag & HTML_FORMAT) ? "HTML" : "PLAIN";
}

function Error($str) {
    echo "$str";
    exit();
}

/** Check the node_name and password against the nodes table's data */
function CheckNameAndPassword( $node_name, $password ) {
    global $db;
    $db->query("SELECT password FROM nodes WHERE name='$node_name'");
    return ($db->next_record() AND ($db->f('password') == $password));
}

/** Find correct feeding slices */
function GetFeedingSlices( $node_name, $user) {
    global $db;

    $db->query("SELECT slice_id FROM ef_permissions WHERE (node='$node_name' OR node='')
                                                    AND (user='$user' OR user='')");
    while ($db->next_record()) {
        $slices[] = unpack_id128($db->f(slice_id));
    }
    return $slices;
}

/** looks up permissions for the slice $slice_id,
  * the user $user of node node_name in the table ef permissions
  */
function CheckFeedingPermissions( $slice_id, $node_name, $user ) {
    global $db;
    $db->query("SELECT slice_id FROM ef_permissions WHERE slice_id='".q_pack_id($slice_id)."'
                                                      AND (node='$node_name' OR node='')
                                                      AND (user='$user' OR user='')");
    return $db->next_record();
}

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

function GetXMLCategories( $slice_id, &$slice_fields, &$xml_categories_refs, &$xml_categories ) {
    global $db;

    $group_id = GetCategoryGroup($slice_id);
    if (!$group_id) return;
    $SQL= "SELECT id, name, value, class FROM constant WHERE group_id='$group_id'";
    $db->query($SQL);

    $xml_categories_refs.="\t<aa:categories><rdf:Bag>\n";
    while ($db->next_record()) {
        $id = unpack_id128($db->f('id'));
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

function CreateXMLChannel( $slice_id, &$xml_fields_refs, &$xml_categories_refs, &$xml_items_refs,$time) {
    $sli = GetSliceInfo($slice_id);
    echo "\t<channel rdf:about=\"".AA_INSTAL_URL."slices/$slice_id\">\n".
                   "\t\t<title>".code($sli[name])."</title>\n".
                   "\t\t<description>".code($sli[description])."</description>\n".
                   "\t\t<link>".code($sli[slice_url])."</link>\n".
                   "\t\t<aa:newestitemtimestamp>$time</aa:newestitemtimestamp>\n".
                   "\t\t<dc:identifier>$slice_id</dc:identifier>\n".
                   $xml_fields_refs.
                   $xml_categories_refs.
                   $xml_items_refs.
                   "\t</channel>\n";
}

function GetBaseFieldContent(&$slice_fields, $ftype, &$content4id) {
    if ($ftype=="") return "";
    $f    = GetBaseFieldId($slice_fields,$ftype);
    $cont = $content4id[$f][0];
    return ($cont['flag'] & HTML_FLAG) ? strip_tags($cont['value']) : $cont['value'];
}

function GetXMLFieldData($slice_id,&$slice_fields, $field_id, &$content4id) {
    global $FORMATS;

    $cont_vals = $content4id[$field_id];
    if (!$cont_vals || !is_array($cont_vals))  return;

    foreach ($cont_vals as $v) {
        $flag_format = GetFlagFormat($v['flag']);
        $out .= "\t\t<rdf:li><aa:fielddata>\n".
                   "\t\t\t<aa:field rdf:resource=\"".AA_INSTAL_URL."field/$slice_id/$field_id\"/>\n".
                   "\t\t\t<aa:fieldflags>".$v['flag']."</aa:fieldflags>\n".
                   "\t\t\t<aa:format rdf:resource=\"".$FORMATS[$flag_format]."\"/>\n".
                   "\t\t\t<rdf:value>". ($flag_format=="HTML" ? "<![CDATA[".code($v['value'], false)."]]>\n" :
                                                                 code($v['value'])).
                        "</rdf:value>\n".
                "\t\t</aa:fielddata></rdf:li>\n";
   }
   return $out;
}

/** Get one item */
function GetXMLItem($slice_id, $item_id, &$content4id, &$slice_fields) {
    global $FORMATS, $MAP_DC2AA;
    static $value2const_id;


    // create RSS elements
    $title       = GetBaseFieldContent($slice_fields,"headline", $content4id);
    $description = GetBaseFieldContent($slice_fields,"abstract", $content4id);
    $link_only   = GetBaseFieldContent($slice_fields,"link_only",$content4id);
    $hl_href     = GetBaseFieldContent($slice_fields,"hl_href",  $content4id);

    $xml_items .= "<item rdf:about=\"".AA_INSTAL_URL."items/$item_id\">\n".
                  "\t<title>".code($title)."</title>\n".
                  "\t<description>".code($description)."</description>\n".
                  "\t<link>".($link_only ? code($hl_href) : "")."</link>\n".
                  "\t<dc:identifier>$item_id</dc:identifier>\n";

    // create fulltext in the element <content:items>
    if (!$link_only) {
        $f = GetBaseFieldId($slice_fields, "full_text");
        if ($f) {
            $flag_format = GetFlagFormat($content4id[$f][0]['flag']);
            $xml_items .="\t<content:items><rdf:Bag>\n".
                           "\t\t<rdf:li><content:item>\n".
                              "\t\t\t<content:format rdf:resource=\"".$FORMATS[$flag_format]."\"/>\n".
                              "\t\t\t<rdf:value>".
                          ($flag_format=="HTML" ? ("<![CDATA[".code($content4id[$f][0]['value'],false)."]]>\n") :
                                                  code($content4id[$f][0]['value'])).
                              "</rdf:value>\n".
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
                $xml_items .="\t\t<rdf:li rdf:resource=\"".AA_INSTAL_URL."cat/".unpack_id128($p_cat_id)."\"/>\n";
            }
        }
        $xml_items.="\t</rdf:Bag></aa:categories>\n";
    }

    $p_date_id = GetBaseFieldId($slice_fields,"publish_date");      // get publish_date field id

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
        $xml_items .= GetXMLFieldData($slice_id,$slice_fields, $k, $content4id);

    }
    $xml_items .="\t</rdf:Bag></aa:fielddatacont>\n".
                 "\t</item>\n";
    return $xml_items;
}

function CreateXMLItems($slice_id, &$items_ids, &$content, &$slice_fields) {
    foreach ($items_ids as $id)  {
        echo GetXMLItem($slice_id, $id, $content[$id], $slice_fields);
    }
}

function GetXMLItemsRefs(&$items_ids) {
    $out .="\t<items><rdf:Seq>\n";
    foreach ( $items_ids as $id) {
        $out .="\t\t<rdf:li rdf:resource=\"".AA_INSTAL_URL."items/$id\"/>\n";
    }
    $out .="\t</rdf:Seq></items>\n";
    return $out;
}

/** Takes array of item ids and returns only the item ids which belongs to any
 *  of specified categories
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

$db = new DB_AA;

// check the node_name and password against the nodes table's data
if ( !CheckNameAndPassword($node_name, $password ))
Error(ERR_PASSWORD);

$xml_channel = $used_fields = $xml_items = "";

if (!$slice_id) {

    /**  feed establishing mode */
    $slice_ids = GetFeedingSlices( $node_name, $user );
    if (!$slice_ids) {
        Error(ERR_NO_SLICE);
    }
    echo $XML_BEGIN;
    foreach ($slice_ids as $sl_id) {
        $GLOBALS['g_slice_encoding'] = getSliceEncoding($sl_id);
        list( $slice_fields,) = GetSliceFields( $sl_id );
        GetXMLFields(     $sl_id, $slice_fields, $xml_fields_refs,  $xml_fields);   // get fields
        GetXMLCategories( $sl_id, $slice_fields, $xml_categories_refs, $xml_categories ); //get categories
        CreateXMLChannel( $sl_id, $xml_fields_refs, $xml_categories_refs,$xml_items_refs,$time); // echo channel
        $xml_categories_refs= $xml_fields_refs = "";      // clear fields and categories for next channel
    }
} else {

    /** feeding mode */
    if (!CheckFeedingPermissions($slice_id, $node_name, $user)) {
        Error("Invalid permissions - slice_id: $slice_id, node_name: $node_name, user:$user");
    }
    $GLOBALS['g_slice_encoding'] = getSliceEncoding($slice_id);

    echo $XML_BEGIN;
    list( $slice_fields,) = GetSliceFields( $slice_id );

    $start_timestamp      = iso8601_to_unixstamp($start_timestamp);
    $cat_field            = GetCategoryFieldId($slice_fields);

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
        $ids[] = unpack_id128($db->f(id));
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
    GetXMLFields(     $slice_id, $slice_fields, $xml_fields_refs,  $xml_fields);   // get fields and fields refs
    GetXMLCategories( $slice_id, $slice_fields, $xml_categories_refs, $xml_categories ); //get categories and cat refs
    CreateXMLChannel( $slice_id, $xml_fields_refs, $xml_categories_refs,$xml_items_refs,$time); // echo channel
}

// Channel(s) was already printed, so print fields and categories and also items (feeding mode)
echo $xml_fields;
echo $xml_categories;

if ($slice_id && $ids) {        // feeding mode
    CreateXMLItems($slice_id, $ids, $content, $slice_fields);
}

echo "</rdf:RDF>"

?>
