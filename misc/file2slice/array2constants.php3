<?php
/* This script allows you to fill constants group with constants defined
     by the array - next in this file ($constants2import).
*/

require_once __DIR__."/../../include/config.php3";
require_once __DIR__."/../../include/locsess.php3";
require_once __DIR__."/../../include/util.php3";
require_once __DIR__."/../../include/formutil.php3";
require_once __DIR__."/../../include/varset.php3";
require_once __DIR__."/../../include/itemfunc.php3";
require_once __DIR__."/../../include/notify.php3";
require_once __DIR__."/../../include/discussion.php3";

require_once __DIR__."/../../include/date.php3";
require_once __DIR__."/../../include/feeding.php3";

function myQuery ($db, $SQL, $fire) {
    echo "$SQL<br>";
    return !$fire ? true : $db->query($SQL);
}

// ---------------------- 2 import -------------------------------------------


// Here you can write your own constants, which will be loaded into database
// as $group_id group

$constants2import = [
'Byst�ice nad Pern�tejnem',
'Chot�bo�',
'Havl��k�v Brod',
'Humpolec',
'Jihlava',
'Jihlava, kraj Vyso�ina',
'Moravsk� Bud�jovice',
'N�m욝 nad Oslavou',
'Nov� M�sto na Morav�'
];
// ---------------------- Just do it -----------------------------------------

$group_id = 'NSZM_Obce_3_____';              // define name of group
                                             // MUST be 16 character long !!!
$fire = true;                               // write to DB?
$priority_step = 10;
$timeLimit = 600;                               // time limit in seconds
// set in seconds - allows the script to work so long
set_time_limit($time_limit);

$err = [];          // error array (Init - just for initializing variable
$varset = new Cvarset();
is_object( $db ) || ($db = getDB());

$SQL = "INSERT INTO constant SET id='". q_pack_id(new_id()) ."',
                                     group_id='lt_groupNames',
                                     name='$group_id',
                                     value='$group_id',
                                     class='',
                                     pri='100'";
myQuery ($db, $SQL, $fire);

foreach ($constants2import as $cnst) {
    $varset->clear();
    $varset->set("name",  $cnst, "text");
    $varset->set("value", $cnst, "text");
    $varset->set("pri",   $pri += $priority_step, "number");
    // $varset->set("class", $class[$key], "quoted");
    $varset->set("id", new_id(), "unpacked" );
    $varset->set("group_id", $group_id, "text" );
    $SQL =  $varset->makeINSERT('constant');

    if ( !myQuery ($db, $SQL, $fire )) {
        $err["DB"] .= MsgErr("Can't copy constant");
        break;
    }
}


if (count($err)) {
  print("<br><b>Import error!</b>");
  print_r($err);
} else
  print("<br><b>Import successful!</b>");


