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

require $GLOBALS[AA_INC_PATH]."constedit_util.php3";
require $GLOBALS[AA_INC_PATH]."javascript.php3";

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

# shows boxes allowing to choose constant in a hiearchical way
function FrmHierarchicalConstant ($name, $txt, $value, $group_id, $levelCount, $boxWidth, 
	$size, $horizontal=0, $firstSelect=0, $needed=false, $hlp="", $morehlp="") 
{
	if (!$levelCount) $levelCount = 3;
	if (!$size) $size = 5;
	$name = safe($name);

	echo "<tr align=left><td class=tabtxt $colspan><b>$txt</b>";
	Needed($needed);
	echo "</td>\n";
	if (SINGLE_COLUMN_FORM)
	  echo "</tr><tr align=left>";
	echo "<td>";
	showHierConstInitJavaScript ($group_id, $levelCount, "inputform", false);
	showHierConstBoxes ($levelCount, $horizontal, $name, false, $firstSelect, $boxWidth);
	for ($i=0; $i<$boxWidth; ++$i) $widthTxt .= "m";
	echo "
	<TABLE border=1 cellpadding=2 width='100%'><TR>
	<TD align=center><B>Selected:</B><BR><BR><INPUT TYPE=BUTTON VALUE='Delete' onclick='hcDelete(\"$name\")'></TD>
	<TD><SELECT name='$name' MULTIPLE size=$size".getTriggers("select",$name).">";
//    debuglog (serialize ($value));
    if (is_array ($value))
	for ($i=0; $i < count($value); ++$i)
		if ($value[$i]['value'])
		    echo "<option>".htmlspecialchars($value[$i]['value'])."\n";
	echo "<OPTION value='wIdThTor'>$widthTxt";
	echo "</SELECT></TD></TR></TABLE>";
	echo "<script language=javascript><!--\n
		hcInit();
		hcDeleteLast ('$name');
        listboxes[listboxes.length] = 'document.inputform[\"$name\"]';
		// -->\n
		</script>\n";
	PrintMoreHelp($morehlp);
	PrintHelp($hlp);
	echo "</td></tr>\n";
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
  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed); 
  echo "</td>\n";
  if ( SINGLE_COLUMN_FORM )
    echo "</tr><tr align=left>";
  echo "<td>$htmlrow<input type=\"Text\" name=\"$name\" size=$size
          maxlength=$maxsize value=\"$val\"".getTriggers("input",$name).">";
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
  
  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed); 
  echo "</td>";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr align=left>";
  echo "<td>$val";
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}

# Prints html tag <input type=password .. to 2-column table
# for use within <form> and <table> tag
function FrmInputPwd($name, $txt, $val, $maxsize=254, $size=25, $needed=false) {
  $name=safe($name); $txt=safe($txt); $val=safe($val);

  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed); 
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr align=left>";
  echo "<td><input type=\"Password\" name=\"$name\" size=$size maxlength=$maxsize value=\"$val\"></td></tr>\n";
}

# Prints html tag <input type=file .. to 2-column table
# for use within <form> and <table> tag
function FrmInputFile($name, $txt, $size=25, $needed=false, $accepts="image/*",
                      $hlp="", $morehlp="" ){
  $name=safe($name); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed); 
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr align=left>";
  echo "<td><input type=\"file\" name=\"$name\" size=$size accept=\"$accepts\"".getTriggers("input",$name).">";  // /**/
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}

