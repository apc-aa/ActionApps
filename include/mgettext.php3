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
&iacute;
    $GLOBALS[mgettext_lang] = "es"; 
*/

# // language file (one for all languages):
# require $GLOBALS[AA_INC_PATH]."../php_rw/mgettext_lang.php3";
$mgettext_langs = array ("en","cz","de","sk","ro","es");

function set_mgettext_domain ($domain) {
    $mgettext_lang = substr ($domain,0,2);
}

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
/*        if (!$mgettext_strings[$id]) {
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
        }*/
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

?>
