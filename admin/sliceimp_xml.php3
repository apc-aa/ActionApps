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

$dry_run = 1;

// This function should go in site-specific file, just here to make easier to debug
// and to have an example
function bayfm_preimport($s) {
#huhl($s);
    reset($s[SLICE]);
    // Create a vid_translate table, and change ids while there
    while(list($k,) = each($s[SLICE])) {
        $sl = &$s[SLICE][$k];
        if ($slvs = &$sl[VIEWS]) {
            print("<br>Processing Views, translating shortids");
#            foreach ($slvs->a as $slv) { # $slv is a viewobj
            $a = &$slvs->a; reset($a);
            while(list($k,) = each($a)) { # $slv is a viewobj
                $slv = &$slvs->a[$k];
                $slvf = &$slv->fields; # Array of fields
                $id = $slvf["id"];
                $newid = $id+1000;
                $vid_translate[$id] = $newid;
                $slvf["id"] = $newid;
                $slv->id = $newid;
                $slvs->a[$newid] = $slv;
                unset($slvs->a[$id]);
            }
        }
    }
                huhl("SLVD",$s);
               exit;
    // Edit Items, translate vids in field "unspecified....1"
    reset($s[SLICE]);
   while(list($k,$sl) = each(&$s[SLICE])) {
        if (is_array($sl[DATA])) {
            print("<br>Processing Data");
            reset($sl[DATA]);
            foreach($sl[DATA] as $sld) {
              foreach($sld[item] as $content4id) { // should only be one
                // unspecified....1 contains a vid
                print("Setting unspecified....1 from ".$content4id["unspecified....1"][0][value]." to ".$vid_translate[$content4id["unspecified....1"][0][value]]);
                $content4id["unspecified....1"][0][value] = $vid_translate[$content4id["unspecified....1"][0][value]];
                while(list($k,$l) = each($content4id)) { 
                }
              }
            }
        }
    }
huhl($s);
exit;
}

function si_err($str) {
    global $sess;
    MsgPage($sess->url(self_base())."index.php3", $str, "standalone");
    exit;
}

// Create and parse data
function sliceimp_xml_parse($xml_data,$dry_run=false) { 
    set_time_limit(600); // This can take a while
    $xu = new xml_unserializer();
    #huhl("Importing data=",$xml_data);
    $i = $xu->parse($xml_data);  // PHP data structure
    #huhl("Parsed data=",$i);
    $s = $i["SLICEEXPORT"][0];
    if (! isset($s)) si_err(_m("\nERROR: File doesn't contain SLICEEXPORT"));
    if ($s[PROCESS]) {
        $v = $s[PROCESS] . "_preimport"; 
        print("\nPre-processing with $v");
        $v($s);
    }
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
			   import_slice($slice);

        if (is_object($sl[VIEWS])) {
            import_views($sl[VIEWS]);
        } // have some views
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

function import_views(&$slvs) {
    global $dry_run, $view_resolve_conflicts, $new_slice_ids,
           $view_IDconflict,$IDconflict, $view_conflicts_ID;
    #print("<br>Checking ".count($slvs->a)." views");
    $av = GetViewsWhere();
    reset($slvs->a);
    foreach ($slvs->a as $slv) { # $slv is a viewobj
        $slvf = $slv->f();
        $id = $slvf["id"];
        if (isset($av[$id]) 
                &&($GLOBALS["Submit"]!=_m("Insert with new ids"))) {
            $view_conflicts_ID[$id] = substr($slvf["name"],10)." to ".
                substr($av[$id]->f("id"),10);
            $view_IDconflict = true;
        }
    }
    if ($IDconflict) return;  // Don't try and add views, if Slice id conflict
    #print("<br>Importing ".count($slvs->a)." views");
    // Several cases here
    // If there is a conflict, then 
    // Overwrite => just go ahead, use changed id if available
    // Insert with new ids => pick a new id for view
    // Insert => skip conflicts
    //
    // note if no conflict then will import, which might be bad if 
    // slice_id would have been changed.
    // Note that difference between Overwrite & InsertNew, is in whether
    // other views for this slice should be deleted first, they are not
    // currently deleted, although that would be an OK change to make
    reset($slvs->a);
    foreach ($slvs->a as $slv) { # $slv is a viewobj
        #huhl("Working on view",$slv);
        $varset = new Cvarset();
        $slvf = $slv->f();
        $id = $slvf["id"];

        #huhl("varset=",$varset);
        if($dry_run) {
                 print("Would import view $id: "
                            .$slv->f("name"));
        } else {
            if (isset($av[$id])) {
                if ($GLOBALS["Submit"] ==_m("Insert")) continue; // skip
                if ($GLOBALS["Submit"] ==_m("Insert with new ids")) {
                    unset($slvf["id"]); // Allow CREATE to create new
                    $id = 0;  // Should never exist, so won't complain
                }
                elseif ($GLOBALS["Submit"] ==_m("Overwrite")) {
                    $res = $view_resolve_conflicts[$id]; // maybe same
                    $slvf["id"] = $res;
                    $id = $res;
                } else {
                    continue;
                }
            }
            // Note this mirrors what looks like bug in import_slice_data
            // if using Overwrite and change id, won't see new slice_id (mitra)
            if ($GLOBALS["Submit"] ==_m("Insert with new ids")) {
	            $slvf["slice_id"] = 
                    pack_id($new_slice_ids[$slice_id]["new_id"]);
            }
            while(list($k,$v) = each($slvf)) {
                $varset->add($k,"text",$v);
            }
            $db = getDB();
            print(_m("<br>Overwriting view %1",array($id)));
            if (isset($av[$id])) {
                $SQL = "UPDATE view SET ". $varset->makeUPDATE() 
                       ." WHERE id='$id'";
                if( !$db->query($SQL)) {
                    $err["DB"] = MsgErr( _m("Can't change slice settings") );
                    break;   # not necessary - halt_on_error is set
                }
            } else {  // no conflict (with new id if changed), or new id
                    if( !$db->query("INSERT INTO view "
                        . $varset->makeINSERT())) {
                        $err["DB"] = MsgErr( _m("Can't insert into view.") );
                         break;  # not necessary - halt_on_error is set
                    }
            } // existing view
            freeDB($db);
        }
    } // each view
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
        // Only import fields with integer keys, arrays - e.g. $slice[fields] 
        // handled elsewhere
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
