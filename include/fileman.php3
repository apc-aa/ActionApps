<?php
/**
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
require_once AA_INC_PATH. 'files.class.php3';

// array params: column header, default sort order
$sortable_columns = array (
    "name"=>array("label"=>_m("Name"),"sort"=>"a"),
    "size"=>array("label"=>_m("Size"),"sort"=>"a"),
    "type"=>array("label"=>_m("Type"),"sort"=>"a"),
    "lastm"=>array("label"=>_m("Last modified"),"sort"=>"d"));

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */
/** filesuffix function
 *  returns the suffix part of the filename (beginning with the last dot (.) in the filename)
 * @param $filename
 */
function filesuffix($filename) {
    if (!strstr ($filename,".")) {
        return "";
    }
    $i = strlen($filename);
    while ($filename[$i] != ".") {
        $i --;
    }
    return substr ($filename,$i+1);
}

/** make_secure function
 * @param $filename
 */
function make_secure(&$filename) {
    $filename = str_replace ("..","",$filename);
}

/** set_directory function
 * @param $dir
 */
function set_directory($dir) {
    global $directory;
    $directory = $dir;
    endslash($directory);
    make_secure($directory);
    if (substr ($directory,0,2) == "./") {
        $directory = substr ($directory, 2);
    }
}
/** compare_files function
 * @param $f1
 * @param $f2
 */
function compare_files($f1, $f2) {
    global $sort_key, $sort_order;

    if ($f1["name"]=="..") {
        return -1;
    }
    if ($f2["name"]=="..") {
        return 1;
    }
    if ($f1["dir"]) {
        if ($f2["dir"] && $f1["name"] > $f2["name"]) {
            return 1;
        } else {
            return -1;
        }
    } else if ($f2["dir"]) {
        return 1;
    }
    if ($f1[$sort_key] == $f2[$sort_key]) {
        return 0;
    }
    if ($f1[$sort_key] < $f2[$sort_key] XOR $sort_order=="a") {
        return 1;
    } else {
        return -1;
    }
}
/** format_file_size function
 * @param $size
 */
function format_file_size($size) {
    $i = strlen ($size) % 3;
    if ($i == 0) {
        $i = 3;
    }
    $retval = substr ($size,0,$i);
    while ($i < strlen ($size)) {
        $retval .= ",".substr ($size,$i,3);
        $i += 3;
    }
    $retval .= " b";
    return $retval;
}

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

$filetypes = array (
    _m("HTML file") => array ("img"=>"html", "ext"=>array ("shtml","html","htm","js")),
    _m("Web file") => array ("img"=>"ie", "ext"=>array ("php","php3","asp")),
    _m("Image file") => array ("img"=>"image2", "ext"=>array("gif","jpg","jpeg","tiff","img")),
    _m("Text file") => array ("img"=>"txt", "ext"=>array("txt")),
    _m("Directory") => array ("img"=>"folder"),
    _m("Parent") => array ("img"=>"parent"),
    _m("Other") => array ("img"=>"oth"));
/** get_filetype function
 * @param $filepath
 */
function get_filetype($filepath) {
    global $filetypes;

    if ($filepath == ".." || substr($filepath,-3) == "/..") {
        return _m("Parent");
    } else if (is_dir($filepath)) {
        return _m("Directory");
    } else {
        $ext = filesuffix($filepath);
        reset ($filetypes);
        while (list ($filetype, $val) = each ($filetypes)) {
            if (!$val["ext"]) {
                continue;
            }
            if (in_array($ext, $val["ext"])) {
                return $filetype;
            }
        }
        return _m("Other");
    }
}

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

/** get_file_array function
 *   independent of AA
 *
 *   creates a $files array, containing info about all files and directories in $path.$dirname,
 *   sorted by global $sort_key (from $sortable_columns) and $sort_order ('a' or 'd')
 *
 *   Array members:
 *       "name" filename (without path)
 *       "path" filename with path relative to $path
 *       "dir" boolean -- is it a directory?
 *       "size" file size (in bytes)
 *       "type" file type (see $filetypes)
 *       "lastm" last modified date, formated
 *       "img" name of image file (from $filetypes, without extension)
 * @param $path
 * @param $dirname
 */
function get_file_array($path, $dirname) {
    global $filetypes;

    endslash($path);
    endslash($dirname);

/*    $connid = ftp_connect ($path);
    $filesa = ftp_nlist ($connid, $dirname);
    print_r ($filesa);
*/

    if (!is_dir ($path.$dirname)) {
            return array ();
    }

    // fill the $files array
    if (!($dir = opendir($path.$dirname))) {
        echo $path.$dirname;
    }
    else {
        while ($filename = readdir ($dir)) {
            // don't allow to jump higher from base dir
            if ($filename == "." || ($filename == ".." && !$dirname)) {
                continue;
            }

            $filepath = $dirname.$filename;
            if ($filename == "..") {
                $i = strlen ($dirname)-2;
                while ($i > 0 && $dirname[$i] != "/") $i --;
                $filepath = substr ($dirname, 0, $i);
            }

            $filetype = get_filetype($path.$dirname.$filename);
            $files[] = array (
                "path"=>$filepath,
                "dir"=>is_dir ($path.$filepath),
                "name"=>$filename,
                "size"=>filesize ($path.$filepath),
                "type"=>$filetype,
                "img"=>$filetypes[$filetype]["img"],
                "lastm"=>date("d-M-Y",filemtime($path.$filepath))
            );
        }
        closedir($dir);

        // sort files
        global $sortable_columns;
        if (!$sortable_columns[$sort_key]) {
            reset($sortable_columns);
            $sort_key = key($sortable_columns);
        }
        if (is_array($files)) {
            uasort($files, "compare_files");
        }
    }
    return $files;
}

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */


