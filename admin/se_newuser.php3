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

# se_newuser.php3 - adds new user to permission system (now LDAP directory)
# expected $slice_id for edit slice
# optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_NEW_USER)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_NEW_USER, "admin");
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

if( $update )
{
  do
  {
    ValidateInput("user_login", L_USER_LOGIN, &$user_login, &$err, true, "login");
    ValidateInput("user_password1", L_USER_PASSWORD1, &$user_password1, &$err, true, "password");
    ValidateInput("user_password2", L_USER_PASSWORD2, &$user_password2, &$err, true, "password");
    ValidateInput("user_mail1", L_USER_MAIL." 1", &$user_mail1, &$err, false, "email");
    //    ValidateInput("user_mail2", L_USER_MAIL." 2", &$user_mail2, &$err, false, "email");
    //   ValidateInput("user_mail3", L_USER_MAIL." 3", &$user_mail3, &$err, false, "email");
    ValidateInput("user_surname", L_USER_SURNAME, &$user_surname, &$err, true, "text");
    ValidateInput("user_firstname", L_USER_FIRSTNAME, &$user_firstname, &$err, true, "text");
    if( $user_password1 != $user_password2 )
      $err[$user_password2] = MsgErr(L_BAD_RETYPED_PWD);
    if( count($err) > 1)
      break;
      
    $userrecord["uid"] = $user_login;
    $userrecord["userpassword"] = $user_password1;
    $userrecord["givenname"] = $user_firstname;
    $userrecord["sn"] = $user_surname;

    if($user_mail1) $userrecord["mail"] = $user_mail1;
    //    if($user_mail2) $userrecord["mail"][] = $user_mail2;
    //    if($user_mail3) $userrecord["mail"][] = $user_mail3;

    if(!AddUser($userrecord))
      $err["LDAP"] = MsgErr( L_ERR_USER_ADD );
  }while(false);
  if( count($err) <= 1 ) {
    $Msg = MsgOK(L_NEWUSER_OK);
    $url = con_url($sess->url(self_base() . "se_users.php3"),"Msg=".rawurlencode($Msg));
    $url = con_url($url, "UsrSrch=".rawurlencode(L_SEARCH));
    $url = con_url($url, "usr=".rawurlencode($user_login));
    go_url($url);
  }  
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_COMPACT_TIT;?></TITLE>
</HEAD>

<?php
  $xx = ($slice_id!="");
  $useOnLoad = false;
  $show = Array("main"=>true, "slicedel"=>$xx, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx, 
                "views"=>$xx, "addusers"=>$xx, "newusers"=>false, "import"=>$xx, "filters"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

  echo "<H1><B>" . L_A_NEWUSER . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form name=f method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_NEWUSER_HDR?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
  FrmInputText("user_login", L_USER_LOGIN, $user_login, 50, 50, true);
  FrmInputPwd("user_password1", L_USER_PASSWORD1, $user_password1, 50, 50, true);
  FrmInputPwd("user_password2", L_USER_PASSWORD2, $user_password2, 50, 50, true);
  FrmInputText("user_firstname", L_USER_FIRSTNAME, $user_firstname, 50, 50, true);
  FrmInputText("user_surname", L_USER_SURNAME, $user_surname, 50, 50, true);
  FrmInputText("user_mail1", L_USER_MAIL." 1", $user_mail1, 50, 50, false);
//  FrmInputText("user_mail2", L_USER_MAIL." 2", $user_mail2, 50, 50, false);
//  FrmInputText("user_mail3", L_USER_MAIL." 3", $user_mail3, 50, 50, false);
?>
</table></td></tr>
<tr><td align="center">
<?php 
  echo "<input type=hidden name=\"update\" value=1>";
  echo "<input type=hidden name=\"slice_id\" value=$slice_id>";
  echo '<input type=submit name=update value="'. L_ADD .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. L_CANCEL .'">&nbsp;&nbsp;';
/*
$Log$
Revision 1.7  2001/05/18 13:50:09  honzam
better Message Page handling (not so much)

Revision 1.6  2001/05/10 10:01:43  honzam
New spanish language files, removed <form enctype parameter where not needed, better number validation

Revision 1.5  2001/03/20 15:27:03  honzam
Changes due to "slice delete" feature

Revision 1.4  2001/02/26 17:26:08  honzam
color profiles

Revision 1.3  2000/10/10 10:06:54  honzam
Database operations result checking. Messages abstraction via MsgOK(), MsgErr()

Revision 1.2  2000/08/03 12:49:22  kzajicek
English editing

Revision 1.1.1.1  2000/06/21 18:40:02  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:51  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.12  2000/06/12 19:58:24  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.11  2000/06/09 15:14:10  honzama
New configurable admin interface

Revision 1.10  2000/04/24 16:45:02  honzama
New usermanagement interface.

Revision 1.9  2000/03/22 09:36:44  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/
?>
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()?>

