<?php 

// params: column header, default sort order
$sortable_columns = array (
    "name"=>array("label"=>"Name","sort"=>"a"), 
    "size"=>array("label"=>"Size","sort"=>"a"),
    "type"=>array("label"=>"Type","sort"=>"a"),
    "lastm"=>array("label"=>"Last modified","sort"=>"d"));

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

/* 
    independt on AA
    
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
    
function get_file_array ($path, $dirname)
{
    $filetypes = array (
        "HTML file" => array ("img"=>"html", "ext"=>array ("shtml","html","htm")),
        "Web file" => array ("img"=>"ie", "ext"=>array ("php","php3","asp")),
        "Image file" => array ("img"=>"image2", "ext"=>array("gif","jpg","jpeg","tiff","img")),
        "Text file" => array ("img"=>"txt", "ext"=>array("txt")),
        "Directory" => array ("img"=>"folder"), 
        "Parent" => array ("img"=>"parent"),
        "Other" => array ("img"=>"oth"));

    endslash ($path);
    endslash ($dirname);

    // fill the $files array
    if ($dir = @opendir($path.$dirname)) {      
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
               
            // find file type
            if ($filename == "..")
                $filetype = "Parent";
            else if (is_dir ($path.$filepath)) 
                $filetype = "Directory";
            else if (!strstr ($filename,"."))
                $filetype = "Other";
            else {
                $i = strlen ($filename);
                while ($filename[$i] != ".") $i --;
                $ext = substr ($filename, $i+1);
                reset ($filetypes);
                while (list ($filetype, $val) = each ($filetypes)) 
                    if (my_in_array ($ext, $val["ext"])) break;
                if (!$filetype) $filetype = "Other";
            }
            
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
    }
    closedir ($dir);
    
    // sort files
    global $sortable_columns;
    if (!$sortable_columns[$sort_key]) {
        reset ($sortable_columns); 
        $sort_key = key ($sortable_columns);
    }
    uasort ($files, "compare_files");
    return $files;
}

// returns HTML for AA file table

function file_table ($path, $dirname)
{
    global $AA_CP_Session;

    $files = get_file_array ($path, $dirname);
    // create the HTML code
    reset ($files);
    while (list (,$file) = each ($files)) {
        $retval .= "<tr>
        <td>".($file[name]!=".." ? "<input type='Checkbox' name='chb[$file[path]]'>" : "&nbsp;")."</td>
        <td><img src='/apc-aa/images/$file[img].gif' border=0></td>
        <td><a href='fileman.php3?AA_CP_Session=$AA_CP_Session&".($file["dir"] ? "chdir" : "edit")."=$file[path]'>$file[name]</a></td>
        <td align=right>".($file["dir"] ? "&nbsp;" : format_file_size($file["size"]))."</td>
        <td>$file[type]</td>
        <td>$file[lastm]</td>
        </tr>";
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
            document.forms[formname][name].value = 1;
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
                if (!confirm('Are you sure you want to delete the selected files and folders?'))
                    break;
                else submitCommand ('delete');
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

