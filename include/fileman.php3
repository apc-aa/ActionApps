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

// array params: column header, default sort order
$sortable_columns = array (
    "name"=>array("label"=>L_SC_NAME,"sort"=>"a"), 
    "size"=>array("label"=>L_SC_SIZE,"sort"=>"a"),
    "type"=>array("label"=>L_SC_TYPE,"sort"=>"a"),
    "lastm"=>array("label"=>L_SC_LAST_MODIFIED,"sort"=>"d"));

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */    

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

function compare_files ($f1, $f2) {
    global $sort_key, $sort_order;
    
    if ($f1["name"]=="..") return -1;
    if ($f2["name"]=="..") return 1;
    if ($f1["dir"]) {
        if ($f2["dir"] && $f1["name"] > $f2["name"])
            return 1;
        else return -1;
    }
    else if ($f2["dir"]) return 1;
    if ($f1[$sort_key] == $f2[$sort_key]) return 0;
    if ($f1[$sort_key] < $f2[$sort_key] XOR $sort_order=="a")
        return 1;
    else return -1;
}

function format_file_size ($size) {
    $i = strlen ($size) % 3;
    if ($i == 0) $i = 3;
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
    L_FILETYPE_HTML => array ("img"=>"html", "ext"=>array ("shtml","html","htm","js")),
    L_FILETYPE_WEB => array ("img"=>"ie", "ext"=>array ("php","php3","asp")),
    L_FILETYPE_IMAGE => array ("img"=>"image2", "ext"=>array("gif","jpg","jpeg","tiff","img")),
    L_FILETYPE_TEXT => array ("img"=>"txt", "ext"=>array("txt")),
    L_FILETYPE_DIRECTORY => array ("img"=>"folder"), 
    L_FILETYPE_PARENT => array ("img"=>"parent"),
    L_FILETYPE_OTHER => array ("img"=>"oth"));
    
function get_filetype ($filepath) {
    global $filetypes;

    if ($filepath == ".." || substr ($filepath,-3) == "/..")
        return L_FILETYPE_PARENT;
    else if (is_dir ($filepath)) 
        return L_FILETYPE_DIRECTORY;
    else {
        $ext = filesuffix ($filepath);
        reset ($filetypes);
        while (list ($filetype, $val) = each ($filetypes)) 
            if (my_in_array ($ext, $val["ext"])) 
                return $filetype;
        return L_FILETYPE_OTHER;
    }
}
    
/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

/*
    Function: get_file_array() 
    independent of AA
    
    creates a $files array, containing info about all files and directories in $path.$dirname,
    sorted by global $sort_key (from $sortable_columns) and $sort_order ('a' or 'd')
    
    Array members:
        "name" filename (without path)
        "path" filename with path relative to $path
        "dir" boolean -- is it a directory?
        "size" file size (in bytes)
        "type" file type (see $filetypes)
        "lastm" last modified date, formated
        "img" name of image file (from $filetypes, without extension)
*/

