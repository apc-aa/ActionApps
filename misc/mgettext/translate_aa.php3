<?php
require "./aagettext.php3";

aagettext (array (""), 
    "/raid/www/htdocs/work.ecn.cz/aa_jakub/", 
    array ("misc/alerts/alerts.php3", "misc/alerts/confirm.php3"));

exit;

$dirlist = array ("admin","include","modules","modules/module_TEMPLATE",
    "modules/jump","modules/mysql_auth");

$aadir = "/raid/www/htdocs/work.ecn.cz/aa_jakub/";

$trdir = "/raid/www/htdocs/work.ecn.cz/aa_jakub/php_rw/translat/";
mkdir ($trdir,0777);

reset ($dirlist);
while (list (,$dir) = each ($dirlist)) {
    mkdir ($trdir.$dir,0777);
    translate_files ($aadir."include/en_news_lang.php3", 
                     $aadir.$dir."/",
                     $trdir.$dir."/");
}
?>
