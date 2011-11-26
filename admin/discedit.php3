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
// discedit.php3 - admin discussion comments
// expected  $item_id for comment's item_id
// optional  $mode for delete,hide or normal mode
//           $d_id
//           $h

require_once "../include/init_page.php3";

require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."discussion.php3";
require_once AA_INC_PATH."item.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."msgpage.php3";

/** getHeadline function
 * get a headline of the item
 * @param $content4id
 * @return headline
 */
function getHeadline($content4id) {
    if (!$content4id) {
        return;
    }
    if ($content4id["headline........"]) {
        return $content4id["headline........"][0]['value'];
    }

    for ($i=1; $i<10; $i++) {
        if ($content4id["headline.......".$i]) {
            return $content4id["headline.......".$i][0]['value'];
        }
    }
}

// check permission to edit discussion - you must be Editor, at least
if (!IfSlPerm(PS_EDIT_ALL_ITEMS)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You don't have permissions to edit all items."), "items");
    exit;
}

$err["Init"] = "";          // error array (Init - just for initializing variable

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
            DeleteDiscForItem($item_id, $slice_id);
            break;
        case "deleteshown":
            DeleteDiscForItem($item_id, $slice_id, 0);
            break;
    }
    updateDiscussionCount($item_id);        // update a count of the comments belong to the item

    $GLOBALS['pagecache']->invalidateFor("slice_id=".$slice_id);  // invalidate old cached values

    // refresh the content and the tree because of delete node
    $zids     = QueryDiscussionZIDs($item_id);
    $dcontent = GetDiscussionContent($zids, false);
    $tree     = GetDiscussionTree($dcontent);
}

$aliases = GetDiscussionAliases();
GetDiscussionThread($tree, "0", 0, $outcome);         // get array of images

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Admin - Discussion comments management");?></title>

<script Language="JavaScript"><!--
  function InitPage() {}
  function DeleteComment(id) {
    if ( !confirm("<?php echo _m("Are you sure you want to delete selected comment?"); ?>"))
       return
    var url="<?php echo $sess->url(con_url("./discedit.php3", "mode=delete")); ?>"
    document.location=url + "&d_id=" + escape(id) + "&item_id=" + "<?php echo $item_id; ?>"
  }
  function DeleteAllComments() {
    if ( !confirm("<?php echo _m("Are you sure you want to delete ALL comments? There is no way back!"); ?>"))
       return;
    var url="<?php echo $sess->url(con_url("./discedit.php3", "mode=deleteall")); ?>"
    document.location=url + "&item_id=" + "<?php echo $item_id; ?>"
  }
  function DeleteAllHiddenComments() {
    if ( !confirm("<?php echo _m("Are you sure you want to delete ALL unhidden comments (the comments, which are NOT HIDDEN)? There is no way back!"); ?>"))
       return;
    var url="<?php echo $sess->url(con_url("./discedit.php3", "mode=deleteshown")); ?>"
    document.location=url + "&item_id=" + "<?php echo $item_id; ?>"
  }
  // -->
</script>
</head>
<body>
<?php
echo "<center>
    <h1><b>" . _m("Discussion comments management") . "</b></h1>";
PrintArray($err);
echo $Msg;

$content  = GetItemContent($item_id);
$headline = getHeadline($content[$item_id]);
?>
  <form method="post" action=<?php echo sess_return_url(self_base()."index.php3") ?> >
  <table width="95%" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
  <tr><td class="tabtit"><b>&nbsp;<?php
     echo _m("Item: ")." $headline</b> (".
           $content[$item_id]["disc_app........"][0]['value']. "/".
           $content[$item_id]["disc_count......"][0]['value']. ")" ?> </td></tr>
  <tr><td>
  <table width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="<?php echo COLOR_TABBG ?>">
     <tr><td width="10">&nbsp;</td>
        <td><b><?php echo _m("Title") ?></td>
        <td width="10">&nbsp;</td>
        <td><b><?php echo _m("Author") ?></td>
        <td width="10">&nbsp;</td>
        <td><b><?php echo _m("IP Address") ?></td>
        <td width="10">&nbsp;</td>
        <td><b><?php echo _m("Date") ?></td>
        <td width="10">&nbsp;</td>
        <td align="center"><b><?php echo _m("Actions") ?></td>
        <td width="10">&nbsp;</td>
    </tr>
      <tr><td colspan="9">&nbsp;</td></tr>

<?php
$item = new AA_Item("",$aliases);
$i = 0;
if (!$outcome) {
    echo "<tr><td colspan=\"9\" align=\"center\" class=\"tabtxt\">". _m("No discussion comments") ."<br><br></td></tr>";
} else {
    foreach ($outcome as $d_id => $images) {
        $im = "";
        while (list(,$img) = each($images)) {
            $im .= GetImageSrc($img);
        }
        $im2 = "<tr><td>&nbsp;</td>
                 <td><table cellspacing=\"0\" cellpadding=\"0\" border=\"0\">
                    <tr><td>".$im. PrintImg("blank.gif",2,21)."</td>
                        <td nowrap>_#SUBJECT_&nbsp;</td>
                    </tr>
                    </table>
                 </td>
                 <td>&nbsp;</td>
                 <td nowrap>_#AUTHOR#_</td>
                 <td>&nbsp;</td>
                 <td nowrap>_#IP_ADDR_</td>
                 <td>&nbsp;</td>
                 <td nowrap>_#DATE###_</td>
                 <td>&nbsp;</td>";
        $item->setformat($im2);
        $item->set_data($dcontent[$d_id]);
        echo $item->get_item();

        echo "<td align=\"center\">&nbsp;&nbsp;&nbsp;<a href=\"javascript:DeleteComment('".$d_id."')\"><small>". _m("Delete") ."</small></a>";
        echo "&nbsp;<a href=". con_url($sess->url("discedit2.php3"),"d_id=".
             $d_id."&item_id=".$item_id) ."><small>". _m("Edit") ."</small></a>";
        $s = ($h = !$dcontent[$d_id]["d_state........."][0]['value']) ? _m("Hide") : _m("Approve");
        echo "&nbsp;<a href=" . con_url($sess->url("discedit.php3"), "mode=hide&h=".$h.
            "&d_id=".$d_id. "&item_id=".$item_id) ."><small>". $s. "</small></a></td>
            <td>&nbsp;</td>";
        echo "</tr>\n";
    }
}
?>
  </table>
  </td></tr>
  <tr><td class="tabtit"  align="center">
     <input type="submit" value="<?php echo _m("Back") ?>">&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;
     <input type="button" value="<?php echo _m("Delete All") ?>" onclick="DeleteAllComments();">&nbsp;
     <input type="button" value="<?php echo _m("Delete All Unhidden") ?>" onclick="DeleteAllHiddenComments();"></td></tr>
  </table>
  </form>
  </center>
<?php
echo "</body></html>";
page_close();
?>