function get_file_array ($path, $dirname) {
    global $filetypes;

    endslash ($path);
    endslash ($dirname);

/*    $connid = ftp_connect ($path);
    $filesa = ftp_nlist ($connid, $dirname);
    print_r ($filesa);
*/    

    if (!is_dir ($path.$dirname)) 
        return array ();
    
    // fill the $files array
    if (!($dir = opendir($path.$dirname))) 
        echo $path.$dirname;
    else {      
        while ($filename = readdir ($dir)) {           
            // don't allow to jump higher from base dir
            if ($filename == "." || ($filename == ".." && !$dirname)) 
                continue;

            $filepath = $dirname.$filename;
            if ($filename == "..") {
                $i = strlen ($dirname)-2;
                while ($i > 0 && $dirname[$i] != "/") $i --;
                $filepath = substr ($dirname, 0, $i);
            }
            
            $filetype = get_filetype ($path.$dirname.$filename);                       
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
        closedir ($dir);
        
        // sort files
        global $sortable_columns;
        if (!$sortable_columns[$sort_key]) {
            reset ($sortable_columns); 
            $sort_key = key ($sortable_columns);
        }
        if (is_array ($files))
            uasort ($files, "compare_files");
    }
    return $files;
}

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

// returns HTML for AA file table

function file_table ($path, $dirname)
{
    global $AA_CP_Session;
    global $directory;

    $files = get_file_array ($path, $dirname);
    if (is_array ($files)) {
        // create the HTML code
        reset ($files);
        while (list (,$file) = each ($files)) {
            $href = "fileman.php3?AA_CP_Session=$AA_CP_Session&cmd=" . ($file["dir"] ? "chdir&arg[chdir]=" : "edit&arg[edit]=") . "$file[path]&fmset[directory]=$directory";
            $retval .= "<tr>
            <td>".($file[name]!=".." ? "<input type='Checkbox' name='chb[$file[path]]'>" : "&nbsp;")."</td>
            <td><a href='$href'><img src='/apc-aa/images/$file[img].gif' alt='$file[type]' border=0></a></td>
            <td><a href='$href'>$file[name]</a></td>
            <td align=right>".($file["dir"] ? "&nbsp;" : format_file_size($file["size"]))."</td>
            <td>$file[type]</td>
            <td>$file[lastm]</td>
            </tr>";
        }
    }
    return $retval;
}       
    
function is_dir_empty ($dirname) {
    if ($dir = @opendir($dirname)) {      
        while ($filename = readdir ($dir)) {           
            if ($filename != "." && $filename != "..")
                return false;
        }
    }
    else return false;
    return true;
}

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

# File upload
# returns: error description or empty string

function fileman_move_uploaded_file ($varname, $destdir, $perms) 
{
    global $err;
    
    endslash ($destdir);    
    if (!$GLOBALS[$varname]) return "No $varname?";
    # get filename and replace bad characters
    $dest_file = eregi_replace("[^a-z0-9_.~]","_",$GLOBALS[$varname."_name"]);

    # images are copied to subdirectory of IMG_UPLOAD_PATH named as slice_id
    $dirname = IMG_UPLOAD_PATH. $GLOBALS["slice_id"];
    $dirurl  = IMG_UPLOAD_URL. $GLOBALS["slice_id"];

    if( !is_dir( $destdir )) 
        return L_DIR_NOT_EXISTS;

    if( file_exists("$destdir$dest_file") )
        return L_FILE_NAME_EXISTS . $destdir . $dest_file;

    # copy the file from the temp directory to the upload directory, and test for success    

    # file uploads are handled differently in PHP >4.0.3
    list($va,$vb,$vc) = explode(".",phpversion());   # this check work with all possibilities (I hope) -
    if( ($va*10000 + $vb *100 + $vc) >= 40003 ) {    # '4.0.3', '4.1.2-dev', '4.1.14' or '5.23.1'
        if (is_uploaded_file($GLOBALS[$varname])) 
            if( !move_uploaded_file($GLOBALS[$varname], "$destdir$dest_file")) 
                return L_CANT_UPLOAD;
            else chmod ($destdir.$dest_file, $perms);
    } 
    else {   # for php 3.x and php <4.0.3
        if (!copy($GLOBALS[$varname],"$destdir$dest_file")) 
            return L_CANT_UPLOAD;
        else chmod ($destdir.$dest_file, $perms);
    }  
}    

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

function fileman_execute_command ($basedir, $directory, $cmd, $arg, $chb, $fmset) {
    global $AA_CP_Session, $err,
        // set to the name of file which should be viewed by filedit.php3
        $fe_filename,
        // used in copy template
        $fileman_dir;
   
    if (!$cmd) return;
    
    $arg = $arg[$cmd];

    // Click on directory
    if ($cmd=='chdir') 
        set_directory ($arg);

    else if ($cmd == 'edit')
        $fe_filename = $arg;

    // Create file
    else if ($cmd=='createfile') {
        if( !EReg("^[0-9a-zA-Z_.]*$", $arg)) { $err[] = L_WRONG_FILE_NAME; return; }
        if (filesuffix($arg) == "") $arg .= ".html";
        $newfile = $basedir.$directory.$arg;
        if (file_exists ($newfile)) { $err[] = L_FILE_EXISTS." ($newfilename)."; return; }
        if (!fopen ($newfile, "w")) { $err[] = L_UNABLE_TO_CREATE_FILE." $newfilename."; return; }
        chmod ($newfile, FILEMAN_MODE);
        $fe_filename = $directory.$arg;
    }

    // Create directory
    else if ($cmd=='createdir') {
        if( !EReg("^[0-9a-zA-Z_]*$", $arg)) { $err[] = L_WRONG_DIR_NAME; return; }
        $newdir = $basedir.$directory.$arg;
        mkdir ($newdir, FILEMAN_MODE);
        if (!is_dir ($newdir)) 
            $err[] = L_UNABLE_TO_CREATE_DIR." $newdirname.";
        chmod ($newdir, FILEMAN_MODE);
    }

    // Delete
    else if ($cmd=='delete' && is_array ($chb)) {
        reset ($chb);
        while (list ($arg) = each ($chb)) {
            $f = $basedir.$arg;
            if (is_dir ($f)) {
                if (!is_dir_empty ($f)) $err[] = L_FIRST_DELETE_ALL_FILES." $arg.";
                else if (!rmdir ($f)) $err[] = L_UNABLE_TO_DELETE_DIR." $arg.";
            }
            else {
                if (!unlink ($f)) $err[] = L_UNABLE_TO_DELETE_FILE." $arg.";
            }
        }
    }
        // Upload file
    else if ($cmd=='upload') {
        set_time_limit(FILEMAN_UPLOAD_TIME_LIMIT);
        $uploaderr = fileman_move_uploaded_file ("uploadarg", $basedir.$directory, FILEMAN_MODE);
        if ($uploaderr) $err[] = L_ERROR.": $uploaderr";
    }
    
    else if ($cmd=='copytmp') {
        $tmperr = fileman_copy_template (FILEMAN_BASE_DIR."templates/".$arg, FILEMAN_BASE_DIR.$fileman_dir);
        if ($tmperr) $err[] = L_ERROR.": $tmperr";
    }

    // Single file actions:
    else if ($filename = $fmset['filename']) {

        // Save changes
        if ($cmd=='savefile') {
            $f = $basedir.$filename;
            $filedes = fopen ($f,"w");
            if (!$filedes) $err[] = L_UNABLE_TO_WRITE." ($filename).";
            else {
                if (get_magic_quotes_gpc()) 
                    $arg = stripslashes ($arg);                
                $bytes = fwrite ($filedes, $arg);
                if ($bytes == -1) $err[] = L_ERROR_WRITING." $filename.";
                fclose ($filedes);
            }
        }

        // Rename
        else if ($cmd=='rename' && $filename) {
            make_secure ($renamearg);    
            $newname = dirname ($basedir.$filename)."/".$arg;
            if (file_exists ($newname)) $err[] = L_FILE_ALREADY_EXISTS." ($arg).";
            else if (!rename ($basedir.$filename, $newname)) $err[] = L_UNABLE_RENAME." $filename.";
        }
    }
}

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */ 

/* returns a list of filenames including subdirectories' entries (like dir1/dir2/filename1.txt etc.) with path relative to $mydir */

function get_files_subtree ($mydir) {
    endslash ($mydir);
    if (!is_dir ($mydir)) return array ();
    if (!($dir = opendir ($mydir)))
        return array ();
    else {
        while ($filename = readdir ($dir)) {
            if ($filename != "." && $filename != "..") {
                if (!is_dir ($mydir.$filename)) 
                    $retval[] = $filename;
                else {
                    $sub = get_files_subtree ($mydir.$filename);
                    reset ($sub);
                    while (list (,$subfn) = each ($sub)) 
                        $retval[] = $filename."/".$subfn;
                }
            }
        }
    }
    return $retval;
}

function fileman_copy_template ($srcdir, $dstdir) {
    global $slice_id;
    
    $aliases = array (
        "_#SLICE_ID" => $slice_id);
    
    $db = new DB_AA;
    $db->query ("SELECT name FROM slice WHERE id = '".q_pack_id ($slice_id)."'");
    $db->next_record();
    $aliases["_#SLICNAME"] = $db->f('name'); 

    if (!is_dir ($srcdir)) return L_ERR_WRONG_DIR." ($srcdir)";
    $files = get_files_subtree ($srcdir);
    reset ($files);
    while (list (,$file) = each ($files)) 
        if (file_exists ($dstdir."/".$file)) 
            $errfiles[] = $file;
    if (is_array ($errfiles)) 
        return L_SOME_FILES_EXIST . " (".join(", ",$errfiles).").";
    reset ($files);
    while (list (,$file) = each ($files)) {
        $ft = get_filetype ($srcdir."/".$file);
        if ($ft == L_FILETYPE_HTML || $ft == L_FILETYPE_TEXT) {
            $fd = fopen ($dstdir."/".$file, "w");
            if (!$fd) return L_UNABLE_TO_CREATE_FILE." $dstdir/$file.";
            $fcontent = file ($srcdir."/".$file);
            reset ($fcontent);
            while (list (,$frow) = each ($fcontent)) {
                reset ($aliases);
                while (list ($alias,$replace) = each ($aliases)) 
                    $frow = str_replace ($alias, $replace, $frow);
                //if (get_magic_quotes_gpc()) $frow = stripslashes ($frow);
                fwrite ($fd, $frow);
            }
        }
        else copy ($srcdir."/".$file, $dstdir."/".$file);
        chmod ($dstdir."/".$file, FILEMAN_MODE);
    }        
}    

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */ 

// J A V A S C R I P T 

$fileman_js = "
    <script language=javascript>
    <!--
        function SelectVis (form, prefix, state) {
            var elem = document.forms[form].elements;
            for( var i=0; i < elem.length; i++ )
                if( elem[i].name.substring(0,prefix.length) == prefix)
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
                if (confirm('".L_SURE_TO_DELETE."'))
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

