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
	Author: Jakub Adámek
	
# Slice Import - XML parsing function
#
# Note: This parser does not check correctness of the data. It assumes, that xml document
#       was exported by slice export and has the form of

<sliceexport ...>
...
<slice id="new id" name="new name">
base 64 data
</slice>
</sliceexport>
*/

function startElement($parser, $name, $attrs) {
	global $curname,
  	  	   $curid,
		   $chardata;
	
	switch ($name) {
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
	}
	$chardata = "";
}

function endElement($parser, $name) {
  global $curname,
  		 $curid,
		 $chardata,
		 $sess;

  switch ($name) {
	case "SLICE":
		$slice = unserialize (base64_decode($chardata));
		if (!is_array($slice)) {
			MsgPage($sess->url(self_base())."index.php3", L_E_IMPORT_WRONG_FILE, "standalone");
			exit;
		}
		$slice["id"] = $curid;
		$slice["name"] = $curname;
		import_slice ($slice);
		break;
  }

  $chardata = "";
}

function charD($parser, $data) {
	global $chardata;
	$chardata .= $data;
}

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
?>
