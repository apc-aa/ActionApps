<?php
/**
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
 * @version   $Id$
 * @author    Jakub Adamek <jakubadamek@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
 * Global parameters:
 *   sort_key .. anything from $sortable_columns
 *   sort_order .. 'a' or 'd'
 *   cmd[] .. contains desired command
 *   arg[] .. command arguments
 *   fmset[directory] .. active dir
 *   fmset[filename] .. active file (only in the one file page)
 *
 *   The code is divided into
 *       admin/fileman.php3 -- basic file table page interface
 *       include/fileman.php3 -- file table creation, all file actions (commands)
 *       include/filedit.php3 -- single file part of the manager (another page)
 *
 *   The variable $basedir is the base directory to the parent of which the user can't go.
 *   All paths are relative to this directory. It is set in the slices settings and if it does
 *   not exist, it is created.
 *   AA admins may go to the upper level.
*/

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."date.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."fileman.php3";
require_once AA_INC_PATH."msgpage.php3";

// FilemanPerms() is defined in perm_core.php3, it sets $fileman_dir
if (!FilemanPerms($slice_id)) {
    MsgPageMenu ("index.php3", _m("No permissions for file manager."), "admin:fileman");
}

// FILEMAN_BASE_DIR defined in config.php3
if (!is_dir (FILEMAN_BASE_DIR)) {
    MsgPageMenu ("index.php3",
    _m("Unable to run File Manager")." '" . FILEMAN_BASE_DIR . "' "
    ._m("doesn't exist"), "admin:fileman");
}

$basedir = FILEMAN_BASE_DIR.$fileman_dir;

if (!is_dir ($basedir) && !file_exists ($basedir)) {
    if (!mkdir ($basedir, FILEMAN_MODE_DIR)) {
        MsgPageMenu("index.php3",
        _m("Unable to mkdir")." '".$basedir."'", "admin:fileman");
    }
}

if (IsSuperadmin()) {
    $basedir = FILEMAN_BASE_DIR;
    if (!isset($fmset['directory']) && is_dir ($basedir.$fileman_dir)) {
        $fmset['directory'] = $fileman_dir;
    }
}

set_directory ($fmset['directory']);
//echo "<p><font color=purple>fileman_execute_command:basedir=$basedir;directory=$directory,cmd=$cmd,arg=$arg,chb=$chb,fmset=$fmset;</font></p>";
fileman_execute_command ($basedir, $directory, $cmd, $arg, $chb, $fmset);

