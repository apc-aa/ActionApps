<?php  #se_category2.php3 - assigns categories to specified slice - writes it to database
# expected $slice_id for edit slice
#          $C[] with ids of assigned categories        
#          $N[] with names of new categories 

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

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CATEGORY)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_CATEGORY);
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$catVS = new Cvarset();

do {
  $db->query("DELETE FROM catbinds WHERE slice_id = '$p_slice_id'");

  if( isset($N) AND is_array($N) ) {  // insert to categories
    while( list(,$val) = each($N) ) {
      $catVS->clear();
      $newid=new_id();
      $catVS->add("id", "unpacked", $newid);
      $catVS->add("name", "text", $val);
      if (!$db->query("INSERT INTO categories" . $catVS->makeINSERT() )) {
        $err["DB"] .= MsgErr( "Can't add category $val" );
        break;  # not necessary - we have set the halt_on_error
      }
      $C[]=$newid;  // add to catbinds
    }
  }    
      
  if( isset($C) AND is_array($C) ) {    // insert to catbinds
    while( list(,$val) = each($C) ) {
      $catVS->clear();
      $catVS->add("slice_id", "unpacked", $slice_id);
      $catVS->add("category_id", "unpacked", $val);
      if(!$db->query("INSERT INTO catbinds" . $catVS->makeINSERT() )) {
        $err["DB"] .= MsgErr("Can't add category binding $val");
        break;     # not necessary - we have set the halt_on_error
      }
    }
  }      
} while(false);
if( count($err) <= 1 ) 
  go_url( $sess->url(self_base() . "se_category.php3") ."&Msg=" . 
    rawurlencode(MsgOK(L_CATBINDS_OK)));
else
  MsgPage($sess->url(self_base()."se_category.php3"), $err);

page_close();
?> 