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
 * @package   UserInput
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

//
// Cross-Server Networking - parsing function
//
// Note: This parser does not check correctness of the RSS document. It assumes, that xml document
//       conforms to the RSS 1.0. Normally it parses document, which was created by aa server module (see
//       getxml.php3)

require_once AA_INC_PATH."convert_charset.class.php3";

// An array mapping all the name spaces we've seen in RSS feeds to abbreviations
// Only a few of the abbreviations are likely to be used below
$module2abbrev = array(
        "HTTP://WWW.W3.ORG/1999/02/22-RDF-SYNTAX-NS#"   => "RDF",
        "HTTP://PURL.ORG/RSS/1.0/"                      => "RSS",
        "HTTP://MY.NETSCAPE.COM/RDF/SIMPLE/0.9/"        => "RSS", //same items
        "HTTP://PURL.ORG/DC/ELEMENTS/1.1/"              => "DC",
        "HTTP://WWW.APC.ORG/RSS/AA-MODULE.HTML"         => "AA",
        "HTTP://PURL.ORG/RSS/1.0/ITEM-IMAGES/"          => "IM",   // Unused
        "HTTP://RECORDS.SOURCEFORGE.NET/SCHEMAS/RSS-META-MODULE/" => "RECORD", // Unused
        "HTTP://WWW.W3.ORG/1999/XHMTL"                  => "XHTML",
        "HTTP://PURL.ORG/RSS/1.0/MODULES/CONTENT/"      => "CONTENT",
        "HTTP://PURL.ORG/RSS/1.0/MODULES/SUBSCRIPTION/" => "SUBSCRIPTION",
        "HTTP://PURL.ORG/RSS/1.0/MODULES/LINK/"         => "LINK",
        "HTTP://PURL.ORG/RSS/1.0/MODULES/RICHEQUIV/"    => "REQV",
        "HTTP://XMLNS.COM/FOAF/0.1/"					=> "FOAF",
        "HTTP://PURL.ORG/RSS/1.0/MODULES/SYNDICATION/"  => "SYNDICATION",
        "HTTP://WEBNS.NET/MVCB/"                        => "ADMIN"
        );

// Examples refered to below (as EG1 ... EG5) where these have been seen,
// 1 - APC e.g. http://web.changenet.sk/aa/admin/getxml.php3
// 2 - http://www.ibiblio.org/ecolandtech/pcwiki/index.php/RecentChanges?format=rss  (Wiki)
// 3 - http://p.moreover.com/cgi-local/page?o=rss&c=Environment%20news   (RSS 0.9)
// 4 - http://www.peerfear.org/rss/index.rss (Kevin Burton)
// 5 - http://www.aaronsw.com/weblog/index.xml
// 6 -

/** decode function
  * Converts data to right character encoding (grabbed from slice setting)
  * Older versions of AA RSS feeds do not contain XML encoding setting and uses
  * iso-8859-1. New versions (>=2.8) uses encoding setting and utf-8
  * which is correct also for non iso-8859-1 languages
  * There must be two global variables defined
  * - $g_source_encoding  - encoding of input XML data
  * - g_slice_encoding    - destination encoding
  * @param $v
  */
function decode($v) {
    static $encoder;
    if ( !$encoder ) {
        $encoder = new ConvertCharset;
    }
    return $encoder->Convert($v, $GLOBALS['g_source_encoding'], $GLOBALS['g_slice_encoding']);
}

/** seems_utf8 function
 *  Function decides if the string could be utf-8 or not
 * @param $str
 */
function seems_utf8($str) {
    for ( $i=0, $ino=strlen($str); $i<$ino; ++$i) {
        if (ord($str[$i]) < 0x80) continue; // 0bbbbbbb
        elseif ((ord($str[$i]) & 0xE0) == 0xC0) $n=1; // 110bbbbb
        elseif ((ord($str[$i]) & 0xF0) == 0xE0) $n=2; // 1110bbbb
        elseif ((ord($str[$i]) & 0xF8) == 0xF0) $n=3; // 11110bbb
        elseif ((ord($str[$i]) & 0xFC) == 0xF8) $n=4; // 111110bb
        elseif ((ord($str[$i]) & 0xFE) == 0xFC) $n=5; // 1111110b
        else return false; // Does not match any model
        for ($j=0; $j<$n; ++$j) { // n bytes matching 10bbbbbb follow ?
            if ((++$i == strlen($str)) || ((ord($str[$i]) & 0xC0) != 0x80)) {
                return false;
            }
        }
    }
    return true;
}
/** nsName2abbrevname function
 * @param $name
 */
