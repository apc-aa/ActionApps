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
# Cross-Server Networking - parsing function
#
# Note: This parser does not check correctness of the RSS document. It assumes, that xml document
#       conforms to the RSS 1.0. Normally it parses document, which was created by aa server module (see
#       getxml.php3)

function decode($v) {
  return utf8_decode($v);
}

function startElement($parser, $name, $attrs) {
  global $cur_tag,
         $rdf_modules,
         $channel_uri,
         $channel,
         $item, $item_uri,
         $field_uri, $field_uri_slice, $category_uri,
         $content_format,
         $fielddata_uri, $fielddata_content_format, $fielddata;

  $cur_tag .= "^$name";

  switch ($cur_tag) {
    case "^RDF:RDF" :                                                   // rdf modules
      $rdf_modules = $attrs; break;

    case "^RDF:RDF^CHANNEL":                                            // channel URI
      $channel_uri = substr(strrchr($attrs["RDF:ABOUT"],"/"),1); break;

    case "^RDF:RDF^CHANNEL^AA:CATEGORIES^RDF:BAG^RDF:LI":               // list of categories
      $channel[categories][substr(strrchr($attrs["RDF:RESOURCE"],"/"),1)] = 1; break;

    case "^RDF:RDF^CHANNEL^AA:FIELDS^RDF:BAG^RDF:LI":                   // list of fields
        $channel[fields][substr($attrs["RDF:RESOURCE"],-16)] = 1;
      break;

    case "^RDF:RDF^CHANNEL^ITEMS^RDF:SEQ^RDF:LI":                       // list of items
     $channel[items][substr(strrchr($attrs["RDF:RESOURCE"],"/"),1)] = 1; break;

    case "^RDF:RDF^AA:CATEGORY":                                        // category URI
      $category_uri = substr(strrchr($attrs["RDF:ABOUT"],"/"),1); break;

    case "^RDF:RDF^AA:FIELD":                                           // field URI
      $field_uri = substr($attrs["RDF:ABOUT"],-16);
      $field_uri_slice = substr(strrchr(substr($attrs["RDF:ABOUT"],0,-17),"/"),1);
      break;
    case "^RDF:RDF^ITEM":                                               // item URI
      $item_uri = substr(strrchr($attrs["RDF:ABOUT"],"/"),1); break;

    case "^RDF:RDF^ITEM^CONTENT:ITEMS^RDF:BAG^RDF:LI^CONTENT:ITEM^CONTENT:FORMAT":    // format (html/plain) of fulltext
      $content_format = $attrs["RDF:RESOURCE"]; break;

    case "^RDF:RDF^ITEM^AA:CATEGORIES^RDF:BAG^RDF:LI":                  // list of categories into which an item belongs
      $item[categories][] = substr(strrchr($attrs["RDF:RESOURCE"],"/"),1); break;

    case "^RDF:RDF^ITEM^AA:FIELDDATACONT^RDF:BAG^RDF:LI^AA:FIELDDATA":
      $fielddata = array(value=>"", flag=>0);
      break;

    case "^RDF:RDF^ITEM^AA:FIELDDATACONT^RDF:BAG^RDF:LI^AA:FIELDDATA^AA:FIELD":       // field's URI
      $fielddata_uri = substr($attrs["RDF:RESOURCE"],-16); break;

    case "^RDF:RDF^ITEM^AA:FIELDDATACONT^RDF:BAG^RDF:LI^AA:FIELDDATA^AA:FORMAT":      // field's content format (html/plain)
      $fielddata_content_format = $attrs["RDF:RESOURCE"]; break;
  }
}

function endElement($parser, $name) {
  global $CONTENT_FORMATS,
         $cur_tag,
         $aa_rss,
         $channel_uri, $channel,
         $category_uri, $category,
         $field_uri, $field_uri_slice, $field,
         $item_uri, $item, $content_format,
         $fielddata_uri, $fielddata, $fielddata_content_format,
         $fulltext_content;

  switch ($cur_tag) {
    case "^RDF:RDF":
      break;

    case "^RDF:RDF^CHANNEL":
      $aa_rss[channels][$channel_uri] = $channel;
      $channel="";
      break;

    case "^RDF:RDF^AA:CATEGORY":
      $aa_rss[categories][$category_uri] = $category;
      $category="";
      break;

    case "^RDF:RDF^AA:FIELD":
      $aa_rss[fields][$field_uri] = $field;
      $field="";
      break;

    case "^RDF:RDF^ITEM":
      // dc elements decode
      while (list($k,$v) =each($item[dc]))
        $item[dc][$k] = decode($v);

      $aa_rss[items][$item_uri] = $item;
      $item="";
      break;

    case "^RDF:RDF^ITEM^AA:FIELDDATACONT^RDF:BAG^RDF:LI^AA:FIELDDATA":
      $fielddata[format] = $CONTENT_FORMATS[$fielddata_content_format];
      $item[fields_content][$fielddata_uri][] = $fielddata;
      break;

    case "^RDF:RDF^ITEM^CONTENT:ITEMS^RDF:BAG^RDF:LI^CONTENT:ITEM":
      $item[content][$CONTENT_FORMATS[$content_format]] = $fulltext_content;
      $fulltext_content="";
      break;
  }

  $caret_pos = strrpos($cur_tag, '^');
  $cur_tag = substr($cur_tag, 0, $caret_pos);
 }

