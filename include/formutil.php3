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

if (!defined ("AA_FORMUTIL_INCLUDED"))
    define ("AA_FORMUTIL_INCLUDED", 1);
else return;

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
	$size, $horizontal=0, $firstSelect=0, $needed=false, $hlp="", $morehlp="", $levelNames="") 
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
	showHierConstBoxes ($levelCount, $horizontal, $name, false, $firstSelect, $boxWidth, $levelNames);
	for ($i=0; $i<$boxWidth; ++$i) $widthTxt .= "m";
	echo "
	<TABLE border=0 cellpadding=2 width='100%'><TR>
	<TD align=center><b><span class=redtext>Selected:<span></b><BR><BR><INPUT TYPE=BUTTON VALUE='Delete' onclick='hcDelete(\"$name\")'></TD>
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

/*  Function: RawRichEditTextarea
    Purpose: Shows a rich edit text area - Wysiwyg editor
    Params:
        $type = "class" | "iframe" (iframes are not completely implemented)
        $doc_complet = do you edit complete documents or HTML fragments only? */

function RawRichEditTextarea ($BName, $name, $val, $rows=10, $cols=80, $type="class", $doc_complet=0) {
    if (!$BName) {
        detect_browser();
        $BName = $GLOBALS['BName'];
    }

    $name=safe($name);

    if( $html==2 ) // text only
        $val = str_replace ("\r","",str_replace ("\n","",
                nl2br (htmlspecialchars ($val,ENT_QUOTES))));
    else {
        $repl = array ("'"=>"\\'","\n"=>"\\n","\r"=>"\\r");
        reset ($repl);
        while (list ($find,$rep) = each ($repl))
            $val = str_replace ($find, $rep, $val);
    }

    echo "<!-- Browser $BName -->";
    if ($type == "iframe")
        $richedit = "richedit_iframe";
    else if ($BName == "MSIE")
        $richedit = "richedt_ie";
    else $richedit = "richedit_ns";

	if ($GLOBALS[debug]) echo $richedit;
    echo
        "<script language=javascript>
        <!--
		var edt$name"."_doc_complet = $doc_complet; 
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
        var edt$name"."_content = '$val';
        function edt$name"."_inicial() { 
            //change_state ('edt$name'); 
            posa_contingut_html('edt$name',edt$name"."_content);
            //change_state ('edt$name');
     	    clearInterval(edt$name"."_timerID); 
            return true; 
        } 
        // --> 
    </script>";
}

# On browsers which do support it, loads a special rich text editor with many
# advanced features based on triedit.dll 
# On the other browsers, loads a normal text area