function nsName2abbrevname($name) {
    global $module2abbrev; // Static array above
    ereg("(.+):([^:]+)",$name,$nameparts);
    if ($ab = $module2abbrev[$nameparts[1]]) {
        return $ab.":".$nameparts[2];
    }
    return $name;
}
/** nsAbbrev2name function
 * @param $abbrev
 */
function nsAbbrev2name($abbrev) {
    global $module2abbrev; // static array above
    return array_search($abbrev,$module2abbrev);
}
/** startElement function
 * @param $parser
 * @param $name
 * @param $attrs
 */
function startElement($parser, $name, $attrs) {
  global $cur_tag,
         $rdf_modules,
         $channel_uri,
         $channel,
         $item, $item_uri,
         $field_uri, $field_uri_slice, $category_uri,
         $content_format, $rss_version,
         $fielddata_uri, $fielddata_content_format, $fielddata, $CONTENT_FORMATS;
  $cur_tag .= "^".nsName2abbrevname($name);
  $RDF = nsAbbrev2name("RDF");   // For matching with NameSpace expanded attributes

  if ($GLOBALS['debugfeed'] >=8) {
      print("\nStartElement:$cur_tag");
  }
  switch ($cur_tag) {
    case "^RSS" :       // rss header, read version
        $rss_version = $attrs["VERSION"]; break;

    case "^RDF:RDF" :                                                   // rdf modules
      $rdf_modules = $attrs; break;

    case "^RDF:RDF^RSS:CHANNEL":                                            // channel URI
      $channel_uri = attr2id($attrs["$RDF:ABOUT"]); break;

    case "^RDF:RDF^RSS:CHANNEL^AA:CATEGORIES^RDF:BAG^RDF:LI":               // list of categories AA specific
      $channel['categories'][substr(strrchr($attrs["$RDF:RESOURCE"],"/"),1)] = 1; break;

    case "^RDF:RDF^RSS:CHANNEL^AA:FIELDS^RDF:BAG^RDF:LI":                   // list of fields AA specific
        $channel['fields'][substr($attrs["$RDF:RESOURCE"],-16)] = 1;
      break;

    case "^RDF:RDF^RSS:CHANNEL^RSS:ITEMS^RDF:SEQ^RDF:LI":                       // list of items
     $channel['items'][attr2id($attrs["$RDF:RESOURCE"])] = 1; break;

    case "^RDF:RDF^AA:CATEGORY":                                        // category URI AA specific
      $category_uri = substr(strrchr($attrs["$RDF:ABOUT"],"/"),1); break;

    case "^RDF:RDF^AA:FIELD":                                           // field URI AA specific
      $field_uri = substr($attrs["$RDF:ABOUT"],-16);
      $field_uri_slice = substr(strrchr(substr($attrs["$RDF:ABOUT"],0,-17),"/"),1);
      break;

    case "^RSS^CHANNEL^ITEM":											// RSS 0.9
        $item_uri = ""; break ;

    case "^RDF:RDF^RSS:ITEM":                                               // item URI
//Some feeds e.g. http://www.heise.de/newsticker/heise.rdf leave blank!
      $item_uri =
        ($attrs["$RDF:ABOUT"])
        ? attr2id($attrs["$RDF:ABOUT"])
        : "";
      break;

    case "^RDF:RDF^RSS:ITEM^CONTENT:ITEMS^RDF:BAG^RDF:LI^CONTENT:ITEM^CONTENT:FORMAT":    // format (html/plain) of fulltext
      $content_format = $attrs["$RDF:RESOURCE"]; break;

    case "^RDF:RDF^RSS:ITEM^CONTENT:ENCODED":   // format is implicit - EG5
      $content_format = array_search(HTML,$CONTENT_FORMATS);  break;

    case "^RDF:RDF^RSS:ITEM^AA:CATEGORIES^RDF:BAG^RDF:LI":                  // list of categories into which an item belongs AA Specific
      $item['categories'][] = substr(strrchr($attrs["$RDF:RESOURCE"],"/"),1); break;

    case "^RDF:RDF^RSS:ITEM^AA:FIELDDATACONT^RDF:BAG^RDF:LI^AA:FIELDDATA":
      $fielddata = array(value=>"", flag=>0);
      break;

    case "^RDF:RDF^RSS:ITEM^AA:FIELDDATACONT^RDF:BAG^RDF:LI^AA:FIELDDATA^AA:FIELD":       // field's URI
      $fielddata_uri = substr($attrs["$RDF:RESOURCE"],-16); break;

    case "^RDF:RDF^RSS:ITEM^AA:FIELDDATACONT^RDF:BAG^RDF:LI^AA:FIELDDATA^AA:FORMAT":      // field's content format (html/plain)
      $fielddata_content_format = $attrs["$RDF:RESOURCE"]; break;
  }
}
/** endElement function
 * @param $parser
 * @param $name
 */
