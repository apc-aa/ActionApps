<?php
//$Id$
/* 
Copyright (C) 1999, 2000 Association for Progressive Communications 
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

# expects $slice_id to be set

/* ////////////////////////////////////////////////////////////////////

 ////////////////////////////////////////////////////////////////////
 INDEX to se_notify
 ////////////////////////////////////////////////////////////////////

 This page has two modes:
   1. update mode-- when someone has filled been to this page before, and has
      clicked on update.
   2. new to page mode -- they have come to the page for the first time

 Therefore, the code for this page has four parts
   I) Standard initialization and sanity checks
  II) if in 'update mode', do the update to the database
 III) prepare to write the form (select from the database)
  IV) write the form (HTML output)

*/

////////////////////////////////////////////////////////////////////
// I) Standard initialization and sanity checks
////////////////////////////////////////////////////////////////////

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
#require $GLOBALS[AA_INC_PATH]."util.php3";

// sanity checks

if($cancel){
  go_url( $sess->url(self_base() . "index.php3"));
}

if(! $slice_id){
  MsgPage($sess->url(self_base())."index.php3", "error on se_notify.php3", "standalone");
  exit;
}

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_EDIT, "standalone");
  exit;
}

// initialization of vars needed by the next 3 parts of the program

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$superadmin = IsSuperadmin();


////////////////////////////////////////////////////////////////////
//  II) if in 'update mode', do the update to the database
////////////////////////////////////////////////////////////////////

if( $update ) {

  //validate input
  //  ValidateInput("name", L_SLICE_NAME, $name, $err, true, "text");

  // check to make sure we passed our validation cleanly
  if( count($err) > 1)
      break;

  // if we passed our validation, change the slice records
  $up  = "notify_holding_item_s = '" . addslashes($notify_holding_item_s) . "',\n ";
  $up .= "notify_holding_item_edit_s = '" . addslashes($notify_holding_item_edit_s) . "',\n ";
  $up .= "notify_holding_item_b = '" . addslashes($notify_holding_item_b) . "',\n";
  $up .= "notify_holding_item_edit_s = '" . addslashes($notify_holding_item_edit_s) . "',\n";
  $up .= "notify_holding_item_edit_b = '" . addslashes($notify_holding_item_edit_b) . "',\n";
  $up .= "notify_active_item_s = '" . addslashes($notify_active_item_s) . "',\n";
  $up .= "notify_active_item_b = '" . addslashes($notify_active_item_b) . "',\n";
  $up .= "notify_active_item_edit_s = '" . addslashes($notify_active_item_edit_s) . "',\n";
  $up .= "notify_active_item_edit_b = '" .addslashes($notify_active_item_edit_b) ."' ";

  $SQL= "UPDATE slice set $up WHERE id = '$p_slice_id'";
  $result = $db->query($SQL);

  // in email_notify

  // delete all the records in email_notify relating to this slice
  // keep a backup until our transaction has gone through. 
  //   ideally we would have transaction that would roll back, but mysql
  //   does not have these transactions
  $SQL= "delete from email_notify where slice_id = 'not_transition'";
  $result = $db->query($SQL);

  $SQL= "update email_notify SET slice_id = 'not_transition' WHERE slice_id='$p_slice_id'";
  $result = $db->query($SQL);

  // insert all the records into email_notify

  // split $notify_holding_item_e textarea into array
  $z = 1;  $arr = split("\n", $notify_holding_item_e);
  reset ($arr);
  $y = "'" . q_pack_id($slice_id) . "'";
  while (list(, $uid) = each ($arr)) {
    if ( ! strstr($uid, '@') )
      continue;
   $uid = clean_email($uid);
   $SQL = "INSERT INTO email_notify (uid, slice_id, function) VALUES ( '$uid', $y, $z)";
   $result = $db->query($SQL);
  }

  // split $notify_holding_item_edit_e textarea into array
  $z = 2;  $arr = split("\n", $notify_holding_item_edit_e);
  reset ($arr);
  $y = "'" . q_pack_id($slice_id) . "'";
  while (list(, $uid) = each ($arr)) {
    if ( ! strstr($uid, '@') )
      continue;
   $uid = clean_email($uid);
   $SQL = "INSERT INTO email_notify (uid, slice_id, function) VALUES ( '$uid', $y, $z)";
   $result = $db->query($SQL);
  }

  // split $notify_active_item_e textarea into array
  $z = 3;  $arr = split("\n", $notify_active_item_e);
  reset ($arr);
  $y = "'" . q_pack_id($slice_id) ."'";
  while (list(, $uid) = each ($arr)) {
    if ( ! strstr($uid, '@') )
      continue;
   $uid = clean_email($uid);
   $SQL = "INSERT INTO email_notify (uid, slice_id, function) VALUES ( '$uid', $y, $z)";
   $result = $db->query($SQL);
  }

  // split $notify_active_item_edit_e textarea into array
  $z = 4;  $arr = split("\n", $notify_active_item_edit_e);
  reset ($arr);
  $y = "'" . q_pack_id($slice_id) ."'";
  while (list(, $uid) = each ($arr)) {
    if ( ! strstr($uid, '@') )
      continue;
   $uid = clean_email($uid);
   $SQL = "INSERT INTO email_notify (uid, slice_id, function) VALUES ( '$uid', $y, $z)";
   $result = $db->query($SQL);
  }

  // delete the backed-up users
  $SQL= "DELETE FROM email_notify WHERE slice_id='not_transition'";
  $result = $db->query($SQL);

} 

