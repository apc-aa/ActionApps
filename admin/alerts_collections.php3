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

# expected $slice_id for edit slice, nothing for adding slice

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."msgpage.php3";
require $GLOBALS[AA_INC_PATH]."mgettext.php3";
require $GLOBALS[AA_INC_PATH]."../misc/alerts/util.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT)) {
  MsgPageMenu($sess->url(self_base())."index.php3", L_NO_PS_VIEWS, "admin");
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable

execute_edit_collections (1);

// ------------------------------------------------------------------------------------
//                                   SHOW PAGE  

         
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo "<TITLE>"._m("Alerts - Collections")."</TITLE>
      </HEAD>";

require $GLOBALS[AA_INC_PATH]."menu.php3";
showMenu ($aamenus, "sliceadmin", "alerts_collections");  

echo "<H1><B>" . _m("Alerts - Collections") . "</B></H1>";
PrintArray($err);
echo $Msg;

$db = new DB_AA;
$db->query ("SELECT id FROM alerts_digest_filter");

if ($db->num_rows() == 0) 
    echo _m("First create some view of type Alerts Digest and define some filters there.");

// list of filters in collections

$SQL = "SELECT C.id AS cid, C.description AS cdesc, 
    DF.description as fdesc, slice.name, CF.filterid AS fid 
    
    FROM alerts_collection C INNER JOIN
    alerts_collection_filter CF ON C.id = CF.collectionid INNER JOIN
    alerts_digest_filter DF ON CF.filterid = DF.id INNER JOIN
    view ON DF.vid = view.id INNER JOIN
    slice ON view.slice_id = slice.id
    
    WHERE C.showme = 1
    
    ORDER BY C.description, C.id, CF.myindex";    

print_js_options_filters(false);

echo  '
    <form method=post action="'.$script.'">
    <table border="0" cellspacing="0" cellpadding="3" bgcolor="'.COLOR_TABTITBG.'" align="center">';

print_edit_collections ($SQL, $sess->url($PHP_SELF));
print_add_collection ();
echo $ac_trstart."<td colspan=2><hr></td>";

echo $ac_trstart.'<td align=center colspan=2>
    <input type="submit" name="cancel" value="'._m("Cancel").'"></td>'.$ac_trend.'
    </table></form></BODY></HTML>';
    
page_close();
?>
