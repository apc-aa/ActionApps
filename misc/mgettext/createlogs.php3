<?php

if (!isset($LANGUAGE_CHARSETS))
    require "../../include/constants.php3";
    
$dir = "/raid/www/htdocs/work.ecn.cz/aa_jakub/";
    
function create_logs ($old_lang_files, $log_files)
{    
    global $LANGUAGE_CHARSETS;
    while (list ($lang) = each ($LANGUAGE_CHARSETS)) {
        $old_lang_file = str_replace ("??", $lang, $old_lang_files);
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
                     fwrite ($fd, "\$_log[\"$value\"] = \"$name\";\n");
                else fwrite ($fd, "\$_log[\"$name\"] = \"$value\";\n");
            }
        }
        fwrite ($fd, "?>");
        fclose ($fd);
        break;
    }
}    

?>