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

# if $condition, shows star
function Needed( $condition=true ) {
  if( $condition )
    echo "&nbsp;*";
}    

# if $txt, shows help message
function PrintHelp( $txt ) {
  if( $txt )
    echo "<div class=tabhlp>$txt</div>";
}    

# if $txt, shows link to more help
function PrintMoreHelp( $txt ) {
  if( $txt )
    echo "&nbsp;<a href='$txt' target='_blank'>?</a>";
}    

# Prints html tag <input type=text .. to 2-column table
# for use within <form> and <table> tag
function FrmInputText($name, $txt, $val, $maxsize=254, $size=25, $needed=false,
                      $hlp="", $morehlp="", $html=false) {
  $name=safe($name); $txt=safe($txt); $val=safe($val); $hlp=safe($hlp); 
  $morehlp=safe($morehlp);
  if( !$maxsize )
    $maxsize = 254;
  if( !$size )
    $size = 25;
  
  if( $html ){
    $htmlvar = $name."html";
    $htmlrow = "<input type='radio' name='$htmlvar' value='h'".
              (( $html==1 ) ? " checked>" : ">" ). L_HTML."</input>
              <input type='radio' name='$htmlvar' value='t'".
              (( $html==2 ) ? " checked>" : ">" ). L_PLAIN_TEXT."</input><br>";
  }    
  echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed); 
  echo "</td>\n";
  if ( SINGLE_COLUMN_FORM )
    echo "</tr><tr>";
  echo "<td>$htmlrow<input type=\"Text\" name=\"$name\" size=$size
          maxlength=$maxsize value=\"$val\">";
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}

# Prints two static text to 2-column table
# for use within <table> tag
function FrmStaticText($txt, $val, $needed=false, $hlp="", $morehlp="", $safing=1 ){
  if( $safing ) {
    $txt=safe($txt); $val=safe($val); $hlp=safe($hlp); $morehlp=safe($morehlp);
  }
  
  echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed); 
  echo "</td>";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr>";
  echo "<td>$val";
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}

# Prints html tag <input type=password .. to 2-column table
# for use within <form> and <table> tag
function FrmInputPwd($name, $txt, $val, $maxsize=254, $size=25, $needed=false) {
  $name=safe($name); $txt=safe($txt); $val=safe($val);

  echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed); 
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr>";
  echo "<td><input type=\"Password\" name=\"$name\" size=$size maxlength=$maxsize value=\"$val\"></td></tr>\n";
}

# Prints html tag <input type=file .. to 2-column table
# for use within <form> and <table> tag
function FrmInputFile($name, $txt, $size=25, $needed=false, $accepts="image/*",
                      $hlp="", $morehlp="" ){
  $name=safe($name); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed); 
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr>";
  echo "<td><input type=\"file\" name=\"$name\" size=$size accept=\"$accepts\">";  // /**/
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}

# Prints html tag <textarea .. to 2-column table
# for use within <form> and <table> tag
function FrmTextarea($name, $txt, $val, $rows=4, $cols=60, $needed=false, 
                     $hlp="", $morehlp="", $single="", $html=false) {
  $name=safe($name); $txt=safe($txt); $val=safe($val); $hlp=safe($hlp); 
  $morehlp=safe($morehlp);

  if( $html ){
    $htmlvar = $name."html";
    $htmlrow = "<input type='radio' name='$htmlvar' value='h'".
              (( $html==1 ) ? " checked>" : ">" ). L_HTML."</input>
              <input type='radio' name='$htmlvar' value='t'".
              (( $html==2 ) ? " checked>" : ">" ). L_PLAIN_TEXT."</input><br>";
  }    
  if( $single )
    $colspan = "colspan=2";

  echo "<tr><td class=tabtxt $colspan><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM OR $single)
    echo "</tr><tr>";
  echo "<td $colspan>$htmlrow<textarea name=\"$name\" rows=$rows cols=$cols wrap=virtual>$val</textarea>";
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}


