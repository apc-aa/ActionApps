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

/*  mini-gettext is a simple environment to handle multilingual support 

    Set active language (e.g. Spanish) by 
    $GLOBALS[mgettext_lang] = "es"; 
*/

// language file (one for all languages):
require $GLOBALS[AA_INC_PATH]."../php_rw/mgettext_lang.php3";
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
