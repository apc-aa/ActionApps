<?
if ($_GET["value"] != "")
header ("Location: ".$_GET["value"]);
else
header ("Location: ".$_GET["url"]);
?>    
