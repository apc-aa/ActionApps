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

require ("../include/config.php3");
require ("$GLOBALS[AA_INC_PATH]" . "locsessi.php3");
require ("$GLOBALS[AA_INC_PATH]" . "perm_core.php3");
require ("$GLOBALS[AA_INC_PATH]" . "perm_" . PERM_LIB . ".php3");
require ("$GLOBALS[AA_INC_PATH]" . "util.php3");
require ("$GLOBALS[AA_INC_PATH]" . "formutil.php3");
require ("$GLOBALS[AA_INC_PATH]" . DEFAULT_LANG_INCLUDE);


function HtmlStart() {
   echo L_SETUP_PAGE_BEGIN;
   echo "<title>" . L_SETUP_TITLE . "</title></head>\n";
   echo "<body bgcolor=\"#F5F0E7\" link=\"#D20000\" vlink=\"#D20000\">\n";
   echo "<center>\n";
   echo "<h1>" . L_SETUP_H1 . "</h1>\n";
}

function NoAction() {
    echo L_SETUP_NO_ACTION;
}

function PrintErr($err) {
   while (list(,$value) = each($err)) {
      echo $value;
   }
}

function SuperForm() {
   global $myurl;
   global $login, $password1, $password2, $fname, $lname, $email;
   ?>
   <form method=post action="<?php echo $myurl; ?>">
       <table border="0" cellspacing="0" cellpadding="1" width="440"
              bgcolor="#584011" align="center">
          <tr><td class=tabtit><b><?php echo L_SETUP_USER; ?></b></td></tr>
          <tr><td>
             <table border="0" cellspacing="0" cellpadding="4" width="100%"
                    bgcolor="#EBDABE" align=center>
                <?php 
                   FrmInputText("login", L_SETUP_LOGIN, $login, 12, 30, true);
                   FrmInputPwd("password1", L_SETUP_PWD1, $password1, 12, 30, true);
                   FrmInputPwd("password2", L_SETUP_PWD2, $password2, 12, 30, true);
                   FrmInputText("fname", L_SETUP_FNAME, $fname, 50, 30, true);
                   FrmInputText("lname", L_SETUP_LNAME, $lname, 50, 30, true);
                   FrmInputText("email", L_SETUP_EMAIL, $email, 50, 30, false);
                ?>
             </table>
          <tr><td align="center">
             <input type=submit name=phase value="<?php echo L_SETUP_CREATE ?>">
          </td></tr>
       </table>
   </form>
   <?php 
}

function InitForm() {
   global $myurl;
   echo L_SETUP_INFO1;
   echo L_SETUP_INFO2;
   ?>
   <form method=post action="<?php echo $myurl; ?>">
   <input type=submit name=phase value="<?php echo L_SETUP_INIT; ?>">
   <input type=submit name=phase value="<?php echo L_SETUP_RECOVER; ?>">
   </form>
   <?php
}

function HtmlEnd() {
   echo "</center></body></html>\n";
}

///////////////////////////////////////////////////////////////////////////

page_open(array("sess" => "AA_SL_Session"));

$myurl = $sess->url($PHP_SELF);

// Discover current state in AA object perms

if ($perms = GetObjectsPerms(AA_ID, "aa")) {
   while (list($key, $value) = each ($perms)) {
      if (!$value["type"]) {
         $notusers[$key] = $value;      // non-existent user/group
      } else if ($value["perm"] != $perms_roles_id["SUPER"]) {
         $others[$key] = $value;        // other than super privilege
      } else if (stristr($value["type"], L_USER)) {
         $superusers[$key] = $value;    // users with super privileges
      } else {
         $supergroups[$key] = $value;   // groups with super privileges
      }
   }
}

if ($debug) {
   echo "<h3>PERMS</h3>";
   p_arr_m($perms);
   echo "<h3>NOTUSERS</h3>";
   if ($notothers) { p_arr_m($notusers); }
   echo "<h3>OTHERS</h3>";
   if ($others) { p_arr_m($others); }
   echo "<h3>SUPER USERS</h3>";
   if ($superusers) { p_arr_m($superusers); }
   echo "<h3>SUPER GROUPS</h3>";
   if ($supergroups) { p_arr_m($supergroups); }
   echo "<p>";
}

HtmlStart();

switch ($phase) {

   case L_SETUP_INIT: 

      if ($superusers || $supergroups) {
         NoAction();
         break;
      }
      
      if (AddPermObject(AA_ID, "aa")) {
         SuperForm();
      } else {         // Either AA_ID exists or there is more severe error
         echo "<p>", L_SETUP_TRY_RECOVER, "</p>";
      }

      break;

   case L_SETUP_RECOVER:

      if ($superusers || $supergroups) {
         NoAction();
         break;
      } 
      
      if (isset($notusers)) {                 // Delete orphan permissions 
         while (list($key,$value) = each ($notusers)) {
            if (!DelPerm ($key, AA_ID, "aa")) {
               echo L_SETUP_ERR_DELPERM, "$key<br>";
            } else {
               echo L_SETUP_DELPERM, "$key<br>";
            }
         }
      }
      
      // Print the account form
      SuperForm();
      
      $recover = true;
      $sess->register("recover");
      
      break;

   case L_SETUP_CREATE: 

      if ($superusers || $supergroups) {
         NoAction();
         break;
      }
   
      ValidateInput("login", L_SETUP_LOGIN, &$login, &$err, true, "login");
      ValidateInput("password1", L_SETUP_PWD1, &$password1,
                    &$err, true, "password");
      ValidateInput("password2", L_SETUP_PWD2, &$password2,
                    &$err, true, "password");
      ValidateInput("fname", L_SETUP_FNAME, &$fname, &$err, true, "all");
      ValidateInput("lname", L_SETUP_LNAME, &$lname, &$err, true, "all");
      ValidateInput("email", L_SETUP_EMAIL, &$email, &$err, false, "email");
   
      if( $password1 != $password2 ) {
         $err[$password1] = MsgERR(L_BAD_RETYPED_PWD);
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
         echo L_ERR_USER_ADD;
         break;
      }
      
      // Assign super admin privilege
      
      AddPerm($superid, AA_ID, "aa", $perms_roles_id["SUPER"]);
      
      // Check whether it was succefful
      
      $perms = GetObjectsPerms(AA_ID, "aa");

      if ($perms[$superid]) {
         echo L_SETUP_OK;
         if (!$recover) {
            echo "<p>", L_SETUP_NEXT, "<p>";
            echo "<a href=\"sliceadd.php3\">" . L_SETUP_SLICE . "</a>";
         }
      } else {
         echo L_SETUP_ERR_ADDPERM;
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

///////////////////////////////////////////////////////////////////////////

/*
$Log$
Revision 1.1  2000/08/11 17:14:07  kzajicek
First version of the setup script added

*/
?>