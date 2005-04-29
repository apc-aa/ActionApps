<?php

// Example script for importing tabuller data into slice
// Modify this script and then run it, in order to import your data
// For more information see:
//      http://apc-aa.sourceforge.net/faq/index.shtml#312

$actions = array(
"category.......1" => array("action" => "storeparsemulti", "delimiter" => ";", "from" => "Category"),
"headline........" => array("action" => "store", "from" => "Title"),
"created_by......" => array("action" => "store", "from" => "Author"),
"full_text......." => array("action" => "store", "from" => "Summary", "flag"=>1)
);

require_once $GLOBALS['AA_BASE_PATH']."misc/file2slice/importer.php3";

Importer (
    "aa65a21b285c25f9fe0d4b662919b4b2", // slice iD
    "./books.txt",                      // file name
  "\t",                               // field separator
    $actions,
    "uid=ada01,ou=People,ou=AA",        // posted by (there should be a number for sql permissions
    true,                               // write to DB?
    600);                               // time limit in seconds
?>
