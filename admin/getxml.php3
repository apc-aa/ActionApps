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

#
# Cross-Server Networking - module server
#

# expected $node_name           - the name of the node making the request
#          $password            - the password of the node
#          $user                - a user at the remote node. This is the user who is trying
#                               - to establish a feed or who established the feed
#          $slice_id            - The id of the local slice from which a feed is requested
#          $start_timestamp     - a timestamp which indicates the creation time of the first item to be sent.
#                               - (www.w3.org/TR/NOTE-datetime format)
#          $categories          - a list of local categories ids separated by space (can be empty)


/*
function ech($text) {
  if( isset($text) and is_array($text) ) {
    echo "<div><font color=blue>";
    print_r( $text );
    echo "</font></div>";
  } else 
    echo "<div><font color=blue>$text</font></div>";
}
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

require "../include/config.php3";
require $GLOBALS[AA_INC_PATH]."locsess.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."csn_util.php3";

//-------------------------- Constants -----------------------------------------

$FORMATS = array("HTML" =>  "http://www.isi.edu/in-notes/iana/assignments/media-types/text/html",
                 "PLAIN" => "http://www.isi.edu/in-notes/iana/assignments/media-types/text/plain");

$MAP_DC2AA = array("title" => "headline",
                   "creator" => "author",
                   "subject" => "abstract",
                   "description" => "abstract",
                   "date" => "publish_date",
                   "source" => "source",
                   "language" => "lang_code",
                   "relation" => "source_href",
                   "coverage" => "place");

$XML_BEGIN = '<'.'?xml version="1.0"?'. ">\n".
"<rdf:RDF
        xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\"
        xmlns:aa=\"http://www.apc.org/rss/aa-module.html\"
        xmlns:dc=\"http://purl.org/dc/elements/1.1/\"
        xmlns:content=\"http://purl.org/rss/1.0/modules/content/\"
        xmlns=\"http://purl.org/rss/1.0/\">\n";

//-------------------------- Function definitons -------------------------------

function code($v) {
  return utf8_encode(htmlspecialchars($v));
}

function GetFlagFormat($flag) {
  if ($flag & HTML_FORMAT == HTML_FORMAT)
    return "HTML";
  else
    return "PLAIN";
}

function Error($str) {
  echo "$str";
  exit();
}

// check the node_name and password against the nodes table's data
function CheckNameAndPassword( $node_name, $password ) {
  global $db;

  $db->query("SELECT password FROM nodes WHERE name='$node_name'");
  if ($db->next_record()) {
    if ($db->f(password) == $password)
      return true;
  }
  return false;
}

// find correct feeding slices
function GetFeedingSlices( $node_name, $user) {
  global $db;

  $db->query("SELECT slice_id FROM ef_permissions WHERE (node='$node_name' OR node='')
                                                    AND (user='$user' OR user='')");
  while ($db->next_record()) {
    $slices[] = unpack_id($db->f(slice_id));
  }
  return $slices;
}

// looks up permissions for the slice $slice_id, the user $user of node node_name in the table
// ef permissions
function CheckFeedingPermissions( $slice_id, $node_name, $user ) {
  global $db;
  $db->query("SELECT slice_id FROM ef_permissions WHERE slice_id='".q_pack_id($slice_id)."'
                                                    AND (node='$node_name' OR node='')
                                                    AND (user='$user' OR user='')");
  return $db->next_record();
}

function GetXMLFields( $slice_id, &$slice_fields, &$xml_fields_refs, &$xml_fields) {
  $xml_fields_refs.="\t<aa:fields><rdf:Bag>\n";

  reset($slice_fields);
  while (list($k,$v) = each($slice_fields)) {
    $xml_fields_refs.="\t\t<rdf:li rdf:resource=\"".AA_INSTAL_URL."field/$slice_id/$k\"/>\n";
    $xml_fields .= "<aa:field rdf:about=\"".AA_INSTAL_URL."field/$slice_id/$k\">\n".
                      "\t<aa:name>".code($v[name])."</aa:name>\n".
                      "\t<aa:id>$k</aa:id>\n".
                   "</aa:field>\n";
  }
  $xml_fields_refs.="\t</rdf:Bag></aa:fields>\n";
}

function GetXMLCategories( $slice_id, &$slice_fields, &$xml_categories_refs,
                         &$xml_categories ) {
  global $db;

  $group_id = GetCategoryGroup($slice_id);
  if (!$group_id)
    return;
  $SQL= "SELECT id, name, value, class FROM constant WHERE group_id='$group_id'";
  $db->query($SQL);

  $xml_categories_refs.="\t<aa:categories><rdf:Bag>\n";
  while ($db->next_record()) {
    $id = unpack_id($db->f(id));
    $xml_categories .= "<aa:category rdf:about=\"".AA_INSTAL_URL."cat/$id\">\n".
                          "\t<aa:name>".code($db->f(name))."</aa:name>\n".
                          "\t<aa:value>".code($db->f(value))."</aa:value>\n".
                          "\t<aa:id>$id</aa:id>\n".
                          "\t<aa:catparent>".code($db->f('class'))."</aa:catparent>\n".
                       "</aa:category>\n";
    $xml_categories_refs .="\t\t<rdf:li rdf:resource=\"".AA_INSTAL_URL."cat/$id\"/>\n";
  }
  $xml_categories_refs.="\t</rdf:Bag></aa:categories>\n";
}

function CreateXMLChannel( $slice_id, &$xml_fields_refs, &$xml_categories_refs,
                           &$xml_items_refs,$time) {
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
  if ($ftype=="")
    return "";
  $f = GetBaseFieldId($slice_fields,$ftype);
  $cont = $content4id[$f][0];
  if ($cont[flag] & HTML_FLAG == HTML_FLAG)
    return strip_tags($cont[value]);
  else
    return $cont[value];
}

function GetXMLFieldData($slice_id,&$slice_fields, $field_id, &$content4id) {
  global $FORMATS;

  $cont_vals = $content4id[$field_id];
  if (!$cont_vals || !is_array($cont_vals))
    return;

  while (list( ,$v) = each($cont_vals)) {
    $flag_format = GetFlagFormat($v[flag]);
    $out .= "\t\t<rdf:li><aa:fielddata>\n".
               "\t\t\t<aa:field rdf:resource=\"".AA_INSTAL_URL."field/$slice_id/$field_id\"/>\n".
               "\t\t\t<aa:fieldflags>".$v[flag]."</aa:fieldflags>\n".
               "\t\t\t<aa:format rdf:resource=\"".$FORMATS[$flag_format]."\"/>\n".
               "\t\t\t<rdf:value>". ($flag_format=="HTML" ? "<![CDATA[".$v[value]."]]>\n" :
                                                             code($v[value])).
                    "</rdf:value>\n".
            "\t\t</aa:fielddata></rdf:li>\n";
   }
   return $out;
}

// get one item
function GetXMLItem($slice_id,$item_id, &$content4id, &$item_categs, &$slice_fields) {
  global $FORMATS, $MAP_DC2AA;

  // create RSS elements
  $title = GetBaseFieldContent($slice_fields,"headline",$content4id);
  $description = GetBaseFieldContent($slice_fields,"abstract",$content4id);
  $link_only = GetBaseFieldContent($slice_fields,"link_only",$content4id);
  $hl_href = GetBaseFieldContent($slice_fields,"hl_href",$content4id);

  $xml_items .= "<item rdf:about=\"".AA_INSTAL_URL."items/$item_id\">\n".
                "\t<title>".code($title)."</title>\n".
                "\t<description>".code($description)."</description>\n".
                "\t<link>".($link_only ? code($hl_href) : "")."</link>\n".
                "\t<dc:identifier>$item_id</dc:identifier>\n";

  // create fulltext in the element <content:items>
  if (!$link_only) {
    $f = GetBaseFieldId($slice_fields, "full_text");
    if ($f) {
      $flag_format = GetFlagFormat($content4id[$f][0][flag]);
      $xml_items .="\t<content:items><rdf:Bag>\n".
                      "\t\t<rdf:li><content:item>\n".
                          "\t\t\t<content:format rdf:resource=\"".$FORMATS[$flag_format]."\"/>\n".
                          "\t\t\t<rdf:value>".
                          ($flag_format=="HTML" ? ("<![CDATA[".$content4id[$f][0][value]."]]>\n") :
                                                  code($content4id[$f][0][value])).
                          "</rdf:value>\n".
                      "\t\t</content:item></rdf:li>\n".
                   "\t</rdf:Bag></content:items>\n";
     }
  }

  // create item's categories
  if ($item_categs && is_array($item_categs)) {
    $xml_items.="\t<aa:categories><rdf:Bag>\n";
    reset($item_categs);
    while (list(,$k) = each($item_categs)) {
      $xml_items .="\t\t<rdf:li rdf:resource=\"".AA_INSTAL_URL."cat/$k\"/>\n";
    }
    $xml_items.="\t</rdf:Bag></aa:categories>\n";
  }

  $p_date_id = GetBaseFieldId($slice_fields,"publish_date");      // get publish_date field id

  // create Dublin Core elements
  reset($MAP_DC2AA);
  while (list($k,$v) = each($MAP_DC2AA)) {
    $cont = GetBaseFieldContent($slice_fields,$v,$content4id);
    if ($v == "publish_date") // convert publish date
      $cont = unixstamp_to_iso8601 ($cont);
    $xml_items .= "\t<dc:$k>".code($cont)."</dc:$k>\n";
  }

  // create AA field data elements
  //  $f = array("headline", "abstract", "link_only", "hl_href", "full_text" ,"category", "slice_id");
  $f = array("full_text" ,"category", "slice_id");

  while (list($k,$v) = each($f)) {        // create array of elements, which will be skipped
    $rss[GetBaseFieldId($slice_fields,$v)] = $v;
  }
  $xml_items .= "\t<aa:fielddatacont><rdf:Bag>\n";
  reset($slice_fields);
  while (list($k,$v) = each($slice_fields)) {
    if (isset($rss[$k]))           // do not create rss elements
      continue;
    $xml_items .= GetXMLFieldData($slice_id,$slice_fields, $k, $content4id);

  }
  $xml_items .="\t</rdf:Bag></aa:fielddatacont>\n".
               "\t</item>\n";
  return $xml_items;
}

function CreateXMLItems($slice_id, &$items_ids, &$content, &$slice_fields, &$items_categs) {
  reset($items_ids);
  while (list(,$id) = each($items_ids)) {
    echo GetXMLItem($slice_id, $id, $content[$id], $items_categs[$id], $slice_fields);
  }
}

function GetXMLItemsRefs(&$items_ids) {
  reset($items_ids);
  $out .="\t<items><rdf:Seq>\n";
  while (list(,$id) = each($items_ids)) {
    $out .="\t\t<rdf:li rdf:resource=\"".AA_INSTAL_URL."items/$id\"/>\n";
  }
  $out .="\t</rdf:Seq></items>\n";
  return $out;
}

// Get all item categories belongs to $categories
function GetItemCategories(&$categs, &$content_vals) {
  if (!$content_vals || !is_array($content_vals))
    return;

  while (list(,$v) = each($content_vals)) {
      if ($cat_id = $categs[$v[value]])
        $cat_ids[] = $cat_id;
  }
  return $cat_ids;
}

//------------------------------------------------------------------------------

$db = new DB_AA;

// check the node_name and password against the nodes table's data
if ( !CheckNameAndPassword($node_name, $password ))
  Error(ERR_PASSWORD);

$xml_channel = $used_fields = $xml_items = "";

if (!$slice_id) {                           // feed establishing mode
  $slice_ids = GetFeedingSlices( $node_name, $user );
  if (!$slice_ids) {
   Error(ERR_NO_SLICE);
  }
  echo $XML_BEGIN;
  while ( list( ,$sl_id ) = each( $slice_ids )) {
    list( $slice_fields,) = GetSliceFields( $sl_id );
    GetXMLFields( $sl_id, $slice_fields, $xml_fields_refs,  $xml_fields);   // get fields
    GetXMLCategories( $sl_id, $slice_fields, $xml_categories_refs, $xml_categories ); //get categories
    CreateXMLChannel( $sl_id, $xml_fields_refs, $xml_categories_refs,$xml_items_refs,$time); // echo channel
    $xml_categories_refs= $xml_fields_refs = "";      // clear fields and categories for next channel
  }
} else {      // feeding mode

  if (!CheckFeedingPermissions($slice_id, $node_name, $user))
    Error("Invalid permissions - slice_id: $slice_id, node_name: $node_name, user:$user");

  echo $XML_BEGIN;
  list( $slice_fields,) = GetSliceFields( $slice_id );

  $start_timestamp  = iso8601_to_unixstamp($start_timestamp);
  $cat_field = GetCategoryFieldId($slice_fields);

  $p_slice_id = q_pack_id($slice_id);

  $now = now();
  $cond1 = "(item.status_code=1 AND item.publish_date <= '$now' AND item.expiry_date > '$now')";
  $cond2 = "(item.last_edit >'$start_timestamp' OR item.publish_date > '$start_timestamp')";

  $SQL   = "SELECT id, publish_date, last_edit FROM item
            WHERE slice_id = '$p_slice_id' 
              AND $cond1
              AND $cond2";  
// AND (externally_fed='' OR externally_fed IS NULL)

  $db->query($SQL);

  $ids="";
  $time=0;
  while ($db->next_record()) {
    $ids[] = unpack_id($db->f(id));
    $time = max( $time, $db->f(publish_date), $db->f(last_edit));   // save time of the newest item
  }
  $time = unixstamp_to_iso8601($time);

//ech( $ids );
  
  if ($ids) {
    $content = GetItemContent($ids);     // get the content of all items

    if ($categories && $cat_field) {     // if slice has no category field or has no categories, send
                                         // all items
      $consts = GetGroupConstants($slice_id, $slice_fields);      // get categories belongs to $slice_id

      $c = explode(" ",$categories);     // create array of requested categories ids indexed by value

      while (list(,$cat ) = each($c)) {
        if ($consts[$cat]) {
          $categs[$consts[$cat][value]] = $cat;
        }
      }

      while (list($k,$id) = each($ids)) {        // find out all items, which belongs to requested categories

#  commented out - why to send all items without category definned if we want 
#  just categories specified by $categories? - Honza
#        if (!($cat_vals = $content[$id][$cat_field][0][value]))  // get category of the item => if empty
#          continue;                                              // send the item;
        if (!($items_categs[$id] = GetItemCategories($categs,$content[$id][$cat_field])))
          unset($ids[$k]);     // if the item categories are not in the set of requested categories
                               // => skip the item
      }
    }
//ech( $ids );
    $xml_items_refs = GetXMLItemsRefs($ids);
  }
  GetXMLFields( $slice_id, $slice_fields, $xml_fields_refs,  $xml_fields);   // get fields and fields refs
  GetXMLCategories( $slice_id, $slice_fields, $xml_categories_refs, $xml_categories ); //get categories and cat refs
  CreateXMLChannel( $slice_id, $xml_fields_refs, $xml_categories_refs,$xml_items_refs,$time); // echo channel
}

// Channel(s) was already printed, so print fields and categories and also items (feeding mode)
echo $xml_fields;
echo $xml_categories;

if ($slice_id && $ids)        // feeding mode
  CreateXMLItems($slice_id, $ids, $content, $slice_fields, $items_categs);

echo "</rdf:RDF>"

?>
