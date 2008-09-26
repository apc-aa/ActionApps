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

/** abstract class as base for different language file formats */
class mgettext_writer {
    var $fd;   // file

    function mgettext_writer($langfile) {
        $this->fd = fopen($langfile, "wb");
    }

    function write_header($lang)     {}
    function write_pair(&$pair)      {}
    function write_footer()          {}
    function write_comment($comment) {}
    /** Prepares a string to be printed in double quotes into a file. */
    function prepare_string($str)    {}
    function close()                 {}
}


/** Writer class for PHP language files */
class mgettext_writer_php extends mgettext_writer {

    /** constructor */
    function mgettext_writer_php($langfile) {
        echo "<br>open $langfile";
        $this->fd = fopen($langfile, "wb");
    }

    /** Prints language file header. */
    function write_header($lang) {
        fputs($this->fd, "<?php\n");
        fputs($this->fd, "// \$Id$\n");
        fputs($this->fd, "// Language: ".strtoupper($lang)."\n");
        fputs($this->fd, "// This file was created automatically by the Mini GetText environment\n");
        fputs($this->fd, "// on ".date("j.n.Y H:i")."\n\n");
        fputs($this->fd, "// Do not change this file otherwise than by typing translations on the right of =\n\n");
        fputs($this->fd, "// Before each message there are links to program code where it was used.\n");
        fputs($this->fd, "\n");
        fputs($this->fd, "\$mgettext_lang = \"$lang\";\n");
        fputs($this->fd, "\n");
    }

    /** Write language constructs */
    function write_pair(&$pair) {
        $text = "";
        if ($pair['comments']) {
            foreach ( $pair['comments'] as $comment ) {
                $text .= "// $comment\n";
            }
        }
        $text .= "\$_m[".$this->prepare_string($pair['message'])."]\n = ".$this->prepare_string($pair['translation']).";\n\n";
        fputs($this->fd, $text);
    }

    function write_comment($comment) {
        fputs($this->fd, "# $comment\n");
    }

    function write_footer() {
        fputs($this->fd, "?>\n");
    }

    function close() {
        fclose($this->fd);
    }

    /** Prepares a string to be printed in double quotes into a file. */
    function prepare_string($str) {
        $str = str_replace(array("\\", '"', "$"), array("\\\\",'\\"', "\\$"), $str);
        // write line ends as new lines, but handle two line ends in other way
        while (strstr($str, "\n\n")) {
            $str = str_replace("\n\n", "\\n\n", $str);
        }
        $str = str_replace("\n", "\\n\"\n   .\"", $str);
        return '"'.$str.'"';
    }
}

/** Writer class for PO (gettext) language files */
class mgettext_writer_po extends mgettext_writer {

    /** constructor */
    function mgettext_writer_po($langfile) {
        echo "<br>open (PO) $langfile";
        $this->fd = fopen($langfile, "wb");
    }

    /** Prints language file header. */
    function write_header($lang) {
        fputs($this->fd, "# \$Id$\n");
        fputs($this->fd, "# Language: ".strtoupper($lang)."\n");
        fputs($this->fd, "# This file was created automatically by the Mini GetText environment\n");
        fputs($this->fd, "# on ".date("j.n.Y H:i")."\n\n");
        fputs($this->fd, "# Before each message there are links to program code where it was used.\n");
        fputs($this->fd, "# \n");
        fputs($this->fd, "# \$mgettext_lang = \"$lang\";\n");
        fputs($this->fd, "# \n\n");
    }

    /** Write language constructs */
    function write_pair(&$pair) {
        $text = "";
        if ($pair['comments']) {
            foreach ( $pair['comments'] as $comment ) {
                $text .= "# $comment\n";
            }
        }
        $text .= "msgid ". $this->prepare_string($pair['message'])."\n";
        $text .= "msgstr ".$this->prepare_string($pair['translation'])."\n\n";
        fputs($this->fd, $text);
    }

    function write_comment($comment) {
        fputs($this->fd, "# $comment\n");
    }

