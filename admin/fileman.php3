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

/* Global parameters:
    sort_key .. anything from $sortable_columns
    sort_order .. 'a' or 'd'
    cmd[] .. contains desired command
    arg[] .. command arguments
    fmset[directory] .. active dir
    fmset[filename] .. active file (only in the one file page)
    
    The code is divided into 
        admin/fileman.php3 -- basic file table page interface
        include/fileman.php3 -- file table creation, all file actions (commands)
        include/filedit.php3 -- single file part of the manager (another page)
        
    The variable $basedir is the base directory to the parent of which the user can't go. 
    All paths are relative to this directory. It is set in the slices settings and if it does
    not exist, it is created.
    AA admins may go to the upper level. 
*/

require_once "../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";
require_once $GLOBALS["AA_INC_PATH"]."date.php3";
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."fileman.php3";
require_once $GLOBALS["AA_INC_PATH"]."msgpage.php3";

// FilemanPerms() is defined in perm_core.php3, it sets $fileman_dir
if (!FilemanPerms($auth, $slice_id)) 
    MsgPageMenu ("index.php3", _m("No permissions for file manager."), "admin:fileman");

$basedir = FILEMAN_BASE_DIR.$fileman_dir;

if (!is_dir ($basedir) && !file_exists ($basedir))
    mkdir ($basedir, $FILEMAN_MODE_DIR);
  
if (IsSuperadmin()) {
    $basedir = FILEMAN_BASE_DIR;
    if (!isset($fmset['directory']) && is_dir ($basedir.$fileman_dir)) 
        $fmset['directory'] = $fileman_dir;
}

set_directory ($fmset['directory']);
//echo "<p><font color=purple>fileman_execute_command:basedir=$basedir;directory=$directory,cmd=$cmd,arg=$arg,chb=$chb,fmset=$fmset;</font></p>";
fileman_execute_command ($basedir, $directory, $cmd, $arg, $chb, $fmset);

// One file page
if ($cmd == 'edit' || $cmd == 'createfile') {
    $fe_path = $basedir;
    $fe_script = $sess->url("fileman.php3");
    $fe_wwwpath = FILEMAN_BASE_URL;
    require_once $GLOBALS["AA_INC_PATH"]."filedit.php3";
    page_close ();
    exit;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("File Manager");?></TITLE>
</HEAD>
<?php
require_once $GLOBALS["AA_INC_PATH"]."menu.php3"; 
showMenu ($aamenus, "sliceadmin","fileman");

echo "<H1><B>" . _m("File Manager");
if ($directory) echo " - "._m("Directory")." ".$directory;
echo "</B></H1>";

PrintArray($err);
echo $Msg;

// J A V A S C R I P T 

echo $fileman_js;

echo "<form name='fileman' enctype='multipart/form-data' method='post' action='".$sess->url("fileman.php3")."'>
<input type=hidden name=cmd>
<input type=hidden name='fmset[directory]' value='$directory'>";

echo '<table border="0" cellspacing="0" cellpadding="5" bgcolor="'.COLOR_TABTITBG.'" align="center">';

function formatAction ($value) {
    return "<strong>$value</strong>";
}

function fileAction ($name,$value) {
    return formatAction ("<a href='javascript:command(\"$name\")'>$value</a>")
    ."&nbsp;&nbsp;";
}

echo $jsSender;
echo "<tr><td class=tabtit align=left>";
echo //fileAction ("checkall",_m("Select All")) . 
     fileAction ("uncheckall",_m("Unselect all")) . 
     fileAction ("delete",_m("Delete"));
echo "</td></tr>
<tr><td class=tabtxt align=center>";
echo '<table border="1" cellspacing="0" cellpadding="5" bgcolor="'.COLOR_TABTITBG.'" align="center">';

// Show column headers
echo '<tr><td>&nbsp;</td><td>&nbsp;</td>';
if (!$sortable_columns[$sort_key]) 
    $sort_key = "name";
reset ($sortable_columns);
while (list ($sortk,$col) = each ($sortable_columns)) {
    if ($sort_key == $sortk) {
        if ($sort_order) $so = $sort_order;
        else $so = $col["sort"];
        $img = "&nbsp;<img src='../images/".($so == 'd' ? 'up' : 'down').".gif' border=0>";   
        $so = $so == 'a' ? 'd' : 'a';
        $so = "&sort_order=$so";
    }
    else {
        $so = "";
        $img = "";
    }
        
    echo "<td><a href='".$sess->url("fileman.php3?sort_key=$sortk$so&fmset[directory]=$directory")."'>" . formatAction($col[label].$img) . "</a></td>";
}
if (!$sort_order)
    $sort_order = $sortable_columns[$sort_key]["sort"];
    
// * * * * * * * * Show the file table * * * * * * * *
echo file_table ($basedir, $directory);
echo "</table>
      <input type=hidden name=sort_key value='$sort_key'>
      <input type=hidden name=sort_order value='$sort_order'>
</td></tr>
<tr><td>";
echo '<table border="0" cellspacing="0" cellpadding="5" align="center">';

$space = 0;

echo "<tr height=$space><td class=tabtxt colspan=2></td></tr>";

echo "<tr><td class=tabtxt>".fileAction ("createfile",_m("Create new file")) ."</td>
<td class=tabtxt><input type=text name='arg[createfile]'></td></tr>";

echo "<tr><td class=tabtxt>".fileAction ("upload",_m("Upload file")." (max. 10 MB)") ."</td>
<td class=tabtxt><input type='hidden' name='MAX_FILE_SIZE' value='10485760'>
<input type='file' name='uploadarg'></td></tr>";
echo "<tr height=$space><td class=tabtxt colspan=2></td></tr>";

$db->query("SELECT * FROM wizard_template");
if ($db->num_rows()) {
    echo "<tr><td class=tabtxt>".fileAction("copytmp",_m("Copy template dir")). "</td>
    <td class=tabtxt><select name='arg[copytmp]'>";
    while ($db->next_record()) 
        echo "<option value='".$db->f("dir")."'>".$db->f("dir")." (".$db->f("description").")";
    echo "</select></td></tr>";    
}

echo "<tr><td class=tabtxt>".fileAction ("createdir",_m("Create new directory")) ."</td>
<td class=tabtxt><input type=text name='arg[createdir]'></td></tr>
</table></td></tr>";

echo "</table></form><p></p>";
HtmlPageEnd();
page_close();
exit;
?>

