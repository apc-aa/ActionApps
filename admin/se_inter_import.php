<?php
/** se_inter_import.php - Inter node feed import settings
 *
 *           $slice_id
 *           $feed_id - if set, then delete this feed
 *
 * optionally $Msg to show under <h1>Headline</h1> (typically: Fields' mapping update)
 *
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

// const  AA_REGISTER_GLOBALS = true;
use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";

try {
    $feed_id = InputVars::intGET('feed_id');
    $Msg     = InputVars::stringGETPOST('Msg');
} catch (Exception) {}

if (!IfSlPerm(PS_FEEDING)) {
    MsgPage(_m("You have not permissions to change feeding setting"));
    exit;
}
require_once __DIR__ . "/../include/formutil.php";

if (isset($feed_id)) {
    // delete mode

    // delete mapping from feedmap table
    $db->query("SELECT remote_slice_id FROM external_feeds WHERE feed_id='$feed_id' AND slice_id='$p_slice_id'");
    if ($db->next_record()) {
        $remote_slice_id = quote($db->f('remote_slice_id'));
        $db->query("DELETE FROM feedmap WHERE from_slice_id='$remote_slice_id' AND to_slice_id='$p_slice_id'");
    }
    $db->query("DELETE FROM ef_categories WHERE feed_id='$feed_id'");      // delete categories
    $db->query("DELETE FROM external_feeds WHERE feed_id='$feed_id'");     // delete feed
}


/* get ext_feeds array for selecting */
$SQL       = "SELECT feed_id, name, node_name, remote_slice_id, remote_slice_name, feed_mode
                FROM external_feeds LEFT JOIN nodes ON external_feeds.node_name = nodes.name
               WHERE slice_id='$p_slice_id' ORDER BY name";
$ext_feeds = GetTable2Array($SQL, 'feed_id');

$rows = [];
if ($ext_feeds AND is_array($ext_feeds)) {
    $row = [];
    foreach ($ext_feeds as $k => $v) {
        $row['node_name']    = $v['node_name'];
        $row['remote_slice'] = $v['remote_slice_name'];
        $row['exact']        = ($v['feed_mode']=='exact') ? _m("Exact copy") : '';
        $row['state']        = ($v['node_name'] != $v['name']) ? _m('Missing!!!') : '';
        $row['delete'] = a_href('javascript:Delete('.$k.')',_m('Delete'),['class'=>'aa-button-delete']);
        $rows[] = $row;
    }
}

$nodes = GetTable2Array('SELECT name FROM nodes ORDER BY name', 'name', 'name');
$err = [];          // error array (Init - just for initializing variable


$apage = new AA_Adminpageutil('sliceadmin','n_import');
$apage->setTitle(_m("Inter node import settings"));
$apage->setForm(['action'=>'se_inter_import2.php','onsubmit'=>"return Submit()"]);
$apage->printHead($err, $Msg);

$form_buttons = [
    "submit"=> ["value" => _m("Create new feed from node")],
                      "cancel"=> ["url"=>"se_fields.php"]
];

if ($rows) {
    array_unshift($rows, [_m("Remote slice"), _m("Node"), _m("Exact copy"), _m("State"), _m("Delete")]);
    echo GetHtmlTable($rows,'th', _m("Existing remote imports into the slice") . " <b>" . AA_Slice::getModuleName(AA::$module_id) . "</b>");
} else {
    echo _m("No remote imports into the slice") . " <b>" . AA_Slice::getModuleName(AA::$module_id) . "</b>";
}
echo '<br><br>';


  FrmTabCaption(_m("Inter Node Import"));
  FrmInputSelect('rem_nodes', _m('Add from remote node'), $nodes, '', true);
  FrmTabEnd($form_buttons);
?>
<script>
    function SelectValue(sel) {
        const svindex = eval(sel).selectedIndex;
        return (svindex !== -1) ? eval(sel).options[svindex].value : null;
    }

    function Delete(sel) {
        if (confirm('<?php echo _m("Are you sure you want to delete the import?"); ?>')) {
            let url = "<?php echo StateUrl(self_base() . "se_inter_import.php"); ?>";
            url += "&feed_id=" + sel
            window.location = url
        }
    }

    function Submit() {
        if (SelectValue(document.f.rem_nodes) == null) {
            alert('<?php echo _m("No selected node"); ?>')
            return false
        }
    }
</script>

<?php
$apage->printFoot();