/** file_table function
 * returns HTML for AA file table
 * @param $path
 * @param $dirname
 */
function file_table($path, $dirname)
{
    global $sess;
    global $directory;

    $files = get_file_array ($path, $dirname);
    if (is_array($files)) {
        // create the HTML code
        reset($files);
        while (list(,$file) = each($files)) {
            $href = $sess->url("fileman.php3?cmd=" . ($file["dir"] ? "chdir&arg[chdir]=" : "edit&arg[edit]=") . $file['path'] . "&fmset[directory]=$directory");
            $retval .= "<tr>
            <td>".($file['name']!=".." ? "<input type=\"checkbox\" name=\"chb[".$file['path']."]\">" : "&nbsp;")."</td>
            <td><a href=\"$href\"><img src=\"../images/$file[img].gif\" alt=\"".$file['type']."\" border=\"0\"></a></td>
            <td><a href=\"$href\">".$file['name']."</a></td>
            <td align=\"right\">".($file["dir"] ? "&nbsp;" : format_file_size($file["size"]))."</td>
            <td>".$file['type']."</td>
            <td>".$file['lastm']."</td>
            </tr>";
        }
    }
    return $retval;
}
/** is_dir_empty function
 * @param $dirname
 */
function is_dir_empty($dirname) {
    if ($dir = @opendir($dirname)) {
        while ($filename = readdir ($dir)) {
            if ($filename != "." && $filename != "..")
                return false;
        }
    }
    else {
        return false;
    }
    return true;
}

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */
/** fileman_execute_command function
 * @param $basedir
 * @param $directory
 * @param $cmd
 * @param array $arg e.g. $arg[edit] = "myfilename.htm"
 * @param $chb
 * @param $fmset
 */
function fileman_execute_command($basedir, $directory, $cmd, $arg, $chb, $fmset) {
    global $err,
        // set to the name of file which should be viewed by filedit.php3
        $fe_filename,
        // used in copy template
        $fileman_dir;

    if (!$cmd) {
        return;
    }

    $arg = $arg[$cmd];

    // Click on directory
    if ($cmd=='chdir'){
        set_directory($arg);
    } else if ($cmd == 'edit') {
        $fe_filename = $arg;
    }
        // Action taken in caller, which outputs HTML

    // Create file
    else if ($cmd=='createfile') {
        if ( !preg_match("/^[0-9a-zA-Z_.]*$/", $arg)) {
            $err[] = _m("Wrong file name.");
            return;
        }
        if (filesuffix($arg) == "") {
            $arg .= ".html";
        }
        $newfile = $basedir.$directory.$arg;
        if (file_exists ($newfile)) {
            $err[] = _m("File already exists")." ($newfilename).";
            return;
        }
        if (!fopen ($newfile, "w")) {
            $err[] = _m("Unable to create file")." $newfilename.";
            return;
        }
        chmod($newfile, FILEMAN_MODE_FILE);
        $fe_filename = $directory.$arg;
        // Action taken in caller, which outputs HTML
    }

    // Create directory
    else if ($cmd=='createdir') {
        if ( !preg_match("/^[0-9a-zA-Z_]*$/", $arg)) {
            $err[] = _m("Wrong directory name.");
            return;
        }
        $newdir = $basedir.$directory.$arg;
        mkdir($newdir, FILEMAN_MODE_DIR);
        if (!is_dir ($newdir)) {
            $err[] = _m("Unable to create directory")." $newdirname.";
        }
    }

    // Delete
    else if ($cmd=='delete' && is_array($chb)) {
        reset ($chb);
        while (list ($arg) = each ($chb)) {
            $f = $basedir.$arg;
            if (is_dir ($f)) {
                if (!is_dir_empty($f)) {
                    $err[] = _m("First delete all files from directory")." $arg.";
                } else if (!rmdir ($f)) {
                    $err[] = _m("Unable to delete directory")." $arg.";
                }
            }
            else {
                if (!unlink ($f)) {
                    $err[] = _m("Unable to delete file")." $arg.";
                }
            }
        }
    }
        // Upload file
    else if ($cmd=='upload') {
        set_time_limit(FILEMAN_UPLOAD_TIME_LIMIT);

        // $uploaderr = aa_move_uploaded_file("uploadarg", $basedir.$directory, FILEMAN_MODE_FILE);
        $dest_file = Files::uploadFile('uploadarg', $basedir.$directory);
        if ($dest_file === false) {   // error
            $err[] = Files::lastErrMsg();
        }
    }

    else if ($cmd=='copytmp') {
        $tmperr = fileman_copy_template(FILEMAN_BASE_DIR."templates/".$arg, FILEMAN_BASE_DIR.$fileman_dir);
        if ($tmperr) {
            $err[] = _m("Error: ").": $tmperr";
        }
    }

    // Single file actions:
    else if ($filename = $fmset['filename']) {

        // Save changes
        if ($cmd=='savefile') {
            $f       = $basedir.$filename;
            $filedes = fopen ($f,"w");
            if (!$filedes) {
                $err[] = _m("Unable to open file for writing")." ($filename).";
            }
            else {
                if (get_magic_quotes_gpc()) {
                    $arg = stripslashes ($arg);
                }
                $bytes = fwrite ($filedes, $arg);
                if ($bytes == -1) {
                    $err[] = _m("Error writing to file")." $filename.";
                }
                fclose ($filedes);
            }
        }

        // Rename
        else if ($cmd=='rename' && $filename) {
            make_secure($renamearg);
            $newname = dirname ($basedir.$filename)."/".$arg;
            if (file_exists ($newname)) {
                $err[] = _m("File with this name already exists")." ($arg).";
            }
            else if (!rename ($basedir.$filename, $newname)) {
                $err[] = _m("Unable to rename")." $filename.";
            }
        }
    }
}

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

