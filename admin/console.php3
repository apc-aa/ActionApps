<?php
//$Id$
/*
Copyright (C) 2003 Mitra Technology Consulting
http://www.mitra.biz

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
  This is a prototype of a console, a single place to access all the tasks that
  might need doing. It might be a better point of entry than the Item Manager

  Note it is based on the code in admin/oneoff.php3
*/



require_once "../include/init_page.php3"; // Loads variables etc
require_once "../include/util.php3"; // Loads variables etc

//require_once $GLOBALS["AA_INC_PATH"]."sliceobj.php3";  // for slices
#$debug = 1;

// Quick test to show contents of slice record
if (0) {
    $db->query("SELECT * FROM slice LIMIT 1");
    $db->next_record();
    huhl(GetTable2Array("SELECT * FROM slice LIMIT 1",NoCoLuMn,1));
}

HtmlPageBegin();
?>
 <TITLE><?php echo _m("Console");?></TITLE>
</HEAD>

<?php
    require_once $GLOBALS["AA_INC_PATH"]."menu.php3";
    showMenu($aamenus, "aaadmin","console");
  
    echo "<H1><B>" . _m("AA - Administration Console") . "</B></H1>";
    PrintArray($err);
    echo $Msg;
    $nocache=1;
// Call code here
    do_actionrequired();
    HtmlPageEnd();
    page_close();


function do_actionrequired() {
  comments_table();
  hold_table();
}
function comments_table() {
    $SQL = "SELECT sum(disc_count),sum(disc_app),slice_id FROM item GROUP BY slice_id";
    $slices_arr = GetTable2Array($SQL,"slice_id");
    while (list($qpsliceid,$slarr) = each($slices_arr)) {
        // huhl("s=",unpack_id128($qpsliceid),"c=".$slarr[0],"a=",$slarr[1]);
        if ($slarr[1] != $slarr[0]) {
            $usi = unpack_id128($qpsliceid);
            print("<tr><td>".sliceid2name($usi)."</td><td><a href=\""
                .get_admin_url("console.php3?slice_id=$usi")
                ."\">".($slarr[0]-$slarr[1])." unapproved comments</a>");
//            if ($GLOBALS["slice_id"] == $usi) 
            {
                $SQL="SELECT disc_count,disc_app,id FROM item WHERE disc_count > disc_app AND slice_id = \"$qpsliceid\"";
                $itemsarr = GetTable2Array($SQL,"id");
                print("<ul>");
                while (list($qpitemid,$itarr) = each($itemsarr)) {
                    $uid=unpack_id128($qpitemid);
                    $ic = GetItemContent($uid);
                    $headline = $ic[$uid]["headline........"][0][value];
//                    huhl($GLOBALS);
                    $url = get_admin_url("discedit.php3?item_id=$uid")."&return_url=$GLOBALS[PHP_SELF]";
                    print("<li><a href=\"$url\">$headline</a>\n");
                }
                print("</ul>");
            }
            print("</td>"
            ."</tr>\n");
        }
    }
    print("</table>");
/*
    $db = getDB();
    $res = $db->tquery($SQL);
    if ($db->next_record()) {
        $dc = $db->f("sum(disc_count)");
    } else {
        $dc = 0;
    }
    freeDB($db);
    return $dc;
*/
}
function hold_table() {
    $SQL = "select slice_id,name,count(*) FROM item,slice WHERE status_code =2 AND slice_id = slice.id GROUP BY slice_id";
    $slices_arr = GetTable2Array($SQL,"slice_id");
    print("<table>\n");
    while (list($qpsliceid,$slarr) = each($slices_arr)) {
            $usi = unpack_id128($qpsliceid);
            print("<tr><td>".$slarr[1]."</td><td><a href=\""
                .get_admin_url("index.php3?Tab=hold&change_id=$usi")
                ."\">".$slarr[2]." held</a>");
            print("</td>"
            ."</tr>\n");
    }
    print("</table>");
}
?>
