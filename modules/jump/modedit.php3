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

/* Params:
    $edit=1 .. called by Edit Jump menu
    $jump_id .. ID of jump to edit
    $update=1 .. write changes to database
*/

$directory_depth = "../";
require "../../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";

// create the $jumps array:
$db = new DB_AA;
$db->query ("SELECT * FROM module WHERE type='J'");
while ($db->next_record()) 
    $jumps[unpack_id($db->f("id"))] = $db->f("name");

// choose the first module when none is chosen
if ($edit && !$jump_id && count ($jumps)) {
    reset ($jumps);
    $jump_id = key ($jumps);
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "</head><body>";

require $GLOBALS[AA_INC_PATH] . "menu.php3";
showMenu ($aamenus, "aaadmin","jumpedit");

if ($update) {
    if (!$jump_id) $jump_id = new_id ();
    $q_jump_id = q_pack_id ($jump_id);
    
    $varset = new Cvarset();
    $varset->clear();
    $varset->add("destination", "quoted", $jump_url);
    $varset->add("dest_slice_id", "unpacked", $dest_id);
    
    $db->query ("SELECT * FROM module WHERE id='$q_jump_id'");
    if ($db->next_record()) {
        $db->query ("UPDATE jump SET ".$varset->makeUPDATE() ." WHERE slice_id='$q_jump_id'");
        $db->query ("UPDATE module SET name='$jump_name' WHERE id='$q_jump_id'");
    }
    else {
        $varset->add("slice_id", "unpacked", $jump_id);
        $db->query ("INSERT INTO jump ".$varset->makeINSERT());
        
        $varset->clear();
        $varset->add("id","unpacked",$jump_id);
        $varset->add("name","quoted",$jump_name);
        $varset->add("type","quoted",'J');
        $varset->add("lang_file","quoted","en_news_lang.php3");
        
        $db->query ("INSERT INTO module ".$varset->makeINSERT());
    }
}

if (!$jump_id)
    $jump_name = $jump_url = $dest_id = "";

else {
    $db->query ("SELECT name,destination,dest_slice_id FROM jump INNER JOIN module 
            ON jump.slice_id = module.id 
            WHERE jump.slice_id = '".q_pack_id ($jump_id)."'");
    if ($db->next_record()) {
        $jump_name = $db->f("name");
        $jump_url = $db->f("destination");
        $dest_id = unpack_id($db->f("dest_slice_id"));
    }
}

echo '<H1><B>'.( !$jump_id ? L_A_JUMP_ADD : L_A_JUMP_EDT) . '</B></H1>';

// Show the select box to choose a module to edit
if ($jump_id) {
    echo '
    <form name=choose action="modedit.php3?AA_CP_Session='.$AA_CP_Session.'" method="post">
    <table border="0" cellspacing="0" cellpadding="3" align="center">
         <tr><td class=tabtit><b>'.L_CHOOSE_JUMP.':</b></td>
            <td class=tabtit>
                <select name="jump_id" onchange="document.forms.choose.submit();">';
                reset ($jumps);
                while (list ($id,$name) = each ($jumps)) 
                    echo "<option value=\"$id\""
                    .($id == $jump_id ? " selected" : "")
                    .">".htmlspecialchars($name);
    
                echo '</select>&nbsp;
                <input type=submit name="edit" value="'.L_EDIT.'">
            </td>
        </tr>
    </table></form>';
}

echo '
<form name=f action="modedit.php3?AA_CP_Session='.$AA_CP_Session.'" method="post">
<input type=hidden name="jump_id" value="'.$jump_id.'">';
if ($edit) echo "<input type=hidden name='edit' value='1'>";
echo '
<table border="0" cellspacing="0" cellpadding="3" align="center">
     <tr><td class=tabtxt><b>'.L_MODULE_NAME.':</b></td>
        <td class=tabtxt><input type=text name="jump_name" size=20 value="'.$jump_name.'"></td></tr>
     <tr><td class=tabtxt><b>'.L_JUMP_TO.' (URL):</b></td>
        <td class=tabtxt><input type=text name="jump_url" size=60 value="'.$jump_url.'"></td></tr>
     <tr><td colspan=2 class=tabtxt>'.L_AA_RELATIVE.' <code>admin/se_constant.php3?group_id=something</code></td></tr>
     <tr><td class=tabtxt><b>'.L_JUMP_SLICE.":</b></td>
        <td class=tabtxt><select name=dest_id>";
//        <option value=''>* * * Don't change slice * * *";
     if( is_array($g_modules) AND (count($g_modules) > 1) ) {
        reset($g_modules);
        while(list($k, $v) = each($g_modules)) { 
            echo "<option value=\"". htmlspecialchars($k)."\"";
            if ( $dest_id == $k ) echo " selected";
            echo ">". htmlspecialchars($v['name']);
        }
     }
     else echo "<option>No module exists";
     echo "</select></td>
     </tr>
     <tr><td class=tabtxt colspan=2>".L_MODULE_ID.": $jump_id</td></tr>
     <tr><td class=tabtxt colspan=2 align=center>
        <input type=submit name='update' value='".($jump_id ? L_UPDATE : L_CREATE)."'>
     </td></tr>
</table></form>";
HTMLPageEnd();
page_close();
?>