function endElement($parser, $name) {
  global $CONTENT_FORMATS,    //csn_util.php3: Array url (e.g. "http://www.isi.edu ... test/html") to int (HTML=0, PLAIN=1)
         $cur_tag,
         $aa_rss,
         $channel_uri, $channel,
         $category_uri, $category,
         $field_uri, $field_uri_slice, $field,
         $item_uri, $item, $content_format,
         $fielddata_uri, $fielddata, $fielddata_content_format,
         $fulltext_content;

    if ($GLOBALS['debugfeed'] >=8) {
        print("\nendElement:$cur_tag");
    }
  switch ($cur_tag) {
    case "^RDF:RDF":
      break;

    case "^RSS^CHANNEL":
    case "^RDF:RDF^RSS:CHANNEL":
      if (!($channel_uri)) $channel_uri = string2id($channel['title']);   // RSS 0.9
      $aa_rss['channels'][$channel_uri] = $channel;
      $channel="";
      break;

    case "^RDF:RDF^AA:CATEGORY":
      $aa_rss['categories'][$category_uri] = $category;
      $category="";
      break;

    case "^RDF:RDF^AA:FIELD":
      $aa_rss['fields'][$field_uri] = $field;
      $field="";
      break;

    case "^RSS^CHANNEL^ITEM":			// RSS 0.9
    case "^RDF:RDF^RSS:ITEM":
      // dc elements decode
      if (isset($item['dc']))
        while (list($k,$v) =each($item['dc']))
            $item['dc'][$k] = decode($v);
      if (!($item_uri)) { $item_uri = string2id($item['title'] . $item["link"] . $item['description']); } // RSS 0.9
      $aa_rss['items'][$item_uri] = $item;
      $item="";
      break;

    case "^RDF:RDF^RSS:ITEM^AA:FIELDDATACONT^RDF:BAG^RDF:LI^AA:FIELDDATA":
      $fielddata['format'] = $CONTENT_FORMATS[$fielddata_content_format];
      $item['fields_content'][$fielddata_uri][] = $fielddata;
      break;

    case "^RDF:RDF^RSS:ITEM^CONTENT:ENCODED":  //EG5
    case "^RDF:RDF^RSS:ITEM^CONTENT:ITEMS^RDF:BAG^RDF:LI^CONTENT:ITEM":
      $item['content'][$CONTENT_FORMATS[$content_format]] = $fulltext_content;
      $fulltext_content="";
      break;
  }

  $caret_pos = strrpos($cur_tag, '^');
  $cur_tag = substr($cur_tag, 0, $caret_pos);
 }
/** charD function
 * @param $parser
 * @param $data
 */
