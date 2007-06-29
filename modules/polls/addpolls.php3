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

/* Based on phpPolls 1.0.3 from http://phpwizards.net
   also distributed under GPL v2.

   Rewrite and APC-AA integration as module by pavelji (pavel@cetoraz.info)

*/
 // used in init_page.php3 script to include config.php3 from the right directory
$directory_depth = '../';

require_once "../../include/init_page.php3";
require_once AA_INC_PATH. "varset.php3";
require_once AA_INC_PATH. "formutil.php3";
require_once AA_INC_PATH. "varset.php3";
require_once AA_INC_PATH. "mgettext.php3";
require_once AA_INC_PATH. "msgpage.php3";


require_once AA_BASE_PATH."modules/polls/util.php3";   // module specific utils
require_once AA_BASE_PATH."modules/polls/constants.php3";

// id of the editted module
$module_id = $slice_id;               // id in long form (32-digit hexadecimal
                                      // number)
$p_module_id = q_pack_id($module_id); // packed to 16-digit as stored in database

$polls_info = GetModuleInfo($module_id,'P');

// Check permissions for this page.

if (!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_MODP_ADD_POLL)) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("No permission to add/edit poll."), "admin");
  exit;
}


// fill code for handling the operations managed on this page

if ($insert  || $update ) {

  $varset = new CVarset();

  $varset->add("id", "quoted", q_pack_id($module_id));
  $varset->add("pollTitle", "quoted" , $question);
  $varset->add("startDate","number", mktime(0,0,0, $tdctr_from_date_month, $tdctr_from_date_day, $tdctr_from_date_year));
  $varset->add("endDate","number", mktime(0,0,0, $tdctr_to_date_month, $tdctr_to_date_day, $tdctr_to_date_year));
  $varset->add("defaults","number",0);
  $varset->add("designID","number", $design);

  $varset->add("Logging","number", ($Logging ? 1 : 0));
  $varset->add("IPLocking","number", ($IPLocking ? 1 : 0));
  $varset->add("IPLockTimeout","number", $IPLockTimeout);
  $varset->add("setCookies","number", ($setCookies ? 1 : 0));
  $varset->add("cookiesPrefix", "quoted", $cookiesPrefix);

  $varset->add("params", "quoted", $params);

  if ($insert) {
        $SQL = "INSERT INTO polls ". $varset->makeINSERT();
  } elseif ($update ) {
        $SQL = "UPDATE polls SET". $varset->makeUPDATE() ." WHERE (id='".q_pack_id($module_id)."' and pollID='".$poll_id."')";
  }
  if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
     $err["DB"] = MsgErr(($update ? _m("Can't update poll with id ".$poll_id) : _m("Can't insert new poll")));
     break;
  }
  $SQL="";

  if ($insert) {
    $poll_id = get_last_insert_id($db, "polls");
  }

    $varset->clear();

    if (is_array($answers)) {
      reset($answers);
      $i=1;
      while (list(,$v) = each($answers)) {
        $varset->clear();
        if ($insert ) {
          $varset->add("pollID", "quoted", $poll_id);
          $varset->add("voteID", "number", $i);
        }
          $varset->add("optionText", "quoted", $v);
        if ($insert) {
          $SQL = "INSERT INTO polls_data ". $varset->makeINSERT();
        } else {
          $SQL = "UPDATE polls_data SET ".$varset->makeUPDATE()." WHERE (pollID='".$poll_id."') AND (voteID='".$i."')";
        }
        if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
          $err["DB"] = MsgErr(_m("Can't insert new poll data"));
          break;
        }
        $i++;
      }

  }
  unset($insert); unset($update); unset($SQL);
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php if ($polledit) { echo _m("Edit poll"); } else { echo _m("Add poll"); } ?></title>

<?php echo printJS(); ?>

</head> <?php

$db = new DB_AA;

require_once AA_BASE_PATH."modules/polls/menu.php3";
showMenu($aamenus, "addpoll", "main");

?>
<form name="inputform" method=post onSubmit="BeforeSubmit();" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
<p>
<h1><? if ($polledit) { echo _m("Edit poll with id ".$poll_id); } else {echo _m("Add poll");} ?></h1>
<p>

<?
echo "
  <table border=\"0\" cellspacing=\"0\" cellpadding=\"1\" bgcolor=\"". COLOR_TABTITBG ."\" align=\"center\">
  <tr><td class=tabtit><b>&nbsp;". _m("Insert question and answers") ."</b>
  </td>
  </tr>
  <tr><td>
  <table width=\"440\" border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"". COLOR_TABBG ."\">";

if ($polledit) {
  $vars = getPollsValues($module_id, $poll_id, false);

  FrmInputText("question", _m("Question: "), $vars["pollTitle"], 99, 40, true);
  FrmInputWithSelect("answers",_m("Insert new answers and choose their order"),$vars["pollText"],"",25,25,6,1);

  MyFrmSelectDate("from_date", _m("Show poll from: "), $vars["startDate"]);
  MyFrmSelectDate("to_date", _m("till: "), $vars["endDate"]);

} else {

  FrmInputText("question", _m("Question: "), "", 99, 40, true);
  FrmInputWithSelect("answers",_m("Insert new answers and choose their order"),"","",25,25,6,1);

  MyFrmSelectDate("from_date", _m("Show poll from: "), time());
  MyFrmSelectDate("to_date", _m("till: "), time()+604800);
}

echo "
  </table>
  </td></tr>
  </table>";

if ($polledit) {
  $vars = getPollsValues($module_id,$poll_id,false);
} else {
  $vars = getPollsValues($module_id, 0,true);
}
  $designs = getDesignsForPoll($module_id);

?>
<h1><? echo _m("Polls settings"); ?></h1>
<p>
<?php
  printPollsSettings($vars["Logging"], $vars["IPLocking"], $vars["IPLockTimeout"], $vars["setCookies"], $vars["cookiesPrefix"], $vars["params"]);
//  print_r($vars);
?>



<h1><? echo _m("Polls design settings"); ?></h1>
<p>
<?
//  reset($designs);
//  while (list(,$vars2) = each($designs)) {
//    printPollsDesign($vars2["designID"], $vars2["name"], $vars2["comment"], $vars2["resultBarFile"], $vars2["answer"], $vars2["top"], $vars2["bottom"]);
//  }
//  printAliases();
  printPollsDesignChooser($designs,$vars["designID"]);
?>
<p>
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<tr><td>
<?
  echo '<center>';
//  echo "<input type=hidden name=\"insert\" value=1>";
if ($polledit) {
  echo '<input type="hidden" name="poll_id" value="'. $poll_id .'">';
  echo '<input type="hidden" name="polledit" value="1">';
  echo '<input type="submit" name="update" value="'. _m("Update") .'">&nbsp;&nbsp;';
} else {
  echo '<input type="submit" name="insert" value="'. _m("Insert") .'">&nbsp;&nbsp;';
}
  echo '<input type="reset" value="'. _m("Reset") .'">&nbsp;&nbsp;';
  echo '<input type="submit" name="cancel" value="'. _m("Cancel") .'">';
  echo '</center>';
?>
</td></tr>
</table>
</td></tr>
</table>
</form>
</body>
</html>

<?
page_close();
exit;
?>