////////////////////////////////////////////////////////////////////
//  III) prepare to write the form (select from the database)
////////////////////////////////////////////////////////////////////

// grab variables from the table slice

$SQL= " 
SELECT notify_holding_item_s,      notify_holding_item_b,  
       notify_holding_item_edit_s, notify_holding_item_edit_b, 
       notify_active_item_s,  notify_active_item_b,
       notify_active_item_edit_s,  notify_active_item_edit_b
 FROM slice WHERE id='".q_pack_id($slice_id)."'";
$db->query($SQL);
if ($db->next_record())
  while (list($key,$val,,) = each($db->Record)) {
    if( EReg("^[0-9]*$", $key))
      continue;
    $$key = $val; // variables and database fields have identical names
  }

// grab variables from email_notify
$SQL= "SELECT uid, function FROM email_notify WHERE slice_id='".q_pack_id($slice_id)."'";
$notify_active_item_edit_e = $notify_active_item_e = $notify_holding_item_edit_e = $notify_holding_item_e = '';

$result = $db->query($SQL);
while ($row = mysql_fetch_array ($result)){
  //  echo $row['uid'] . " " . $row['function'] . "<BR>";
  // if the uid does not have an @ in it, we should really do a lookup in users
  if ($row['function'] == 1){
    $notify_holding_item_e .= $row['uid'] . "\n";
    //    echo "<h1>MATCH</h1>";
  }
  else if ($row['function'] == 2)
    $notify_holding_item_edit_e .= $row['uid'] . "\n";
  else if ($row['function'] == 3)
    $notify_active_item_e .= $row['uid'] . "\n"; 
  else if ($row['function'] == 4)
    $notify_active_item_edit_e .= $row['uid'] . "\n"; 
}
//exit;
////////////////////////////////////////////////////////////////////
//   IV) write the form  (HTML output)
////////////////////////////////////////////////////////////////////

// Print HTML start page tags (html begin, encoding, style sheet, but no title)
HtmlPageBegin();
echo '<TITLE> '. L_A_NOTIFY_TIT. '</TITLE></HEAD>';
  $xx = ($slice_id!="");
$show["notify"] = false;
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable
  
  echo "<H1><B>" . L_A_NOTIFY_TIT . "</B></H1>";
  PrintArray($err);
  echo $Msg;  
?>

<form method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">

<tr><td class=tabtit><b>&nbsp;<?php echo L_A_NOTIFY_TIT?></b>
</td>
</tr>

<tr><td>

<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
echo "<TR><TD COLSPAN =2>". L_NOTIFY_HOLDING . "</TD></TR>"; 
  FrmTextArea("notify_holding_item_e", L_NOTIFY_EMAILS, $notify_holding_item_e, 3, 40, false);
  FrmInputText("notify_holding_item_s", L_NOTIFY_SUBJECT, $notify_holding_item_s, 99, 40, true);
  FrmTextArea("notify_holding_item_b", L_NOTIFY_BODY, $notify_holding_item_b, 3, 40, true);

echo "<TR><TD COLSPAN =2>". L_NOTIFY_HOLDING_EDIT . "</TD></TR>"; 
FrmTextArea("notify_holding_item_edit_e", L_NOTIFY_EMAILS, $notify_holding_item_edit_e, 3, 40, false);
  FrmInputText("notify_holding_item_edit_s", L_NOTIFY_SUBJECT, $notify_holding_item_edit_s, 99, 40, true);
  FrmTextArea("notify_holding_item_edit_b", L_NOTIFY_BODY, $notify_holding_item_edit_b, 3, 40, true);

echo "<TR><TD COLSPAN =2>". L_NOTIFY_APPROVED . "</TD></TR>"; 
  FrmTextArea("notify_active_item_e", L_NOTIFY_EMAILS, $notify_active_item_e, 3, 40, false);
  FrmInputText("notify_active_item_s", L_NOTIFY_SUBJECT, $notify_active_item_s, 99, 40, true);
  FrmTextArea("notify_active_item_b", L_NOTIFY_BODY, $notify_active_item_b, 3, 40, true);

echo "<TR><TD COLSPAN =2>". L_NOTIFY_APPROVED_EDIT . "</TD></TR>"; 
FrmTextArea("notify_active_item_edit_e", L_NOTIFY_EMAILS, $notify_active_item_edit_e, 3, 40, false);
  FrmInputText("notify_active_item_edit_s", L_NOTIFY_SUBJECT, $notify_active_item_edit_s, 99, 40, true);
  FrmTextArea("notify_active_item_edit_b", L_NOTIFY_BODY, $notify_active_item_edit_b, 3, 40, true);

?>
</table>
</td></tr><tr><td align="center">
<?php
  echo "<input type=hidden name=\"update\" value=1>";
  echo '<input type=submit name=update value="'. L_UPDATE .'">&nbsp;&nbsp;';
  echo '<input type=reset value="'. L_RESET .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. L_CANCEL .'">';
?>

</td></tr></table></FORM></BODY></HTML>

<?php
page_close();
?>

