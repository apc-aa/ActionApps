<? 
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

/*  Function: translate_files
    Purpose:  translates files using L_ language constants to _m() function calls
              and writes a log file with constants to help to prepare language files for mgettext
    Parameters: $old_lang_file -- full file name of the file with L_ language constants
                                  a file with the same filename will be created in $src_dir,
                                    this is the log file mentioned above
                $src_dir -- all files from this directory will be processed
                $dst_dir -- here will be the translated files saved
*/

function translate_files ($old_lang_file, $src_dir, $dst_dir)
{
//    set_time_limit(10000);
    
    include $old_lang_file;
    
    create_log ($old_lang_file, $dst_dir);

    $consts = get_defined_constants();
    // we want to replace first L_NO_EVENT and only later L_NO
    krsort ($consts);  
    
    reset ($consts);
    while (list ($name, $value) = each($consts)) {
        if (substr ($name,0,2) != "L_") 
            unset ($consts[$name]);
        else $consts [$name] = "_m(\"".str_replace(
                array ('"',"\n","\r"),
                array ('\\"',"\\n",""),
                $value)."\")";
    }
   
    $dir = opendir ($src_dir);
    while ($file = readdir ($dir)) {
        if (is_dir ($src_dir.$file))
            continue;
        echo $file."<br>";
        $content = file ($src_dir.$file);
        $fd = @fopen ($dst_dir.$file, "w");
        chmod ($dst_dir.$file, 0777);
        
        if (!$fd) echo " write permission denied<br>";
        if (!is_array($content) || !$fd)
            continue;
            
        foreach ($content as $row) {
            for (reset ($consts); $name = key ($consts); next ($consts)) {
                // first try the quick search
                if (strstr ($row, $name)) {
                    echo HTMLentities($row)." => ";
                    // now replace only when it is not a part of a longer name
                    $row = preg_replace ("'([^A-Z0-9_])".$name."([^A-Z0-9_])'si", "\\1".$consts[$name]."\\2", $row);
                    echo HTMLentities($row)."<br>";
                }
            }
                    
            fwrite ($fd, $row);
        }       
        
        fclose ($fd);
    }
    closedir ($dir);    
}

function isidletter ($c) 
{ return ($c >= 'a' && $c <= 'z') 
      || ($c >= 'A' && $c <= 'Z') 
      || ($c >= '0' && $c <= '9')
      || ($c == '_')
      || (ord ($c) > 127);
}

function isspace ($c)
{ return ($c == ' ' || $c == '\t' || $c == '\n' || $c == '\r'); }

// -------------------------------------------------------------------------------------

/*  Function: collect_messages
    Purpose:  goes through given files and finds all _m () calls
              Does not recognize commentaries (which may be the desired behavior).
*/  

function collect_messages ($files_base_dir, $files, &$messages, &$warnings)
{
    /* $pos is position on row, -1 when not inside some _m ()
       $next_needed
    */
    
    reset ($files);
    while (list (,$filename) = each ($files)) {
        $content = file ($files_base_dir.$filename);
        $filetext = "";
        reset ($content);        
        while (list ($irow, $row) = each ($content)) {
            $row_start [$irow+1] = strlen ($filetext);
            $filetext .= $row;
        }
        
        $quotes = 0;
        $irow = 1;
        if ($debug) echo "<br><br><hr>File $filename";
        
        for ($pos = 0; $pos < strlen ($filetext); $pos ++) {
            if ($row_start [$irow+1] && $row_start[$irow+1] <= $pos) 
                $irow ++;            
            
            if (strchr ("\"'", $filetext[$pos])) { 
                if ($pos == 0 || $filetext[$pos-1] != "\\") {
                    if (!$quotes) 
                        $quotes = $filetext[$pos]; 
                    else if ($quotes == $filetext[$pos])
                        $quotes = 0;
                }
            }
            
            if ($find == 0)
                $message = "";

            switch ($find) {
            // outside _m()
            case 0:
                if (!$quotes
                  && ($pos == 0 || !isidletter ($filetext[$pos-1]))
                  && $filetext[$pos] == '_' 
                  && $filetext[$pos+1] == 'm' 
                  && !isidletter ($filetext[$pos+2])) {
                    
                    // _m was the whole identifier
                    $find = 1;
                    $pos ++;
                }
                break;
            // after _m
            case 1: 
                if (isspace ($filetext[$pos]))
                    continue;
                else if ($filetext[$pos] == '(') {
                    $find = 2;
                }
                else $find = 0;
                break;
            // after _m (
            case 2:
                if (!$quotes) {
                    if (isspace ($filetext[$pos]))
                        continue;
                    else {
                        $warnings[] = "$filename, row $irow: bad syntax after _m (";
                        $find = 0;
                    }
                }
                else {
                    $find = 3;
                    $quotes_start = $quotes;
                }
                break;
            // inside message, e.g. after _m ( " or after _m ( "Hello" . "
            case 3:
                if ($quotes) {
                    $message_part .= $filetext[$pos];
                    if ($filetext[$pos] == '$' && $quotes == '"'
                     && $filetext[$pos-1] != "\\") 
                        $warnings[] = "$filename, row $irow: using variable in _m is not allowed";
                }
                else {                    
                    $to_be_evaled = "\$message .= ".$quotes_start.$message_part.$quotes_start.";";
                    $message_part = "";
                    eval ($to_be_evaled);
                    $find = 4;
                }
                break;
            // after message, e.g. _m ( "Hello"
            case 4:
                if (isspace ($filetext[$pos]))
                    continue;
                else if ($filetext[$pos] == ".") 
                    $find = 2;
                else if ($filetext[$pos] == "," || $filetext[$pos] == ")") {
                    $messages [$message]["code"][] = $filename.", row ".$irow;
                    $find = 0;
                }
                else {
                    $warnings[] = "$filename, row $irow: bad syntax inside _m ()";
                    $find = 0;
                }
                break;
            }            
        }
    }   
}

