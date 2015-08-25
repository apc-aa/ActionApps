<?php
/** se_inter_import.php3 - Inter node feed import settings
 *
 *           $slice_id
 *           $feed_id - if set, then delete this feed
 *
 * optionaly $Msg to show under <h1>Headline</h1> (typicaly: Fields' mapping update)
 *
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
 * @author    Honza Malik <honza.malik@ecn.cz>
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
require_once AA_INC_PATH."formutil.php3";

$p_slice_id = q_pack_id($slice_id);

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
if ($ext_feeds AND is_array($ext_feeds)) {
    foreach ($ext_feeds as $k => $v) {
        if ($v['feed_mode']=='exact') {
            $text2show       = '(=) ';
            $show_exact_help = true;
        } else {
            $text2show       = '    ';
        }
        $text2show .= $v['node_name'];
        if ($v['node_name'] != $v['name']) {
            $text2show .= ' -  '. _m('Missing!!!');
        }
        $ext_feeds[$k] = str_pad($text2show,35)." ".$v['remote_slice_name'];
    }
}

$nodes = GetTable2Array('SELECT name FROM nodes ORDER BY name', 'name', 'name');

$err["Init"] = "";          // error array (Init - just for initializing variable
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <title><?php echo _m("Inter node import settings");?></title>
<script Language="JavaScript"><!--

function InitPage() {}

function SelectValue(sel) {
  svindex = eval(sel).selectedIndex;
  if (svindex != -1) { return eval(sel).options[svindex].value; }
  return null;
}

function Delete() {
  sel = SelectValue('document.f.feed_id')
  if (sel == null) {
    alert('<?php echo _m("No selected import"); ?>')
    return
  }
  if (!confirm('<?php echo _m("Are you sure you want to delete the import?"); ?>'))
    return

  var url = "<?php echo $sess->url(self_base() . "se_inter_import.php3"); ?>"
  url += "&feed_id=" + sel
  document.location = url
}

function Submit() {
  if (SelectValue(document.f.rem_nodes) == null) {
     alert('<?php echo _m("No selected node"); ?>')
     return false
  }
}

// -->
</script>

</head>
<body>
<?php
  $useOnLoad = true;
  require_once AA_INC_PATH."menu.php3";
  showMenu($aamenus, "sliceadmin","n_import");

  echo "<h1><b>" . _m("Inter node import settings") . "</b></h1>";
  PrintArray($err);
  echo $Msg;
  $form_buttons = array("submit"=>array("value" => _m("Create new feed from node")),
                        "cancel"=>array("url"=>"se_fields.php3"));
?>
<form method="post" name="f" action="<?php echo $sess->url(self_base() ."se_inter_import2.php3") ?>" onSubmit="return Submit()" >
<?php
  FrmTabCaption(_m("Existing remote imports into the slice") ." <b>". AA_Slice::getModuleName($slice_id). "</b>");
  FrmInputMultiSelect('feed_id', _m('Imported slices'), $ext_feeds, '', 5, false, true, _m('feeds prefixed by (=) are "exact copy" feeds'));
  FrmTabSeparator(_m("All remote nodes"), array("delete" => array('value'=>_m("Delete"),'type'=>'button', 'add'=>'onClick="Delete()"')));
  FrmInputMultiSelect('rem_nodes', _m('Remote node'), $nodes, $node, 5, false, true);
  FrmTabEnd($form_buttons, $sess, $slice_id);
?>
</form>
<?php
HtmlPageEnd();
page_close();
?>
