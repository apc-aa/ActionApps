<?php

require "./xmgettext.php3";
require "./translate.php3";

//translate_aa_files ("/raid/www/htdocs/work.ecn.cz/aa_jakub/", "/raid/www/htdocs/work.ecn.cz/aa_jakub/php_rw/tr1/");

// call this script several times if it does not manage to go through all files 
// it will continue where it stopped last time

create_language_files_updates ("/raid/www/htdocs/work.ecn.cz/aa_jakub/", "/raid/www/htdocs/work.ecn.cz/aa_jakub/php_rw/lang/", 0);

function create_language_files_updates ($aadir, $destdir, $addlogs = false)
{    
    @mkdir ($destdir, 0777);
    $lang_groups ["alerts"] =
        array ("misc/alerts/");
    $lang_groups ["news"] =
        array ("admin/",
               "include/");

    $logfile = $destdir."log_language_updates.php3";
    if (file_exists ($logfile)) 
        include $logfile;

    $xmgettext_logfile = $destdir."collect_msg_log.php3";
            
    reset ($lang_groups);
    while (list ($langfiles, $srcfiles) = each ($lang_groups)) {
        if ($log_group_processed == $langfiles)
            unset ($log_group_processed);
        if (!$log_group_processed) {
            $fd = fopen ($logfile, "w");
            fwrite ($fd, "<?php \$log_group_processed = \"$langfiles\"; ?>\n");
            fclose ($fd);
            chmod ($logfile, 0664);
            if (!$addlogs)
                xmgettext ($xmgettext_logfile, $destdir."??_".$langfiles."_lang.inc", $aadir, $srcfiles, 0666, false);
            else xmgettext ($xmgettext_logfile, $destdir."??_".$langfiles."_lang.inc", $aadir, $srcfiles, 0666, false,
                $destdir."log_??_".$langfiles."_lang.inc");
        }
    }
    unlink ($logfile);
    echo "Ready<Br>";
}

function translate_aa_files ($aadir, $dstdir)
{
    $dirlist = array ("admin","include","modules","modules/module_TEMPLATE",
        "modules/jump","modules/mysql_auth");
    
    @mkdir ($dstdir,0777);
    
    reset ($dirlist);
    while (list (,$dir) = each ($dirlist)) {
        @mkdir ($dstdir.$dir,0777);
        translate_files ($aadir."include/en_news_lang.php3", 
                         $aadir.$dir."/",
                         $dstdir.$dir."/");
    }
    echo "Ready<br>";
}


?>
