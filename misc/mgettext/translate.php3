<?php
/**
 * Changes PHP scripts containing old approach language constants
 * to calls of the _m() mini-gettext function.
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

require_once "../../include/mgettext.php3";

/**  Translates files using L_ language constants to _m() function calls.
*
*    If set_time_limit doesn't work on your server, use the function several times:
*    it will skip the files processed before.
*    If you don't like this behavior, delete the destination files
*
*    @param     $old_lang_file -- full file name of the file with L_ language constants
*    @param     $src_dir -- all files from this directory will be processed
*    @param     $dst_dir -- here will be the translated files saved
**/
function translate_files ($old_lang_file, $src_dir, $dst_dir) {
    set_time_limit(10000);

    include $old_lang_file;
    $consts = get_defined_constants();
    // we want to replace first L_NO_EVENT and only later L_NO
    krsort ($consts);

    foreach ($consts as $name => $value) {
        if (substr ($name,0,2) != "L_") {
            unset ($consts[$name]);
        } else {
            if (strlen($value) <= 1 || is_numeric ($value)) {
                $consts[$name] = "\"$value\"";
            } else {
                $consts [$name] = "_m(\"".str_replace( array ('"',"\n","\r"), array ('\\"',"\\n",""),  $value)."\")";
            }
        }
    }

    $dir = opendir ($src_dir);
    while ($file = readdir ($dir)) {
        if (is_dir ($src_dir.$file)) {
            continue;
        }
        if (file_exists ($dst_dir.$file) && filesize ($dst_dir.$file) > 1) {
            continue;
        }
        echo $file."<br>";
        $content     = file ($src_dir.$file);
        $new_content = "";

        foreach ($content as $row) {
            for (reset ($consts); $name = key ($consts); next ($consts)) {
                // first try the quick search
                if (strstr ($row, $name)) {
                    //echo HTMLentities($row)." => ";
                    // now replace only when it is not a part of a longer name
                    $row = preg_replace ("'([^A-Z0-9_$])".$name."([^A-Z0-9_])'si", "\\1".$consts[$name]."\\2", $row);
                    //echo HTMLentities($row)."<br>";
                }
            }
            $new_content[] = $row;
        }

        $fd = @fopen ($dst_dir.$file, "wb");
        if (!$fd) {
            echo " write permission denied<br>";
        }
        if (!is_array($new_content) || !$fd) {
            continue;
        }
        foreach ($new_content as $row) {
            fwrite ($fd, $row);
        }
        fclose ($fd);
        chmod ($dst_dir.$file, 0777);
    }
    closedir ($dir);
}

?>
