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

/*
	Author: Jakub Adámek, Pavel Jisl
	
# Slice Import - XML parsing function
#
# Note: This parser does not check correctness of the data. It assumes, that xml document
#       was exported by slice export and has the form of

<sliceexport version="1.0">
...
<slice id="new id" name="new name">
base 64 data
</slice>
</sliceexport>

new version 1.1:

<sliceexport version="1.1">
<slice id="new id" name="new name">
<slicedata gzip="1">
if gzip parameter == 1 => gzipped base 64 slice struct
                  == 0 => base 64 slice struct
</slicedata>
<data item_id="item id" gzip="1">
base 64 data from item_id (w/wo gzip)
</data>
</slice>
</sliceexport>

*/

function startElement($parser, $name, $attrs) {
	global $curname,
  	  	   $curid,
		   $curdataid,
		   $curdatagzip,
		   $chardata,
		   $gzipped,
		   $expver;	
	switch ($name) {
		case "SLICEEXPORT":
			reset ($attrs);
			while (list($key, $val) = each($attrs)) {
				switch ($key) {
					case "VERSION": $expver = $val; break;
				}	
			}
			break;	
		case "SLICE": 		
			$curid = ""; 
			$curname = ""; 
			$curdata = ""; 
			reset ($attrs);
			while (list($key,$val) = each($attrs)) {
				switch ($key) {
					case "ID": $curid = $val; break;
					case "NAME": $curname = $val; break;
				}
			}
			break;
		case "SLICEDATA":
			$gzipped = "";
			while (list($key, $val) = each($attrs)) {
				switch ($key) {
					case "GZIP": $gzipped = $val; break;
				}
			}		
			break;
		case "DATA":
			$curdataid = "";
			$curdatagzip = "";
			while (list($key, $val) = each($attrs)) {
				switch ($key) {
					case "ITEM_ID": $curdataid = $val; break;
					case "GZIP": $curdatagzip = $val; break;
				}	
			}
			break;		
	}
	$chardata = "";
}

function endElement($parser, $name) {
  global $curname,
  		 $curid,
     	 $curdataid,
		 $curdatagzip,
		 $chardata,
		 $expver,
		 $gzipped,
		 $sess;

  switch ($name) {
  	case "SLICEDATA":
			$chardata = base64_decode($chardata);
			$chardata = $gzipped ? gzuncompress($chardata) : $chardata;
			$slice = unserialize ($chardata);
			if (!is_array($slice)) {
				MsgPage($sess->url(self_base())."index.php3", _m("ERROR: Text is not OK. Check whether you copied it well from the Export."), "standalone");
				exit;
			}
			$slice["id"] = $curid;
			$slice["name"] = $curname;
			import_slice ($slice);	
		break;
	case "DATA":
			$chardata = base64_decode($chardata);
			$chardata = $curdatagzip ? gzuncompress($chardata) : $chardata;
			$content4id = unserialize ($chardata);
			if (!is_array($content4id)) {
				MsgPage($sess->url(self_base())."index.php3", _m("ERROR: Text is not OK. Check whether you copied it well from the Export."), "standalone");
				exit;
			}
			import_slice_data($curid, $curdataid, $content4id, true, true);
		break;
	case "SLICE":
		if ($expver == "1.0") {
			$slice = unserialize (base64_decode($chardata));
			if (!is_array($slice)) {
				MsgPage($sess->url(self_base())."index.php3", _m("ERROR: Text is not OK. Check whether you copied it well from the Export."), "standalone");
				exit;
			}
			$slice["id"] = $curid;
			$slice["name"] = $curname;
			import_slice ($slice);
		} else {
		}
	break;
	case "SLICEEXPORT":
	break;	
  }
  $chardata = "";
}

function charD($parser, $data) {
	global $chardata;
	$chardata .= $data;
}

// creates xml parser
function sliceimp_xml_parse($xml_data) {
  $xml_parser = xml_parser_create();
  xml_set_element_handler($xml_parser, "startElement", "endElement");
  xml_set_character_data_handler($xml_parser,"charD");

  if (!xml_parse($xml_parser, $xml_data, true))
    return sprintf("XML parse error: %s at line %d",
            xml_error_string(xml_get_error_code($xml_parser)),
            xml_get_current_line_number($xml_parser));

  xml_parser_free($xml_parser);
  return "";
}

// creates SQL command for inserting
function create_SQL_insert_statement ($fields, $table, $pack_fields = "", $only_fields="", $add_values="")

// fields - fields for creating query
// table - name of table
// pack_fields - whitch fields needs to be packed (some types of ids...)
// only_fields - put in SQL command only some values from $fields
// add_values - adds some another values, whitch aren't in $fields

{
	$sqlfields = "";
	$sqlvalues = "";
	reset($fields);
	while (list($key,$val) = each($fields)) {
		if (!is_array($val) && !is_int ($key)) {
			if ((strstr($only_fields,";".$key.";")) || ($only_fields=="")) {
				if ($sqlfields > "") {
					$sqlfields .= ",\n";
					$sqlvalues .= ",\n";
				}
				$sqlfields .= $key;
		
				if (strstr($pack_fields,";".$key.";"))
					$val = pack_id ($val);
				$sqlvalues .= '"'.addslashes($val).'"';
			}	
		}
	}
	
	if ($add) {
		$add = explode(",", $add_fields);
		for ($i=0; $i<count($add); $i++) {
			$dummy=explode("=",$add[$i]);
			if ($sqlfields > "") {
				$sqlfields .= ",\n". $dummy[0];
				$sqlvalues .= ",\n". $dummy[1];
			}	
		}
	}
	return "INSERT INTO ".$table." (".$sqlfields.") VALUES (".$sqlvalues.")";
}

?>
