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

# Parameters: group_id - identifier of constant group
#             categ - if true, constants are taken as category, so 
#                     APC parent categories are displayed for selecting parent

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_FIELDS);
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

function ShowConstant($id, $name, $value, $pri, $class, $categ, $classes) {
  global $sess;
  echo "
  <tr>
    <td><input type=\"Text\" name=\"name[$id]\" size=25 maxlength=49 value=\"$name\"></td>
    <td><input type=\"Text\" name=\"value[$id]\" size=25 maxlength=49 value=\"$value\"></td>
    <td class=tabtxt><input type=\"Text\" name=\"pri[$id]\" size=4 maxlength=4 value=\"$pri\"></td>";
  if( $categ ){   # it is categories - show APC wide categories for parent category select
    echo "<td class=tabtxt>";
    FrmSelectEasy("class[$id]", $classes, $class);
    echo"</td>";
  } else {
    echo "<td class=tabtxt>&nbsp;</td>";
  }  
  echo "</tr>\n";
}

if( $update )
{
  do {
    if( !(isset($name) AND is_array($name) ))
      break;
    reset($name);
    while( list($key,$nam) = each($name) ) {
      $prior = $pri[$key];
      $val =   $value[$key];
      ValidateInput("nam", L_CONSTANT_NAME, $nam, &$err, false, "text");   // if not filled it will be deleted
      ValidateInput("val", L_CONSTANT_VALUE, $val, &$err, false, "text");
      ValidateInput("prior", L_CONSTANT_PRIORITY, $prior, &$err, false, "number");
    }
      
    if( !$group_id ) {  # new constant grop  
      ValidateInput("new_group_id", L_CONSTANT_GROUP, $new_group_id, &$err, true, "text");
      if( count($err) > 1)
        break;
      $SQL = "SELECT * FROM constant WHERE group_id = '$new_group_id'";
      $db->query($SQL);
      if( $db->next_record() )
        $err["DB"] = L_CONSTANT_GROUP_EXIST;
       else
        $group_id = $new_group_id
    }    

    if( count($err) > 1)
      break;
      
    # first delete old values
    $SQL = "DELETE * FROM constant WHERE group_id = '$group_id'";
    $db->query($SQL);
      
    reset($name);
    while( list($key,$nam) = each($name) ) {
      if( $nam == "" )   # remove this constant
        continue;

      $varset->clear();
      $varset->set("group_id", $group_id, "quoted" );
      $varset->set("name",  $name[$key], "quoted");
      $varset->set("value", $value[$key], "number");
      $varset->set("pri", ( $pri[$key] ? $pri[$key] : 1000, "number");
      $varset->set("class", $class[$key], "quoted");
      if( !$db->query("INSERT INTO field " . $varset->makeINSERT() )) {
        $err["DB"] .= MsgErr("Can't copy field");
        break;
      }
    }
    if( count($err) <= 1 )
      $Msg = MsgOK(L_CONSTANTS_OK);
  } while( 0 );           #in order we can use "break;" statement
}    

  # lookup constants
if( $group_id )
  $SQL = "SELECT name, value, class, pri FROM constant
           WHERE group_id='$group_id' ORDER BY pri";
  $s_constants = GetTable2Array($SQL, $db, "NoCoLuMn");
}  

  # lookup apc categories classes
$SQL = "SELECT name, value pri FROM constant
         WHERE group_id='lt_apcCategories' ORDER BY pri";
$classes = GetTable2Array($SQL, $db, "value");
         
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_CONSTANTS_TIT;?></TITLE>
</HEAD>
<?php 
  $xx = ($slice_id!="");
  $show = Array("main"=>true, "config"=>$xx, "category"=>$xx, "fields"=>false, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx, 
                "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable
  
  echo "<H1><B>" . L_A_CONSTANTS_EDT . "</B></H1>";
  PrintArray($err);
  echo $Msg;  
?>
<form enctype="multipart/form-data" method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="#584011" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_CONSTANTS_HDR?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<tr>
 <td class=tabtxt><b><?php echo L_CONSTANT_GROUP ?></b></td>
 <td class=tabtxt colspan=3><?php
   echo ( $group_id ? safe($group_id) :
         "<input type=\"Text\" name=\"new_group_id\" size=16 maxlength=16 value=\"$new_group_id\">");?>
 </td>
</tr>
<tr><?php
echo "
 <td class=tabtxt align=center><b>". L_CONSTANT_NAME ."</b><br>". L_CONSTANT_NAME_HLP ."</td>
 <td class=tabtxt align=center><b>". L_CONSTANT_VALUE ."</b><br>". L_CONSTANT_VALUE_HLP ."</td>
 <td class=tabtxt align=center><b>". L_CONSTANT_PRI ."</b><br>". L_CONSTANT_PRI_HLP ."</td>
 <td class=tabtxt align=center><b>". L_CONSTANT_CLASS . "</b><br>". L_CONSTANT_CLASS_HLP ."</td>
</tr>
<tr><td colspan=4><hr></td></tr>";

if( $s_constants ) {
  reset($s_constants);
  $i=0;
  while( list(, $v) = each($s_constants)) {
    if( $update ) # get values from form
      ShowConstant($i, $name[$i], $value[$i], $pri[$i], $class[$i], $categ, $classes);
    else  
      ShowConstant($i, $v[name], $v[value], $v[pri], $v[class], $categ, $classes);
    $i++;  
  }
}  

  # ten rows for possible new constants
for( $j=0; $j<10; $j++) {
  if( $update ) # get values from form
    ShowConstant($i, $name[$i], $value[$i], $pri[$i], $class[$i], $categ, $classes);
  else  
    ShowConstant($i, "", "", 1000, "", $categ, $classes);
  $i++;
}  

echo '</table>
<tr><td align="center">
  <input type=hidden name="update" value=1>
  <input type=hidden name="group_id" value="'. $group_id .'">
  <input type=submit name=update value="'. L_UPDATE .'">&nbsp;&nbsp;
  <input type=submit name=cancel value="'. L_CANCEL .'">&nbsp;&nbsp;
</td></tr></table>
</FORM>
</BODY>
</HTML>';

/*
$Log$
Revision 1.1  2000/12/23 19:56:50  honzam
Multiple fulltext item view on one page, bugfixes from merge v1.2.3 to v1.5.2

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
page_close()?>
