<?php 

if (!extension_loaded('gd')) print "<p>Warning: GD is unavailable, image manipulation won't be available</p>";
require_once "../include/imagefunc.php3";
PrintSupportedTypes();

phpinfo() 

?>