/** get_files_subtree function
 * returns a list of filenames including subdirectories' entries
 * (like dir1/dir2/filename1.txt etc.) with path relative to $mydir
 * @param $mydir
 */
function get_files_subtree($mydir) {
    endslash($mydir);
    if (!is_dir ($mydir)) {
        return array ();
    }
    if (!($dir = opendir($mydir))) {
        return array ();
    }
    else {
        while ($filename = readdir($dir)) {
            if ($filename != "." && $filename != "..") {
                if (!is_dir ($mydir.$filename))
                    $retval[] = $filename;
                else {
                    $sub = get_files_subtree($mydir.$filename);
                    reset ($sub);
                    while (list (,$subfn) = each ($sub))
                        $retval[] = $filename."/".$subfn;
                }
            }
        }
    }
    return $retval;
}
/** fileman_copy_template function
 * @param $srcdir
 * @param $dstdir
 */
function fileman_copy_template($srcdir, $dstdir) {
    global $slice_id;

    $aliases = array (
        "_#SLICE_ID" => $slice_id);

    $db = getDB();
    $db->query("SELECT name FROM slice WHERE id = '".q_pack_id ($slice_id)."'");
    $db->next_record();
    $aliases["_#SLICNAME"] = $db->f('name');
    freeDB($db);

    if (!is_dir ($srcdir)) {
        return _m("Wrong directory name")." ($srcdir)";
    }
    $files = get_files_subtree ($srcdir);
    reset ($files);
    while (list (,$file) = each ($files)) {
        if (file_exists ($dstdir."/".$file)) {
            $errfiles[] = $file;
        }
    }
    if (is_array($errfiles)) {
        return _m("Files with the same names as some in the template already exist. Please change the file names first.") . " (".join(", ",$errfiles).").";
    }
    reset ($files);
    while (list (,$file) = each ($files)) {
        $ft = get_filetype($srcdir."/".$file);
        if ($ft == _m("HTML file") || $ft == _m("Text file")) {
            $fd = fopen ($dstdir."/".$file, "w");
            if (!$fd) {
                return _m("Unable to create file")." $dstdir/$file.";
            }
            $fcontent = file ($srcdir."/".$file);
            reset ($fcontent);
            while (list (,$frow) = each ($fcontent)) {
                reset ($aliases);
                while (list ($alias,$replace) = each ($aliases)) {
                    $frow = str_replace ($alias, $replace, $frow);
                }
                //if (get_magic_quotes_gpc()) $frow = stripslashes ($frow);
                fwrite ($fd, $frow);
            }
        }
        else {
            copy ($srcdir."/".$file, $dstdir."/".$file);
        }
        chmod ($dstdir."/".$file, FILEMAN_MODE_FILE);
    }
}

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

// J A V A S C R I P T

$fileman_js = "
    <script language=\"javascript\">
    <!--
        function SelectVis (form, prefix, state) {
            var elem = document.forms[form].elements;
            for ( var i=0; i < elem.length; i++ )
                if ( elem[i].name.substring(0,prefix.length) == prefix)
                    elem[i].checked = state;
        }

        function submitCommand (name) {
            document.forms[formname]['cmd'].value = name;
            document.forms[formname].submit();
        }

        function command (name) {
            formname = 'fileman';
            switch (name) {
            case 'checkall':
                SelectVis (formname,'chb',1); break;
            case 'uncheckall':
                SelectVis (formname,'chb',0); break;
            case 'delete':
                if (confirm('"._m("Are you sure you want to delete the selected files and folders?")."'))
                    submitCommand ('delete');
                break;
            case 'reset':
                document.fileman.filecontent.value = filetext; break;
            default:
                submitCommand (name);
                break;
            }
        }
    // -->
    </script>";
?>
