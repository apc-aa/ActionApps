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

// se_newuser.php3 - adds new user to permission system (now LDAP directory)
// expected $slice_id for edit slice
// optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."msgpage.php3";

if ($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if (!IfSlPerm(PS_NEW_USER)) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("No permission to create new user"), "admin");
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

if ( $update )
{
  do
  {
    ValidateInput("user_login", _m("Login name"), $user_login, $err, true, "login");
    ValidateInput("user_password1", _m("Password"), $user_password1, $err, true, "password");
    ValidateInput("user_password2", _m("Retype password"), $user_password2, $err, true, "password");
    ValidateInput("user_mail1", _m("E-mail")." 1", $user_mail1, $err, false, "email");
    //    ValidateInput("user_mail2", _m("E-mail")." 2", $user_mail2, $err, false, "email");
    //   ValidateInput("user_mail3", _m("E-mail")." 3", $user_mail3, $err, false, "email");
    ValidateInput("user_surname", _m("Surname"), $user_surname, $err, true, "text");
    ValidateInput("user_firstname", _m("First name"), $user_firstname, $err, true, "text");
    if ( $user_password1 != $user_password2 )
      $err[$user_password2] = MsgErr(_m("Retyped password is not the same as the first one"));
    if ( count($err) > 1)
      break;
      
    $userrecord["uid"] = $user_login;
    $userrecord["userpassword"] = $user_password1;
    $userrecord["givenname"] = $user_firstname;
    $userrecord["sn"] = $user_surname;

    if ($user_mail1) $userrecord["mail"] = $user_mail1;
    //    if ($user_mail2) $userrecord["mail"][] = $user_mail2;
    //    if ($user_mail3) $userrecord["mail"][] = $user_mail3;

    if (!AddUser($userrecord))
      $err["LDAP"] = MsgErr( _m("It is impossible to add user to permission system") );
  }while(false);
  if ( count($err) <= 1 ) {
    $Msg = MsgOK(_m("User successfully added to permission system"));
    $url = con_url($sess->url(self_base() . "se_users.php3"),"Msg=".rawurlencode($Msg));
    $url = con_url($url, "UsrSrch=".rawurlencode(_m("Search")));
    $url = con_url($url, "usr=".rawurlencode($user_login));
    go_url($url);
  }  
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Admin - design Index view");?></TITLE>
</HEAD>

<?php
  $useOnLoad = false;
  require_once AA_INC_PATH."menu.php3";
  showMenu ($aamenus, "sliceadmin","newuser");

  echo "<H1><B>" . _m("New user in permission system") . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form name=f method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo _m("New user")?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
  FrmInputText("user_login", _m("Login name"), $user_login, 50, 50, true);
  FrmInputPwd("user_password1", _m("Password"), $user_password1, 50, 50, true);
  FrmInputPwd("user_password2", _m("Retype password"), $user_password2, 50, 50, true);
  FrmInputText("user_firstname", _m("First name"), $user_firstname, 50, 50, true);
  FrmInputText("user_surname", _m("Surname"), $user_surname, 50, 50, true);
  FrmInputText("user_mail1", _m("E-mail")." 1", $user_mail1, 50, 50, false);
//  FrmInputText("user_mail2", _m("E-mail")." 2", $user_mail2, 50, 50, false);
//  FrmInputText("user_mail3", _m("E-mail")." 3", $user_mail3, 50, 50, false);
?>
</table></td></tr>
<tr><td align="center">
<?php 
  echo "<input type=hidden name=\"update\" value=1>";
  echo "<input type=hidden name=\"slice_id\" value=$slice_id>";
  echo '<input type=submit name=update value="'. _m("Add") .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. _m("Cancel") .'">&nbsp;&nbsp;';
?>
</td></tr></table>
</FORM>
<?php HtmlPageEnd();
page_close()?>

