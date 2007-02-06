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

require_once AA_INC_PATH."mgettext.php3";
require_once AA_INC_PATH."varset.php3";
//require_once AA_INC_PATH."tabledit.php3";
require_once AA_INC_PATH."date.php3";
require_once AA_BASE_PATH."modules/polls/constants.php3";

// Miscellaneous utility functions for the module

// function printJS prints needed support javascript rutines
// (grabbed from fillform.js with additional changes)
function printJS() {
  return '
    <script Language="JavaScript"><!--
      function SelectAllInBox( listbox ) {
        var len = eval(listbox).options.length
        for (var i = 0; i < eval(listbox).options.length; i++) {
          // select all rows without the wIdThTor one, which is only for <select> size setting
          eval(listbox).options[i].selected = ( eval(listbox).options[i].value != "wIdThTor" );

        }
      }

      var box_index=0;   // index variable for box input fields
      var listboxes=Array(); // array of listboxes where all selection should be selected
      var relatedwindow;  // window for related stories

      // before submit the form we need to select all selections in some
      // listboxes (2window, relation) in order the rows are sent for processing
      function BeforeSubmit() {
        for (var i = 0; i < listboxes.length; i++)
          SelectAllInBox( listboxes[i] );
          return true;
      }
    //-->
    </script>
';
}

// prints "headline" from poll in Polls Manager (called from index.php3)
//
// module_polls - polls id
// poll_id - id of one poll
// pollTitle - it's title
// startDate, endDate - it's validity
function showOnePollTitle($module_polls, $poll_id, $pollTitle, $startDate, $endDate){

//  $startdatectrl = new datectrl("startDate", 1, 4, false, false);
//  $startdatectrl->setdate_int($startDate);
//  $enddatectrl = new datectrl("endDate", 1, 4, false, false);
//  $enddatectrl->setdate_int($endDate);

  global $sess;

  echo "<tr><td>";
  echo "<input type=\"checkbox\" name=\"sP[]\" value=\"$poll_id\">";
  echo "</td><td>$poll_id</td><td>";
  echo "<a href=\"";
  echo $sess->url("./addpolls.php3")."&poll_id=$poll_id&id=$module_polls&polledit=1";
  echo "\"><b>$pollTitle</b></a></td>
  <td>".date("d.n.Y",$startDate)."</td><td>".date("d.n.Y", $endDate)."</td></tr>";
}

// function for printing date selectboxes (3 at all - for day, month, year)
//
// name - used for generating name in html tag
// text - caption
// date - date to show (in timestamp);
// required - is this field required?
// help, morehelp - prints additional informations
function MyFrmSelectDate($name, $text, $date, $required=true, $help="", $morehelp=""){
  $datectrl = new datectrl($name, 1, 4, true, false);
  $datectrl->setdate_int($date);

  FrmStaticText($text, $datectrl->getselect(), $required,
                $help, $morehelp, "0" );

}

// used for easy getting values for poll
//
// id - polls id
// poll_id - if set, we get info only for this poll
// defaults - if set, we get defaults info, else for certain poll
//
// values are returned in associative array
function getPollsValues($id, $poll_id, $defaults=false){
  global $db;
  $vars = new CVarset();

  if ($defaults) {
    $SQL = "SELECT * FROM polls WHERE (id='".q_pack_id($id)."') AND (defaults='1')";
  } else {
    $SQL = "SELECT * FROM polls WHERE (id='".q_pack_id($id)."') AND (pollID='".$poll_id."')";
  }
  $db->query($SQL);
  if ( !$db->next_record() ) {
    $err["DB"] = MsgErr("nejaka chybka se stala");
    break;
  }
  $vars = $db->Record;

  if (!$defaults) {
    $SQL = "SELECT optionText AS text FROM polls_data WHERE (pollID='".$poll_id."')";
    $db->query($SQL);

    while ($db->next_record() ){
      $options[$db->f("text")] = $db->f("text");
    }
    $vars["pollText"] = $options;
  }
  return $vars;
}

function getDesignsForPoll($module_id) {
  global $db;

  $SQL = "SELECT * FROM polls_designs WHERE (pollsModuleID='".q_pack_id($module_id)."')";
  $db->query($SQL);
  while ($db->next_record()) {
    $designs[]=$db->Record;
  }
  return $designs;
}


// prints form for setting main values of polls module
//
// id - module polls id
// name - module polls name
// slice_url - module polls url for showing
function printPollsModuleSettings($id, $name, $slice_url) {
echo "
  <table border=\"0\" cellspacing=\"0\" cellpadding=\"1\" bgcolor=\"". COLOR_TABTITBG ."\" align=\"center\">
  <tr><td class=tabtit><b>&nbsp;". _m("Module Polls setting") ."</b>
  </td>
  </tr>
  <tr><td>
  <table width=\"440\" border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"". COLOR_TABBG ."\">
  <tr><td>";
  FrmStaticText(_m("ID"), unpack_id($id));
  FrmInputText("name", _m("Polls name"), $name, 99, 25, true);
  FrmInputText("slice_url", _m("Polls URL"), $slice_url, 254, 25, false);
echo "
  </td></tr>
  </table>
  </td></tr>
  </table>";

}