    function write_footer() {
        fputs($this->fd, "");
    }

    function close() {
        fclose($this->fd);
    }

    /** Prepares a string to be printed in double quotes into a file. */
    function prepare_string($str) {
        $str = str_replace(array("\\", '"'), array("\\\\",'\\"'), $str);
        // write line ends as new lines, but handle two line ends in other way
        while (strstr($str, "\n\n")) {
            $str = str_replace("\n\n", "\\n\n", $str);
        }
        $str = str_replace("\n", "\\n\"\n   \"", $str);
        return '"'.$str.'"';
    }

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
function xmgettext($lang_list, $logfile, $lang_files, $files_base_dir, $files, $chmod=0664, $stop_on_warning=true,
                   $old_logs="", $add_source_links=true, $file_type='php3') {
    set_time_limit(10000);
    collect_messages($logfile, $files_base_dir, $files, $messages, $warnings);

    if (is_array($warnings)) {
        echo "<Br>Warnings:<br>";
        echo join ("<br>", $warnings);
        if ($stop_on_warning) exit;
    }

    reset ($lang_list);
    while (list($lang) = each ($lang_list)) {
        $langfile = str_replace ("??", $lang, $lang_files);
        // read the language constants
        $_m = "";
        echo "<br> exist $langfile?";
        if (file_exists($langfile)) {
            echo " YES - require it - ";
            require_once $langfile;
        }
        echo count($_m) ." ($old_logs)";

        if ($old_logs) {
            add_old_translations($old_logs, $lang, $_m, $other_translations);
        }
        echo " - ". count($_m) .'<br>';

        // write the file
        if ($file_type == 'php3') {
            $out = new mgettext_writer_php($langfile);
        } else {
            $out = new mgettext_writer_po(str_replace('php3', 'PO', $langfile));
        }
        $out->write_header($lang);

        if (is_array($_m)) {
            // unused messages
            $out->write_comment('Unused messages');
            foreach ( $_m as $message => $tr) {
                if (!isset($messages[$message]) && $tr) {
                    $pair = array('message'=>$message, 'translation'=>$tr);
                    $out->write_pair($pair);
                }
            }
            $out->write_comment('End of unused messages');
        }

        // messages with code location description and other translations (from old lang files)
        if (is_array($messages)) {
            foreach ( $messages as $message => $params) {
                $pair = array();    // clean the variable
                if ($add_source_links) {
                    foreach ($params["code"] as $filename => $rows) {
                        $pair['comments'][] = "$filename, row ".join(", ",$rows);
                    }
                }

                // other translations
                if (is_array ($other_translations) && $other_translations[$message]) {
                    $other_join = "";
                    foreach ( $other_translations[$message] as $other => $foo) {
                        $other_join[] = $other;
                    }
                    $pair['comments'][] = "other translations: ".join (", ", $other_join);
                }

                $mmsg = $_m[$message];
                if ($message == $mmsg) {
                    $mmsg = "";
                }
                $pair['message']     = $message;
                $pair['translation'] = $mmsg;
                $out->write_pair($pair);
            }
        }
        $out->write_footer();
        $out->close();
        chmod($langfile, $chmod);
    }
}

// -------------------------------------------------------------------------------------

/** Adds translations from logs from old language files to $_m. */
function add_old_translations($log_files, $lang, &$_m, &$other_translations) {
    $file = str_replace("??","en",$log_files);
    if (!file_exists($file)) {
        echo "ERROR: $file does not exist<br>";
    } else {
        $_log = "";
        require_once $file;
        $en_log = $_log;
        $_log = "";
        require_once str_replace("??",$lang,$log_files);
        foreach ($en_log as $msg => $names) {
            foreach ($names as $name) {
                if (!$_m[$msg]) {
                    $_m[$msg] = $_log[$name];
                } elseif ($_m[$msg] != $_log[$name]) {
                    $other_translations[$msg][$_log[$name]] = 1;
                }
            }
        }
    }
}

/** creates php string which could be printed into ph apostroph construct */
function php_string($text) {
    $text = str_replace("\\", "\\\\", $text);
    return  str_replace("'",  "\\'",  $text);
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
function collect_messages($logfile, $files_base_dir, $files, &$messages, &$warnings)
{
    // creates a log file allowing to process lots of files

    if (file_exists ($logfile)) {
        require_once $logfile;
    }
    echo "<br>collect_messages()";
    foreach ( $files as $fname) {
        $skip = ($fname[0] == "-");
        if ($skip) {
            $fname = substr ($fname, 1);
        }
        echo "<br> $fname";
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

    // echo "<br> skiplist:";
    // print_r($skiplist);
    if (is_array($skiplist)) {
        foreach ($skiplist as $skipfile => $foo) {
            unset($filelist[$skipfile]);
        }
    }

    // echo "<br> filelist:";
    // print_r($filelist);
    foreach ($filelist as $filename => $foo) {
        $messages = array();
        $warnings = array();
        if (!$processed_files[$filename]) {
            collect_messages_from_file($files_base_dir, $filename, $messages, $warnings);
            $msgstr = php_string(serialize($messages));
            $wrnstr = php_string(serialize($warnings));
            $fd = fopen($logfile, "ab");
            chmod($logfile, 0664);
            fwrite($fd, "\n<?php \$processed_files[\n\n'$filename']=array ('messages'=>'$msgstr','warnings'=>'$wrnstr');?>");
            fclose($fd);
        }
    }

    // go through the log file

    $messages = "";
    $warnings = "";
    require_once $logfile;
    foreach ($processed_files as $msgwrn) {
        $msg = unserialize($msgwrn["messages"]);
        if (is_array($msg)) {
            foreach ($msg as $message => $code) {
                foreach ($code["code"] as $filename => $rows) {
                    foreach ($rows as $row) {
                        $messages [$message]["code"][$filename][] = $row;
                    }
                }
            }
        }
        $wrn = unserialize($msgwrn["warnings"]);
        if (is_array($wrn)) {
            foreach ($wrn as $warning) {
                $warnings[] = $warning;
            }
        }
    }

    unlink($logfile);
}

// -------------------------------------------------------------------------------------

/**  Parses the file to find all _m() calls. See more info in collect_messages.
*/
function collect_messages_from_file($base_dir, $filename, &$messages, &$warnings)
{
    $content = file($base_dir.$filename);
    $filetext = "";
    foreach ( $content as $irow => $row) {
        $row_start[$irow+1] = strlen($filetext);
        $filetext .= $row;
    }
    if (!strstr($filetext, "_m")) {
        return;
    }

    $quotes        = "0";
    $inner_quotes  = "0";   // count quotes only after _m
    $comment       = "0";
    $irow          = 1;

    if ($debug) echo "<br><br><hr>File $filename";

    for ($pos = 0; $pos < strlen($filetext); $pos++) {
        if ($row_start[$irow+1] && $row_start[$irow+1] <= $pos) {
            $irow++;
        }

        // comments of all types (#, //, /*)

        if ($comment == "0" && $quotes == "0") {
            if ($filetext[$pos] == "#") {
                $comment = "#";
            } elseif (substr($filetext, $pos, 2) == "//") {
                $comment = "#";
                $pos++;
            } elseif (substr ($filetext, $pos, 2) == "/*") {
                $comment = "/*";
                $pos++;
            }
        }
        elseif ($comment == "#" && $filetext[$pos] == "\n") {
            $comment = "0";
        } elseif ($comment == "/*" && substr($filetext, $pos, 2) == "*/") {
            $comment = "0";
            $pos++;
        }

        // quotes of both types (", ')

        // added ($find != 0) by Honza - do not take care about outer
        if ($comment == "0" && strchr("\"'", $filetext[$pos])) {
            if ($pos == 0 || $filetext[$pos-1] != "\\") {
                if (!$quotes) {
                    $quotes       = $filetext[$pos];
                } elseif ($quotes == $filetext[$pos]) {
                    $quotes = "0";
                }
            }
        }

        if ($find == 0) {
            $message      = "";
            $inner_quotes = '0';
        }
/*         echo "<br>". (($pos == 0) ? '+' : '.').
                     (!isidletter($filetext[$pos-1]) ? '+' : '.').
                     (($filetext[$pos] == '_') ? '+' : '.').
                     (($filetext[$pos+1] == 'm') ? '+' : '.').
                     (!isidletter($filetext[$pos+2]) ? '+' : '.'). $filetext[$pos] .", $comment, $find, $quotes, $inner_quotes, $message_part";
 */
        //if ($find) echo $find;
        //if ($quotes != $old_quotes) echo "row $irow: $quotes<br>";
        $old_quotes = $quotes;

        if (!$comment) {
            switch ($find) {
            // outside _m()
            case 0:
                if (($pos == 0 || !isidletter($filetext[$pos-1]))
                  && ($filetext[$pos] == '_')
                  && ($filetext[$pos+1] == 'm')
                  && !isidletter($filetext[$pos+2])) {
                    // _m was the whole identifier
                    $find = 1;
                    $pos++;

                    // Following code prevents from looking into quoted string
                    // for _m() strings, which I found as nonsence, because the
                    //  code often looks like:
                    // <input type="submit" value="< ?php echo _m("Login now") ? >">
                    // Honzam 2005-22-06

                    // if ($quotes == "0") {  $find = 1; $pos ++;
                    // } else echo "<br>$base_dir.$filename row $irow: _m inside quotes $quotes". substr($filetext,$pos-10,40);
                }
                break;
            // after _m
            case 1:
                if (isspace($filetext[$pos]))
                    continue;
                elseif ($filetext[$pos] == '(') {
                    $find = 2;
                } else {
                    $find = 0;
                }
                break;
            // after _m (
            case 2:
                if (isspace($filetext[$pos])) {
                    continue;
                }
                if (strchr("\"'", $filetext[$pos])) {
                    $find = 3;
                    $quotes_start = $inner_quotes = $filetext[$pos];
                } else {
                    $warnings[] = "$filename, row $irow: bad syntax after _m (";
                    $inner_quotes = '0';
                    $find = 0;
                }
                break;
            // inside message, e.g. after _m ( " or after _m ( "Hello" . "
            case 3:
                if (($filetext[$pos]==$inner_quotes) && ($filetext[$pos-1] != "\\") ) {
                    $to_be_evaled = "\$message .= ".$quotes_start.$message_part.$quotes_start.";";
                    $message_part = "";
                    eval($to_be_evaled);
                    $find = 4;
                    $inner_quotes = '0';
                }
                else {
                    $message_part .= $filetext[$pos];
                    if ($filetext[$pos] == '$' && $inner_quotes == '"'
                     && $filetext[$pos-1] != "\\")
                        $warnings[] = "$filename, row $irow: using variable in _m is not allowed";
                }
                break;
            // after message, e.g. _m ( "Hello"
            case 4:
                if (isspace($filetext[$pos])) {
                    ;
                } elseif ($filetext[$pos] == ".") {
                    $find = 2;
                } elseif ($filetext[$pos] == "," || $filetext[$pos] == ")") {
                    $messages [$message]["code"][$filename][] = $irow;
                    $find = 0;
                } else {
                    $warnings[] = "$filename, row $irow: bad syntax inside _m () after $message";
                    $find = 0;
                }
                break;
            }
        }
    }
}

// -------------------------------------------------------------------------------------

function isidletter($c)
{ return ($c >= 'a' && $c <= 'z')
      || ($c >= 'A' && $c <= 'Z')
      || ($c >= '0' && $c <= '9')
      || ($c == '_')
      || (ord ($c) > 127);
}

function isspace($c) {
    return strchr(" \t\r\n", $c);
}

/** strips path from file name */
function filepath($filename) {
    if (!strstr($filename,"/")) {
        return "./";
    }
    $i = strlen($filename);
    while ($filename[$i] != "/") {
        $i--;
    }
    return substr($filename,0,$i+1);
}

?>
