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
*/

function get_mgettext_lang () {
    global $mgettext_lang;
    if (!isset ($mgettext_lang))
        return "en";
    else return $mgettext_lang;
}

/* Function: bind_mgettext_domain
   Purpose:  reads language constants from given file
   Remarks:  use full file name 
*/
function bind_mgettext_domain ($filename) {
    global $_m, $mgettext_lang;
    
    if (!file_exists ($filename)) {
        echo "<h1>WRONG MGETTEXT DOMAIN $filename</h1>";
        exit;
    }
    else {
        $_m = "";
        include $filename;        
    }
}

/*  Function: _m
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
function _m ($id, $params = 0) {
    global $_m;
    
    $retval = $_m[$id];
    if (!$retval) 
        $retval = $id;
    
    if (is_array ($params)) {
        $foo = "#$&*-";
        $retval = str_replace ("\%", $foo, $retval);
        for ($i = 0; $i < count ($params); $i ++) 
            $retval = str_replace ("%".($i+1), $params[$i], $retval);
        $retval = str_replace ($foo, "%", $retval);
    }
        
    return $retval;
} 

?>