# Prints html tag <input type=checkbox .. to 2-column table
# for use within <form> and <table> tag
function FrmInputChBox($name, $txt, $checked=true, $changeorder=false, 
                    $add="", $colspan=1, $needed=false, $hlp="", $morehlp=""){
  $name=safe($name); $txt=safe($txt); $val=safe($val); $hlp=safe($hlp); 
  $morehlp=safe($morehlp);

  echo "<tr>";
  if( !$changeorder ) {
    echo "<td class=tabtxt colspan=$colspan><b>$txt</b>";
    Needed($needed);
    echo "</td>\n  ";
    if (SINGLE_COLUMN_FORM)
      echo "</tr><tr>";
  }  
  echo "<td><input type=\"checkbox\" name=\"$name\" $add ";
  if($checked)
    echo " checked";
  echo ">";
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo"</td>";
  if( $changeorder ) {
    if (SINGLE_COLUMN_FORM)
      echo "</tr><tr>";
    echo "<td class=tabtxt colspan=$colspan><b>$txt</b>";
    Needed($needed);
    echo "</td>\n  ";
  }  
  echo "</tr>\n";
}

# Prints html tag <input type=checkbox 
function FrmChBoxEasy($name, $checked=true, $add="") {
  $name=safe($name); # $add=safe($add); NO!!

  echo "<input type=\"checkbox\" name=\"$name\" $add ";
  if($checked)
    echo " checked";
  echo ">";
}

# Prints html tag <select .. to 2-column table
# for use within <form> and <table> tag
function FrmInputSelect($name, $txt, $arr, $selected="", $needed=false,
                        $hlp="", $morehlp="") {
  $name=safe($name); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr>";
  echo "<td><select name=\"$name\">";	
  reset($arr);
  while(list($k, $v) = each($arr)) { 
    echo "<option value=\"". htmlspecialchars($k)."\"";
    if ((string)$selected == (string)$k) 
      echo " selected";
    echo "> ". htmlspecialchars($v) ." </option>";
  }
  reset($arr);
  echo "</select>";
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}  

# Prints html tag <intup type=text ...> with <select ...> as presets to 2-column 
# table for use within <form> and <table> tag
function FrmInputPreSelect($name, $txt, $arr, $val, $maxsize=254, $size=25, 
                           $needed=false, $hlp="", $morehlp="") {
  $name=safe($name); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  if( !$maxsize )
    $maxsize = 254;
  if( !$size )
    $size = 25;

  echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr>";
  echo "<td><input type=\"Text\" name=\"$name\" size=$size maxlength=$maxsize value=\"$val\">
          <select name=\"foo_$name\" onchange=\"$name.value=this.options[this.selectedIndex].value\">";	
  reset($arr);
  while(list($k, $v) = each($arr)) { 
    echo "<option value=\"". htmlspecialchars($k)."\"";
    if ((string)$val == (string)$k) 
      echo " selected";
    echo "> ". htmlspecialchars($v) ." </option>";
  }
  reset($arr);
  echo "</select>";
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}  

# Prints two boxes for multiple selection for use within <form> and <table> tag
function FrmTwoBox($name, $txt, $arr, $val, $maxsize=254, $size=25, 
                           $needed=false, $hlp="", $morehlp="") {
  $name=safe($name); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

/*  TODO 

  echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr>";
  echo "<table border="0" cellspacing="0" cellpadding="0"><tr>
      <td align='CENTER' valign='TOP'>
      <SELECT name="export_n" size=8 class=tabtxt>
        <?php
        reset($all_slices);
        if( isset($export_to) AND is_array($export_to)) {
          while(list($s_id,$name) = each($all_slices))
            if( $export_to[$s_id] == "" )
              echo "<option value=\"$s_id\"> $name </option>"; 
        }else 
          while(list($s_id,$name) = each($all_slices))
            echo "<option value=\"$s_id\"> $name </option>"; 

      </SELECT></td>
      <td><input type="button" VALUE="  >>  " onClick = "MoveSelected('document.f.export_n','document.f.export_y')" align=center>
          <br><br><input type="button" VALUE="  <<  " onClick = "MoveSelected('document.f.export_y','document.f.export_n')" align=center></td>
      <td align="CENTER" valign="TOP">
      <SELECT name="export_y" size=8 class=tabtxt>
        <?php
        if( isset($export_to) AND is_array($export_to)) {
          reset($export_to);
          while(list($s_id,$name) = each($export_to))
            echo "<option value=\"$s_id\"> $name </option>"; 
        }    
      </SELECT>
      </td>
      </tr>

*/
}

