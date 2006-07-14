<?php

require "../../include/mgettext.php3";
require "./xmgettext.php3";
require "./translate.php3";
require "./createlogs.php3";
require "../../include/config.php3";

// call this script several times if it does not manage to go through all files 
// it will continue where it stopped last time

$aadir = substr ($AA_INC_PATH, 0, strlen($AA_INC_PATH)-strlen("include/"));
$destdir = $aadir."php_rw/";

// call this script several times to create language log files for all languages,
// it creates one language log at a time
//create_logs ($aadir."include/??_news_lang.php3", $destdir."lang/log_??_news_lang.inc");

if (is_array ($update)) 
    create_language_files_updates ($aadir, $destdir."lang/", false);
        
//translate_aa_files ($aadir, $aadir."php_rw/tr2/");

function create_language_files_updates ($aadir, $destdir, $addlogs = false)
{    
    global $update;
    //echo $aadir." ".$destdir;    @mkdir ($destdir, 0777);
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
        if (!$update[$langfiles])
            continue;
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
    $dirlist = array ("include",".", "admin","modules","modules/module_TEMPLATE",
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

echo "
<HTML>
<HEAD><TITLE>Translate AA</TITLE></HEAD>
<BODY>
    Which language files do you want to update?<br><br>
    <FORM name=f METHOD=post ACTION='translate_aa.php3'>
    <INPUT type=checkbox name='update[alerts]' checked> Alerts<br>
    <INPUT type=checkbox name='update[news]' checked> News<br><br>
    <INPUT type=submit name='go' value='Fire!'>
    </FORM>
</BODY>
</HTML>";
?>