// prints form for setting polls module (eg. ip locking, cookies, ...)
//
// logging - (true/false) log all to db
// ip_locking - (true/false) use ip locking
// ip_locking_timeout - (int) timeout of ip locking
// cookies - (true/false) use cookies to identify browser
// cookies_prefix - (string) prefix of cookies
function printPollsSettings($logging, $ip_locking, $ip_locking_timeout, $cookies, $cookies_prefix, $params){
global $sess;

  $aaa= "
  <script language=\"JavaScript\" type=\"text/javascript\">
  <!--
  /* Calls the parameters wizard. Parameters are as follows:
    list = name of the array containing all the needed data for the wizard
    combo_list = a combobox of which the selected item will be shown in the wizard
    text_param = the text field where the parameters are placed
  */
  function CallParamWizard(list, combo_list, text_param ) {
    page = \"". $sess->url("param_wizard.php3")."\"
        + \"&list=\" + list + \"&text_param=\" + text_param;
    page += \"&item=1\";
    param_wizard = window.open(page,\"somename\",\"width=450,scrollbars=yes,menubar=no,hotkeys=no,resizable=yes\");
    param_wizard.focus();
  }
  //-->
  </script>
  ";

    echo "<center>
        <table border=\"0\" cellspacing=\"0\" cellpadding=\"1\" bgcolor=\"". COLOR_TABTITBG ." align=\"center\">
        <tr><td class=tabtit><b>&nbsp;". _m("Polls settings") ."</b>
        </td>
        </tr>
        <tr><td>
        <table width=\"440\" border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"". COLOR_TABBG ."\">
        <tr><td>";
      FrmInputChBox("Logging", _m("Use logging"), $logging);
      FrmInputChBox("IPLocking", _m("Use IP locking"), $ip_locking);
      FrmInputText("IPLockTimeout", _m("IP Locking timeout"), $ip_locking_timeout);
      FrmInputChBox("setCookies", _m("Use cookies"), $cookies);
      FrmInputText("cookiesPrefix", _m("Cookies prefix"), $cookies_prefix);
      FrmInputText("params", _m("Parameters"), $params);
/*  	   echo "<tr><td><a href='javascript:CallParamWizard  (\"POLL_PARAMS\", \"pol\", \"params\")'>".L_PARAM_WIZARD_LINK."</a></td></tr>";*/
    echo "
        </td></tr>
        </table>
        </td></tr>
        </table>
        </center>";
}

// prints form for setting polls design
//
// design_type - (int) type of view (obsolete)
// bar_image - (string) url for bar image
// design_question - (string) design string for one question (answer) - aliases can be used
// design_top - (string) design string for top of poll - aliases can be used
// design_bottom - (string) design string for bottom of poll - aliases can be used
// insert - (1/0) insert (=1) or update (=0)
function printPollsDesign($design_id, $design_name, $design_comment, $bar_image, $bar_image_width, $bar_image_height,
                            $design_question, $design_top, $design_bottom, $params, $insert=0) {
    echo "<center>";
    if ($insert == 0) {
      echo "<script language=\"JavaScript\">
        <!--
          // easy confirm function
          function removeQuestion() {
            var agree=confirm(\""._m("Are You sure to delete this design?")."\");
            if (agree)
                return true ;
            else
                return false ;
          }
        //-->
      </script>";
    }
    echo"	<table border=\"0\" cellspacing=\"0\" cellpadding=\"1\" bgcolor=\"". COLOR_TABTITBG ." align=\"center\">
        <tr><td class=tabtit><b>&nbsp;". _m("Polls design") ."</b>
        </td>
        </tr>
        <tr><td>
        <table width=\"440\" border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"". COLOR_TABBG ."\">
        <tr><td>";
          FrmStaticText($design_id, _m("Design ID"));
          FrmInputText($design_id."_design_name", _m("Design name"), $design_name);
          FrmTextarea($design_id."_design_comment", _m("Design comment"), $design_comment,4,40);
          FrmInputText($design_id."_resultBarFile", _m("URL to bar image"), $bar_image);
          FrmInputText($design_id."_resultBarWidth", _m("Width of bar image"), $bar_image_width);
          FrmInputText($design_id."_resultBarHeight", _m("Height of bar image"), $bar_image_height);
          FrmTextarea($design_id."_top_format", _m("HTML design of top part of poll"), $design_top, 4, 40);
          FrmTextarea($design_id."_answer_format", _m("HTML design of one answer"), $design_question, 4, 40);
          FrmTextarea($design_id."_bottom_format", _m("HTML design of bottom part of poll"), $design_bottom, 4, 40);
          FrmInputText($design_id."_params", _m("Parameters"), $params);
    echo "
        </td></tr>";
        if ($insert == 0) {
            echo "<tr><td colspan=2 align=center>
            <input type=\"hidden\" name=\"design_id\" value=\"".$design_id."\">
                    &nbsp;&nbsp;<input type=\"submit\" name=\"update\" value=\""._m("Update")."\">
            &nbsp;&nbsp;<input type=\"reset\" value=\""._m("Reset")."\">
            &nbsp;&nbsp;<input type=\"submit\" name=\"cancel\" value=\""._m("Cancel")."\">
                        &nbsp;&nbsp;<input type=\"submit\" name=\"remove\" onClick=\"return removeQuestion();\" value=\""._m("Remove design")."\"></td></tr>";
        }
        echo "
        </table>
        </td></tr>
        </table>
        </center>";
}

function printPollsDesignChooser($designs, $selected, $edit=0, $this_url="") {
global $module_id, $js_trig;
    echo "<center>
        <table border=\"0\" cellspacing=\"0\" cellpadding=\"1\" bgcolor=\"". COLOR_TABTITBG ." align=\"center\">
        <tr><td class=tabtit><b>&nbsp;". _m("Polls design") ."</b>
        </td>
        </tr>
        <tr><td>
        <table width=\"440\" border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"". COLOR_TABBG ."\">
        <tr><td>";
        reset($designs);
        while (list($k, $v) = each($designs)) {
          $arr[$v["designID"]] = $v["name"]." (".$v["comment"].")";
        }
        if ($edit == 1) {
          echo "<script language=\"JavaScript\"> <!--
                   function aa_onChange(id) {
                     document.location=\"".$this_url."&v".$module_id."=\"+id+\"#edit\";
                   }
                //--></script>";

          echo "<tr align=left><td class=tabtxt><b>"._m("Select design type")."</b>";
          echo "</td>\n";
          echo "<td><select name=\"design\" onChange=\"aa_onChange(this.options[this.selectedIndex].value)\">";
          reset($arr);
          while (list($k, $v) = each($arr)) {
            if ( $usevalue )                    // special parameter to use values instead of keys
              $k = $v;
            echo "<option value=\"". htmlspecialchars($k)."\"";
            if ((string)$selected == (string)$k)
              echo " selected";
            echo "> ". htmlspecialchars($v) ." </option>";
          }
          reset($arr);
          echo "</select>";
          PrintMoreHelp($morehlp);
          PrintHelp($hlp);
          echo "</td></tr>\n";


        } else {
          FrmInputSelect("design",_m("Select design type"), $arr, $selected);
        }
    echo "
        </td></tr>
        </table>
        </td></tr>
        </table>
        </center>";
    unset($js_trig);
}

function printAliases(){
  global $aliases;
  echo "<center><table>";
  PrintAliasHelp($aliases);
  echo "</table></center>";
}

function registerVote($poll, $vote) {
  global $db;
  global $REMOTE_ADDR;
  $vote_invalid = "";
  $current_time = time();

// checkig for duplicated votes - IPLocking method
  if ($poll["IPLocking"] == 1) {
    $SQL = "SELECT * FROM polls_ip_lock WHERE pollID='". $poll["pollID"]."'";
    $db->query($SQL);

    while ($db->next_record() ){
      $ip_lock = $db->Record;
      if (($ip_lock["timeStamp"]+$poll["IPLockTimeout"]) < $current_time) {
        $SQL = "DELETE FROM polls_ip_lock WHERE timeStamp='".$ip_lock["timeStamp"]."'";
        $db->query($SQL);
        unset($ip_lock);
      }
    }
    $SQL = "SELECT * FROM polls_ip_lock WHERE (pollID='". $poll["pollID"]."') AND (votersIP = '".$REMOTE_ADDR."')";
    $db->query($SQL);
        $count=0;
        while ($db->next_record()){
          $count++;
        }
    if ($count == 0) {
      $SQL = "INSERT INTO polls_ip_lock (pollID, voteID, votersIP, timeStamp) VALUES ('".$poll["pollID"]."', '".$vote."', '".$REMOTE_ADDR."', '".$current_time."')";
      $db->query($SQL);
    } else {
      $vote_invalid = "IP";
    }
  // end IPLocking
  }

// checkig for duplicated votes - Cookies method
  if ($poll["setCookies"] == 1) {
    $SQL = "SELECT startDate FROM polls WHERE pollID='".$poll["pollID"]."'";
    $db->query($SQL);
    $db->next_record();
    $startDate = $db->f("startDate");
    $cookie = $poll["cookiesPrefix"].$startDate;
    if ($$cookie == "1") {
      $vote_invalid = "Cookie";
    } else {
      setCookie($cookie, "1");
    }
  // end Cookies
  }
//  echo "your ip: $REMOTE_ADDR vote_invalid: $vote_invalid";
  if ($vote_invalid == "") {
    $SQL = "UPDATE polls_data SET optionCount=optionCount+1 WHERE (pollID='".$poll["pollID"]."') AND (voteID='".$vote."')";
    $db->query($SQL);
    if ($poll["Logging"] == 1) {
      $SQL = "INSERT INTO polls_log (pollID, voteID, votersIP, timeStamp) VALUES ('".$poll["pollID"]."', '".$vote."', '".$REMOTE_ADDR."', '".$current_time."')";
      $db->query($SQL);
    }
  }

//end registerVote
}


?>