# Prints html tag <input type="radio" .. to 2-column table
# for use within <form> and <table> tag
function FrmInputRadio($name, $txt, $arr, $selected="", $needed=false,
                       $hlp="", $morehlp="") {
  $name=safe($name); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n <td>";	
  reset($arr);
  while(list($k, $v) = each($arr)) { 
    echo "<input type='radio' name='$name'
                 value='". htmlspecialchars($k) ."'";
    if ((string)$selected == (string)$k) 
      echo " checked";
    echo ">".htmlspecialchars($v);
  }
  reset($arr);
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}  

# Prints html tag <input type="radio" .. to 2-column table
# for use within <form> and <table> tag
function FrmInputMultiChBox($name, $txt, $arr, $selected="", $needed=false,
                       $hlp="", $morehlp="") {
  $name=safe($name); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n <td>";	
  reset($arr);
  while(list($k, $v) = each($arr)) { 
    echo "<input type='checkbox' name='$name'
                 value='". htmlspecialchars($k) ."'";
    if ($selected[$k]) 
      echo " checked";
    echo ">".htmlspecialchars($v);
  }
  reset($arr);
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}  


# Prints html tag <select multiple .. to 2-column table
# for use within <form> and <table> tag
function FrmInputMultiSelect($name, $txt, $arr, $selected="", $size=5, 
          $relation=false, $needed=false, $hlp="", $morehlp="", $minrows=0) {
  $name=safe($name); $size=safe($size); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  echo "<tr><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n <td><select name=\"$name\" size=\"$size\" multiple>";	
  $option_no = 0;
  if( isset($arr) && is_array($arr) ) {
    reset($arr);
    while(list($k, $v) = each($arr)) { 
      echo "<option value='". htmlspecialchars($k) ."'";
      if ($selected[$k]) 
        echo " selected";
      echo ">".htmlspecialchars($v)."</option>";
      $option_no++;
    }
  }  
  # add blank rows if asked for
  while( $option_no++ < $minrows )  // if no options, we must set width of <select> box
    echo '<option value="wIdThTor"> &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; </option>';
  
  echo "</select>";
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  if( $relation )       # all selection in this box should be selected on submit
    echo "<br><center>
          <input type='button' value='". L_ADD ."' onclick='OpenRelated(\"$name\", \"$relation\")'> 
          <input type='button' value='". L_DELETE ."' 
            onclick='document.inputform.elements[\"$name\"].options[document.inputform.elements[\"$name\"].selectedIndex].value=\"wIdThTor\";
                     document.inputform.elements[\"$name\"].options[document.inputform.elements[\"$name\"].selectedIndex].text=\"\";'> 
          <SCRIPT Language='JavaScript'><!--
             listboxes[box_index++] = 'document.inputform.elements[\"$name\"]'
            // -->
           </SCRIPT>          
          </center>";
  echo "</td></tr>\n";
}  

function FrmRelated($name, $txt, $arr, $size, $sid, $needed=false, $hlp="", $morehlp="") {
  FrmInputMultiSelect($name, $txt, $arr, "", $size=5, $sid, $needed, $hlp, $morehlp, MAX_RELATED_COUNT);
}  

# Prints html tag <select .. 
function FrmSelectEasy($name, $arr, $selected="", $add="") { 
  $name=safe($name); # safe($add) - NO! - do not safe it

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
      $err["$variableName"] = MsgErr(L_ERR_IN." $inputName (".L_ERR_NEED.")");
      return false;
    }
    else  return true;     
  switch($type)
  {
    case "id":     if((string)$variable=="0" AND !$needed)
                     return true;     
                   if( !EReg("^[0-9a-f]{1,32}$",Chop($variable)))
                   { $err["$variableName"] = MsgErr(L_ERR_IN." $inputName");
                     return false;
                   }
                   return true;
    case "alias":  if((string)$variable=="0" AND !$needed)
                     return true;     
                   if( !EReg("^_#[0-9_#a-zA-Z]{8}$",Chop($variable)))
                   { $err["$variableName"] = MsgErr(L_ERR_IN." $inputName");
                     return false;
                   }
                   return true;
    case "number": if( !EReg("^[0-9]+$",Chop($variable)) )
                   { $err["$variableName"] = MsgErr(L_ERR_IN." $inputName");
                     return false;
                   }
                   return true;
    case "perms":  if( !(($Promenna=="editor") OR ($Promenna=="admin")))
                   { $err["$variableName"] = MsgErr(L_ERR_IN." $inputName");
                     return false;
                   }
                   return true;
    case "email":  if( !EReg("^.+@.+\..+",Chop($variable)))
                   { $err["$variableName"] = MsgErr(L_ERR_IN." $inputName");
                     return false;
                   }
                   return true;
    case "login":
      $len = strlen($variable);
      if( ($len>=3) AND ($len<=32) )
      { if( !EReg("^[a-zA-Z0-9]*$",Chop($variable)))
        { $err["$variableName"] = MsgErr(L_ERR_IN." $inputName (".L_ERR_LOG.")");
          return false;
        }
        return true;
      }  
      $err["$variableName"] = MsgErr(L_ERR_IN." $inputName (".L_ERR_LOGLEN.")");                   
      return false; 
    case "password":
      $len = strlen($variable);
      if( ($len>=5) AND ($len<=32) )
        return true;
      $err["$variableName"] = MsgErr(L_ERR_IN." $inputName (".L_ERR_LOGLEN.")");                   
      return false; 
    case "url":
    case "all":    
    default:       return true;
  }  
}    

