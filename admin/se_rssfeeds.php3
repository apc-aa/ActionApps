<?php
/** se_rssfeeds.php3 - RSS Feed administration
 *
 *    expected    $mode
 *                $name
 *                $server_url
 *                $password
 *    optionaly $Msg to show under <h1>Headline</h1> (typicaly: Fields' mapping update)
 *                $old_rssfeed_name
 *                $sel_rssfeed_name
 *
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
 * @author    Mitra
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once "../include/init_page.php3";

if (!IfSlPerm(PS_FEEDING)) {
    MsgPage($sess->url(self_base()."index.php3"), _m("You have not permissions to change feeding setting"));
    exit;
}

require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."xml_fetch.php3";

$err["Init"]="";
require_once AA_INC_PATH."formutil.php3";
$qp_slice_id=q_pack_id($slice_id);

if ($mode == "map") {
    $feed = name2rssfeed($slice_id,$sel_rssfeed_name);
    go_url($sess->url(self_base() . "se_mapping.php3?from_slice_id=".$feed["remote_slice_id"]));
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

if ($mode == "edit" || $mode == "test") {
    $db->query("SELECT * FROM rssfeeds WHERE name='$sel_rssfeed_name' AND slice_id = '$qp_slice_id'");
    if ($db->next_record()) {
        $testfeed              = $db->Record;
        $testfeed["feed_type"] = FEEDTYPE_RSS;
        $old_rssfeed_name      = $sel_rssfeed_name;
        $rssfeed_name          = $db->f('name');
        $server_url            = $db->f('server_url');
        $new_mode              = "update";
    }
} else {
    switch ($mode) {
        case "delete" :
            $db->query("DELETE FROM rssfeeds WHERE name='$sel_rssfeed_name' AND slice_id = '$qp_slice_id'");
            // $db->query("DELETE FROM ef_permissions WHERE node='$sel_rssfeed_name'");
            break;

        case "insert" :
            $db->query("SELECT * FROM rssfeeds WHERE name='$rssfeed_name' AND slice_id = '$qp_slice_id'");
            if ($db->next_record()) {
                $err["DB"] .= MsgErr("Can't add RSS Feed $rssfeed_name");
            } else {
                $catVS = new Cvarset();
                $catVS->add("slice_id",  "unpacked", $slice_id);
                $catVS->add("name",      "quoted",   $rssfeed_name);
                $catVS->add("server_url","quoted",   $server_url);
                $SQL = "INSERT INTO rssfeeds" . $catVS->makeINSERT();
                if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
                    $err["DB"] .= MsgErr("Can't add RSS Feed $rssfeed_name");
                }
            }
            break;

        case "update" :
            $catVS = new Cvarset();
            $catVS->add("name",      "quoted", $rssfeed_name);
            $catVS->add("server_url","quoted", $server_url);
            $SQL = "INSERT INTO rssfeeds" .    $catVS->makeINSERT();
            $db->query("UPDATE rssfeeds SET ". $catVS->makeUPDATE()
              ." WHERE name='$old_rssfeed_name' AND slice_id = '$qp_slice_id'");
            break;

        case "add" : $new_mode = "insert"; break;
    } // switch
  $rssfeed_name = $server_url = "";
  $new_mode     = "insert";  // So show "Add" rather than "Edit" on next page, and set in hidden input

}

$db->query("SELECT * FROM rssfeeds WHERE slice_id = '$qp_slice_id' ORDER BY name ");
$rssfeeds="";
while ($db->next_record()) {
    $rssfeeds[] = $db->f(name);
}

?>
 <title><?php echo _m("Remote RSS Feed administration");?></title>
<script Language="JavaScript"><!--

function InitPage() {}

function SelectValue(sel) {
    svindex = eval(sel).selectedIndex;
    if (svindex != -1) {
        return eval(sel).options[svindex].value;
    }
    return null;
}

function SubmitForm(mode) {
    if (mode== 'add') {
        document.f.mode.value = mode;
        document.f.submit();
    } else {
        sel = SelectValue('document.f.rssfeeds');
        if (sel == null) {
            alert('<?php echo _m("No selected rssfeed"); ?>');
        } else {
            if (mode == 'delete') {
                if (!confirm('<?php echo _m("Are you sure you want to delete the rssfeed?"); ?>')) {
                    return;
                }
            }
            document.f.sel_rssfeed_name.value = sel;
            document.f.mode.value = mode;
            document.f.submit();
        }
    }
}

function checkData() {
    if (document.f.rssfeed_name.value=="") {
        alert('<?php echo _m("Error: RSS node empty"); ?>');
        return false;
    }
    document.f.submit();
}

// -->
</script>

</head>
<body>
<?php
  $useOnLoad = true;
  require_once AA_INC_PATH."menu.php3";
  showMenu($aamenus, "sliceadmin","rssfeeds");

  echo "<h1><b>" . _m("Remote RSS Feed administration") . "</b></h1>";
  PrintArray($err);
  echo $Msg;
?>
<form method="post" name="f" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
<?php

  FrmTabCaption(_m("Remote RSS Feed administration"));
?>
      <tr><td colspan="2"><?php echo _m("Current remote rssfeeds") ?></td></tr>
      <tr><td align="center" colspan="2">
      <select name="rssfeeds" class="tabtxt" size=5>
      <?php
        if (isset($rssfeeds) && is_array($rssfeeds)) {
          reset($rssfeeds);
          while (list(,$name) = each($rssfeeds))
            echo "<option value=\"$name\">$name</option>";
        }
      ?>
      </select>
    <tr><td colspan="2" align="center">
      <input type="button" value="<?php echo _m("Edit") ?>" onClick = "SubmitForm('edit')" >
      <input type="button" VALUE="<?php echo _m("Delete") ?>" onClick = "SubmitForm('delete')">
      <input type="button" VALUE="<?php echo _m("Add") ?>" onClick = "SubmitForm('add')">
      <input type="button" VALUE="<?php echo _m("Test") ?>" onClick = "SubmitForm('test')">
      <input type="button" VALUE="<?php echo _m("Map") ?>" onClick = "SubmitForm('map')">
     </td></tr>
<?php
  FrmTabSeparator($new_mode=="insert" ? _m("Add new rssfeed") : _m("Edit rssfeed data"));
?>
    <tr><td><?php echo _m("RSS Feed name") ?></td>
        <td><input type="text" name="rssfeed_name" size="40" value="<?php echo safe($rssfeed_name)?>" ><br><?php echo _m("New rssfeed name")?></tr>
    <tr><td><?php echo _m("URL of the feed") ?></td>
         <td><input type="text" name="server_url" size="40" value="<?php echo safe($server_url)?>" ><br><?php echo _m("e.g. http://www.someplace.com/rss/index.xml")?>"
    <input type="hidden" name="mode" value="<?php echo safe($new_mode) ?>">
    <input type="hidden" name="old_rssfeed_name" value="<?php echo safe($old_rssfeed_name) ?>">
    <input type="hidden" name="sel_rssfeed_name">
</tr>
<?php

  FrmTabEnd(array("send"=>array('type'=>'button', 'value'=>_m('Send'), 'add'=>'onClick="checkData()"'), "cancel"=>array("url"=>"se_fields.php3")), $sess, $slice_id);

?>
</form>
<?php
if ($mode == "test") {
    onefeed($testfeed["feed_id"],$testfeed, 5, 'write');
}
HtmlPageEnd();
page_close()
?>