function FrmRichEditTextarea($name, $txt, $val, $rows=10, $cols=80, $type="class", $needed=false, 
                     $hlp="", $morehlp="", $single="", $html=false) {
  global $BName; 
  if (! richEditShowable()) {
	 FrmTextarea($name, $txt, $val, $rows, $cols, $needed, $hlp, $morehlp, $single, $html, $BName != "MSIE");
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
  echo "<td $colspan>";

  RawRichEditTextarea ($BName, $name, $val, $rows, $cols, $type);

    echo "
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
  echo FrmChBoxEasyCode($name, $checked, $add);
}
function FrmChBoxEasyCode($name, $checked=true, $add="") {
  $name=safe($name); # $add=safe($add); NO!!

  return "<input type=\"checkbox\" name=\"$name\" $add".
    ($checked ? " checked>" : ">");
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

# Prints html tag <intup type=text ...> with <select ...> and buttons
# form moving with items
# to 2-column table for use within <form> and <table> tag
function FrmInputWithSelect($name, $txt, $arr, $val, $input_maxsize=254, $input_size=25,
                            $select_size=6, $numbered=0, $needed=false, $hlp="", $morehlp="", $adding=0,
                            $secondfield="", $usevalue=false) {
  $name=safe($name); $val=safe($val); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  if( !$input_maxsize )
    $input_maxsize = 254;
  if( !$input_size )
    $input_size = 25;
  if ( !$select_size )
    $select_size = 6;
  if ($secondfield) {
    $varsecfield = 'v'. unpack_id($secondfield);
  }
    echo "\n<script language=\"JavaScript\"  type=\"text/javascript\">
  <!--
    function add_to_select(selectbox, inputbox) {
                  value = inputbox.value;
                  length = selectbox.length;
                  if (value.length != 0) {
                    if((length == 1) && (selectbox.options[0].value=='wIdThTor') ){\n";

        if ($numbered==1) {
          echo "    text = length+'. '+value; ";
        }
        echo "
                selectbox.options[0].text = text;
                selectbox.options[0].value = value;
              } else {";
        if ($numbered==1) {
          echo "    text = (length+1)+'. '+value; ";
        }
        echo "      selectbox.options[selectbox.length] = new Option (text, value);
        }
                    inputbox.select();
                  }
                }

                function remove_selected(selectbox) {
                  number = selectbox.selectedIndex;
                  length = selectbox.length;
                  selectbox.options[number] = null;\n";
        if ($numbered==1) {
          echo "
                  for (i=number;i<length; i++){
                    selectbox.options[i].text = (i+1)+'. '+selectbox.options[i].value;
                  }";
        }
        echo "    selectbox.selectedIndex = number;
                }

                function move(selectbox, type) {
                  length = selectbox.length;
                  s = selectbox.selectedIndex;

                  dontwork = 0;

                  if (s < 0) { dontwork=1; }

                  if (type=='up') {
                    s2 = s-1;
                    if (s2 < 0) { s2 = 0;}
                  } else {
                    s2 = s+1;
                    if (s2 >= length-1) { s2 = length-1; }
                  }

                  if (dontwork == 0) {
                    dummy_val = selectbox.options[s2].value;
                    dummy_txt = selectbox.options[s2].text;
                    selectbox.options[s2].value = selectbox.options[s].value;
                    selectbox.options[s2].text = selectbox.options[s].text;
                    selectbox.options[s].value = dummy_val;
                    selectbox.options[s].text  = dummy_txt;

                    selectbox.selectedIndex = s2;\n";
        if ($numbered==1) {
          echo "
                  number = selectbox.selectedIndex;
                  if (type == 'up') {
                    for (i=number;i<length; i++){
                      selectbox.options[i].text = (i+1)+'. '+selectbox.options[i].value;
                    }
                  } else {
                    for (i=0;i<=number; i++){
                      selectbox.options[i].text = (i+1)+'. '+selectbox.options[i].value;
                    }
                  }";
        }
        echo "

                  }
                }

                listboxes[listboxes.length] = 'document.inputform.elements[\"".$name."[]\"]';
  //-->
  </script>\n";

  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr align=left>";
  echo "<td align=left>

        <table>
        <tr><td><input type=\"Text\" name=\"foo_$name\" size=$input_size maxlength=$input_maxsize value=\"$val\"></td>
        <td align=center><input type=\"button\" name=\"".$name."_add\" value=\"  Add  \" ".
        " onclick=\"add_to_select(document.inputform['".$name."[]'], foo_$name)\"></td></tr>
        <tr align=left><td rowspan=3><select name=\"".$name."[]\" multiple width=$input_size size=\"$select_size\">\n";

  if (is_array($arr)) {
    reset($arr);
    $i=0;
    while(list($k, $v) = each($arr)) {
      $i++;
      echo "<option value=\"". htmlspecialchars($usevalue ? $v : $k)."\"";
      if ((string)$val == (string)(($usevalue OR $secondfield) ? $v : $k))
        echo " selected";
      echo "> ";
      if ($numbered ==1) { echo htmlspecialchars($i.". ".$v); }
      else { echo htmlspecialchars($v); }
      echo " </option>";
    }
    reset($arr);
  } else {
    echo "<option value=\"wIdThTor\"> ";
        for ($i=0; $i<$select_size*3; $i++) {
          echo "&nbsp; ";}
        echo "</option>";
  }

  echo "</select></td>
        <td align=center><input type=\"button\" name=\"".$name."_up\" value=\" /\ \" ".
                 " onclick = \"move(document.inputform['".$name."[]'],'up');\"></td></tr>
        <tr><td align=center><input type=\"button\"  name=\"".$name."_remove\" value=\" "._m("Remove")."\" ".
                 " onclick = \"remove_selected(document.inputform['".$name."[]']);\"></td></tr>
        <tr><td align=center><input type=\"button\" name=\"".$name."_down\" value=\" \/ \" ".
                 " onclick = \"move(document.inputform['".$name."[]'], 'down');\"></td></tr>
        </table>";
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}

# Prints two boxes for multiple selection for use within <form> and <table> tag
function FrmTwoBox($name, $txt, $arr, $val, $size=8, $selected,
                   $needed=false, $wi2_offer, $wi2_selected, $hlp="", $morehlp="") {
  $name=safe($name); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  if ($wi2_offer == "") $wi2_offer = L_WI2_OFFER;
  if ($wi2_selected == "") $wi2_selected = L_WI2_SELECTED;

  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  echo "<td>";
  echo "<table border=\"0\" cellspacing=\"0\" cellpadding=\"0\"><tr align=left>
      <td align='CENTER' valign='TOP'>". $wi2_offer ."</td><td></td>
        <td align=\"CENTER\" valign=\"TOP\">". $wi2_selected ."</td></tr>
      <tr align=left><td align='CENTER' valign='TOP'>
      <SELECT name=\"".$name."_1\" size=$size ".getTriggers("select",$name).">\n";

  reset($arr);
  while (list($k,$v) = each($arr)) {
    if (!($selected[$k])) {
      echo "<option value=\"". htmlspecialchars($k)."\"> ".htmlspecialchars($v)." </option>\n";
    }
  }
  echo "
        </SELECT>
      </td>
      <td>&nbsp;&nbsp;<input type=\"button\" VALUE=\"  >>  \" onClick = \"MoveSelected(document.inputform.".$name."_1,document.inputform['".$name."[]'])\" align=center>
          <br><br>&nbsp;&nbsp;<input type=\"button\" VALUE=\"  <<  \" onClick = \"MoveSelected(document.inputform['".$name."[]'],document.inputform.".$name."_1)\" align=center>&nbsp;&nbsp;</td>
      <td align=\"CENTER\" valign=\"TOP\">
      <SELECT multiple name=\"".$name."[]\" size=$size  ".getTriggers("select",$name).">";

  $option_no=0;
  while(list($k, $v) = each($selected)) {
    echo "<option value=\"". htmlspecialchars($k)."\"> ".htmlspecialchars($v)." </option>\n";
    $onption_no++;
  }
  if ($option_no == 0) {
    echo '<option value="wIdThTor"> &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;  &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; </option>';
  }
  echo "
      </SELECT>";
  echo "
  <script language=\"javascript\" type=\"text/javascript\"><!--
    listboxes[listboxes.length] = 'document.inputform.elements[\"".$name."[]\"]';
  //--></script>
  ";

  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "
    </td></tr></table>
      </td>
      </tr>";
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
        echo "<nobr><input type='checkbox' name='$name'
                     value='". htmlspecialchars($k) ."'".getTriggers("input",$name);
        if ($selected[$k])
          echo " checked";
        echo ">".htmlspecialchars($v)."</nobr>";
      }
  }
  PrintMoreHelp($morehlp);
  PrintHelp($hlp);
  echo "</td></tr>\n";
}


