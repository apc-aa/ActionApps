<?php
 /**
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
*/
// discedit.php - admin discussion comments
// expected  $item_id for comment's item_id
// optional  $mode for delete,hide or normal mode
//           $d_id
//           $h

const AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/discussion.php";
require_once __DIR__ . "/../include/item.php";


/** getHeadline function
 * get a headline of the item
 * @param $content4id
 * @return string headline
 */
function getHeadline($content4id): string {
    if (!$content4id) {
        return '';
    }
    if ($content4id["headline........"]) {
        return $content4id["headline........"][0]['value'];
    }

    for ($i=1; $i<10; $i++) {
        if ($content4id["headline.......".$i]) {
            return $content4id["headline.......".$i][0]['value'];
        }
    }
    return '';
}

// check permission to edit discussion - you must be Editor, at least
if (!IfSlPerm(PS_EDIT_ALL_ITEMS)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php3", _m("You don't have permissions to edit all items."), "items");
    exit;
}

$err = [];          // error array (Init - just for initializing variable

// get discussion content and tree
$zids     = QueryDiscussionZIDs($item_id);
$dcontent = GetDiscussionContent($zids, false);
$tree     = GetDiscussionTree($dcontent);

if ($mode == "hide" || $mode=="delete" || $mode=="deleteall" || $mode=="deleteshown") {
    switch ($mode) {
        case "hide" :
            $h = ( $h ? 1 : 0 );
            $dcontent[$d_id]["d_state........."][0]['value'] = $h;

            $db->query("UPDATE discussion SET state='$h' WHERE id='".q_pack_id($d_id)."'");
            break;
        case "delete":
            DeleteNode($tree, $dcontent, $d_id);
            break;
        case "deleteall":
            DeleteDiscForItem($item_id, AA::$module_id);
            break;
        case "deleteshown":
            DeleteDiscForItem($item_id, AA::$module_id, 0);
            break;
    }
    updateDiscussionCount($item_id);        // update a count of the comments belong to the item

    AA::Pagecache()->invalidateFor(AA::$module_id);  // invalidate old cached values

    // refresh the content and the tree because of delete node
    $zids     = QueryDiscussionZIDs($item_id);
    $dcontent = GetDiscussionContent($zids, false);
    $tree     = GetDiscussionTree($dcontent);
}

GetDiscussionThread($tree, "0", 0, $outcome);         // get array of images



$script2run = '<script>
  function DeleteComment(id) {
    if ( !confirm("'. _m("Are you sure you want to delete selected comment?") .'")) {
       return;
    }
    window.location = "'. get_url("./discedit.php", ['mode'=>'delete', 'item_id' => $item_id], true). '&d_id=" + escape(id);
  }
  function DeleteAllComments() {
    if ( !confirm("'. _m("Are you sure you want to delete ALL comments? There is no way back!") .'")) {
       return;
    }
    window.location = "'. get_url("./discedit.php", ['mode'=>'deleteall', 'item_id' => $item_id], true). '";
  }
  function DeleteAllHiddenComments() {
    if ( !confirm("'. _m("Are you sure you want to delete ALL unhidden comments (the comments, which are NOT HIDDEN)? There is no way back!") .'")) {
       return;
    }
    window.location = "'. get_url("./discedit.php", ['mode'=>'deleteshown', 'item_id' => $item_id], true). '";
  }
</script>';

$apage = new AA_Adminpageutil();
$apage->setTitle(_m("Admin - Discussion comments management"));
$apage->setForm(['action'=>'index.php3']);
$apage->addRequire($script2run, 'AA_Req_Footcode');
$apage->printHead($err, $Msg);

$content  = GetItemContent($item_id);
$headline = getHeadline($content[$item_id]);

FrmTabCaption(_m("Item: ")." $headline (". $content[$item_id]["disc_app........"][0]['value']. "/". $content[$item_id]["disc_count......"][0]['value']. ")");
   echo '<tr class="aa-tbl-header">
        <th>'. _m("Title") .'</th>
        <th>'. _m("Author") .'</th>
        <th>'. _m("IP Address") .'</th>
        <th>'. _m("Date") .'</th>
        <th>'. _m("Actions") .'</th>
    </tr>';

$item = new AA_Item('', new AA_Aliases(new AA_Aliasgenerator_Discussion()));
$i = 0;
if (!$outcome) {
    echo "<tr><td colspan=5>". _m("No discussion comments") ."<br><br></td></tr>";
} else {
    foreach ($outcome as $d_id => $images) {
        $im = "";
        foreach($images as $img) {
            $im .= GetImageSrc($img);
        }
        $im2 = "<tr>
                 <td><table>
                    <tr><td>".$im. GetAAImage('blank.gif','',2,21)."</td>
                        <td>_#SUBJECT_&nbsp;</td>
                    </tr>
                    </table>
                 </td>
                 <td>_#AUTHOR#_</td>
                 <td>_#IP_ADDR_</td>
                 <td>_#DATE###_</td>
         ";
        $item->setformat($im2);
        $item->set_data($dcontent[$d_id]);
        echo $item->get_item();

        echo "<td>";
        echo   "<a class=aa-button-edit href=". con_url("discedit2.php",["d_id"=>$d_id,"item_id"=>$item_id],true) ."><small>". _m("Edit") ."</small></a>";
        $s = ($h = !$dcontent[$d_id]["d_state........."][0]['value']) ? _m("Hide") : _m("Approve");
        echo   "<a class=aa-button-delete href=\"javascript:DeleteComment('".$d_id."')\"><small>". _m("Delete") ."</small></a>";
        echo   "<a class='btn btn-secondary' href=" . con_url("discedit.php", ["mode"=>'hide','h'=>$h, 'd_id'=>$d_id, 'item_id'=>$item_id], true) ."><small>". $s. "</small></a>";
        echo "</td></tr>\n";
    }
}
$form_buttons = [
        "update"     => ["value"=>_m("Back")],
        "delete_all" => ["value"=>_m("Delete All"), "js"=>"DeleteAllComments()"],
        "delete_un"  => ["value"=>_m("Delete All Unhidden"), "js"=>"DeleteAllHiddenComments()"]
        ];
FrmTabEnd($form_buttons);
$apage->printFoot();
