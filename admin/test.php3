<html><head
<title>APC-AA testing</title>
</head>
<body><ul>
<?php
if (extension_loaded('gd')) {
    print("<li>GD is loaded</li>\n");
    require_once "../include/imagefunc.php3";
    PrintSupportedTypes();
} else {
    print("<li><font color=\"red\">Warning: GD is unavailable, image manipulation won't be available</li>\n");
}
require_once "../include/config.php3";

function checkdir($name,$dir,$mustendslash) {
    if ($mustendslash && ! preg_match('`/$`',$dir)) {
        print("<li><font color=\"red\">$name=$dir should end in a slash</font></li>\n");
    }
    if (! is_dir($dir)) {
        print("<li><font color=\"red\">$name=$dir which is not a directory</font></li>\n");
    } else if ($mustwritable && ! is_writable($dir)) {
        print("<li><font color=\"red\">$name=$dir exists, but is not writable</font></li>\n");
    }
}
checkdir("AA_SITE_PATH",    AA_SITE_PATH,    true,false);
checkdir("AA_INC_PATH",     AA_INC_PATH,     true,false);
checkdir("FILEMAN_BASE_DIR",FILEMAN_BASE_DIR,true,true);


print("</ul>");
phpinfo()
?>
