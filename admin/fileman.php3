<?php 

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

// sort = default sort order
$sortable_columns = array (
    "name"=>array("label"=>"Name","sort"=>"a"), 
    "size"=>array("label"=>"Size","sort"=>"a"),
    "type"=>array("label"=>"Type","sort"=>"a"),
    "lastm"=>array("label"=>"Last modified","sort"=>"d"));

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."date.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."fileman.php3";
require $GLOBALS[AA_INC_PATH]."filedit.php3";

$basedir = "/raid/www/htdocs/work.ecn.cz/apc-aa/tmp/";

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * 
 *                  REQUESTED FILE ACTION                      */

// Click on directory
if ($chdir) $directory = $chdir;
else $directory = dirname ($filename);
if (substr ($directory,0,2) == "./") 
    $directory = substr ($directory, 2);

endslash ($directory);

// Rename
if ($rename && $filename && $renamename) {
    if (!rename ($basedir.$filename, dirname ($basedir.$filename)."/".$renamename))
        $err[] = "Unable to rename $filename.";
}
 
// Edit  
if ($edit) {
    if (filedit ($basedir, $edit, "fileman.php3?AA_CP_Session=$AA_CP_Session", "filedit_top", "filedit_bottom",AA_INSTAL_URL."tmp/")) {
        page_close ();
        exit;
    }
}

// Create file
if ($createfile && $newfilename) {
    $newfile = $basedir.$directory.$newfilename;
    if (!file_exists ($newfile)) {
        if (!fopen ($newfile, "w"))
            $err[] = "Unable to create file";
        else if (filedit ($basedir, $directory.$newfilename, "fileman.php3?AA_CP_Session=$AA_CP_Session", "filedit_top", "filedit_bottom", AA_INSTAL_URL."tmp/")) {
            page_close ();
            exit;
        }
    }
    else $err[] = "File $newfilename already exists.";
}

// Create directory
if ($createdir && $newdirname) {
    $newdir = $basedir.$directory.$newdirname;
    mkdir ($newdir, 0775);
    if (!is_dir ($newdir)) 
        $err[] = "Unable to create directory $newdirname";
}

// Delete
if ($delete && is_array ($chb)) {
    reset ($chb);
    while (list ($filename) = each ($chb)) {
        $f = $basedir.$filename;
        if (is_dir ($f)) {
            if (!is_dir_empty ($f))
                $err[] = "First delete all files from directory $f.";
            else if (!rmdir ($f))
                $err[] = "Unable to delete directory $f.";
        }
        else {
            if (!unlink ($f)) 
                $err[] = "Unable to delete file $filename.";
        }
    }
}

// Save changes
if ($savefile && $filename && $filecontent) {
    $f = $basedir.$filename;
    $filedes = fopen ($f,"w");
    if (!$filedes)
        $err[] = "Unable to open file $filename for writing.";
    else {
        $bytes = fwrite ($filedes, $filecontent);
        if ($bytes == -1)
            $err[] = "Error writing to file $filename.";
        fclose ($filedes);
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

echo "<form name='fileman' method='post' action='fileman.php3?AA_CP_Session=$AA_CP_Session'>";
echo "<input type=hidden name=directory value='$directory'>";
echo '<table border="0" cellspacing="0" cellpadding="5" bgcolor="'.COLOR_TABTITBG.'" align="center">';

function formatAction ($value) {
    return "<strong>$value</strong>";
}

function fileAction ($name,$value) {
    return "<input type=hidden name='$name'>"
    .formatAction ("<a href='javascript:command(\"$name\")'>$value</a>")
    ."&nbsp;&nbsp;";
}

echo $jsSender;
echo "<tr><td class=tabtit align=left>";
echo fileAction ("checkall","Select All") . 
     fileAction ("uncheckall","Unselect All") . 
     fileAction ("delete","Delete") . 
     fileAction ("move","Move");
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
        $so = $so == 'a' ? 'd' : 'a';
        $so = "&sort_order=$so";
    }
    else $so = "";
        
    echo "<td><strong><a href='fileman.php3?AA_CP_Session=$AA_CP_Session&sort_key=$sortk$so'>
            $col[label]</a></strong></td>";
}
if (!$sort_order)
    $sort_order = $sortable_columns[$sort_key]["sort"];
echo file_table ($basedir, $directory);
echo "</table></td></tr>";

$space = 0;

echo "<tr height=$space><td class=tabtxt colspan=2></td></tr>";
echo "<tr><td class=tabtxt colspan=2>".fileAction ("createfile","Create new file:") .
"<input type=text name='newfilename'></td></tr>";
echo "<tr><td class=tabtxt colspan=2>".fileAction ("upload","Upload new file:") .
"<input type=file name='upload'></td></tr>";
echo "<tr height=$space><td class=tabtxt colspan=2></td></tr>";
echo "<tr><td class=tabtxt colspan=2>" . fileAction("copytmp","Copy template dir"). "
    <select name='template'>
    <option>simple (Simple)
    <option>ionline (With itrainonline header)
    </select></td></tr>";    
echo "<tr><td class=tabtxt colspan=2>".fileAction ("createdir","Create new directory:") .
"<input type=text name='newdirname'></td></tr>";

page_close();
exit;
?>

