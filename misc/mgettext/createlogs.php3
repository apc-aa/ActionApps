<?php
/**
 * Creates logs from old AA language files to be added into mini-gettext language files.
 * Because the old system uses PHP constants, only one language file may be read at a time.
 * This script must be called several times to produce several logs.
 * 
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
    
$dir = "/raid/www/htdocs/work.ecn.cz/aa_jakub/";

/** Creates a log containing old language file content to be added into mini-gettext language files. */    
function create_logs ($lang_list, $old_lang_files, $log_files)
{    
    reset ($lang_list);
    while (list ($lang) = each ($lang_list)) {
        $old_lang_file = str_replace ("??", $lang, $old_lang_files);
        if (!file_exists ($old_lang_file)) 
            continue;    
        $logfile = str_replace ("??", $lang, $log_files);
        if (file_exists ($logfile) && filesize ($logfile) > 0)
            continue;       
        $fd = fopen ($logfile, "wb");
        if (!$fd) return;
        chmod ($logfile, 0777);
        fwrite ($fd,"<?php
            # this is a log file of the language translation on ".date("d.j.Y H:i")."\n\n");
        include $old_lang_file;    
        $consts = get_defined_constants();
        reset ($consts);
        while (list ($name, $value) = each($consts)) {
            if (substr ($name,0,2) == "L_" && $value) {
                $value = str_replace(
                    array ('"',"\n","\r"),
                    array ('\\"',"\\n",""),
                    $value);
                if ($lang == "en")
                     fwrite ($fd, "\$_log[\"$value\"][] = \"$name\";\n");
                else fwrite ($fd, "\$_log[\"$name\"] = \"$value\";\n");
            }
        }
        fwrite ($fd, "?>");
        fclose ($fd);
        break;
    }
    if ($lang) echo '<font color="red">Log <b>'.$logfile.'</b> created.</font>';
    else echo '<font color="red">All logs are created.</font>';
}    

?>