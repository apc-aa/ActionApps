<?php
/** se_inter_import.php - Inter node feed import settings
 *
 *           $slice_id
 *           $feed_id - POST with the page's token: delete this feed
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

use AA\Central\ResponderPolicy;
use AA\IO\DB\DB_AA;
use AA\Util\FormToken;
use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";

const INTER_IMPORT_TOKEN = 'aa-inter-import';

try {
    $feed_id = InputVars::intPOST('feed_id');
    $token   = InputVars::stringPOST('token');
    $Msg     = InputVars::messageGETPOST();
} catch (Exception $e) { AA_Adminpageutil::pageParameterError($e); }

if (!IfSlPerm(PS_FEEDING)) {
    MsgPage(_m("You do not have permissions to change feeding setting"));
    exit;
}
require_once __DIR__ . "/../include/formutil.php";

if ($feed_id > 0) {
    // an import is deleted only by the Delete button of this page, and only an import into this slice
    if (ResponderPolicy::isForeignBrowserRequest($_SERVER) || !FormToken::checkPost(INTER_IMPORT_TOKEN, $token, $_SERVER)) {
        $Msg = MsgErr(_m('The form has expired; reload the page and try again.'));
    } elseif ($feed = DB_AA::select1([], 'SELECT remote_slice_id FROM external_feeds', [['feed_id', $feed_id, 'i'], ['slice_id', AA::$module_id, 'l']])) {
        DB_AA::transaction(static function () use ($feed, $feed_id): void {
            DB_AA::delete('feedmap', [['from_slice_id', $feed['remote_slice_id'], 'b'], ['to_slice_id', AA::$module_id, 'l']]);
            DB_AA::delete('ef_categories', [['feed_id', $feed_id, 'i']]);
            DB_AA::delete('external_feeds', [['feed_id', $feed_id, 'i']]);
        });
    }
}


/* get ext_feeds array for selecting */
$SQL       = "SELECT feed_id, name, node_name, remote_slice_id, remote_slice_name, feed_mode
                FROM external_feeds LEFT JOIN nodes ON external_feeds.node_name = nodes.name";
$ext_feeds = DB_AA::select(['feed_id' => []], $SQL, [['slice_id', pack_id(AA::$module_id), 'b']], ['name']);

$rows = [];
if ($ext_feeds && is_array($ext_feeds)) {
    $row = [];
    foreach ($ext_feeds as $k => $v) {
        $row['node_name']    = $v['node_name'];
        $row['remote_slice'] = $v['remote_slice_name'];
        $row['exact']        = ($v['feed_mode'] === 'exact') ? _m("Exact copy") : '';
        $row['state']        = ($v['node_name'] != $v['name']) ? _m('Missing!!!') : '';
        $row['delete'] = a_href('javascript:Delete('.$k.')',_m('Delete'),['class'=>'aa-button-delete']);
        $rows[] = $row;
    }
}

$nodes = DB_AA::select(['name' => 'name'], 'SELECT name FROM nodes', null, ['name']);
$err = [];          // error array (Init - just for initializing variable


$apage = new AA_Adminpageutil('sliceadmin','n_import');
$apage->setTitle(_m("Inter node import settings"));
$apage->setForm(['action'=>'se_inter_import2.php','onsubmit'=>"return Submit()"]);
$apage->addRequire(get_aa_url('javascript/manager.min.js', ['v' => AA_JS_VERSION]));   // AA_PostForm()
$apage->printHead($err, $Msg);

$form_buttons = [
    "submit"=> ["value" => _m("Create new feed from node")],
                      "cancel"=> ["url"=>"se_fields.php"]
];

if ($rows) {
    array_unshift($rows, [_m("Remote slice"), _m("Node"), _m("Exact copy"), _m("State"), _m("Delete")]);
    echo GetHtmlTable($rows, 'th', _m("Existing remote imports into the slice")." <b>".AA_Slice::getModuleNameSafe(AA::$module_id)."</b>");
} else {
    echo _m("No remote imports into the slice") . " <b>" . AA_Slice::getModuleNameSafe(AA::$module_id) . "</b>";
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
            AA_PostForm(<?php echo json_encode(StateUrl('se_inter_import.php')); ?>, {feed_id: sel, token: <?php echo json_encode(FormToken::get(INTER_IMPORT_TOKEN)); ?>});
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
