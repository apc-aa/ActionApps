<?php
/** se_filters.php3 - feeding filters settings
 *   expected $slice_id for edit slice
 *   optionaly $import_id for selected imported slice
 *   optionaly $Msg to show under <h1>Hedline</h1> (typicaly: Filters update successful)
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


//

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."csn_util.php3";
require_once AA_INC_PATH."msgpage.php3";

if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_FEEDING)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change feeding setting"), "sliceadmin", "filters");
    exit;
}
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

$err["Init"] = "";          // error array (Init - just for initializing variable

// lookup (slices)
$SQL= "SELECT name, id FROM feeds, slice
        LEFT JOIN feedperms ON slice.id=feedperms.from_id
        WHERE slice.id=feeds.from_id
          AND (feedperms.to_id='$p_slice_id' OR slice.export_to_all=1)
          AND feeds.to_id='$p_slice_id' ORDER BY name";

$db->query($SQL);
while ($db->next_record()) {
    $impslices[unpack_id($db->f('id'))] = $db->f('name');
}

// lookup external slices
$SQL = "SELECT remote_slice_id, remote_slice_name, feed_id, node_name
        FROM external_feeds
        WHERE slice_id='$p_slice_id' ORDER BY remote_slice_name";
$db->query($SQL);

while ($db->next_record()) {
    $impslices[unpack_id($db->f(remote_slice_id))] = $db->f(node_name)." - ".$db->f(remote_slice_name);
    $remote_slices[unpack_id($db->f(remote_slice_id))] = $db->f(feed_id);
}

if ( !isset($impslices) OR !is_array($impslices)){
    MsgPageMenu(con_url($sess->url(self_base()."se_import.php3"), "slice_id=$slice_id"), _m("There are no imported slices"), "sliceadmin", "filters");
    exit;
}

if ( $import_id == "" ) {
    reset($impslices);
    $import_id = key($impslices);
}
$p_import_id = q_pack_id($import_id);


// lookup (to_categories)
$group = GetCategoryGroup($slice_id);
if ( $group ) {
    $db->query("SELECT id, name FROM constant
                 WHERE group_id='$group'
                 ORDER BY pri");
    $first_time = true;               // in order to The Same to be first in array
    while ($db->next_record()) {
        if ( $first_time ) {
            $to_categories[unpack_id('AA_The_Same_Cate')] = _m("-- The same --");
            $first_time = false;
        }
        $to_categories[unpack_id($db->f('id'))] = $db->f('name');
    }
}

// lookup (from_categories) and preset form values
if ($feed_id = $remote_slices[$import_id]) {   // not comparison! - external feed

    $ext_categs = GetExternalCategories($feed_id, true);  // get also default 'AA_Other_Categor' category

    $imp_count  = sizeof($ext_categs);

    if ($ext_categs AND is_array($ext_categs)) {

        // check, if we use 'All categories' option
        $all_categories = UseAllCategoriesOption( $ext_categs );

        if ( $all_categories ) {
            // first row - all categories
            $approved_0     = $ext_categs[UNPACKED_AA_OTHER_CATEGOR]['approved'];
            $categ_0        = $ext_categs[UNPACKED_AA_OTHER_CATEGOR]['target_category_id'];
        } else {
            foreach ( $ext_categs as $id => $v) {
                $chboxcat[$id] = (strlen($v['target_category_id'])>1);
                $selcat[$id]   =  $v['target_category_id'];
                $chboxapp[$id] =  $v['approved'];
            }
        }
    }
} else {   // inner feeding
    $imp_group = GetCategoryGroup($import_id);

    // count number of imported categories
    $SQL= "SELECT count(*) as cnt FROM constant
            WHERE group_id='$imp_group'";
    $db->query($SQL);
    $imp_count = ($db->next_record() ? $db->f('cnt') : 0);

    // preset variables due to feeds database
    $SQL= "SELECT category_id, to_category_id, all_categories, to_approved FROM feeds
            WHERE from_id='$p_import_id' AND to_id='$p_slice_id'";
    $db->query($SQL);
    while ($db->next_record()) {
        if ( $db->f('all_categories') ) {
            $all_categories = true;
            $approved_0     = $db->f('to_approved');
            $categ_0        = unpack_id($db->f('to_category_id'));  // if 0 => the same category
        } else {
            $chboxcat[unpack_id($db->f('category_id'))] = true;
            $selcat[unpack_id($db->f('category_id'))]   = unpack_id($db->f('to_category_id'));
            $chboxapp[unpack_id($db->f('category_id'))] = $db->f('to_approved');
        }
    }
}
?>
 <title><?php echo _m("Admin - Content Pooling - Filters");?></title>
<script Language="JavaScript"><!--

function ChBoxState(chbox) {
  return eval(chbox).checked
}

function SelectValue(sel) {
  svindex = eval(sel).selectedIndex;
  if (svindex != -1) { return eval(sel).options[svindex].value; }
  return null;
}

function HiddenValue(sel) {
  return eval(sel).value
}

function ChangeImport()
{
  var url = "<?php echo $sess->url(self_base() . "se_filters.php3")?>"
  url += "&slice_id=<?php echo $slice_id ?>"
  url += "&import_id=" + SelectValue('document.f.import_id')
  document.location=url
}

function AllCategClick() {
  for ( i=1; i<=<?php echo $imp_count?>; i++ ) {
    DisableClick('document.f.all_categories','document.f.chbox_'+i)
    if ( <?php echo (( isset($to_categories) AND is_array($to_categories)) ? 1 : 0 ) ?> )
      DisableClick('document.f.all_categories','document.f.categ_'+i)
    DisableClick('document.f.all_categories','document.f.approved_'+i)
  }
}

function InitPage() {
  AllCategClick()
}

function DisableClick(cond,what) {
     eval(what).disabled=eval(cond).checked;
     // property .disabled supported only in MSIE 4.0+
}

function UpdateFilters(slice_id, import_id) {
  var url = "<?php echo $sess->url(self_base() . "se_filters2.php3")?>"
  var done = 0
  url += "&slice_id=" + slice_id
  url += "&import_id=" + import_id
  url += "&feed_id=<?php echo $feed_id ?>"
  if ((typeof document.f.all_categories == 'undefined') ||   // no import cats
     (ChBoxState('document.f.all_categories'))) {
    done = 1
    if ( <?php echo (( isset($to_categories) AND is_array($to_categories)) ? 1 : 0 ) ?> ) {
      url += "&all=1&C=" + escape(SelectValue('document.f.categ_0'))
      url += "-" + (ChBoxState('document.f.approved_0') ? 1 : 0)
    } else {
      url += "&all=1&C=0-" + (ChBoxState('document.f.approved_0') ? 1 : 0)
    }
  } else {
    for (var i = 1; i <= <?php echo $imp_count?>; i++) {
      if (ChBoxState('document.f.chbox_'+i)) {
        done = 1
        if ( <?php echo (( isset($to_categories) AND is_array($to_categories)) ? 1 : 0 ) ?> ) {
           url += "&T%5B%5D=" + escape(SelectValue('document.f.categ_'+i))
        } else {
           url += "&T%5B%5D=0"
        }
        url += "&F%5B%5D=" +  escape(HiddenValue('document.f.hid_'+i))
        url += "-" + (ChBoxState('document.f.approved_'+i) ? 1 : 0)
      }
    }
  }
  if (done == 0) {
    alert ( "<?php echo _m("No From category selected!") ?>" )
  } else {
    document.location=url
  }
}

    function SelectChboxes(chb_name) {
      var len = document.f.elements.length;
      var state = 2;
      var str_len = chb_name.length;
      for ( var i=0; i<len; i++ )
        if ( document.f.elements[i].name.substring(0,str_len) == chb_name) { //items checkboxes
          if (state == 2) {
            state = ! document.f.elements[i].checked;
          }
          document.f.elements[i].checked = state;
        }
    }
// -->
</script>
</head>
<?php
  $useOnLoad = true;
  require_once AA_INC_PATH."menu.php3";
  showMenu ($aamenus, "sliceadmin", "filters");

  echo "<h1><b>" . _m("Admin - Content Pooling - Filters") . "</b></h1>";
  PrintArray($err);
  echo $Msg;

  $form_buttons=array("btn_upd"=>array("type"  =>"button",
                                 "value"       =>_m("Update"),
                                 "accesskey"   =>"S",
                                 "add"         =>"onclick=\"UpdateFilters('".$slice_id."','".$import_id."')\""),
                "cancel"       =>array("url"   =>"se_fields.php3"))

?>
<form method="post" name="f" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
<?php
  FrmTabCaption(_m("Content Pooling - Configure Filters"),'','',$form_buttons, $sess, $slice_id);
?>
<tr>
  <td colspan class="tabtxt" align="center"><b><?php echo _m("Filter for imported slice") . "&nbsp; "?></b></td>
  <td><?php FrmSelectEasy("import_id", $impslices, $import_id, "OnChange=\"ChangeImport()\""); ?></td>
</tr>
<?php
FrmTabSeparator(_m("Categories"));
?>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<tr>
  <td width="40%" colspan="2" class="tabtxt" align="center"><b><?php echo _m("From") ?></b></td>
  <td width="30%" class="tabtxt" align="center"><b><?php echo _m("To") ?></b></td>
  <td width="30%" class="tabtxt" align="center"><b><?php echo _m("Active") ?></b></td>
</tr>

<tr>
<?php
if ($imp_count) {
   echo "<td align=\"center\">";
   FrmChBoxEasy("all_categories", $all_categories, "OnClick=\"AllCategClick()\"");
   echo "</td>";
}
?>
<td class=tabtxt <?php if (!$imp_count) { echo "colspan=\"2\" align=\"center\""; } ?>><?php echo _m("All Categories") ?></td>
</td>

<td><?php
if ( isset($to_categories) AND is_array($to_categories) ) {
    FrmSelectEasy("categ_0", $to_categories, $categ_0);
} else {
    echo "<span class=\"tabtxt\">". _m("No category defined") ."</span>";
}
?></td>
<td align="center"><?php FrmChBoxEasy("approved_0", $approved_0); ?></td>
</tr>
<tr><td colspan="4"><hr></td></tr>
<?php
/** PrintOneRow function
 *  @param $id
 *  @param $cat_name
 *  @param $i
 */