/*
$Log$
Revision 1.17  2001/10/24 16:48:10  honzam
fixed bug with unspecified maxlength parameter

Revision 1.16  2001/09/27 15:53:39  honzam
New related stories support, New "Preselect" input option

Revision 1.15  2001/06/12 16:00:55  honzam
date inputs support time, now
new multivalue input possibility - <select multiple>

Revision 1.14  2001/05/10 10:01:43  honzam
New spanish language files, removed <form enctype parameter where not needed, better number validation

Revision 1.13  2001/04/04 18:27:44  honzam
Morehelp question mart in itemedit opens new window.

Revision 1.12  2001/03/20 16:10:37  honzam
Standardized content management for items - filler, itemedit, offline, feeding
Better feeding support

Revision 1.11  2001/03/07 14:34:01  honzam
fixed bug with radiobuttons dispaly

Revision 1.10  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...

Revision 1.9  2001/01/23 23:58:03  honzam
Aliases setings support, bug in permissions fixed (can't login not super user), help texts for aliases page

Revision 1.8  2001/01/22 17:32:48  honzam
pagecache, logs, bugfixes (see CHANGES from v1.5.2 to v1.5.3)

Revision 1.7  2000/12/23 19:56:50  honzam
Multiple fulltext item view on one page, bugfixes from merge v1.2.3 to v1.5.2

Revision 1.6  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.5  2000/11/17 19:05:20  madebeer
added SINGLE_COLUMN_FORM

Revision 1.4  2000/10/10 10:06:54  honzam
Database operations result checking. Messages abstraction via MsgOK(), MsgErr()

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
