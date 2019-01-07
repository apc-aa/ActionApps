<?php
/** This original script has been stolen from old version of Horde.
 *  It was never fully updated for AA, but could be good start for
 *  new version of it - see AA code from the end of 2017.
 *
 *  The script is here to remind us that we shoudl indeed have
  * something like this for installation and troubleshooting
  * purposes. The installation docs refers to it. Maybe the first
  * thing AA user will see after the installation :-|
  *
  * @version $Id$
  * @author Marek Tichy, Econnect
  * @copyright (c) 2002-3 Association for Progressive Communications
*/
?>

<html>
<head>
  <title>APC-AA testing</title>
</head>
<body>
  <ul>

<?php
if (extension_loaded('gd')) {
    print("<li>GD is loaded</li>\n");
    require_once __DIR__.'/include/imagefunc.php3';
    PrintSupportedTypes();
} else {
    print("<li style=\"color: red; \">Warning: GD is unavailable, image manipulation won't be available</li>\n");
}
require_once __DIR__.'/include/config.php3';

function checkdir($name,$dir,$mustendslash, $mustwritable) {
    if ($mustendslash && ! preg_match('`/$`',$dir)) {
        print("<li style=\"color:red\">$name=$dir should end in a slash</li>\n");
    }
    if (! is_dir($dir)) {
        print("<li style=\"color:red\">$name=$dir which is not a directory</li>\n");
    } else if ($mustwritable && ! is_writable($dir)) {
        print("<li style=\"color:red\">$name=$dir exists, but is not writable</li>\n");
    }
}
checkdir("AA_SITE_PATH",    AA_SITE_PATH,     true, false);
checkdir("AA_INC_PATH",     AA_INC_PATH,      true, false);
//checkdir("FILEMAN_BASE_DIR",FILEMAN_BASE_DIR, true, true);  // it is old and no necessary functionality in AA - no need to test it
?>

  </ul>
</body>
</html>
