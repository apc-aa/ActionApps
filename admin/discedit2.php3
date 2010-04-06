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
*/

// discedit2.php3 - admin discussion comments
// expected    $item_id for comment's item_id
//             $d_id
// optionaly   $update

require_once "../include/init_page.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."pagecache.php3";

if ($cancel) {
    go_url($sess->url(self_base() . "discedit.php3?item_id=".$item_id));
}

if (!IfSlPerm(PS_EDIT_ALL_ITEMS)) {
    MsgPage($sess->url(self_base())."index.php3", _m("You do not have permission to edit items in this slice"));
    exit;
}

require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."discussion.php3";
require_once AA_INC_PATH."item.php3";

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

if ($update) {
  //update discussion table
    ValidateInput("subject", _m("Subject"), $subject, $err, true, "text");
    ValidateInput("author", _m("Author"), $author, $err, true, "text");
    ValidateInput("e_mail", _m("E-mail"), $e_mail, $err, false, "text");
    ValidateInput("body", _m("Text of discussion comment"), $body, $err, false, "text");
    ValidateInput("url_address", _m("Authors's WWW  - URL"), $url_address, $err, false, "url");
    ValidateInput("url_description", _m("Authors's WWW - description"), $url_description, $err, false, "text");
    ValidateInput("remote_addr", _m("Remote address"), $remote_addr, $err, true, "text");
    ValidateInput("free1", _m("Free1"), $free1, $err, false, "text");

    $datectrl = new datectrl('date');
    $datectrl->update();                   // updates datectrl
    $date     = $datectrl->get_date();


    if (count($err)<=1) {
        $varset->add("subject", "quoted", $subject);
        $varset->add("author", "quoted", $author);
        $varset->add("e_mail", "quoted", $e_mail);
        $varset->add("body", "quoted", $body);
        $varset->add("date", "quoted", $date);
        $varset->add("url_address", "quoted", $url_address);
        $varset->add("url_description", "quoted", $url_description);
        $varset->add("remote_addr", "quoted", $remote_addr);
        $varset->add("free1", "quoted", $free1);

        $SQL = "UPDATE discussion SET ". $varset->makeUPDATE() . " WHERE id='" .q_pack_id($d_id)."'";
        $db->query($SQL);

        $GLOBALS['pagecache']->invalidateFor("slice_id=".$slice_id);  // invalidate old cached values

        go_url($sess->url(self_base() . "discedit.php3?item_id=".$item_id));
    }
}

// set variables from table discussion
$SQL= " SELECT * FROM discussion WHERE id='".q_pack_id($d_id)."'";
$db->query($SQL);
if ($db->next_record()) {
    while (list($key,$val,,) = each($db->Record)) {
        if (!is_numeric($key)) {
            $$key = $val; // variables and database fields have identical names
        }
    }
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Edit discussion");?></title>
<script Language="JavaScript"><!--
function InitPage() {}
// -->
</script>


</head>
<body>
<?php
echo "<h1><b>" . _m("Items managment - Discussion comments managment - Edit comment") . "</b></h1>";
PrintArray($err);
echo $Msg;
?>
  <form method="post" action="<?php echo $sess->url($_SERVER['PHP_SELF'] . "?d_id=".$d_id) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class="tabtit"><b>&nbsp;<?php echo _m("Edit comment") ?></b></td></tr>
<tr><td>
<table width="540" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
    FrmStaticText("id", $d_id);
    FrmInputText("subject",_m("Subject"), $subject, 99, 50, true);
    FrmInputText("author",_m("Author"), $author, 60, 25, true);
    FrmInputText("e_mail",_m("E-mail"), $e_mail, 60, 25, false);
    FrmTextArea("body", _m("Text of discussion comment"), $body, 10, 40, false);
    FrmDate("date", _m('Date'), $date, false, '', '', true);
    FrmInputText("url_address",_m("Authors's WWW  - URL"), $url_address, 99, 50, false);
    FrmInputText("url_description", _m("Authors's WWW - description"), $url_description, 60, 25, false);
    FrmInputText("remote_addr",_m("Remote address"), $remote_addr, 60, 25, false);
    FrmTextArea("free1", _m("Free 1"), $free1, 5, 40, false);
?>
</table>
<tr><td align="center">
<?php
    echo "<input type=\"hidden\" name=\"d_id\" value=".$d_id.">";
    echo "<input type=\"hidden\" name=\"item_id\" value=".unpack_id($item_id).">";
    echo "<input type=\"submit\" name=\"update\" value=". _m("Update") .">&nbsp;&nbsp;";
    echo "<input type=\"reset\" value=". _m("Reset form") .">&nbsp;&nbsp;";
    echo "<input type=\"submit\" name=\"cancel\" value=". _m("Cancel") .">";
?>
</td></tr></table>
</form>
</body>
</html>
<?php page_close()
?>
