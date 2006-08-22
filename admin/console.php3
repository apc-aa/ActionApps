<?php

/* Shows a Table View, allowing to edit, delete, update fields of a table
   Params:
       $set_tview -- required, name of the table view
*/

$require_default_lang = true;      // do not use module specific language file
                                   // (message for init_page.php3)
require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."mgettext.php3";
// ----------------------------------------------------------------------------------------

if (!IsSuperadmin()) {
    MsgPage($sess->url(self_base()."index.php3"), _m("You have not permissions to add slice"), "standalone");
    exit;
}

echo _m('comment out following "exit;" line in admin/console.php3');
exit;

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo "<TITLE>"._m("ActionApps onsole")."</TITLE></HEAD>";
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "aaadmin", "console");
echo "<H1><B>" ._m("ActionApps Cosole"). "</B></H1>";

echo $Msg;

$db = new DB_AA;

if ($code) {
    $code = get_magic_quotes_gpc() ? stripslashes($code) : $code;
    eval($code);
}

// ------------------------------------------------------------------------------------------
?>
<form name=f method=post action="<?php echo $sess->url($PHP_SELF) ?>">

    <table width="95%" border="0" cellspacing="0" cellpadding="1" bgcolor="#00638C" align="center" >
        <tr><td>
          <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="#D2E2E8">
          <tr><td align="center" valign="middle" bgcolor=#D2E2E8>&nbsp;<input type="submit" name="update" accesskey="S" value=" Actualizar (ALT+S)  ">&nbsp;
&nbsp;<input type="hidden" name="update" value="1">&nbsp;
&nbsp;<input type="button" name="cancel" value=" Cancelar " onclick="document.location='se_fields.php3?slice_id=5abf7d2c73d7294cb105505a45e97762&AA_CP_Session=e755ffbe019124c6cfdb89e3352cc8a2'">&nbsp;
<input type="hidden" name="AA_CP_Session" value="<?php echo $AA_CP_Session ?>">
<input type="hidden" name="slice_id" value="<?php echo $slice_id ?>"></td></tr></table></td></tr>
      <tr>
        <td>
          <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="#D2E2E8" ><tr class="formrow{formpart}">
 <td class="tabtxt" colspan="2"><b>Console</b></td>
</tr>
<tr><td colspan="2"><textarea id="code" name="code" rows="20" cols="60" style="width:100%" ><?php echo $code ?></textarea></td>
</tr>
</table>

<table width="95%" border="0" cellspacing="0" cellpadding="1" bgcolor="#00638C" align="center">
  <tr>
    <td align="center" valign="middle" bgcolor=#00638C>&nbsp;<input type="submit" name="update" accesskey="S" value=" Actualizar (ALT+S)  ">&nbsp;</td>
  </tr>
</table>

</td>
        </tr>
        </table>

</form>
<?php
HtmlPageEnd();
page_close()
?>

