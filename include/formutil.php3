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

#
# Form utility functions
#

# if $condition, shows exclamatiom point
function Needed( $condition=true ) {
  if( $condition )
    echo "&nbsp;*";
}    

# Prints html tag <input type=text .. to 2-column table
# for use within <form> and <table> tag
function FrmInputText($name, $txt, $val, $maxsize=254, $size=25, $needed=false) {
  echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed); 
  echo "</td>\n  <td><input type=\"Text\" name=\"$name\" size=$size maxlength=$maxsize value=\"$val\"></td></tr>\n";
}

# Prints two static text to 2-column table
# for use within <table> tag
function FrmStaticText($txt, $val){
  echo "<tr><td class=tabtxt><b>$txt</b></td><td>$val</td></tr>\n";
}

# Prints html tag <input type=password .. to 2-column table
# for use within <form> and <table> tag
function FrmInputPwd($name, $txt, $val, $maxsize=254, $size=25, $needed=false)
{ echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed); 
  echo "</td>\n  <td><input type=\"Password\" name=\"$name\" size=$size maxlength=$maxsize value=\"$val\"></td></tr>\n";
}

# Prints html tag <input type=file .. to 2-column table
# for use within <form> and <table> tag
function FrmInputFile($name, $txt, $size=25, $needed=false)
{ echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed); 
  echo "</td>\n  <td><input type=\"file\" name=\"$name\" size=$size accept=\"image/*\"></td></tr>\n";  // /**/
}

# Prints html tag <textarea .. to 2-column table
# for use within <form> and <table> tag
function FrmTextarea($name, $txt, $val, $rows=4, $cols=60, $needed=false)
{
 echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n  <td><textarea name=\"$name\" rows=$rows cols=$cols wrap=virtual>$val</textarea></td></tr>\n";
}

# Prints html tag <input type=checkbox .. to 2-column table
# for use within <form> and <table> tag
function FrmInputChBox($name, $txt, $checked=true, $changeorder=false, $add="", $colspan=1, $needed=false)
{ echo "<tr>";
  if( !$changeorder ) {
    echo "<td class=tabtxt colspan=$colspan><b>$txt</b>";
    Needed($needed);
    echo "</td>\n  ";
  }  
  echo "<td><input type=\"checkbox\" name=\"$name\" $add ";
  if($checked)
    echo " checked";
  echo "></td>";
  if( $changeorder ) {
    echo "<td class=tabtxt colspan=$colspan><b>$txt</b>";
    Needed($needed);
    echo "</td>\n  ";
  }  
  echo "</tr>\n";
}

# Prints html tag <input type=checkbox 
function FrmChBoxEasy($name, $checked=true, $add="")
{ echo "<input type=\"checkbox\" name=\"$name\" $add ";
  if($checked)
    echo " checked";
  echo ">";
}

# Prints html tag <select .. to 2-column table
# for use within <form> and <table> tag
function FrmInputSelect($name, $txt, $arr, $selected="", $needed=false) {
  echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n <td><select name=\"$name\">";	
  reset($arr);
  while(list($k, $v) = each($arr)) { 
    echo "<option value=\"". htmlspecialchars($k)."\"";
    if ((string)$selected == (string)$k) 
      echo " selected";
    echo "> ". htmlspecialchars($v) ." </option>";
  }
  reset($arr);
  echo "</select></td></tr>\n";
}  

# Prints html tag <select .. 
function FrmSelectEasy($name, $arr, $selected="", $add="") { 
  echo "<select name=\"$name\" $add>";	
  reset($arr);
  while(list($k, $v) = each($arr)) { 
    echo "<option value=\"". htmlspecialchars($k)."\"";
    if ((string)$selected == (string)$k) 
      echo " selected";
    echo "> ". htmlspecialchars($v) ." </option>";
  }
  echo "</select>\n";
}  

# Validate users input. Error is reported in $err array
function ValidateInput($variableName, $inputName, $variable, $err, $needed=false, $type="all") 
{
  if($variable=="" OR Chop($variable)=="")
    if( $needed ) {                     // NOT NULL
      $err["$variableName"] = "<div class=err>".L_ERR_IN." $inputName (".L_ERR_NEED.")</div>";
      return false;
    }
    else  return true;     
  switch($type)
  {
    case "id":     if((string)$variable=="0" AND !$needed)
                     return true;     
                   if( !EReg("^[0-9a-f]{1,32}$",Chop($variable)))
                   { $err["$variableName"] = "<div class=err>".L_ERR_IN." $inputName</div>";
                     return false;
                   }
                   return true;
    case "number": if( !EReg("^[0-9]+$",Chop($variable)) || ($variable > 32767))
                   { $err["$variableName"] = "<div class=err>".L_ERR_IN." $inputName</div>";
                     return false;
                   }
                   return true;
    case "perms":  if( !(($Promenna=="editor") OR ($Promenna=="admin")))
                   { $err["$variableName"] = "<div class=err>".L_ERR_IN." $inputName</div>";
                     return false;
                   }
                   return true;
    case "email":  if( !EReg("^.+@.+\..+",Chop($variable)))
                   { $err["$variableName"] = "<div class=err>".L_ERR_IN." $inputName</div>";
                     return false;
                   }
                   return true;
    case "login":
      $len = strlen($variable);
      if( ($len>=3) AND ($len<=32) )
      { if( !EReg("^[a-zA-Z0-9]*$",Chop($variable)))
        { $err["$variableName"] = "<div class=err>".L_ERR_IN." $inputName (".L_ERR_LOG.")</div>";
          return false;
        }
        return true;
      }  
      $err["$variableName"] = "<div class=err>".L_ERR_IN." $inputName (".L_ERR_LOGLEN.")</div>";                   
      return false; 
    case "password":
      $len = strlen($variable);
      if( ($len>=5) AND ($len<=32) )
        return true;
      $err["$variableName"] = "<div class=err>".L_ERR_IN." $inputName (".L_ERR_LOGLEN.")</div>";                   
      return false; 
    case "url":
    case "all":    
    default:       return true;
  }  
}    

# returns html safe code
# use for preparing variable to print in form
function safe( $var ) {
  return htmlspecialchars( stripslashes($var) );  // stripslashes function added because of quote varibles sended to form before
}  

/*
$Log$
Revision 1.3  2000/08/29 11:29:58  honzam
Better validation of id (1-32 chars) and password (any character).

Revision 1.2  2000/08/03 12:31:19  honzam
Session variable r_hidden used instead of HIDDEN html tag. Magic quoting of posted variables if magic_quotes_gpc is off.

Revision 1.1.1.1  2000/06/21 18:40:38  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:23  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.8  2000/06/12 19:58:36  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.7  2000/05/30 09:11:39  honzama
MySQL permissions upadted and completed.

Revision 1.6  2000/04/24 16:50:34  honzama
New usermanagement interface.

Revision 1.5  2000/03/29 15:54:47  honzama
Better Netscape Navigator javascript support, new direct feeding support, minor changes in texts and look.

Revision 1.4  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>
