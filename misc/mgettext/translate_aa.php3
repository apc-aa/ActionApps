<?php

require "./xmgettext.php3";
require "./translate.php3";

//translate_aa_files ("/raid/www/htdocs/work.ecn.cz/aa_jakub/", "/raid/www/htdocs/work.ecn.cz/aa_jakub/php_rw/tr1/");

create_language_files_updates ("/raid/www/htdocs/work.ecn.cz/aa_jakub/", "/raid/www/htdocs/work.ecn.cz/aa_jakub/php_rw/lang/");

function create_language_files_updates ($aadir, $destdir)
{    
    @mkdir ($destdir, 0777);
    xmgettext ("$destdir/??_alerts_lang.inc",
        $aadir, 
        array ("misc/alerts/alerts.php3", 
               "misc/alerts/confirm.php3",
               "misc/alerts/add_user_collection.php3",
               "misc/alerts/index.php3",
               "misc/alerts/newuser.php3",
               "misc/alerts/print_collections_select.php3",
               "misc/alerts/subscribe.php3",
               "misc/alerts/user_filter.php3",
               "misc/alerts/util.php3",
               "admin/alerts_collections.php3",
               "admin/te_alerts_collections.php3"),
        0666);
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
