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

if (!isset($LANGUAGE_CHARSETS))
    require "../../include/constants.php3";

function lang_file_header ($fd, $lang)
{    
    fputs ($fd, "<?php\n");
    fputs ($fd, "# \$Id$\n");
    fputs ($fd, "# Language: ".strtoupper($lang)."\n");
    fputs ($fd, "# This file was automatically created by the Mini GetText environment\n");
    fputs ($fd, "# on ".date("j.n.Y H:i")."\n\n");
    fputs ($fd, "# Do not change this file otherwise than by typing translations on the right of =\n\n");
    fputs ($fd, "# Before each message the places where it was used are noted.\n");
    fputs ($fd, "# Look there if you are not sure how to translate it.\n");
    fputs ($fd, "\n");
    fputs ($fd, "\$mgettext_lang = \"$lang\";\n");
    fputs ($fd, "\n");
}

// -------------------------------------------------------------------------------------

/* Function: xmgettext
   Purpose:  Updates mgettext language files. Goes through given files and finds all uses of _m().
   Params:   $lang_files -- mgettext language files mask, the language name is replaced by ??,
                            with full path name, e.g. .../include/lang/??_news_lang.php3
                            Tries all languages from $mgettext_langs_list in place of ??
                  WARNING:  PHP rw access must be enabled to this lang files          
             $files -- list of files to go through, path relative to $files_base_dir  
                       if you specify only folder (must be terminated by backslash "/" !),
                            all files from that folder are used       
*/             

function xmgettext ($logfile, $lang_files, $files_base_dir, $files, $chmod = 0664, $stop_on_warning = true,
    $old_logs = "") {
    global $LANGUAGE_CHARSETS;

    set_time_limit(10000);
    collect_messages ($logfile, $files_base_dir, $files, $messages, $warnings);
    
    if (is_array ($warnings)) {
        echo "<Br>Warnings:<br>";
        echo join ("<br>", $warnings);
        if ($stop_on_warning) exit; 
    }
    
    reset ($LANGUAGE_CHARSETS);
    while (list ($lang) = each ($LANGUAGE_CHARSETS)) {
        $langfile = str_replace ("??", $lang, $lang_files);
        // read the language constants
        $_m = "";
        if (file_exists ($langfile))
            include $langfile;
        if ($old_logs)
            add_old_translations ($old_logs, $lang, &$_m);            
            
        // write the file 
        $fd = fopen ($langfile, "w");
        lang_file_header ($fd, $lang);

        if (is_array ($_m)) {
            // unused messages
            fputs ($fd, "# Unused messages\n");
            reset ($_m);
            while (list ($message, $tr) = each ($_m)) 
                if (!isset ($messages[$message]) && $tr) 
                    fputs ($fd, "\$_m[".prepare_string($message)."]\n".
                        "  = ".prepare_string($tr).";\n");
            fputs ($fd, "# End of unused messages\n\n");
        }

        // messages with code location description
        reset ($messages);
        while (list ($message, $params) = each ($messages)) {            
            reset ($params["code"]);
            while (list ($filename,$rows) = each ($params["code"]))
                fputs ($fd, "# $filename, row ".join(", ",$rows)."\n");
            fputs ($fd, "\$_m[".prepare_string($message)."]\n".
                "  = ".prepare_string($_m[$message]).";\n");
            fputs ($fd, "\n");
        }
        fputs ($fd, "?>\n");
        fclose ($fd);
        chmod ($langfile, $chmod);
    }
}

// -------------------------------------------------------------------------------------

// adds old translations (L_... language file) from logs created from the old language files
function add_old_translations ($log_files, $lang, &$_m) {
    include str_replace ("??","en",$log_files);
    $en_log = $_log;
    include str_replace ("??",$lang,$log_files);
    reset ($_log);
    while (list ($msg, $name) = each ($en_log)) 
        $_m[$msg] = $_log[$name];    
}

// -------------------------------------------------------------------------------------

/*  Function: collect_messages
    Purpose:  goes through given files and finds all _m () calls
              Does not recognize commentaries (which may be the desired behavior).
    Params:   $files -- list of files to go through, path relative to $files_base_dir          
    Return values: $messages -- array with occurences of messages,
                    1st index is the message, 2nd index is "code",
                    3rd index is filename, 4th index is row_number
                   $warnings -- wrong syntax warnings                   
*/  

