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

// messages for init_page:
$no_slice_id = true;  
$require_default_lang = true;

require "../include/init_page.php3";

// the parts used by the slice wizard are in the included file

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

$err["Init"] = "";          // error array (Init - just for initializing variable

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Slice Administration");?></TITLE>
</HEAD>
<?php 
  echo "<H1><B>" . _m("Create New Slice / Module") ."</B></H1>";
  PrintArray($err);
  echo $Msg;  
?>

<center>
<form method=post action="<?php echo $sess->url("slicedit.php3") ?>">
<?php
    require $GLOBALS[AA_INC_PATH]."sliceadd.php3";
?>

</table>
</table>

<br><br>

<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo _m("Module")?></b>
<tr><td><table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php  reset ($MODULES);
    while (list ($type, $module) = each ($MODULES)) {
        if ($module["hide_create_module"]) continue;
        echo "<TR><TD class=tabtxt><B>".$module['name']."</B></TD><TD>";
        if ($module["show_templates"]) {
            echo "<SELECT name=\"template[$type]\">";
            reset ($g_modules);
            while (list ($mid,$mod) = each ($g_modules)) {
                if( $mod['type']==$type )
                    echo "<OPTION value=\"x$mid\">".$mod['name']."</OPTION>";
            }        
            echo "</SELECT>";
        } else
            echo "&nbsp;";
        echo "</TD><TD>
            <INPUT TYPE=SUBMIT NAME='create[$type]' value='"._m("Add")."'></TD></TR>";
    }
?>
</table></td></tr>
</table>

<br><br>

<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td align="center">
<?php 
  echo '<input type=submit name=cancel value="'. _m("Cancel") .'">';
?>   
</td></tr>
</table>
</FORM>
</center>
<?php echo _m("<br><br><br><br>") ?>
<?php echo "</body></html>";
page_close()?>