function charD($parser, $data) {
 global $cur_tag,
         $aa_rss, $channel, $category, $field, $item,
         $fulltext_content, $fielddata,$content_format;

  switch ($cur_tag) {
    case "^RSS^CHANNEL^TITLE":
    case "^RDF:RDF^RSS:CHANNEL^RSS:TITLE" :             $channel['title']       .= decode($data); break;
    case "^RSS^CHANNEL^DESCRIPTION":
    case "^RDF:RDF^RSS:CHANNEL^DESCRIPTION" :           $channel['description'] .= decode($data); break;
    case "^RSS^CHANNEL^LANGUAGE":
    case "^RDF:RDF^RSS:CHANNEL^RSS:LANGUAGE" :          $channel['language']     = $data; break;
    case "^RDF:RDF^RSS:CHANNEL^AA:NEWESTITEMTIMESTAMP": $channel['timestamp']    = $data; break;
    case "^RSS^CHANNEL^LINK":
    case "^RDF:RDF^RSS:CHANNEL^RSS:LINK" :              $channel['link']        .= decode($data); break;
    case "^RDF:RDF^RSS:CHANNEL^DC:IDENTIFIER" :         $channel['slice_id']     = $data; break;

    case "^RDF:RDF^AA:CATEGORY^AA:NAME":                $category['name']       .= decode($data); break;
    case "^RDF:RDF^AA:CATEGORY^AA:ID":                  $category['id']          = $data; break;
    case "^RDF:RDF^AA:CATEGORY^AA:VALUE":               $category['value']      .= decode($data); break;
    case "^RDF:RDF^AA:CATEGORY^AA:CATPARENT":           $category['catparent']   = decode($data); break;

    case "^RDF:RDF^AA:FIELD^AA:NAME":                   $field['name']          .= decode($data); break;
    case "^RDF:RDF^AA:FIELD^AA:ID":                     $field['id']             = $data; break;

    case "^RSS^CHANNEL^ITEM^TITLE":
    case "^RDF:RDF^RSS:ITEM^RSS:TITLE" :                $item['title']          .= decode($data); break;
    case "^RSS^CHANNEL^ITEM^DESCRIPTION":
    case "^RDF:RDF^RSS:ITEM^RSS:DESCRIPTION" :          $item['description']    .= decode($data); break;
    case "^RSS^CHANNEL^ITEM^LINK":
    case "^RDF:RDF^RSS:ITEM^RSS:LINK" :                 $item['link']           .= decode($data); break;
    case "^RDF:RDF^RSS:ITEM^DC:IDENTIFIER" :            $item['id']              = $data; break;
    case "^RSS^CHANNEL^ITEM^GUID":                      $item['guid']           .= decode($data); break;
    case "^RSS^CHANNEL^ITEM^PUBDATE":                   $item['pubdate']        .= decode($data); break;

    // Dublin Core elements
    // Mitra changed these back to DC names, and then interprets them in the client
    case "^RDF:RDF^RSS:ITEM^DC:TITLE" :       $item['dc']['title']       .= $data; break; // was headline
    case "^RDF:RDF^RSS:ITEM^DC:CREATOR" :     $item['dc']['creator']     .= $data; break; // was author
    case "^RDF:RDF^RSS:ITEM^DC:SUBJECT" :     $item['dc']['subject']     .= $data; break; // was abstract
    case "^RDF:RDF^RSS:ITEM^DC:DESCRIPTION" : $item['dc']['description'] .= $data; break; // was abstract
    case "^RDF:RDF^RSS:ITEM^DC:DATE" :        $item['dc']['date']        .= $data; break; // was publish_date
    case "^RDF:RDF^RSS:ITEM^DC:SOURCE" :      $item['dc']['source']      .= $data; break;
    case "^RDF:RDF^RSS:ITEM^DC:LANGUAGE" :    $item['dc']['language']    .= $data; break; // was lang_code
    case "^RDF:RDF^RSS:ITEM^DC:RELATION" :    $item['dc']['relation']    .= $data; break; // was source_href
    case "^RDF:RDF^RSS:ITEM^DC:COVERAGE" :    $item['dc']['coverage']    .= $data; break; // was place

    case "^RDF:RDF^RSS:ITEM^CONTENT:ENCODED":   // EG5
    case "^RDF:RDF^RSS:ITEM^CONTENT:ITEMS^RDF:BAG^RDF:LI^CONTENT:ITEM^RDF:VALUE":    // item's fulltext
        $fulltext_content .= decode($data); break;

    case "^RDF:RDF^RSS:ITEM^AA:FIELDDATACONT^RDF:BAG^RDF:LI^AA:FIELDDATA^RDF:VALUE":
        $fielddata['value'] .= decode($data); break;
    case "^RDF:RDF^RSS:ITEM^AA:FIELDDATACONT^RDF:BAG^RDF:LI^AA:FIELDDATA^AA:FIELDFLAGS":
        $fielddata['flag'] = $data; break;
  }
}