function PrintOneRow($id, $cat_name, $i) {
    global $chboxcat, $selcat, $chboxapp, $to_categories;

    echo "<tr><td align=\"center\">";
    $chboxname = "chbox_". $i;
    FrmChBoxEasy($chboxname, $chboxcat[$id] );
    echo "</td>\n<td class=\"tabtxt\">". $cat_name. "</td><td>";
    $selectname = "categ_". $i;
    if ( isset($to_categories) AND is_array($to_categories) ) {
        FrmSelectEasy($selectname, $to_categories, isset($selcat[$id]) ? $selcat[$id] : $id);
    } else {
        echo "<span class=\"tabtxt\">". _m("No category defined") ."</span>";
    }
    echo "</td>\n<td align=\"center\">";
    $chboxname = "approved_". $i;
    FrmChBoxEasy($chboxname, $chboxapp[$id] );
    echo "<input type=\"hidden\" name=\"hid_$i\" value=\"$id\">";
    echo "</td></tr>";
}

if ($feed_id) {
    if (isset($ext_categs) && is_array($ext_categs)) {
        $i=1;
        foreach ($ext_categs as $id => $v ) {
            if ( $id == UNPACKED_AA_OTHER_CATEGOR ) {
                $other_categ = $v;   // we just want to list it at the end
                continue;
            }
            PrintOneRow($id,$v['name'],$i++);
        }
        if ( $other_categ ) {
            PrintOneRow(UNPACKED_AA_OTHER_CATEGOR,$other_categ['name'],$i++);
        }
    }
}
else {
    if ( $imp_group ) {
        $db->query("SELECT id, name, value FROM constant
                     WHERE group_id='$imp_group'
                     ORDER BY name");
        $i=1;
        while ($db->next_record()) {
            PrintOneRow(unpack_id($db->f('id')),$db->f('name'),$i++);
        }
    }
}
?>
<tr><td colspan="3"><a href="javascript:SelectChboxes('chbox_')"><?php echo _m('Select all');?></td><td><a href="javascript:SelectChboxes('approved_')"><?php echo _m('Select all');?></td></tr>
<?php
FrmTabEnd($form_buttons,$sess, $slice_id);
?>
</form>
<?php
HtmlPageEnd();
page_close();
?>
