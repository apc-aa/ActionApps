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

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_FIELDS);
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable

function PackFields($name,$fields_count) {
  $foo="";
  for( $i=0; $i<$fields_count; $i++ ) {
    $varname = "$name$i";
    $foo .= ( $GLOBALS[$varname] ? "y" : "n");
  }
  return $foo;
}  

// function for unpacking string in edit_fields and needed_fields in database
// example: for "yynnny" string fils variable f0=1, f1=1, f2=0 .. ($name='f')
function UnpackFields($packed,$name,$field_count) {
  for( $i=0; $i<$field_count; $i++ ) {
    $varname = "$name$i";
    $GLOBALS[$varname] = (substr($packed,$i,1)=="y" ? true : false);
  }
}  

function FieldInput($name1, $name2, $txt)
{ echo "<tr><td class=tabtxt><b>$txt</b></td>\n ";
  echo "<td align=center><input type=\"checkbox\" name=\"$name1\"";
  if($GLOBALS[$name1])
    echo " checked";
  echo "></td>";
  echo "<td align=center><input type=\"checkbox\" name=\"$name2\"";
  if($GLOBALS[$name2])
    echo " checked";
  echo "></td>";
  echo "</tr>\n";
}

if( $update )
{
  $shown  = PackFields('f', count($itemedit_fields));
  $needed = PackFields('n', count($itemedit_fields));
    
  if( $update )
  {
    $SQL = "UPDATE slices SET edit_fields = '$shown', needed_fields = '$needed' WHERE id='$p_slice_id'";
    if (!$db->query($SQL))    # not necessary - we have set the halt_on_error
      $err["DB"] = MsgErr("Can't change fields");
  }    
  if( count($err) <= 1 )
    $Msg = MsgOK(L_FIELDS_OK);
}
else if( $slice_id!="" ) { // update => set variables from database
  $SQL= "SELECT edit_fields, needed_fields FROM slices WHERE id='$p_slice_id'";
  $db->query($SQL);
  if ($db->next_record()) {
    UnpackFields( $db->f(edit_fields), 'f', count($itemedit_fields));
    UnpackFields( $db->f(needed_fields), 'n', count($itemedit_fields));
  }  
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_FIELDS_TIT;?></TITLE>
</HEAD>
<?php 
  $xx = ($slice_id!="");
  $show = Array("main"=>true, "config"=>$xx, "category"=>$xx, "fields"=>false, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx, 
                "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable
  
  echo "<H1><B>" . L_A_FIELDS_EDT . "</B></H1>";
  PrintArray($err);
  echo $Msg;  
?>
<form enctype="multipart/form-data" method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="#584011" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_FIELDS_HDR?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<tr><td class=tabtxt align=center><b><?php echo L_FIELD ?></b></td><td class=tabtxt align=center><b><?php echo L_FIELD_IN_EDIT ?></b></td><td class=tabtxt align=center><b><?php echo L_NEEDED_FIELD ?></b></td></tr>
<tr><td colspan=3><hr></td></tr>
<?php
  reset($itemedit_fields);
  $i=0;
  while( list(, $val) = each($itemedit_fields)) {
    FieldInput("f$i", "n$i", $val);
    $i++;
  }  
?>  
</table>
<tr><td align="center">
<?php 
  echo "<input type=hidden name=\"update\" value=1>";
  echo "<input type=hidden name=\"slice_id\" value=$slice_id>";
  echo '<input type=submit name=update value="'. L_UPDATE .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. L_CANCEL .'">&nbsp;&nbsp;';
/*
$Log$
Revision 1.2  2000/10/10 10:06:54  honzam
Database operations result checking. Messages abstraction via MsgOK(), MsgErr()

Revision 1.1.1.1  2000/06/21 18:39:59  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:49  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.12  2000/06/12 19:58:24  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.11  2000/06/09 15:14:10  honzama
New configurable admin interface

Revision 1.10  2000/04/24 16:45:02  honzama
New usermanagement interface.

Revision 1.9  2000/03/22 09:36:43  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/
?>   
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()?>