/** aa_rss_parse function
 *  Parse feed, return array or false on failure
 * $GLOBALS['g_slice_encoding'] - destination slice encoding - must be set
 * @param $xml_data
 */
function aa_rss_parse($xml_data) {
    global $aa_rss,
    $cur_tag, $aa_rss, $channel, $category, $field, $item,
    $fulltext_content, $fielddata,$content_format;


    if ($GLOBALS['debugfeed'] >=8) {
        huhl("aa_rss_parse:Parsing ...");
    }


    // get encoding from the xml document: it is used in decode() function above
    // Added by Norbert Brazda
    $regexp = '/<\?xml.*encoding=[\'"]([^\'"]*)[\'"].*\?>/m';  // /m means multiline
    if (preg_match($regexp, $xml_data, $enc)) {
        $GLOBALS['g_source_encoding'] = strtolower($enc[1]);
    } else {
        $GLOBALS['g_source_encoding'] = 'iso-8859-1';
    }

    // Destination slice encoding should be set in aa_rss_parse caller function.
    // If not set, we probably want to parse data for current slice (node list, ... )
    if ( !$GLOBALS['g_slice_encoding'] ) {
        $GLOBALS['g_slice_encoding'] = getSliceEncoding($GLOBALS['slice_id']);
    }

    // Older versions of AA RSS do not contain XML encoding setting but uses
    // utf-8 (but only for iso-8859-1 slices - as supported by php.
    // New versions (>=2.8) uses encoding setting and utf-8 and works well with
    // slices in other encoding
    if ( strpos( substr($xml_data,0,100), 'encoding="UTF-8"' ) ) {
        $source_encoding = 'utf-8';
    } else {
        $source_encoding = ( seems_utf8($xml_data) ? 'utf-8' : 'iso-8859-1');
        if ($GLOBALS['debugfeed'] >=8) huhl("aa_rss_parse:Encoding not specified, used: $source_encoding" );
    }
    // see php.net/xml_parser_create_ns for mmore info - it supports only utf-8
    $xml_parser = xml_parser_create_ns($source_encoding);

    // Clear out, or will just append to previous parse!
    // unset do not works for GLOBAL variables!!!
    $cur_tag = $channel = $category = $field = $item = $fulltext_content = $fielddata = $content_format = null;
    $aa_rss = array();

    xml_set_element_handler($xml_parser, "startElement", "endElement");
    xml_set_character_data_handler($xml_parser,"charD");
    if (!xml_parse($xml_parser, $xml_data)) {
        $err = sprintf("XML parse error: %s at line %d", xml_error_string(xml_get_error_code($xml_parser)), xml_get_current_line_number($xml_parser));
        print("\nXML_RSSPARSE:ERR:$err");
        return false;
    }
    if ($GLOBALS['debugfeed'] >=8) huhl("aa_rss_parse:Parsed ok array=",$aa_rss);
    xml_parser_free($xml_parser);
    return $aa_rss;
}

/*
attr is $attrs["$RDF:RESOURCE"]
content_format <- set to URL of format - e.g. http://www.isi.edu...
by startElement: RDF/ITEM/CONTENT/ITEMS/BAG/LI/ITEM/FORMAT to attr.
to URL of HTML by RDF/ITEM/ENCODED

fielddata_content_format set to attr
in RDF/ITEM/AA:FIELDDATACONT/BAF/LI/FIELDDATA/FORMAT

$item[fields_content][$fielddata_uri][] = ( format => 0 (html) or 1 (plain))
by endElement RDF/ITEM/AA:FIELDDATACONT/BAG/LI/FIELDDATA
from fielddata_content_format
$item[content][0 | 1] = $fulltext_content  where 0|1
from $content_format in RDF/ITEM/ITEMS/BAG/LI/ITEM | RDF/ITEM/ENCODED
*/
?>
