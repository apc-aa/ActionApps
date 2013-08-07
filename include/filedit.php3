<?php
/** Single file part of file manager
 * edit, download, rename a file here
 *
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @package   UserInput
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (c) 2002-3 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/


// parameters: $fe_path, $fe_filename, $fe_script, $fe_wwwpath

$filedit_js = "
    <script language='javascript'>
    <!--

    var formname = 'fileman';
    var richname = 'rich';

    function submitCommand (name) {
        document.forms[formname]['cmd'].value = name;
        document.forms[formname].submit();
    }

    function command (name) {
        switch (name) {
        case 'norichedit':
            document.forms[formname]['arg[norichedit]'].value = 1;
            submitCommand ('edit');
            break;
        case 'savefile':
            if (edt) document.forms[formname]['arg[savefile]'].value = get_text('edt'+richname);
            submitCommand (name);
            break;
        case 'reset':
            document.forms[formname]['arg[savefile]'].value = filetext;
            break;
        default:
            submitCommand (name);
            break;
        }
    }

    //-->
    </script>";

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<title>"._m("File Manager")."</title>";
echo "</head>";

require_once AA_INC_PATH."menu.php3";
showMenu ($aamenus, "sliceadmin","fileman");

echo "<h1><b>" . _m("File Manager") . " - "._m("File")." ".$fe_filename . "</b></h1>";

PrintArray($err);
echo $Msg;

echo '<table border="0" cellspacing="0" cellpadding="5" bgcolor="'.COLOR_TABTITBG.'" align="center">';
echo "<tr><td colspan=\"2\" class=\"tabtxt\">";

echo $GLOBALS['filedit_js'];
echo "
<form name='fileman' method=\"post\" action='$fe_script'>
<input type=\"hidden\" name='cmd'>
<input type=\"hidden\" name='fmset[filename]' value='$fe_filename'>
<input type=\"hidden\" name='fmset[directory]' value='".dirname($fe_filename)."'>"
.fileAction ("cancel", _m("Back to file list"))
.formatAction("<a href='$fe_wwwpath$fe_filename'>"._m("Download (right-click)")."</a>&nbsp;&nbsp;")
.fileAction("rename",_m("Rename to"))."<input type=\"text\" name='arg[rename]' value='".basename($fe_filename)."'>";

echo "<hr>";

$filetype = get_filetype($fe_filename);
if ($filetype == _m("Text file") || $filetype == _m("Web file") || $filetype == _m("HTML file")) {

    // don't edit the file if you won't be able to save it - only show it's contents
    $filedes = @fopen ($fe_path.$fe_filename, "a");
    if ($filedes) {
        fclose ($filedes);
        $filedes = fopen ($fe_path.$fe_filename, "r");
        $value = "";
        while (!feof ($filedes)) {
            $value .= fgets($filedes, 4096);
        }
        fclose ($filedes);
/*            if ($filetype == _m("HTML file") && !$arg["norichedit"]) {
            echo formatAction(_m("Edit").":")."&nbsp;&nbsp;".
                fileAction("norichedit",L_NORICHEDIT).
                "<input type=hidden name='arg[norichedit]' value=$arg[norichedit]>
                <input type=hidden name='arg[edit]' value='$arg[edit]'><br>";
            RawRichEditTextarea("",'rich', $value, 20, 80, "class", 1);
            $repl = array ("'"=>"\\'","\n"=>"\\n","\r"=>"\\r");
            reset ($repl);
            while (list ($find,$rep) = each ($repl))
                $value = str_replace ($find, $rep, $value);
            echo "<INPUT TYPE=HIDDEN NAME='arg[savefile]' VALUE='$value'>";
            echo fileAction ("savefile", _m("Save changes"));
        }
        else {*/
            echo formatAction(_m("Edit").":")."<br>";
            echo "<textarea name='arg[savefile]' cols=\"80\" rows=\"30\">
            </textarea><br>";
            $value = str_replace ("'", "\\'", $value);
            $value = str_replace ("\n", "\\n", $value);
            $value = str_replace ("\r", "\\r", $value);
            echo "<script language='javascript'>
            <!--
                var edt = 0;
                var filetext = '$value';
                document.forms['fileman']['arg[savefile]'].value = filetext;
            //-->
            </script>";
            echo fileAction ("savefile", _m("Save changes"))
                .fileAction ("reset", _m("Reset content"));
//            }
    }
    else {
        $filedes = @fopen ($fe_path.$fe_filename, "r");
        if ($filedes) {
            echo formatAction(_m("File content").":")."<br>";
            while (!feof ($filedes)) {
                $row = fgets($filedes, 4096);
                echo str_replace("\t","    ",nl2br(myspecialchars ($row)));
            }
            fclose ($filedes);
        }
    }
}
else if ($filetype == _m("Image file"))
    echo "<img src='$fe_wwwpath$fe_filename' border=\"0\">";
else
    echo _m("This is a file of type")." $filetype. "._m("I can't view it. If you want to view or edit it, change it's extension.");
//echo "</td></tr></table>";
echo "</form>";

echo "</td></tr></table>";
HTMLPageEnd();
page_close();
?>
