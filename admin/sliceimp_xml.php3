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
	Author: Mitra (based on earlier versions by Jakub Adámek, Pavel Jisl)
	
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


require_once $GLOBALS[AA_INC_PATH] . "xml_serializer.php3";

function si_err($str) {
    global $sess;
    MsgPage($sess->url(self_base())."index.php3", $str, "standalone");
    exit;
}

// Create and parse data
function sliceimp_xml_parse($xml_data,$dry_run=false) { 
    $xu = new xml_unserializer();
    $i = $xu->parse($xml_data);  // PHP data structure
    $s = $i["SLICEEXPORT"][0];
    if (! isset($s)) si_err(_m("\nERROR: File doesn't contain SLICEEXPORT"));
    if ($s[VERSION] == "1.0") {
            $sl = $s[SLICE][0];
			$slice = unserialize (base64_decode($sl[DATA]));
			if (!is_array($slice))
                si_err(_m("ERROR: Text is not OK. Check whether you copied it well from the Export."));
			$slice["id"] = $sl[ID];
			$slice["name"] = $sl[NAME];
            if($dry_run) {
                huhl("Would import slice=",$slice);
            } else 
			    import_slice ($slice);
    }
    else if ($s[VERSION] == "1.1") {
      foreach($s[SLICE] as $sl) {
        $sld=$sl[SLICEDATA][0];
        if ($sld[CHARDATA]) { // Its an encoded serialized data
    		$chardata = base64_decode($sld[CHARDATA]);
	    	$chardata = $sld[GZIP]
                  ? gzuncompress($chardata) : $chardata;
    		$slice = unserialize ($chardata);
        } elseif ($sld[slice]) {
            $slice = $sld[slice];
        }
		if (!is_array($slice))
            si_err(_m("ERROR: Text is not OK. Check whether you copied it well from the Export."));

		$slice["id"] = $sl[ID];
		$slice["name"] = $sl[NAME];
        if($dry_run) {
               huhl("Would import slice=",$slice);
        } else 
			    import_slice ($slice);
        if (is_array($sl[DATA])) 
         foreach ($sl[DATA] as $sld) {
          if (isset($sld[CHARDATA])) {
			$chardata = base64_decode($sld[CHARDATA]);
			$chardata = $sld[GZIP] 
                ? gzuncompress($chardata) : $chardata;
			$content4id = unserialize ($chardata);
          } else { // Its in XML
            $content4id = $sld[item]; 
          }
          if (!is_array($content4id))
             si_err(_m("ERROR: Text is not OK. Check whether you copied it well from the Export."));
          if($dry_run)
            huhl("Would import data to ",$sld[ITEM_ID],$content4id);
          else 
    		import_slice_data($sl[ID], $sld[ITEM_ID], 
                $content4id, true, true);
        } // loop over each item 
      } // loop over each slice
    } // Version 1.1
    else si_err(_m("ERROR: Unsupported version for import").$s[VERSION]);
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
	
	if ($add_fields) {
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
