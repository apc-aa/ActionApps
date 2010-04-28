<?php
/* This script allows you to fill constants group with constants defined
     by the array - next in this file ($constants2import).
*/

require_once "../../include/config.php3";
require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."itemfunc.php3";
require_once AA_INC_PATH."notify.php3";
require_once AA_INC_PATH."discussion.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."date.php3";
require_once AA_INC_PATH."feeding.php3";

function myQuery (&$db, $SQL, $fire) {
  global $debug;
  echo "$SQL<br>";

  if ( !$fire )
    return true;

  if ($debug)
    return $db->dquery ($SQL);
  else
    return $db->query($SQL);
}

// ---------------------- 2 import -------------------------------------------


// Here you can write your own constants, which will be loaded into database
// as $group_id group

$constants2import = array (
'Bystøice nad Pernštejnem',
'Chotìboø',
'Havlíèkùv Brod',
'Humpolec',
'Jihlava',
'Jihlava, kraj Vysoèina',
'Moravské Budìjovice',
'Námìš nad Oslavou',
'Nové Mìsto na Moravì'
);
// ---------------------- Just do it -----------------------------------------

$group_id = 'NSZM_Obce_3_____';              // define name of group
                                             // MUST be 16 character long !!!
$fire = true;                               // write to DB?
$priority_step = 10;
$timeLimit = 600;                               // time limit in seconds
// set in seconds - allows the script to work so long
set_time_limit($time_limit);

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$db = new DB_AA;

$SQL = "INSERT INTO constant SET id='". q_pack_id(new_id()) ."',
                                     group_id='lt_groupNames',
                                     name='$group_id',
                                     value='$group_id',
                                     class='',
                                     pri='100'";
myQuery ($db, $SQL, $fire);

reset( $constants2import );
while ( list(,$cnst) = each($constants2import) ) {
  $varset->clear();
  $varset->set("name",  $cnst, "text");
  $varset->set("value", $cnst, "text");
  $varset->set("pri",   $pri += $priority_step, "number");
  // $varset->set("class", $class[$key], "quoted");
  $varset->set("id", new_id(), "unpacked" );
  $varset->set("group_id", $group_id, "text" );
  $SQL =  "INSERT INTO constant " . $varset->makeINSERT();
  if ( !MyQuery ($db, $SQL, $fire )) {
    $err["DB"] .= MsgErr("Can't copy constant");
    break;
  }
}


if (count($err) > 1) {
  print("<br><b>Import error!</b>");
  print_r($err);
} else
  print("<br><b>Import successful!</b>");

?>