# Prints html tag <textarea .. to 2-column table
# for use within <form> and <table> tag
function FrmTextarea($name, $txt, $val, $rows=4, $cols=60, $needed=false, 
                     $hlp="", $morehlp="", $single="", $html=false, $showrich_href=false) {
  $name=safe($name); $txt=safe($txt); $val=safe($val); $hlp=safe($hlp); 
  $morehlp=safe($morehlp);

	if ( $showrich_href ) {
			$htmlrow .= '
			<script language=javascript>
			function load_rich_edit ()
			{ window.location.href = window.location.href+"&showrich=1"; }
			</script>
			<a href="javascript:load_rich_edit();">'.L_SHOW_RICH.'</a><br>';
	}

  if( $html ){
    $htmlvar = $name."html";
    $htmlrow .= "<input type='radio' name='$htmlvar' value='h'".
              (( $html==1 ) ? " checked>" : ">" ). L_HTML."</input>
              <input type='radio' name='$htmlvar' value='t'".
              (( $html==2 ) ? " checked>" : ">" ). L_PLAIN_TEXT."</input><br>";
  }    

  if( $single )
    $colspan = "colspan=2";

  echo "<tr align=left><td class=tabtxt $colspan><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM OR $single)
    echo "</tr><tr align=left>";
  echo "<td $colspan>$htmlrow<textarea name=\"$name\" rows=$rows cols=$cols wrap=virtual".getTriggers("textarea",$name).">$val</textarea>";
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}

