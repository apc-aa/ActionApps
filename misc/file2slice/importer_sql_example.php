<?php
/**
 * Example if SQL Saver with key fields matches
 * 
 * @version $Id$
 * @author Honza Malik
 */

use AA\Later\Toexecute;

require_once __DIR__."/../../include/config.php";
/** Main include file for using session management function on a page */
require_once __DIR__ . "/../../include/locsess.php";
require_once __DIR__ . "/../../include/util.php";
require_once __DIR__ . "/../../include/formutil.php";


// call the script like https://example.org/apc-aa/misc/file2slice/importer_sql_example.php?someSecret=1 - just to not run it by accident
if ($_GET['someSecret']) {
    $t13s = [];
    $t13s['headline........'] = new AA_Transformation_Value(['new_content'=>'{index:name}']);
    $t13s['abstract........'] = new AA_Transformation_Value(['new_content'=>'{index:value}']);

    $grabber   = new AA\IO\Grabber\SQL('SELECT * FROM myproducts', 'dbname', 'dbuser', 'dbpassword');

    $saver     = new AA\IO\Saver($grabber, $t13s, 'a3c11b24b73514307b956eb49a5aa45f', 'insert/update', '["headline........"]');  // key ["relation........","year............"]

    // you can also plan the import 
    // $importer  = new AA\IO\Importer($saver);
    // $importer->toexecutelater();

    // switch to false for dry run without write to the database
    $saver->run(true);
    echo $saver->report();
}