function collect_messages ($logfile, $files_base_dir, $files, &$messages, &$warnings)
{    
    // creates a log file allowing to process lots of files 
    
    if (file_exists ($logfile)) 
        include $logfile;

    reset ($files);
    while (list (,$fname) = each ($files)) {
        if (! is_dir ($files_base_dir.$fname)) 
            $filelist = array ($fname);
        else {
            $dir = opendir ($files_base_dir.$fname);
            while ($file = readdir ($dir)) {
                if (!is_dir ($files_base_dir.$fname.$file))
                    $filelist[] = $fname.$file;
            }
            closedir ($dir);
        }
        
        reset ($filelist);
        while (list (,$filename) = each ($filelist)) {            
            $messages = array ();
            $warnings = array ();
            if (!$processed_files[$filename]) {
                collect_messages_from_file ($files_base_dir.$filename, $messages, $warnings);
                $msgstr = str_replace ("'", "\\'", serialize ($messages));
                $wrnstr = str_replace ("'", "\\'", serialize ($warnings));
                $fd = fopen ($logfile, "a");
                chmod ($logfile, 0664);
                fwrite ($fd, "<?php \$processed_files[\n\n'$filename']=array ('messages'=>'$msgstr','warnings'=>'$wrnstr');?>");            
                fclose ($fd);
            }
        }
    }

    // go through the log file
    
    $messages = "";
    $warnings = "";
    include $logfile;
    reset ($processed_files);
    while (list (,$msgwrn) = each ($processed_files)) {
        $msg = unserialize ($msgwrn["messages"]);
        reset ($msg);
        while (list ($message,$code) = each ($msg)) {
            reset ($code["code"]);
            while (list ($filename,$rows) = each ($code["code"])) {
                reset ($rows);
                while (list (,$row) = each ($rows)) 
                    $messages [$message]["code"][$filename][] = $row;
            }
        }
        $wrn = unserialize ($msgwrn["warnings"]);
        reset ($wrn);
        while (list (,$warning) = each ($wrn))
            $warnings[] = $warning;
    }
    
    unlink ($logfile);
}        
        
// -------------------------------------------------------------------------------------
        
function collect_messages_from_file ($filename, &$messages, &$warnings)
{
    $content = file ($filename);
    $filetext = "";
    reset ($content);        
    while (list ($irow, $row) = each ($content)) {
        $row_start [$irow+1] = strlen ($filetext);
        $filetext .= $row;
    }
	if (!strstr ($filetext, "_m")) 
		return;
		    
    $quotes = "0";
    $comment = "0";
    $irow = 1;

    if ($debug) echo "<br><br><hr>File $filename";
    
    for ($pos = 0; $pos < strlen ($filetext); $pos ++) {
        if ($row_start [$irow+1] && $row_start[$irow+1] <= $pos) 
            $irow ++;            
        
        // comments of all types (#, //, /*)
        
        if ($comment == "0" && $quotes == "0") {
            if ($filetext[$pos] == "#")
                $comment = "#";
            else if (substr ($filetext, $pos, 2) == "//") {
                $comment = "#";
                $pos ++;
            }
            else if (substr ($filetext, $pos, 2) == "/*") {
                $comment = "/*";
                $pos ++;
            }
        }            
        else if ($comment == "#" && $filetext[$pos] == "\n")
            $comment = "0";                
        else if ($comment == "/*" && substr ($filetext, $pos, 2) == "*/") {
            $comment = "0";
            $pos ++;
        }
        
        // quotes of both types (", ')
                                        
        if ($comment == "0" && strchr ("\"'", $filetext[$pos])) { 
            if ($pos == 0 || $filetext[$pos-1] != "\\") {
                if (!$quotes) 
                    $quotes = $filetext[$pos]; 
                else if ($quotes == $filetext[$pos])
                    $quotes = "0";
            }
        }
        
        if ($find == 0)
            $message = "";
            
        //if ($find) echo $find;
        //if ($quotes != $old_quotes) echo "row $irow: $quotes<br>";
        $old_quotes = $quotes;

        if (!$comment) 
        switch ($find) {
        // outside _m()
        case 0:
            if (($pos == 0 || !isidletter ($filetext[$pos-1]))
              && $filetext[$pos] == '_' 
              && $filetext[$pos+1] == 'm' 
              && !isidletter ($filetext[$pos+2])) {
                if ($quotes == "0") {                    
                    // _m was the whole identifier
                    $find = 1;
                    $pos ++;
                }
                //else echo "<br>row $irow: _m inside quotes $quotes<br>";
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
            if ($quotes == "0") {
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
            if ($quotes != "0") {
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
                ;
            else if ($filetext[$pos] == ".") 
                $find = 2;
            else if ($filetext[$pos] == "," || $filetext[$pos] == ")") {
                $messages [$message]["code"][$filename][] = $irow;
                $find = 0;
            }
            else {
                $warnings[] = "$filename, row $irow: bad syntax inside _m () after $message";
                $find = 0;
            }
            break;
        }            
    }
}

// -------------------------------------------------------------------------------------

// prepares a string to be printed in double quotes into a file

function prepare_string ($str) {
    $str = str_replace ("\\", "\\\\", $str);
    $str = str_replace ('"', '\\"', $str);
    $str = str_replace ("$", "\\$", $str);
    // write line ends as new lines, but handle two line ends in other way
    while (strstr ($str, "\n\n"))
        $str = str_replace ("\n\n", "\\n\n", $str);
    $str = str_replace ("\n", "\\n\"\n   .\"", $str);
    return '"'.$str.'"';
}


// -------------------------------------------------------------------------------------
                      
function isidletter ($c) 
{ return ($c >= 'a' && $c <= 'z') 
      || ($c >= 'A' && $c <= 'Z') 
      || ($c >= '0' && $c <= '9')
      || ($c == '_')
      || (ord ($c) > 127);
}

function isspace ($c)
{ return strchr (" \t\r\n", $c); }

function filepath ($filename) {
    if (!strstr ($filename,"/")) return "./";
    $i = strlen($filename);
    while ($filename[$i] != "/") $i --;
    return substr ($filename,0,$i+1);
}

?>