# On browsers which do support it, loads a special rich text editor with many
# advanced features based on triedit.dll 
# On the other browsers, loads a normal text area
function FrmRichEditTextarea($name, $txt, $val, $rows=10, $cols=80, $type="class", $needed=false, 
                     $hlp="", $morehlp="", $single="", $html=false) {
  global $BName; 
  if (! richEditShowable()) {
	 FrmTextarea($name, $txt, $val, $rows, $cols, $needed, $hlp, $morehlp, $single, $html, $BName != "MSIE ");
     return;
  }

  $name=safe($name); $txt=safe($txt); $hlp=safe($hlp); 
  $morehlp=safe($morehlp);

  if( $single )
    $colspan = "colspan=2";

  echo "<tr><td class=tabtxt $colspan><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM OR $single)
    echo "</tr><tr>";

  if( $html==2 ) // text only
  	$val = str_replace ("\r","",str_replace ("\n","",
		nl2br (htmlspecialchars ($val,ENT_QUOTES))));
  else {
    $repl = array ("'"=>'"',"\n"=>" ","\r"=>"");
    reset ($repl);
    while (list ($find,$rep) = each ($repl))
        $val = str_replace ($find, $rep, $val);
  }
  echo "<td $colspan>";

    echo "<!-- UUUUU $BName -->";
    if ($type == "iframe") 
        $richedit = "richedit_iframe";
    else if ($BName == "MSIE ") 
        $richedit = "richedt_ie";
    else $richedit = "richedit_ns";
        
	if ($GLOBALS[debug]) echo $richedit;
    echo
        "<script language=javascript> 
        <!--
		var edt$name"."_doc_complet = 0; 
        var edt = \"edt$name\"; 
 		var edtdoc = \"edt$name.document\"; 
        var richHeight = ".($rows * 22)."; 
        var richWidth = ".($cols * 8)."; 
        var imgpath = '../misc/wysiwyg/images/'; 
        // -->
	</script>
    <script language=javascript src=\"../misc/wysiwyg/".$richedit.".js\">
	</script>
    <script language=javascript src=\"../misc/wysiwyg/".$richedit.".html\">
	</script>";

    echo "
    <script language =javascript > 
        <!-- 
        edt$name"."_timerID=setInterval(\"edt$name"."_inicial()\",100); 
        function edt$name"."_inicial() { 
            posa_contingut_html('edt$name','$val');
     	    clearInterval(edt$name"."_timerID); 
            return true; 
        } 
        // --> 
    </script> 
	<input type=hidden name='$name' value='$val'> 
 	<input type=hidden name='".$name."html' value='h'>";	
    
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

  echo "<tr align=left>";
  if( !$changeorder ) {
    echo "<td class=tabtxt colspan=$colspan><b>$txt</b>";
    Needed($needed);
    echo "</td>\n  ";
    if (SINGLE_COLUMN_FORM)
      echo "</tr><tr align=left>";
  }  
  echo "<td><input type=\"checkbox\" name=\"$name\" $add ";
  if($checked)
    echo " checked";
  echo getTriggers("input",$name).">";
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo"</td>";
  if( $changeorder ) {
    if (SINGLE_COLUMN_FORM)
      echo "</tr><tr align=left>";
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
                        $hlp="", $morehlp="", $usevalue=false) {
  $name=safe($name); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr align=left>";
  echo "<td><select name=\"$name\"".getTriggers("select",$name).">";	
  reset($arr);
  while(list($k, $v) = each($arr)) { 
    if( $usevalue )                    // special parameter to use values instead of keys
      $k = $v;
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
                           $needed=false, $hlp="", $morehlp="", $adding=0,
						   $secondfield="", $usevalue=false) {
  $name=safe($name); $val=safe($val); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  if( !$maxsize )
    $maxsize = 254;
  if( !$size )
    $size = 25;
  if ($secondfield) {
    $varsecfield = 'v'. unpack_id($secondfield);
  }
  if ($adding) {
    echo "\n<script language=\"JavaScript\">
	<!--
		function add_to_line(inputbox, value) {
		  if (inputbox.value.length != 0) {
		    inputbox.value=inputbox.value+\",\"+value;
		  } else {
		    inputbox.value=value;
		  }	
		}
	//-->
	</script>\n";
  }
  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr align=left>";
  echo "<td><input type=\"Text\" name=\"$name\" size=$size maxlength=$maxsize value=\"$val\"".getTriggers("input",$name).">
          <select name=\"foo_$name\"";
  if ($secondfield) { 	  
    echo "onchange=\"$name.value=this.options[this.selectedIndex].text;";
    echo "$varsecfield.value=this.options[this.selectedIndex].value\">";	
  } else {
    if ($adding) {
	  echo "onchange=\"add_to_line($name, this.options[this.selectedIndex].value)\">";
	} else {
	  echo "onchange=\"$name.value=this.options[this.selectedIndex].value\">";
	}  
  }
  reset($arr);
  while(list($k, $v) = each($arr)) { 
    echo "<option value=\"". htmlspecialchars($usevalue ? $v : $k)."\"";
    if ((string)$val == (string)(($usevalue OR $secondfield) ? $v : $k)) 
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

  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr align=left>";
  echo "<table border="0" cellspacing="0" cellpadding="0"><tr align=left>
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

  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n <td>";	
  reset($arr);
  while(list($k, $v) = each($arr)) { 
    echo "<input type='radio' name='$name'
                 value='". htmlspecialchars($k) ."'".getTriggers("input",$name);
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

  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n <td>";	
  if (is_array ($arr)) {
      reset($arr);
      while(list($k, $v) = each($arr)) { 
        echo "<input type='checkbox' name='$name'
                     value='". htmlspecialchars($k) ."'".getTriggers("input",$name);
        if ($selected[$k]) 
          echo " checked";
        echo ">".htmlspecialchars($v);
      }
  }
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}  


# Prints html tag <select multiple .. to 2-column table
# for use within <form> and <table> tag
function FrmInputMultiSelect($name, $txt, $arr, $selected="", $size=5, 
          $relation=false, $needed=false, $hlp="", $morehlp="", $minrows=0) {
  $name=safe($name); $size=safe($size); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n <td><select name=\"$name\" size=\"$size\" multiple".getTriggers("select",$name).">";	
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
             listboxes[listboxes.length] = 'document.inputform.elements[\"$name\"]'
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

function FrmTextareaPreSelect($name, $txt, $arr, $val, $needed=false, $hlp="", $morehelp="",  $rows=4, $cols=60) {
		
  $name=safe($name); $val=safe($val); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  echo "\n<script language=\"JavaScript\">
	<!--
		function add_to_area(inputbox, value) {
		  if (inputbox.value.length != 0) {
		    inputbox.value=inputbox.value+\",\"+value;
		  } else {
		    inputbox.value=value;
		  }	
		}
	//-->
	</script>\n";

  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr align=left>";
  echo "<td><textarea name=\"$name\" rows=$rows cols=$cols wrap=virtual".getTriggers("textarea",$name).">$val</textarea>
          <select name=\"foo_$name\"";
  echo "onchange=\"add_to_area($name, this.options[this.selectedIndex].value)\">";
  
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

# Validate users input. Error is reported in $err array
function ValidateInput($variableName, $inputName, $variable, &$err, $needed=false, $type="all") 
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
?>
