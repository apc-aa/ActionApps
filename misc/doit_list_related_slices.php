<?php
/**
  * list of related slices
  * @version $Id$
  * @author Honza Malik, Econnect
  * @copyright (c) 2002-3 Association for Progressive Communications
*/

/** APC-AA configuration file */
require_once __DIR__."/../include/config.php3";
/** Main include file for using session management function on a page */
require_once __DIR__ . "/../include/locsess.php";
/** Set of useful functions used on most pages */
require_once __DIR__ . "/../include/util.php";
require_once __DIR__ . "/../include/formutil.php";


$slices = GetTable2Array("SELECT id, name FROM slice order by name", 'id', 'name');

foreach ($slices as $psid => $name) {
    $fields = GetTable2Array("SELECT id, name, input_show_func FROM field WHERE slice_id='".quote($psid)."' AND input_show_func LIKE '%#sLiCe-%'", 'NoCoLuMn');
    
    echo "<br><br>$name";
    foreach ($fields as $field) {
        $params = explode(':', $field['input_show_func']);
        echo "<br> &nbsp; - ". $slices[pack_id(substr($params[1], 7))] . " ($field[name])";
    }
}