function charD($parser, $data) {
 global $cur_tag,
         $aa_rss, $channel, $category, $field, $item,
         $fulltext_content, $fielddata;

  switch ($cur_tag) {

    case "^RDF:RDF^CHANNEL^TITLE" : $channel[title] .= decode($data); break;
    case "^RDF:RDF^CHANNEL^DESCRIPTION" : $channel[description] .= decode($data); break;
    case "^RDF:RDF^CHANNEL^LANGUAGE" : $channel[language] = $data; break;
    case "^RDF:RDF^CHANNEL^AA:NEWESTITEMTIMESTAMP" : $channel[timestamp] = $data; break;
    case "^RDF:RDF^CHANNEL^LINK" : $channel['link'] .= decode($data); break;
    case "^RDF:RDF^CHANNEL^DC:IDENTIFIER" : $channel[slice_id] = $data; break;

    case "^RDF:RDF^AA:CATEGORY^AA:NAME": $category[name] .= decode($data); break;
    case "^RDF:RDF^AA:CATEGORY^AA:ID": $category[id] = $data; break;
    case "^RDF:RDF^AA:CATEGORY^AA:VALUE": $category[value] .= decode($data); break;
    case "^RDF:RDF^AA:CATEGORY^AA:CATPARENT": $category[catparent] = decode($data); break;

    case "^RDF:RDF^AA:FIELD^AA:NAME": $field[name] .= decode($data); break;
    case "^RDF:RDF^AA:FIELD^AA:ID": $field[id] = $data; break;

    case "^RDF:RDF^ITEM^TITLE" : $item[title] .= decode($data); break;
    case "^RDF:RDF^ITEM^DESCRIPTION" : $item[description] .= decode($data); break;
    case "^RDF:RDF^ITEM^LINK" : $item['link'] .= decode($data); break;
    case "^RDF:RDF^ITEM^DC:IDENTIFIER" : $item[id] = $data; break;

    // Dublin Core elements
    case "^RDF:RDF^ITEM^DC:TITLE" :       $item[dc][headline] .= $data; break;
    case "^RDF:RDF^ITEM^DC:CREATOR" :     $item[dc][author] .= $data; break;
    case "^RDF:RDF^ITEM^DC:SUBJECT" :     $item[dc][abstract] .= $data; break;
    case "^RDF:RDF^ITEM^DC:DESCRIPTION" : $item[dc][abstract] .= $data; break;
    case "^RDF:RDF^ITEM^DC:DATE" :        $item[dc][publish_date] .= $data; break;
    case "^RDF:RDF^ITEM^DC:SOURCE" :      $item[dc][source] .= $data; break;
    case "^RDF:RDF^ITEM^DC:LANGUAGE" :    $item[dc][lang_code] .= $data; break;
    case "^RDF:RDF^ITEM^DC:RELATION" :    $item[dc][source_href] .= $data; break;
    case "^RDF:RDF^ITEM^DC:COVERAGE" :    $item[dc][place] .= $data; break;

    case "^RDF:RDF^ITEM^CONTENT:ITEMS^RDF:BAG^RDF:LI^CONTENT:ITEM^RDF:VALUE":    // item's fulltext
      $fulltext_content .=decode($data); break;

    case "^RDF:RDF^ITEM^AA:FIELDDATACONT^RDF:BAG^RDF:LI^AA:FIELDDATA^RDF:VALUE":
      $fielddata[value] .= decode($data); break;
    case "^RDF:RDF^ITEM^AA:FIELDDATACONT^RDF:BAG^RDF:LI^AA:FIELDDATA^AA:FIELDFLAGS":
      $fielddata[flag] = $data; break;
  }
}

function aa_rss_parse($xml_data) {
  global $aa_rss;

  $xml_parser = xml_parser_create();
  xml_set_element_handler($xml_parser, "startElement", "endElement");
  xml_set_character_data_handler($xml_parser,"charD");
  if (!xml_parse($xml_parser, $xml_data))
    return sprintf("XML parse error: %s at line %d",
            xml_error_string(xml_get_error_code($xml_parser)),
            xml_get_current_line_number($xml_parser));

  xml_parser_free($xml_parser);
  return $aa_rss;
}

?>
