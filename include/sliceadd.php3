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

function cmp ($a, $b) {
  return strcmp($a["name"], $b["name"]);
} 

if(!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD)) {
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_ADD, "standalone");
    exit;
}

$SQL = "SELECT name, id, template, lang_file FROM slice WHERE deleted<>1";
$db->query($SQL);
while( $db->next_record() ) {
    if ( $db->f(template) ) {
        $templates[unpack_id( $db->f(id) )][value] = unpack_id( $db->f(id) ) ."{". $db->f(lang_file);
        $templates[unpack_id( $db->f(id) )][name]  = $db->f(name);
    } 
    else {
        $temp_slices[unpack_id( $db->f(id) )][value] = unpack_id( $db->f(id) ) ."{". $db->f(lang_file);
        $temp_slices[unpack_id( $db->f(id) )][name]  = $db->f(name);
    }  
}    

echo '
    <table border="0" cellspacing="0" cellpadding="1" bgcolor="'.COLOR_TABTITBG.'" align="center">
    <tr><td class=tabtit><b>&nbsp;'.L_A_SLICE.'</b>
    </td></tr>
    <tr><td>
    <table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="'.COLOR_TABBG.'">';

if( isset( $templates ) AND is_array( $templates ) AND
    isset( $temp_slices ) AND is_array( $temp_slices )
    ){
      echo "<tr><td class=tabtxt colspan=4>" . L_A_SLICE_ADD_HELP . "</TD></TR>";
    }

  if( isset( $templates ) AND is_array( $templates )) {
    usort($templates, "cmp"); 
    echo "<tr><td class=tabtxt><b>". L_TEMPLATE ."</b>";
    echo "</td><td><select name=\"template_id\">";	
    reset($templates);
    while(list(,$v) = each($templates)) { 
      echo "<option value=\"". htmlspecialchars($v[value])."\"";
      echo "> ". htmlspecialchars($v[name]) ." </option>";
    }
    echo '</select></td><td>';
    if ($wizard)
         echo '<input type="radio" name="template_slice_radio" value="template" checked>';
    else echo '<input type="SUBMIT" name="template_slice_sel[template]" value="'.L_ADD.'" checked>';
    echo "</td></tr>";
  } else
    echo "<tr><td class=tabtxt colspan=2>". L_NO_TEMPLATES ."</td></tr>";
        

  if( isset( $temp_slices ) AND is_array( $temp_slices )) {
    usort($temp_slices, "cmp"); 
    echo "<tr><td class=tabtxt><b>". L_SLICE ."</b>";
    echo "</td>\n <td><select name=\"template_id2\">";	
    reset($temp_slices);
    while(list(,$v) = each($temp_slices)) { 
      echo "<option value=\"". htmlspecialchars($v[value])."\"";
      echo "> ". htmlspecialchars($v[name]) ." </option>";
    }
    echo '</select></td><td>';
    if ($wizard)
         echo '<input type="radio" name="template_slice_radio" value="slice" checked>';
    else echo '<input type="SUBMIT" name="template_slice_sel[slice]" value="'.L_ADD.'"';
    echo '<input type="hidden" name="Add_slice" value="1">
        </td></tr>';
  } else
    echo "<tr><td class=tabtxt colspan=2>". L_NO_SLICES ."</td></tr>";

 ?>
