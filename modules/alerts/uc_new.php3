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

// (c) Econnect, Jakub Adamek, December 2002

require "uc_menu.php3";
require $GLOBALS[AA_INC_PATH]."constants.php3";
require $GLOBALS[AA_INC_PATH]."tabledit.php3";
require $GLOBALS[AA_INC_PATH]."tv_common.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require "uc_tableviews.php3";

showMenu ("new");

$db->query ("SELECT AC.id, slice_url, name FROM alerts_collection AC
             INNER JOIN module ON AC.moduleid = module.id
             ORDER BY name");
while ($db->next_record())
    $collections[$db->f("id")] = array ("name"=>$db->f("name"), "url"=>$db->f("slice_url"));
    
$db->query ("SELECT collectionid FROM alerts_user_collection 
    WHERE userid=".$auth->auth["uid"]);
while ($db->next_record())
    unset ($collections[$db->f("collectionid")]);    

if (!is_array ($collections) || count ($collections) == 0) 
    echo "<B>"._m("You are already subscribed to all existing collections.")."</B>";    
else {    
    usort ($collections, "mycompare");        
    reset ($collections);
    echo '<TABLE cellspacing="0" cellpadding="5" border=1>';
    while (list ($id, $cprop) = each ($collections)) {
        echo '<TR><TD class=tabtxt><A href="'
            .$AA_INSTAL_PATH."post2shtml.php3?shtml_page=".$cprop["url"]."&uid=".$auth->auth ["uid"]
            .'">'.$cprop["name"].'</A></TD></TR>';
    }
    echo "</TABLE>";
}

EndMenuPage();

function mycompare ($c1, $c2) {
    return $c1["name"] < $c2["name"];
}
?>
