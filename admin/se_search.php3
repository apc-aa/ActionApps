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

// expected $slice_id for edit slice, nothing for adding slice

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."msgpage.php3";

if ($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if (!IfSlPerm(PS_SEARCH)) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change search settings"), "admin");
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable

function PackFields($name,$fields_count) {
  $foo="";
  for ( $i=0; $i<$fields_count; $i++ ) {
    $varname = "$name$i";
    $foo .= ( $GLOBALS[$varname] ? "y" : "n");
  }
  return $foo;
}  

// function for unpacking string in edit_fields and default_fields in database
// example: for "yynnny" string fils variable f0=1, f1=1, f2=0 .. ($name='f')
function UnpackFields($packed,$name,$field_count) {
  for ( $i=0; $i<$field_count; $i++ ) {
    $varname = "$name$i";
    $GLOBALS[$varname] = (substr($packed,$i,1)=="y" ? true : false);
  }
}  

function FieldInput($name1, $name2, $txt){
  echo "<tr><td class=tabtxt><b>$txt</b></td>\n ";
  echo "<td align=center><input type=\"checkbox\" name=\"$name1\"";
  if ($GLOBALS[$name1])
    echo " checked";
  echo "></td>";
  echo "<td align=center><input type=\"checkbox\" name=\"$name2\"";
  if ($GLOBALS[$name2])
    echo " checked";
  echo "></td>";
  echo "</tr>\n";
}

function FieldInputShow($name1, $txt){
  echo "<tr><td class=tabtxt><b>$txt</b></td>\n ";
  echo "<td align=center><input type=\"checkbox\" name=\"$name1\"";
  if ($GLOBALS[$name1])
    echo " checked";
  echo "></td>";
  echo "<td>&nbsp;</td>";
  echo "</tr>\n";
}

if ( $update )
{
  $shown   = PackFields('f', count($SHOWN_SEARCH_FIELDS));
  $default = PackFields('d', count($DEFAULT_SEARCH_IN));
    
  $SQL = "UPDATE slices SET search_show = '$shown', search_default = '$default' WHERE id='$p_slice_id'";
  if (!$db->query($SQL))   // not necessary - we have set the halt_on_error
    $err["DB"] = MsgErr("Can't change fields");
  $GLOBALS[cache]->invalidateFor("slice_id=$slice_id");  // invalidate old cached values
    
  if ( count($err) <= 1 )
    $Msg = MsgOK(_m("Search fields update successful"));
}
else if ( $slice_id!="" ) { // update => set variables from database
  $SQL= "SELECT search_show, search_default FROM slices WHERE id='$p_slice_id'";
  $db->query($SQL);
  if ($db->next_record()) {
    UnpackFields( $db->f(search_show), 'f', count($SHOWN_SEARCH_FIELDS));
    UnpackFields( $db->f(search_default), 'd', count($DEFAULT_SEARCH_IN));
  }  
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Admin - design Search Page");?></TITLE>
</HEAD>
<?php 
  require_once AA_INC_PATH."menu.php3";
  showMenu ($aamenus, "sliceadmin","search");
  
  echo "<H1><B>" . _m("Admin - design Search Page") . "</B></H1>";
  PrintArray($err);
  echo $Msg;  
?>
<form method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo _m("Search form criteria")?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<tr><td class=tabtxt width="40%"><b><?php echo _m("Field") ?></b></td><td class=tabtxt width="30%" align=center><b><?php echo _m("Show") ?></b></td><td>&nbsp;</td></tr>
<?php
  reset($SHOWN_SEARCH_FIELDS);
  $number=0;
  while ( list($name, $val) = each($SHOWN_SEARCH_FIELDS)) {
    if ($DEFAULT_SEARCH_IN[$name]=="") {    // no default value for this field
      FieldInputShow("f$number", $val);
      $number++;
    }  
  }  
?>  
</table></td></tr>
<tr><td class=tabtit><b>&nbsp;<?php echo _m("Search in fields")?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<tr><td class=tabtxt width="40%"><b><?php echo _m("Field") ?></b></td>
    <td class=tabtxt width="30%" align=center><b><?php echo _m("Show") ?></b></td>
    <td class=tabtxt align=center><b><?php echo _m("Default settings") ?></b></td></tr>
<?php
  reset($DEFAULT_SEARCH_IN);
  $i=0;
  while ( list($name, $val) = each($DEFAULT_SEARCH_IN)) {
    FieldInput("f$number", "d$i", $val);
    $number++; $i++;
  }  
?>  
</table></td></tr>
 <tr><td align="center">
<?php 
  echo "<input type=hidden name=\"update\" value=1>";
  echo "<input type=hidden name=\"slice_id\" value=$slice_id>";
  echo '<input type=submit name=update value="'. _m("Update") .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. _m("Cancel") .'">&nbsp;&nbsp;';
?>   
</td></tr></table>
</FORM>
<?php HtmlPageEnd();
page_close()?>