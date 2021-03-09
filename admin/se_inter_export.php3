<?php
/**
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
//             $slice_id
// optionaly $Msg to show under <h1>Headline</h1> (typicaly: Fields' mapping update)

use AA\IO\DB\DB_AA;

require_once __DIR__."/../include/init_page.php3";

if (!IfSlPerm(PS_FEEDING)) {
   MsgPage(StateUrl(self_base()."index.php3"), _m("You have not permissions to change feeding setting"));
   exit;
}
require_once __DIR__."/../include/formutil.php3";

$p_slice_id = q_pack_id($slice_id);
switch ($mode) {
    case "delete" :
        [$sel_node, $sel_user] = explode("~",$perms);
        $db->query("DELETE FROM ef_permissions WHERE slice_id='$p_slice_id' AND  node='$sel_node' AND  user='$sel_user'");
        break;
    case "insert" :
        $db->query("SELECT slice_id FROM ef_permissions WHERE slice_id='$p_slice_id' AND  node='$r_nodes' AND  user='$user_name'");
        if ($db->next_record()) {   // duplicity
            $err["DB"].= "Can't add new permission record";
        } else {
            $db->query("INSERT INTO ef_permissions VALUES('$p_slice_id','$r_nodes','$user_name')");
        }
      break;
}

$perms = DB_AA::select([], "SELECT `node`, `user` FROM `ef_permissions`", [['slice_id',$slice_id, 'l']], ['node','user']);
//$db->query("SELECT * FROM ef_permissions WHERE slice_id='$p_slice_id' ORDER BY node,user ");
//$perms="";
//while ($db->next_record()) {
//    $perms[] = array( 'node'=>$db->f('node'), 'user'=>$db->f('user'));
//}

$nodes = DB_AA::select('', "SELECT `name` FROM `nodes`", '', ['name']);
//$db->query("SELECT * FROM nodes ORDER BY name ");
//$nodes="";
//while ($db->next_record()) {
//    $nodes[] = $db->f('name');
//}


$err = [];          // error array (Init - just for initializing variable

$headcode = '<script>
    function SelectValue(sel) {
        svindex = eval(sel).selectedIndex;
        if (svindex != -1) { return eval(sel).options[svindex].value; }
        return null;
    }

    function Delete() {
        sel = SelectValue(\'document.f.perms\')
        if (sel == null) {
            alert(\''. _m("No selected export") .'\')
            return 
        }
        if (!confirm(\''. _m("Are you sure you want to delete the export?") .'\')) {
            return
        }
        document.f.mode.value=\'delete\';
        document.f.submit();
    }
</script>';

$apage = new AA_Adminpageutil('sliceadmin','n_export');
$apage->setTitle(_m("Inter node export settings"));
$apage->addRequire($headcode, 'AA_Req_Headcode');
$apage->printHead($err, $Msg);

FrmTabCaption(_m("Inter node export settings"));
?>
      <tr><td colspan="2"><?php echo _m("Existing exports of the slice "). "<b>".AA_Slice::getModuleName($slice_id)."</b>"; ?></td></tr>
      <tr><td colspan="2" align="center">
        <select name="perms" size="5">
         <?php
           if ($perms && is_array($perms)) {
               foreach ($perms as $perm) {
                   $n = (!$perm['node']) ? "All Nodes" : $perm['node'];
                   $u = (!$perm['user']) ? "All Users" : $perm['user'];
                   $str = str_replace(" ","&nbsp;",substr($n."                              ",0,30)."  ");
                   echo "<option value=\"".$perm['node']."~".$perm['user']."\">".
                     $str.$u."</option>";
               }
           }
          ?>
        </select>
      </td></tr>
      <tr><td colspan="2" align="center">
        <input type="button" VALUE="<?php echo _m("Delete") ?>" onClick = "Delete()">
       </td></tr>
<?php
      FrmTabSeparator(_m("Insert new item"));
?>
      <tr><td width="40%"><?php echo _m("Remote Nodes"); ?></td><td align="left">
        <select name="r_nodes" class="tabtxt" size="5">
        <?php
          if ($nodes && is_array($nodes)) {
              foreach ($nodes as $n) {
                  echo "<option value=\"$n\"".($n==$node ? "SELECTED":"")." >$n</option>";
              }
          }
        ?>
        </select>
      </td></tr>
      <tr><td width="40%"><?php echo _m("User name"); ?></td>
          <td align="left"><input type="text" name="user_name" size="40" value="<?php echo safe($user_name)?>" >
      </td></tr>
<?php
    FrmTabEnd([
        "mode"=> ["type"=>"hidden", "value"=>"insert"],
                    "submit",
                    "cancel"=> ["url"=>"se_fields.php3"]
    ]);

$apage->printFoot();
