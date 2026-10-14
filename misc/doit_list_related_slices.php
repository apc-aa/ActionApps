<?php

/**
  * list of related slices
  * @version $Id$
  * @author Honza Malik, Econnect
  * @copyright (c) 2002-3 Association for Progressive Communications
*/

use AA\IO\DB\DB_AA;

/** APC-AA configuration file */
require_once __DIR__ . "/../include/config.php";
/** Main include file for using session management function on a page */
require_once __DIR__ . "/../include/locsess.php";
/** Set of useful functions used on most pages */
require_once __DIR__ . "/../include/util.php";
require_once __DIR__ . "/../include/formutil.php";


$slices = DB_AA::select(['id' => 'name'], 'SELECT id, name FROM slice', null, ['name']);

foreach ($slices as $psid => $name) {
    $fields = DB_AA::select([], 'SELECT id, name, input_show_func FROM field', [['slice_id', $psid, 'b'], ['input_show_func', '%#sLiCe-%', 'LIKE']]);

    echo "<br><br>$name";
    foreach ($fields as $field) {
        $params = explode(':', $field['input_show_func']);
        echo "<br> &nbsp; - " . $slices[pack_id(substr($params[1], 7))] . " ($field[name])";
    }
}
