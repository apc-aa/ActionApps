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
    chdir .. change directory
    edit .. edit file
    
    The code is divided into 
        admin/fileman.php3 -- basic file table page interface, all file actions (commands)
        include/fileman.php3 -- file table creation
        include/filedit.php3 -- single file part of the manager (another page)
*/

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."date.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."fileman.php3";
require $GLOBALS[AA_INC_PATH]."filedit.php3";

$basedir = FILEMAN_BASE_DIR;

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * 
 *                  REQUESTED FILE ACTION                      */

echo $directory;
 
function make_secure (&$filename) {
    $filename = str_replace ("..","",$filename);
}
 
function set_directory ($dir) {
    global $directory;
    $directory = $dir;
    endslash ($directory);
    make_secure($directory);
    if (substr ($directory,0,2) == "./") 
        $directory = substr ($directory, 2);
}

set_directory ($fmset['directory']);

if ($cmd) {
    $arg = $arg[$cmd];

    // Click on directory
    if ($cmd=='chdir') 
        set_directory ($arg);

    // Edit  
    else if ($cmd=='edit') {
        if (filedit ($basedir, $arg, "fileman.php3?AA_CP_Session=$AA_CP_Session", "filedit_top", "filedit_bottom",AA_INSTAL_URL."tmp/")) {
            page_close ();
            exit;
        }
    }

    // Create file
    else if ($cmd=='createfile') {
        $newfile = $basedir.$directory.$arg;
        if (!file_exists ($newfile)) {
            if (!fopen ($newfile, "w"))
                $err[] = "Unable to create file";
            else {
                chmod ($newfile, FILEMAN_MODE);
                if (filedit ($basedir, $directory.$arg, "fileman.php3?AA_CP_Session=$AA_CP_Session", "filedit_top", "filedit_bottom", AA_INSTAL_URL."tmp/")) {
                    page_close ();
                   exit;
                }
            }
        }
        else $err[] = "File $newfilename already exists.";
    }

    // Create directory
    else if ($cmd=='createdir') {
        $newdir = $basedir.$directory.$arg;
        mkdir ($newdir, FILEMAN_MODE);
        if (!is_dir ($newdir)) 
            $err[] = "Unable to create directory $newdirname";
    }

    // Delete
    else if ($cmd=='delete' && is_array ($chb)) {
        reset ($chb);
        while (list ($arg) = each ($chb)) {
            $f = $basedir.$arg;
            if (is_dir ($f)) {
                if (!is_dir_empty ($f))
                    $err[] = "First delete all files from directory $arg.";
                else if (!rmdir ($f))
                    $err[] = "Unable to delete directory $arg.";
            }
            else {
                if (!unlink ($f)) 
                    $err[] = "Unable to delete file $arg.";
            }
        }
    }

        // Upload file
    else if ($cmd=='upload') {
        set_time_limit(FILEMAN_UPLOAD_TIME_LIMIT);
        $uploaderr = fileman_move_uploaded_file ("uploadarg", $basedir.$directory, FILEMAN_MODE);
        if ($uploaderr) $err[] = "Error: ".$uploaderr;
    }

    // Single file actions:
    else if ($filename = $fmset['filename']) {

        // Save changes
        if ($cmd=='savefile') {
            $f = $basedir.$filename;
            $filedes = fopen ($f,"w");
            if (!$filedes)
                $err[] = "Unable to open file $filename for writing.";
            else {
                $bytes = fwrite ($filedes, $arg);
                if ($bytes == -1)
                    $err[] = "Error writing to file $filename.";
                fclose ($filedes);
            }
        }

        // Rename
        else if ($cmd=='rename' && $filename) {
            make_secure ($renamearg);    
            $newname = dirname ($basedir.$filename)."/".$arg;
            if (file_exists ($newname)) 
                $err[] = "Error: File with name $arg already exists.";
            else if (!rename ($basedir.$filename, $newname))
                $err[] = "Unable to rename $filename.";
        }
    }
}
    
/*               END OF REQUESTED FILE ACTION                  * 
 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */
    

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_FTP_TIT;?></TITLE>
</HEAD>
<?php

$show ["fileman"] = false;
require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

echo "<H1><B>" . L_A_FTP_TIT;
if ($directory) echo " - ".L_DIRECTORY." ".$directory;
echo "</B></H1>";

PrintArray($err);
echo $Msg;

// J A V A S C R I P T 

echo $fileman_js;

echo "<form name='fileman' enctype='multipart/form-data' method='post' action='fileman.php3?AA_CP_Session=$AA_CP_Session'>
<input type=hidden name=cmd>";
echo "<input type=hidden name='fmset[directory]' value='$directory'>";

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
echo fileAction ("checkall","Select All") . 
     fileAction ("uncheckall","Unselect All") . 
     fileAction ("delete","Delete");
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
        
    echo "<td><strong><a href='fileman.php3?AA_CP_Session=$AA_CP_Session&sort_key=$sortk$so'>
            $col[label]</a>$img</strong></td>";
}
if (!$sort_order)
    $sort_order = $sortable_columns[$sort_key]["sort"];
echo file_table ($basedir, $directory);
echo "</table>
      <input type=hidden name=sort_key value='$sort_key'>
      <input type=hidden name=sort_order value='$sort_order'>
</td></tr>
<tr><td>";
echo '<table border="0" cellspacing="0" cellpadding="5" align="center">';

$space = 0;

echo "<tr height=$space><td class=tabtxt colspan=2></td></tr>";

echo "<tr><td class=tabtxt>".fileAction ("createfile","Create new file") ."</td>
<td class=tabtxt><input type=text name='arg[createfile]'></td></tr>";

echo "<tr><td class=tabtxt>".fileAction ("upload","Upload new file (max. 10 Mb)") ."</td>
<td class=tabtxt><input type='hidden' name='MAX_FILE_SIZE' value='10485760'>
<input type='file' name='uploadarg'></td></tr>";
echo "<tr height=$space><td class=tabtxt colspan=2></td></tr>";

echo "<tr><td class=tabtxt>".fileAction("copytmp","Copy template dir"). "</td>
<td class=tabtxt><select name='arg[copytmp]'>
    <option>simple (Simple)
    <option>ionline (With itrainonline header)
    </select></td></tr>";    

echo "<tr><td class=tabtxt>".fileAction ("createdir","Create new directory") ."</td>
<td class=tabtxt><input type=text name='arg[createdir]'></td></tr>
</table></td></tr>";

echo "</table></form>";

page_close();
exit;
?>

