<?php
/**
* Updates mini-gettext language files.
* @package MiniGetText
* @version $Id$
* @author Jakub Adamek, Econnect, January 2003
* @copyright Copyright (C) 1999-2003 Association for Progressive Communications
*/
/*
Copyright (C) 1999-2003 Association for Progressive Communications
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

/** Prints language file header. */
function lang_file_header ($fd, $lang)
{
    fputs ($fd, "<?php\n");
    fputs ($fd, "# \$Id$\n");
    fputs ($fd, "# Language: ".strtoupper($lang)."\n");
    fputs ($fd, "# This file was created automatically by the Mini GetText environment\n");
    fputs ($fd, "# on ".date("j.n.Y H:i")."\n\n");
    fputs ($fd, "# Do not change this file otherwise than by typing translations on the right of =\n\n");
    fputs ($fd, "# Before each message there are links to program code where it was used.\n");
    fputs ($fd, "\n");
    fputs ($fd, "\$mgettext_lang = \"$lang\";\n");
    fputs ($fd, "\n");
}

// -------------------------------------------------------------------------------------
/**
* Updates mini-gettext language files. Goes through given files and finds all uses of _m().
*
* No variables must appear in the _m() calls, because xmgettext can't resolve them.
* E.g., _m("You are $age years old") is a _m() syntax error. You should use
* _m("You are %1 years old", array ($age)) instead.
*
* @param string $logfile File name where xmgettext stores info allowing to continue
*                        its work on page reload.
* @param string $lang_files Full path to language files, with ?? instead of language name,
*                           e.g. /www/htdocs/aa.ecn.cz/apc-aa/include/lang/??_news_lang.php3.
*                           Goes through all languages from @c $mgettext_langs_list.
*                 WARNING:  PHP read-write access must be enabled to these lang files.
* @param string $files_base_dir Base dir used for $files.
* @param array  $files  List of files in which to look for _m() occurences.
*                       Path relative to @c $files_base_dir.
*                       Folders may be included in the list (must be terminated by backslash "/" !),
*                       all files in that folders are used.
*                       Skip files by adding minus sign before the file name (e.g. "-include/mgettext.php3").
* @param int    $chmod  Permissions to assign to the language files.
* @param bool   $stop_on_warning Should the script stop when it finds a _m() syntax error?
* @param string $old_logs Full path to logs created from old language files by the function create_logs().
*                         If empty, no logs are used.
* $param bool   $add_source_links Should xmgettext add commentary specifying where was the message used?
*/
function xmgettext ($lang_list, $logfile, $lang_files, $files_base_dir, $files, $chmod = 0664, $stop_on_warning = true,
    $old_logs = "", $add_source_links = true) {
    set_time_limit(10000);
    collect_messages ($logfile, $files_base_dir, $files, $messages, $warnings);

    if (is_array ($warnings)) {
        echo "<Br>Warnings:<br>";
        echo join ("<br>", $warnings);
        if ($stop_on_warning) exit;
    }

    reset ($lang_list);
    while (list ($lang) = each ($lang_list)) {
        $langfile = str_replace ("??", $lang, $lang_files);
        // read the language constants
        $_m = "";
        if (file_exists ($langfile)) {
            require $langfile;
        }
        if ($old_logs)
            add_old_translations ($old_logs, $lang, $_m, $other_translations);

        // write the file
        $fd = fopen ($langfile, "wb");
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

        // messages with code location description and other translations (from old lang files)
        if (is_array ($messages)) {
            reset ($messages);
            while (list ($message, $params) = each ($messages)) {
                reset ($params["code"]);
                if ($add_source_links)
                    while (list ($filename,$rows) = each ($params["code"]))
                        fputs ($fd, "# $filename, row ".join(", ",$rows)."\n");

                // other translations
                if (is_array ($other_translations) && $other_translations[$message]) {
                    reset ($other_translations[$message]);
                    $other_join = "";
                    while (list ($other) = each ($other_translations[$message]))
                        $other_join[] = $other;
                    fputs ($fd, "# other translations: ".join (", ", $other_join)."\n");
                }

                $mmsg = $_m[$message];
                if ($message == $mmsg)
                    $mmsg = "";
                fputs ($fd, "\$_m[".prepare_string($message)."]\n".
                    "  = ".prepare_string($mmsg).";\n");
                fputs ($fd, "\n");
            }
        }
        fputs ($fd, "?>\n");
        fclose ($fd);
        chmod ($langfile, $chmod);
    }
}

// -------------------------------------------------------------------------------------

