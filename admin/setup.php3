<?php 
// $Id$
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

///////////////////////////////////////////////////////////////////////////

# handle with PHP magic quotes - quote the variables if quoting is set off
function Myaddslashes($val, $n=1) {
  if (!is_array($val)) {
    return addslashes($val);
  }  
  for (reset($val); list($k, $v) = each($val); )
    $ret[$k] = Myaddslashes($v, $n+1);
  return $ret;
}    

if (!get_magic_quotes_gpc()) { 
  // Overrides GPC variables 
  if( isset($HTTP_GET_VARS) AND is_array($HTTP_GET_VARS))
    for (reset($HTTP_GET_VARS); list($k, $v) = each($HTTP_GET_VARS); ) 
      $$k = Myaddslashes($v); 
  if( isset($HTTP_POST_VARS) AND is_array($HTTP_POST_VARS))
    for (reset($HTTP_POST_VARS); list($k, $v) = each($HTTP_POST_VARS); ) 
      $$k = Myaddslashes($v); 
  if( isset($HTTP_COOKIE_VARS) AND is_array($HTTP_COOKIE_VARS))
    for (reset($HTTP_COOKIE_VARS); list($k, $v) = each($HTTP_COOKIE_VARS); ) 
      $$k = Myaddslashes($v); 
}

require_once ("../include/config.php3");
require_once ($GLOBALS["AA_INC_PATH"] . "locsessi.php3");
require_once ($GLOBALS["AA_INC_PATH"] . "perm_core.php3");
require_once ($GLOBALS["AA_INC_PATH"] . "perm_" . PERM_LIB . ".php3");
require_once ($GLOBALS["AA_INC_PATH"] . "util.php3");
require_once ($GLOBALS["AA_INC_PATH"] . "formutil.php3");
require_once ($GLOBALS["AA_INC_PATH"] . "mgettext.php3");
bind_mgettext_domain ($GLOBALS["AA_INC_PATH"]."lang/".DEFAULT_LANG_INCLUDE);

function HtmlStart() {
   HTMLPageBegin ("../".ADMIN_CSS);
   echo "<title>" . _m("AA Setup") . "</title></head>\n";
   echo "<body bgcolor=\"". COLOR_BACKGROUND ."\">\n";
   echo "<center>\n";
   echo "<h1>" . _m("AA Setup") . "</h1>\n";
}

function NoAction() {
    echo _m("This script can't be used on a configured system.");
}

function PrintErr($err) {
   while (list(,$value) = each($err)) {
      echo $value;
   }
}

function SuperForm() {
  global $sess;
  global $login, $password1, $password2, $fname, $lname, $email;
  ?>
  <form action="setup.php3">
    <table border="0" cellspacing="0" cellpadding="1" width="440"
           bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
      <tr><td class=tabtit><b><?php echo _m("Superadmin account"); ?></b></td></tr>
      <tr><td>
         <table border="0" cellspacing="0" cellpadding="4" width="100%"
                bgcolor="<?php echo COLOR_TABBG ?>" align=center>
            <?php 
              FrmInputText("login", _m("Login name"), $login, 12, 30, true);
              FrmInputPwd("password1", _m("Password"), $password1, 12, 30, true);
              FrmInputPwd("password2", _m("Retype Password"), $password2, 12, 30, true);
              FrmInputText("fname", _m("First name"), $fname, 50, 30, true);
              FrmInputText("lname", _m("Last name"), $lname, 50, 30, true);
              FrmInputText("email", _m("E-mail"), $email, 50, 30, false);
            ?>
         </table>
      <tr><td align="center">
         <?php $sess->hidden_session(); ?>
         <input type=submit name=phase value="<?php echo _m("Create") ?>">
    </td></tr>
    </table>
  </form>
  <?php 
}

function InitForm() {
   global $sess;
   echo _m("Welcome! Use this script to create the superadmin account.<p>If you are installing a new copy of AA, press <b>Init</b>.<br>");
   echo _m("If you deleted your superadmin account by mistake, press <b>Recover</b>.<br>");
   ?>
   <form method=get action="setup.php3">
   <?php $sess->hidden_session(); ?>
   <input type=submit name=phase value="<?php echo _m(" Init "); ?>">
   <input type=submit name=phase value="<?php echo _m("Recover"); ?>">
   </form>
   <?php
}
 
