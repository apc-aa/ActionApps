<HTML><HEAD
<TITLE>>APC-AA testing</TITLE>
</HEAD>
<BODY><UL>
<?php 
if (extension_loaded('gd')) {
    print("<li>GD is loaded</li>\n");
    require_once "../include/imagefunc.php3";
    PrintSupportedTypes();
} else {
    print("<li><font color=red>Warning: GD is unavailable, image manipulation won't be available</li>\n");
}
require "../include/config.php3";
$qf = is_dir(FILEMAN_BASE_DIR);
print("<li>" . ($qf ? "" : "<font color=red>") . "File Manager directory '"
    .FILEMAN_BASE_DIR."' ".($qf ? "exists" : "doesn't exist</font>")."</li>\n");

print("</ul>");
phpinfo() 

?>