/** Adds translations from logs from old language files to $_m. */
function add_old_translations ($log_files, $lang, &$_m, &$other_translations) {
    $file = str_replace ("??","en",$log_files);
    if (!file_exists ($file))
        echo "ERROR: $file does not exist<br>";
    else {
        $_log = "";
        require $file;
        $en_log = $_log;
        $_log = "";
        require str_replace ("??",$lang,$log_files);
        reset ($en_log);
        while (list ($msg, $names) = each ($en_log)) {
            reset ($names);
            while (list (, $name) = each ($names)) {
                if (!$_m[$msg])
                    $_m[$msg] = $_log[$name];
                else if ($_m[$msg] != $_log[$name])
                    $other_translations[$msg][$_log[$name]] = 1;
            }
        }
    }
}

/** creates php string which could be printed into ph apostroph construct */
function php_string($text) {
    $text = str_replace ("\\", "\\\\", $text);
    return  str_replace ("'",  "\\'",  $text);
}

/** add file to $skiplist or $filelist */
function mark_file_4_processing($dirname, $fname, $skip, &$skiplist, &$filelist) {
    if ($skip OR (substr($fname,0,2)=='.#')) {   // ignore also CVS backups
        $skiplist[$dirname.$fname] = 1;
    } else {
        $filelist[$dirname.$fname] = 1;
    }
}


// -------------------------------------------------------------------------------------
/**
* Goes through given files and finds all _m() calls. Skips quoted strings, but not
* commentaries.
*
* @param string $logfile       File name where collect_messages stores its results.
*                       This file also allows to continue the work on page reload.
* @param array $files (input) list of files to go through, path relative to $files_base_dir
* @param array $messages (output) array with info about occurences of the messages,
*                    $messages [message_text]["code"][filename][row_number]
* @param array $warnings (output) wrong syntax warnings
*/
function collect_messages ($logfile, $files_base_dir, $files, &$messages, &$warnings)
{
    // creates a log file allowing to process lots of files

    if (file_exists ($logfile)) {
        require $logfile;
    }
    reset ($files);
    while (list (,$fname) = each ($files)) {
        $skip = ($fname[0] == "-");
        if ($skip)
            $fname = substr ($fname, 1);
        echo "$fname<br>";
        if (! is_dir($files_base_dir.$fname)) {
            mark_file_4_processing('', $fname, $skip, $skiplist, $filelist);
        }
        else {
            $dir = opendir ($files_base_dir.$fname);
            while ($file = readdir ($dir)) {
                if (!is_dir($files_base_dir.$fname.$file)) {
                    mark_file_4_processing($fname, $file, $skip, $skiplist, $filelist);
                }
            }
            closedir ($dir);
        }
    }

    if (is_array ($skiplist)) {
        reset ($skiplist);
        while (list ($skipfile) = each ($skiplist))
            unset ($filelist[$skipfile]);
    }

    reset ($filelist);
    while (list ($filename) = each ($filelist)) {
        $messages = array ();
        $warnings = array ();
        if (!$processed_files[$filename]) {
            collect_messages_from_file ($files_base_dir, $filename, $messages, $warnings);
            $msgstr = php_string(serialize ($messages));
            $wrnstr = php_string(serialize ($warnings));
            $fd = fopen ($logfile, "ab");
            chmod ($logfile, 0664);
            fwrite ($fd, "\n<?php \$processed_files[\n\n'$filename']=array ('messages'=>'$msgstr','warnings'=>'$wrnstr');?>");
            fclose ($fd);
        }
    }

    // go through the log file

    $messages = "";
    $warnings = "";
    require $logfile;
    reset ($processed_files);
    while (list (,$msgwrn) = each ($processed_files)) {
        $msg = unserialize ($msgwrn["messages"]);
        if (is_array ($msg)) {
            reset ($msg);
            while (list ($message,$code) = each ($msg)) {
                reset ($code["code"]);
                while (list ($filename,$rows) = each ($code["code"])) {
                    reset ($rows);
                    while (list (,$row) = each ($rows))
                        $messages [$message]["code"][$filename][] = $row;
                }
            }
        }
        $wrn = unserialize ($msgwrn["warnings"]);
        if (is_array ($wrn)) {
            reset ($wrn);
            while (list (,$warning) = each ($wrn))
                $warnings[] = $warning;
        }
    }

    unlink($logfile);
}

// -------------------------------------------------------------------------------------

/**  Parses the file to find all _m() calls. See more info in collect_messages.
*/
function collect_messages_from_file ($base_dir, $filename, &$messages, &$warnings)
{
    $content = file ($base_dir.$filename);
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

/** Prepares a string to be printed in double quotes into a file. */
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

/** strips path from file name */
function filepath ($filename) {
    if (!strstr ($filename,"/")) return "./";
    $i = strlen($filename);
    while ($filename[$i] != "/") $i --;
    return substr ($filename,0,$i+1);
}

?>
