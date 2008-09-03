<?php
/**
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/
// set template id (changes language file => must be here):
require_once "../include/slicedit2.php3";

list($set_template_id, $change_lang_file) = get_template_and_lang($template_slice_radio == "slice" ? $template_id2 : $template_id);

// messages for init_page:
$no_slice_id          = true;
$require_default_lang = true;

require_once "../include/init_page.php3";
// the parts used by the slice wizard are in the included file
require_once AA_INC_PATH."formutil.php3";

if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

$wizard = 1;

if ($add) {
    require_once AA_INC_PATH."slicedit.php3";
}

$err["Init"] = "";          // error array (Init - just for initializing variable

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <title><?php echo _m("Add Slice Wizard");?></title>
</head>
<?php
  echo "<h1><b>" . _m("Add Slice Wizard") ."</b></h1>";
  PrintArray($err);
  echo $Msg;
?>

<center>
<form method="post" action="<?php echo $sess->url("slicedit.php3") ?>">
<?php
    require_once AA_INC_PATH."sliceadd.php3";

    FrmInputRadio("wiz[copyviews]", _m("Copy Views"), array (1=>_m("yes"),0=>_m("no")), 1);
    FrmInputRadio("wiz[constants]", _m("Categories/Constants"),
        array ('share'=>_m("Share with Template"),'copy'=>_m("Copy from Template")),'copy');
?>

</table>
</table>

<br><br>
<?php

FrmTabCaption(_m("[Optional] Create New User"));

// User data ---------------------------------------------------
    FrmInputRadio("user_role", _m("Level of Access"),
        array ("EDITOR"=>_m("Editor"), "ADMINISTRATOR"=>_m("Slice Administrator")), "EDITOR");
    FrmInputText("user_login", _m("Login name"), "", 50, 50, true);
    FrmInputPwd("user_password1", _m("Password"), "", 50, 50, true);
    FrmInputPwd("user_password2", _m("Retype password"), "", 50, 50, true);
    FrmInputText("user_firstname", _m("First name"), "", 50, 50, true);
    FrmInputText("user_surname", _m("Surname"), "", 50, 50, true);
    FrmInputText("user_mail1", _m("E-mail")." 1", "", 50, 50, false);
    echo '<input type="hidden" name="add_submit" value="1">
    <input type="hidden" name="um_uedit_no_go_url" value="1">';

    $email_welcomes = GetUserEmails("slice wizard welcome");
    $email_welcomes[NOT_EMAIL_WELCOME] = _m("Do Not Email Welcome");

    FrmInputSelect("wiz[welcome]", _m("Email Welcome"), $email_welcomes, NOT_EMAIL_WELCOME);

    FrmTabEnd();
?>


<br><br>
<?php

  FrmTabCaption("");
  FrmTabEnd(array("no_slice"=>array("value"=>_m("Go: Add Slice"),
                                    "type"=>"submit",
                                    "accesskey"=>"S"),
                  "cancel"=>array("url"=>"um_uedit.php3")), $sess, $slice_id);
?>
</form>
</center>
<?php echo "<br><br><br><br>"; ?>
</body>
</html>
<?php page_close()?>