function HtmlEnd() {
   echo "</center></body></html>";
}

///////////////////////////////////////////////////////////////////////////

page_open(array("sess" => "AA_SL_Session"));

// Discover current state in AA object perms

if ($perms = GetObjectsPerms(AA_ID, "aa")) {
   while (list($key, $value) = each ($perms)) {
      if (!$value["type"]) {
         $notusers[$key] = $value;      // non-existent user/group
      } else if ($value["perm"] != $perms_roles["SUPER"]['id']) {
         $others[$key] = $value;        // other than super privilege
      } else if (stristr($value["type"], _m("User"))) {
         $superusers[$key] = $value;    // users with super privileges
      } else {
         $supergroups[$key] = $value;   // groups with super privileges
      }
   }
}

// Consider only non-empty superadmin groups

if (isset($supergroups)) {
   while (list($key,$value) = each ($supergroups)) {
      $members = GetGroupMembers($key);
      if (count($members)) {
         $nonemptysupergroups[] = $value;
      }
   }
   $supergroups = $nonemptysupergroups;
}

HtmlStart();

switch ($phase) {

   case _m(" Init "): 
      if ($superusers || $supergroups) {
         NoAction();
         break;
      }
      
      if (AddPermObject(AA_ID, "aa")) {
         SuperForm();
      } else {         // Either AA_ID exists or there is more severe error
         echo "<p>", _m("Can't add primary permission object.<br>Please check the access settings to your permission system.<br>If you just deleted your superadmin account, use <b>Recover</b>"), "</p>";
      }

      break;

   case _m("Recover"):

      if ($superusers || $supergroups) {
         NoAction();
         break;
      } 
      
      if (isset($notusers)) {                 // Delete orphan permissions 
         while (list($key,$value) = each ($notusers)) {
            if (!DelPerm ($key, AA_ID, "aa")) {
               echo _m("Can't delete invalid permission."), "$key<br>";
            } else {
               echo _m("Invalid permission deleted (no such user/group): "), "$key<br>";
            }
         }
      }
      
      // Print the account form
      SuperForm();
      
      $recover = true;
      $sess->register("recover");
      
      break;

   case _m("Create"): 

      if ($superusers || $supergroups) {
         NoAction();
         break;
      }
   
      ValidateInput("login", _m("Login name"), $login, $err, true, "login");
      ValidateInput("password1", _m("Password"), $password1,
                    $err, true, "password");
      ValidateInput("password2", _m("Retype Password"), $password2,
                    $err, true, "password");
      ValidateInput("fname", _m("First name"), $fname, $err, true, "all");
      ValidateInput("lname", _m("Last name"), $lname, $err, true, "all");
      ValidateInput("email", _m("E-mail"), $email, $err, false, "email");
   
      if( $password1 != $password2 ) {
         $err[$password1] = MsgErr(_m("Retyped password is not the same as the first one"));
      }

      if (count($err)) {        // Insufficient input data
         PrintErr($err);
         SuperForm();
         break;
      }
      
      // Input data are OK, prepare the record
   
      $super["uid"] = $login;
      $super["userpassword"] = $password1;
      $super["givenname"] = $fname;
      $super["sn"] = $lname;
      if ($email) { 
         $super["mail"][] = $email;
      } else {
         $super["mail"][] = "";
      }
      
      // Try to create the account
         
      $superid = AddUser($super);
   
      if (!$superid) {               // No success :-(
         echo _m("It is impossible to add user to permission system");
         break;
      }
      
      // Assign super admin privilege
      
      AddPerm($superid, AA_ID, "aa", $perms_roles["SUPER"]['id']);
      
      // Check whether succefful
      
      $perms = GetObjectsPerms(AA_ID, "aa");

      if ($perms[$superid]) {
         echo _m("Congratulations! The account was created.");
         if (!$recover) {
            echo "<p>", _m("Use this account to login and add your first slice:"), "<p>";
            echo "<a href=\"sliceadd.php3\">" . _m("Add Slice") . "</a>";
         }
      } else {
         echo _m("Can't assign super access permission.");
      }

      break;

   default:

      if ($superusers || $supergroups) {
         NoAction();
         break;
      }
      
      // Print the welcome page
      
      InitForm();
      break;

}

HtmlEnd();

page_close();
?>
