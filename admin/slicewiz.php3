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

// set template id (changes language file => must be here):
require "../include/slicedit2.php3";

$New_slice = true;  // variable tells to init_page, there should not be defined slices, here
require "../include/init_page.php3";
// the parts used by the slice wizard are in the included file
require $GLOBALS[AA_INC_PATH]."formutil.php3";

if($cancel)
    go_url( $sess->url(self_base() . "index.php3"));

$wizard = 1;

if ($add) 
    require $GLOBALS[AA_INC_PATH]."slicedit.php3";
    
$err["Init"] = "";          // error array (Init - just for initializing variable

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_SLICE_WIZ_TIT;?></TITLE>
</HEAD>
<?php 
  echo "<H1><B>" . L_A_SLICE_WIZ_TIT ."</B></H1>";
  PrintArray($err);
  echo $Msg;  
?>

<center>
<form method=post action="<?php echo $sess->url("slicedit.php3") ?>">
<?php
    require $GLOBALS[AA_INC_PATH]."sliceadd.php3";

    FrmInputRadio ("wiz[copyviews]", L_COPY_VIEWS, array (1=>L_YES,0=>L_NO), 1);
    FrmInputRadio ("wiz[constants]", L_CATEGO_CONST, 
        array ('share'=>L_SHARE_WITH_TEMPLATE,'copy'=>L_COPY_FROM_TEMPLATE),'copy');
?>

</table>
</table>

<br><br>

<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align=center>
<tr><td class=tabtit colspan=2><b>&nbsp;<?php echo L_WIZ_NEWUSER_HDR; ?></b>
</td></tr>

<?php

# User data ---------------------------------------------------
    FrmInputRadio("user_role", L_USER_LEVEL_OF_ACCESS, 
        array ("EDITOR"=>L_ITEM_MANAGER, "ADMINISTRATOR"=>L_SLICE_ADMINIS), "EDITOR");
    FrmInputText("user_login", L_USER_LOGIN, "", 50, 50, true);
    FrmInputPwd("user_password1", L_USER_PASSWORD1, "", 50, 50, true);
    FrmInputPwd("user_password2", L_USER_PASSWORD2, "", 50, 50, true);
    FrmInputText("user_firstname", L_USER_FIRSTNAME, "", 50, 50, true);
    FrmInputText("user_surname", L_USER_SURNAME, "", 50, 50, true);
    FrmInputText("user_mail1", L_USER_MAIL." 1", "", 50, 50, false);
    echo '<input type=hidden name=add_submit value="1">
    <input type=hidden name=um_uedit_no_go_url value=1>';  
    
    $email_welcomes = array (NOT_EMAIL_WELCOME => L_NOT_EMAIL_WELCOME);
        
    $db = new DB_AA;
    $db->query ("SELECT description, id FROM wizard_welcome");
    while ($db->next_record()) 
        $email_welcomes[$db->f("id")] = $db->f("description");
  
    FrmInputSelect("wiz[welcome]", L_EMAIL_WELCOME, $email_welcomes, NOT_EMAIL_WELCOME);
?>
</table>

<br><br>

<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td align="center">
<?php 
  echo '<input type=submit name=Add_slice value="'.L_ADD_SLICE.'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. L_CANCEL .'">';
?>   
</td></tr>
</table>
</FORM>
</center>
<?php echo L_APP_TYPE_HELP ?>
</BODY>
</HTML>
<?php page_close()?>

