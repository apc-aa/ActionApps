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

$New_slice = true;  // variable tells to init_page, there should not be defined slices, here
require "../include/init_page.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_ADD, "standalone");
  exit;
}

$SQL = "SELECT name, id, template, lang_file FROM slice WHERE deleted<>1";
$db->query($SQL);
while( $db->next_record() ) {
  if( $db->f(template) ) {
    $templates[unpack_id( $db->f(id) )][value] = unpack_id( $db->f(id) ) ."{". $db->f(lang_file);
    $templates[unpack_id( $db->f(id) )][name]  = $db->f(name);
  } else {
    $temp_slices[unpack_id( $db->f(id) )][value] = unpack_id( $db->f(id) ) ."{". $db->f(lang_file);
    $temp_slices[unpack_id( $db->f(id) )][name]  = $db->f(name);
  }  
}    
   
$err["Init"] = "";          // error array (Init - just for initializing variable

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_SLICE_TIT;?></TITLE>
</HEAD>
<?php 
  echo "<H1><B>" . L_A_MODULE_ADD ."</B></H1>";
  PrintArray($err);
  echo $Msg;  
?>

<center>
<form method=post action="<?php echo $sess->url("slicedit.php3") ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_A_SLICE?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php

if( isset( $templates ) AND is_array( $templates ) AND
    isset( $temp_slices ) AND is_array( $temp_slices )
    ){
      echo "<tr><td class=tabtxt colspan=4>" . L_A_SLICE_ADD_HELP . "</TD></TR>";
    }

  if( isset( $templates ) AND is_array( $templates )) {
    usort($templates, "cmp"); 
    echo "<tr><td class=tabtxt colspan=2><b>". L_TEMPLATE ."</b>";
    echo "</td>\n <td><select name=\"template_id\">";	
    reset($templates);
    while(list(,$v) = each($templates)) { 
      echo "<option value=\"". htmlspecialchars($v[value])."\"";
      echo "> ". htmlspecialchars($v[name]) ." </option>";
    }
    echo '</select></td><td>
          <input type="SUBMIT" name="template_slice_sel[template]" value="'.L_ADD.'">
        </td></tr>';
  } else
    echo "<tr><td class=tabtxt colspan=2>". L_NO_TEMPLATES ."</td></tr>";
        

  if( isset( $temp_slices ) AND is_array( $temp_slices )) {
    usort($temp_slices, "cmp"); 
    echo "<tr><td class=tabtxt colspan=2><b>". L_SLICE ."</b>";
    echo "</td>\n <td><select name=\"template_id2\">";	
    reset($temp_slices);
    while(list(,$v) = each($temp_slices)) { 
      echo "<option value=\"". htmlspecialchars($v[value])."\"";
      echo "> ". htmlspecialchars($v[name]) ." </option>";
    }
    echo '</select></td><td>
          <input type="SUBMIT" name="template_slice_sel[slice]" value="'.L_ADD.'">
          <input type="hidden" name="Add_slice" value="1">
        </td></tr>';
  } else
    echo "<tr><td class=tabtxt colspan=2>". L_NO_SLICES ."</td></tr>";
 ?>

</table>
</table>

<br><br>

<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_A_MODULE?></b>
<tr><td><table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?  reset ($MODULES);
    while (list ($letter,$module) = each ($MODULES)) {
        if ($module["hide_create_module"]) continue;
        echo "<TR><TD class=tabtxt><B>".$module['name']."</B></TD><TD>
            <INPUT TYPE=SUBMIT NAME='create[$letter]' value='".L_ADD."'></TD></TR>";
    }
?>
</table></td></tr>
</table>

<br><br>

<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td align="center">
<?php 
  echo '<input type=submit name=cancel value="'. L_CANCEL .'">';
?>   
</td></tr>
</table>
</FORM>
</center>
<?php echo L_APP_TYPE_HELP ?>
</BODY>
</HTML>
<?php page_close()?>