// One file page
if ($cmd == 'edit' || $cmd == 'createfile') {
    $fe_path = $basedir;
    $fe_script = $sess->url("fileman.php3");
    $fe_wwwpath = FILEMAN_BASE_URL;
    // NOTE require_once outputs text, its not just defining functions
    require_once AA_INC_PATH."filedit.php3";
    page_close ();
    exit;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <title><?php echo _m("File Manager");?></title>
</head>
<?php
require_once AA_INC_PATH."menu.php3";
showMenu ($aamenus, "sliceadmin","fileman");

echo "<h1><b>" . _m("File Manager");
if ($directory) echo " - "._m("Directory")." ".$directory;
echo "</b></h1>";

PrintArray($err);
echo $Msg;

// J A V A S C R I P T

echo $fileman_js;

echo "<form name=\"fileman\" enctype=\"multipart/form-data\" method=\"post\" action=\"".$sess->url("fileman.php3")."\">
<input type=\"hidden\" name=\"cmd\">
<input type=\"hidden\" name=\"fmset[directory]\" value=\"$directory\">";

echo '<table border="0" cellspacing="0" cellpadding="5" bgcolor="'.COLOR_TABTITBG.'" align="center">';

/** formatAction function
 * @param $value
 * @return prints the value in <strong>
 */
function formatAction($value) {
    return "<strong>$value</strong>";
}

/** fileAction function
 * Creates a fileAction user element,
 *   @param string $name - the name of the javascript command
 *   @param string $value - the internationalized string to display
 *   @return an HTML button
 */
function fileAction($name,$value) {
    // Old style uses a link mostly to left of input field
    // this is BAD UI design, should be a button to the right.
    // switch the comments, if you disagree with me!  (mitra)
// OLD - links
/*
    return formatAction("<a href='javascript:command(\"$name\")'>$value</a>")
    ."&nbsp;&nbsp;";
*/
// NEW - buttons
     return "<input type=\"button\" name=\"button$name\" value=\"$value\" onclick=\"command('$name')\">&nbsp;&nbsp;";
}

/** uilr function
 *   @param string $inp - the HTML for the input element
 *   @param string $act - the HTML for the action element
 *   @return two columns of input and action, can flip and table
 */
function uilr($inp,$act) {
// OLD input on right of action
//   return ("<tr><td class=tabtxt>$act</td><td class=tabtxt>$inp</td></tr>\n");
// NEW input on left of action
    return ("<tr><td class=\"tabtxt\">$inp</td><td class=\"tabtxt\">$act</td></tr>\n");
}

/** inputplusaction function
 * Creates a input box and action, uses uilr and fileAction which vary
 *   @param string $name - the name of the javascript command
 *   @param string $value - the internationalized string to display
*/
function inputplusaction($name,$value) {
    $argname = ($argname ? $argname : "arg[$name]");
    return uilr("<input name=\"arg[$name]\">", fileAction($name,$value));
}


echo $jsSender;
echo "<tr><td class=\"tabtit\" align=\"left\">";
echo //fileAction ("checkall",_m("Select All")) .
     fileAction ("uncheckall",_m("Unselect all")) .
     fileAction ("delete",_m("Delete selected"));
echo "</td></tr>
<tr><td class=\"tabtxt\" align=\"center\">";
echo '<table border="1" cellspacing="0" cellpadding="5" bgcolor="'.COLOR_TABTITBG.'" align="center">';

// Show column headers
echo '<tr><td>&nbsp;</td><td>&nbsp;</td>';
if (!$sortable_columns[$sort_key]) {
    $sort_key = "name";
}
reset ($sortable_columns);
while (list ($sortk,$col) = each ($sortable_columns)) {
    if ($sort_key == $sortk) {
        if ($sort_order) {
            $so = $sort_order;
        } else {
            $so = $col["sort"];
        }
        $img = "&nbsp;<img src='../images/".($so == 'd' ? 'up' : 'down').".gif' border=0>";
        $so  = $so == 'a' ? 'd' : 'a';
        $so  = "&sort_order=$so";
    } else {
        $so  = "";
        $img = "";
    }

    echo "<td><a href=\"".$sess->url("fileman.php3?sort_key=$sortk$so&fmset[directory]=$directory")."\">" . formatAction($col[label].$img) . "</a></td>";
}
if (!$sort_order) {
    $sort_order = $sortable_columns[$sort_key]["sort"];
}

// * * * * * * * * Show the file table * * * * * * * *
echo file_table ($basedir, $directory);
echo "</table>
      <input type=\"hidden\" name=\"sort_key\" value=\"$sort_key\">
      <input type=\"hidden\" name=\"sort_order\" value=\"$sort_order\">
</td></tr>
<tr><td>";
echo '<table border="0" cellspacing="0" cellpadding="5" align="center">';

$space = 0;
echo "<tr height=\"$space\"><td class=\"tabtxt\" colspan=\"2\"></td></tr>";
echo inputplusaction("createfile",_m("Create new file"));
echo "<input type=\"hidden\" name=\"MAX_FILE_SIZE\" value=\"10485760\">";
echo uilr("<input type=\"file\" name=\"uploadarg\">",
    fileAction("upload",_m("Upload file")." (max. 10 MB)"));
echo "<tr height=\"$space\"><td class=\"tabtxt\" colspan=\"2\"></td></tr>";

$db->query("SELECT * FROM wizard_template");
if ($db->num_rows()) {
    $i = "<select name=\"arg[copytmp]\">";
    while ($db->next_record())
        $i.="<option value=\"".$db->f("dir")."\">".$db->f("dir")." (".$db->f("description").")";
    $i .= "</select>";
    echo uilr($i,fileAction("copytmp",_m("Copy template dir")));
}

echo inputplusaction("createdir",_m("Create new directory"));
echo "</table></td></tr>";

echo "</table></form><p></p>";
HtmlPageEnd();
page_close();
exit;
?>

