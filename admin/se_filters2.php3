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

# se_filters2.php3 - assigns feeding filters to specified slice - writes it to database
# expected $slice_id for edit slice
#          $import_id for id of imported slice
#          $all (set to 1 if import from all categories is selected)
#          $C contains category into which all categories are imported (only when $all is 1)
#             if $C==0 then import to the same category as source item category
#          $F[] array of imported categories, plus string "-0" or "-1" in order to approved checked
#          $T[] array of categories into which we should import (corresponds to F[] array)
#             if $T[]==0 then import to the same category as source item category
#          $feed_id

require_once "../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
require_once $GLOBALS["AA_INC_PATH"]."csn_util.php3";
require_once $GLOBALS["AA_INC_PATH"]."msgpage.php3";

if(!IfSlPerm(PS_FEEDING)) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change feeding setting"), "admin");
  exit;
}  

function ParseIdA($param,&$app) {
   if (ERegI("([0-9a-f]{1,32}|0)-([01])", $param, $parse)) {  // slice_id or 0 
      $app = $parse[2];
      return $parse[1];
   }
}   

$p_import_id= q_pack_id($import_id);

$err["Init"] = "";       // error array (Init - just for initializing variable)
$catVS = new Cvarset();

if ($feed_id) {
  // setting filters from external slice

  if ($ext_categs = GetExternalCategories($feed_id)) {

    // delete current filters and then insert new
    $db->query("DELETE FROM ef_categories WHERE feed_id='$feed_id'");

    if ($all) {                           // all categories
      $to_id = ParseIdA($C, $app);
      while (list($id, ) = each($ext_categs)) {
        $ext_categs[$id][target_category_id] = $to_id;
        $ext_categs[$id][approved] = $app;
      }
    }
    else {                                // individual categories
      while (list($id, ) = each($ext_categs)) {
        $ext_categs[$id][target_category_id] = "";
        $ext_categs[$id][approved] = 0;
      }

      while (list($index,$id ) = each($F)) {
        $from_cat = ParseIdA($id, $app);
        $ext_categs[$from_cat][target_category_id] = $T[$index];
        $ext_categs[$from_cat][approved] = $app;
      }
    }

    reset($ext_categs);
    while (list ($id,$v) = each($ext_categs)) {
      $catVS->clear();
      $catVS->add("category", "quoted", $v[value]);
      $catVS->add("category_name", "quoted", $v[name]);
      $catVS->add("category_id", "unpacked", $id);
      $catVS->add("feed_id", "number", $feed_id);
      $catVS->add("target_category_id", "unpacked", $v[target_category_id]);
      $catVS->add("approved", "number", $v[approved]);   // zero = the same category
      $SQL = "INSERT INTO ef_categories" . $catVS->makeINSERT();
        if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
        $err["DB"] .= MsgErr("Can't add import from $val");
      }
    }
  }
} else {

// First we DELETE current filters and then INSERT new.
// We can't use UPDATE because the count of old and new rows can be different.
// We could UPDATE existing rows and INSERT new, but DELETE/INSERT is simpler.
// A transaction would be nice.

$db->query("DELETE FROM feeds WHERE to_id = '$p_slice_id' " .
           "AND from_id = '$p_import_id'");

if ($all) {                                         // all_categories
  $id = ParseIdA($C, $app);
  $catVS->clear();
  $catVS->add("to_id", "unpacked", $slice_id);
  $catVS->add("from_id", "unpacked", $import_id);
  $catVS->add("all_categories", "number", 1);
  $catVS->add("to_approved", "number", $app);
  $catVS->add("to_category_id", "unpacked", $id);   // zero = the same category
  $SQL = "INSERT INTO feeds" . $catVS->makeINSERT();
  if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
    $err["DB"] .= MsgErr("Can't add import from $val");
  }
} else if (isset($F) AND is_array($F)) {            // insert to categories
  reset($F);
  while( list($index,$val) = each($F) ) {
    $from_cat = ParseIdA($val, $app);
    $to_cat = $T[$index];
    if( $to_cat == "0" )
      $to_cat = $from_cat;
    $catVS->clear();
    $catVS->add("to_id", "unpacked", $slice_id);
    $catVS->add("from_id", "unpacked", $import_id);
    $catVS->add("all_categories", "number", 0);
    $catVS->add("to_approved", "number", $app);
    $catVS->add("category_id", "unpacked", $from_cat);  
    $catVS->add("to_category_id", "unpacked", $to_cat);
    $SQL = "INSERT INTO feeds" . $catVS->makeINSERT();
    if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
      $err["DB"] .= MsgErr("Can't add import from $val");
      break;
    }
  }
}        
}

if( count($err) <= 1 )
  go_url( $sess->url(self_base() . "se_filters.php3") ."&import_id=$import_id&Msg=" . rawurlencode(MsgOK(_m("Content Pooling update successful"))));
else
  MsgPageMenu($sess->url(self_base()."se_import.php3"), $err, "admin");

page_close();
?> 