function aagettext ($lang_files, $files_base_dir, $files) {
    collect_messages ($files_base_dir, $files, $messages, $warnings);
    if (is_array ($warnings)) {
        echo "<Br>Warnings:<br>";
        echo join ("<br>", $warnings);
    }
    if (is_array ($messages)) {
        echo "<br><br>Messages: ";
        print_r ($messages);
    }
}

                      
function create_log ($old_lang_file, $dst_dir)
{    
    $fd = @fopen ($dst_dir.basename($old_lang_file), "w");
    chmod ($dst_dir.basename($old_lang_file), 0777);
    fwrite ($fd,"# this is a log file of the language translation on ".date("d.j.Y H:i")."\n\n");
    $consts = get_defined_constants();
    reset ($consts);
    while (list ($name, $value) = each($consts)) {
        if (substr ($name,0,2) == "L_" && $value) {
            $value = str_replace(
                array ('"',"\n","\r"),
                array ('\\"',"\\n",""),
                $value);
            fwrite ($fd, "_m[\"$value\"] = \"$name\";\n");
        }
    }
    fclose ($fd);
}    

$mgettext_langs = array ("en","cz","de","sk","ro","es");


/*  Function: mgettext
    Alias:    _m(), see below
    Purpose:  basic function to get translations
              writes new language strings at the end of the language file 
    Params:   $id -- text to be translated
              $params -- you may use %1,%2,... in $id and supply an array of params,
                         which are substituted for %i. 
                         If you want to print %i verbatim, use \%i.                         
              Example: _m("Hello %1, how are you?",array($username))
    Return value: if translation in the active language ($mgettext_lang) does not yet exist,
                  returns $id    
*/

function mgettext ($id, $params = 0) {
    global $mgettext_lang, $mgettext_strings, $mgettext_langs;
    
    $retval = $mgettext_strings[$id][$mgettext_lang];
    if (!$retval) {
        $retval = $id;
        if (!$mgettext_strings[$id]) {
            // Add the string to language file
            $fd = @fopen ($GLOBALS[AA_INC_PATH]."../php_rw/mgettext_lang.php3","ab");
            if ($fd) {
                fputs ($fd, "\r\n");
                fputs ($fd, "\$mgettext_strings \r\n");
                fputs ($fd, "[\"".str_replace("\"","\\\"", $id)."\"] = array (\r\n");
                reset ($mgettext_langs);
                $first = true;
                while (list (,$lang) = each ($mgettext_langs)) {
                    if (!$first) fputs ($fd, ",\r\n");
                    $first = false;
                    $txt = $lang == "en" ? $id : "";
                    fputs ($fd, "    \"$lang\"=>\"$txt\"");
                }
                fputs ($fd, ");\r\n");            
                fclose ($fd);
            }
        }
    }
    
    if (is_array ($params)) {
        $foo = "#$&*-";
        $retval = str_replace ("\%", $foo, $retval);
        for ($i = 0; $i < count ($params); $i ++) 
            $retval = str_replace ("%".($i+1), $params[$i], $retval);
        $retval = str_replace ($foo, "%", $retval);
    }
        
    return $retval;
} 

/* mgettext alias */

function _m ($id, $params = 0) {
    return mgettext ($id, $params);
}

/*  Function: mgettext_constants2strings
    Purpose:  replaces language constants to _m function calls
    Params:   langfiles - list of file names (located in include/) with language constants
              destdir - where processed files will be stored
              dirs - directories to be processed

    Example:
    $langfiles = array ("en_news_lang.php3" => "en", "cz_news_lang.php3" => "cz", ...)
    
    TODO: the function is not at all working!!!!!! It was used with another params and 
          now is under construction.
*/

function mgettext_constants2strings ($langfile, $destdir, $dirs) 
{   
    $constants = file ($langfile);
    reset ($constants);
    while (list (,$row) = each ($constants)) {        
        if (strstr ($row,"define")) {
            $quotes = 0;
            $name = $value = "";
            for ($i = strpos ($row,"define") + 7; $i < strlen ($row); $i ++) {
                if ($row[$i] == '"' && $row[$i-1] != '\\')
                    $quotes ++;
                else if ($quotes == 1) $name .= $row[$i];
                else if ($quotes == 3) $value .= $row[$i];
            }
            $const[$name] = $value;
        }
    }
    reset ($mgettext_files);
    while (list ($dir, $files) = each ($mgettext_files)) {
        reset ($files);
        if (!is_dir ($destdir.$dir))
            mkdir ($destdir.$dir, 508);
        while (list (,$file) = each ($files)) {
            echo "Translating $filename to $destdir$dir$file<br>";
            $filename = $mgettext_basedir.$dir.$file;
            $content = file ($filename);
                $fd = fopen ($destdir.$dir.$file, "w");
            reset ($content);
            while (list (,$row) = each ($content)) {
                reset ($const);
                while (list ($name,$value) = each ($const))
                    if ($name != "")
                        $row = preg_replace ("'([^A-Z0-9_])".$name."([^A-Z0-9_])'si", "\\1_m(\"$value\")\\2", $row);
                fwrite ($fd, $row);
            }
            fclose ($fd);
            chmod ($destdir.$dir.$file, 508);            
        }
    }          
}
?>