# Prints html tag <select multiple .. to 2-column table
# for use within <form> and <table> tag
function FrmInputMultiSelect($name, $txt, $arr, $selected="", $size=5, 
          $relation=false, $needed=false, $hlp="", $morehlp="", $minrows=0, $mode='AMB', $design=false) {
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
          <input type='button' value='". L_ADD ."' onclick='OpenRelated(\"$name\", \"$relation\", \"$mode\", \"$design\" )'> 
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

function FrmRelated($name, $txt, $arr, $size, $sid, $mode, $design, $needed=false, $hlp="", $morehlp="") {
  FrmInputMultiSelect($name, $txt, $arr, "", $size=5, $sid, $needed, $hlp, $morehlp, MAX_RELATED_COUNT, $mode, $design);
}  

# Prints html tag <select .. 
function FrmSelectEasy($name, $arr, $selected="", $add="") { 
  echo FrmSelectEasyCode ($name, $arr, $selected, $add);
}

function FrmSelectEasyCode($name, $arr, $selected="", $add="") { 
  $name=safe($name); # safe($add) - NO! - do not safe it

  $retval = "<select name=\"$name\" $add>\n";	
  reset($arr);
  while(list($k, $v) = each($arr)) { 
    $retval .= "  <option value=\"". htmlspecialchars($k)."\"";
    if ((string)$selected == (string)$k) 
      $retval .= " selected";
    $retval .= ">". htmlspecialchars( is_array($v) ? $v['name'] : $v ) ."</option>\n";
  }
  $retval .= "</select>\n";
  return $retval;
}  

function FrmTextareaPreSelect($name, $txt, $arr, $val, $needed=false, $hlp="", $morehelp="",  $rows=4, $cols=60) {
		
  $name=safe($name); $val=safe($val); $txt=safe($txt); $hlp=safe($hlp); $morehlp=safe($morehlp);

  echo "<tr align=left><td class=tabtxt><b>$txt</b>";
  Needed($needed);
  echo "</td>\n";
  if (SINGLE_COLUMN_FORM)
    echo "</tr><tr align=left>";
  echo "<td><textarea name=\"$name\" rows=$rows cols=$cols wrap=virtual".getTriggers("textarea",$name).">$val</textarea>
          <select name=\"foo_$name\"";
  echo "onchange=\"add_to_line($name, this.options[this.selectedIndex].value)\">";
  
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
    case "filename": if( !EReg("^[0-9a-zA-Z_]+$", $variable)) {
                       $err[$variableName] = MsgErr(L_ERR_IN." $inputName");
                       return false;
                     }
                     return true;
    case "url":
    case "all":    
    default:       return true;
  }  
} 

// used in tabledit.php3 and itemedit.php3

function get_javascript_field_validation () {
    return "
        function validate (txtfield, type, required) {
            var invalid_email = /(@.*@)|(\.\.)|(@\.)|(\.@)|(^\.)/;
            var valid_email = /^.+\@(\[?)[a-zA-Z0-9\-\.]+\.([a-zA-Z]{2,3}|[0-9]{1,3})(\]?)$/;

            if (txtfield == null)
                return true;

            var val = txtfield.value;
            var err = '';

            if (val == '' && required)
                err = '"._m("This field is required.")."';

            else if (val == '')
                return true;

            else switch (type) {
                case 'number':
                    if (!val.match (/^[0-9]+$/))
                        err = '"._m("Not a valid integer number.")."';
                    break;
                case 'filename':
                    if (!val.match (/^[0-9a-zA-Z_]+$/))
                        err = '"._m("Not a valid file name.")."';
                    break;
                case 'email':
                    if (val.match(invalid_email) || !val.match(valid_email))
                        err = '"._m("Not a valid email address.")."';
                    break;
            }

            if (err != '') {
                alert (err);
                txtfield.focus();
                return false;
            }
            else return true;
        }";
}

?>
