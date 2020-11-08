<?php
/**  se_nodes.php3 - Remote node administration
 *
 *     expected    $mode
 *                 $name
 *                 $server_url
 *                 $password
 *     optionaly $Msg to show under <h1>Headline</h1> (typicaly: Fields' mapping update)
 *                 $old_node_name
 *                 $sel_node_name
 *
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;

require_once __DIR__."/../include/init_page.php3";

if ( !IsSuperadmin() ) {
    MsgPage(StateUrl(self_base()."index.php3"), _m("You have not permissions to manage nodes"));
    exit;
}
$err = [];
require_once __DIR__."/../include/formutil.php3";

if ($mode == "edit") {
    $db->query("SELECT * FROM nodes WHERE name='$sel_node_name'");
    if ($db->next_record()) {
        $old_node_name = $sel_node_name;
        $node_name = $db->f('name');
        $server_url = $db->f('server_url');
        $password = $db->f('password');
        $new_mode = "update";
    }
} else {
    switch ($mode) {
        case "delete" :
            $db->query("DELETE FROM nodes WHERE name='$sel_node_name'");
            $db->query("DELETE FROM ef_permissions WHERE node='$sel_node_name'");
            break;

        case "insert" :
            $db->query("SELECT * FROM nodes WHERE name='$node_name'");
            if ($db->next_record()) {
                $err["DB"] .= MsgErr("Can't add node $node_name");
            } else {
                $SQL = "INSERT INTO nodes VALUES('$node_name','$server_url','$password')";
                if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
                    $err["DB"] .= MsgErr("Can't add node $node_name");
                }
            }
            break;

        case "update" :
            $db->query("UPDATE nodes SET name='$node_name', server_url='$server_url',
                                          password='$password' WHERE name='$old_node_name'");
            break;

        case "add" :
            $new_mode = "insert";
            break;
    }
    $node_name = $server_url = $password = "";
    $new_mode = "insert";

}

$nodes = DB_AA::select('', "SELECT `name` FROM `nodes`");

$apage = new AA_Adminpageutil('sliceadmin','nodes');
$apage->setTitle(_m("Remote node administration"));
$apage->setForm(['onsubmit' => "return checkData()"]);
$apage->printHead($err, $Msg);

$form_buttons = ["submit","cancel"=> ["url"=>"se_fields.php3"]];

FrmTabCaption(_m("Remote node administration"));
?>
      <tr><td colspan="2"><?php echo _m("Known remote nodes") ?></td></tr>
      <tr><td align="center" colspan="2">
      <select name="nodes" class="tabtxt" size="5">
      <?php
        if (isset($nodes) && is_array($nodes)) {
            foreach ($nodes as $name) {
                echo "<option value=\"$name\">$name</option>";
            }
        }
      ?>
      </select>
    <tr><td colspan=2 align="center">
      <input type="button" value="<?php echo _m("Edit") ?>" onclick="ActionGo('edit');">
      <input type="button" value="<?php echo _m("Delete") ?>" onclick="ActionGo('delete');">
      <input type="button" value="<?php echo _m("Add") ?>" onclick="ActionGo('add');">
     </td></tr>
    <tr><td colspan="2">&nbsp;</td></tr>
<?php

    FrmTabSeparator(($new_mode == "insert" ? _m("Add new node") : _m("Edit node data")));

?>
    <tr><td><?php echo _m("Node name") ?></td>
        <td><input type="text" name="node_name" size="40" value="<?php echo safe($node_name)?>" ><br><?php echo _m("Your node name")?>: "<?php echo ORG_NAME ?>"
    <tr><td><?php echo _m("URL of the getxml.php3") ?></td>
        <td><input type="text" name="server_url" size="40" value="<?php echo safe($server_url)?>" ><br><?php echo _m("Your getxml is")?>: "<?php echo AA_INSTAL_URL ?>admin/getxml.php3"
    <tr><td><?php echo _m("Password") ?></td>
         <td><input type="text" name="password" size="40" value="<?php echo safe($password)?>" >
    <input type="hidden" name="mode" value="<?php echo safe($new_mode) ?>">
    <input type="hidden" name="old_node_name" value="<?php echo safe($old_node_name) ?>">
    <input type="hidden" name="sel_node_name">
<?php
    FrmTabEnd($form_buttons);
?>
<script>

    function ActionGo(mode) {
        var frm = document.f;
        if (mode== 'add') {
            frm.elements["mode"].value = mode;
            frm.submit();
        } else {

            sel = frm.elements["nodes"].value;
            if (!sel)
                alert('<?php echo _m("No selected node"); ?>')
            else {
                if (mode == 'delete') {
                    if (!confirm('<?php echo _m("Are you sure you want to delete the node?"); ?>')) {
                        return;
                    }
                }
                frm.elements["sel_node_name"].value = sel
                frm.elements["mode"].value = mode;
                frm.submit();
            }
        }
    }

    function checkData() {
        if (document.f.elements['node_name'].value=="") {
            alert('<?php echo _m("Node empty"); ?>')
            return false;
        }
    }

    function Cancel() {
        document.location = "<?php echo StateUrl("index.php3")?>"
    }
</script>
<?php
$apage->printFoot();
