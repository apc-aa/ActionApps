<?php 
//$Id$
/* 
Copyright (C) 1999, 2000 Association for Progressive Communications 
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

# se_filters.php3 - feeding filters settings
# expected $slice_id for edit slice
# optionaly $import_id for selected imported slice
# optionaly $Msg to show under <h1>Hedline</h1> (typicaly: Filters update successful)

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."csn_util.php3";
require $GLOBALS[AA_INC_PATH]."msgpage.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING)) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change feeding setting"), "sliceadmin", "filters");
  exit;
}  
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

$err["Init"] = "";          // error array (Init - just for initializing variable

// lookup (slices) 
$SQL= "SELECT name, id FROM slice, feeds 
        LEFT JOIN feedperms ON slice.id=feedperms.from_id 
        WHERE slice.id=feeds.from_id 
          AND (feedperms.to_id='$p_slice_id' OR slice.export_to_all=1)
          AND feeds.to_id='$p_slice_id' ORDER BY name";


$db->query($SQL);
while($db->next_record())
  $impslices[unpack_id($db->f(id))] = $db->f(name);

// lookup external slices
$SQL = "SELECT remote_slice_id, remote_slice_name, feed_id, node_name
        FROM external_feeds
        WHERE slice_id='$p_slice_id' ORDER BY remote_slice_name";
$db->query($SQL);

while($db->next_record()) {
  $impslices[unpack_id($db->f(remote_slice_id))] = $db->f(node_name)." - ".$db->f(remote_slice_name);
  $remote_slices[unpack_id($db->f(remote_slice_id))] = $db->f(feed_id);
}

if( !isset($impslices) OR !is_array($impslices)){
  MsgPageMenu(con_url($sess->url(self_base()."se_import.php3"), "slice_id=$slice_id"), _m("There are no imported slices"), "sliceadmin", "filters");
  exit;
}  
  
if( $import_id == "" ) {
  reset($impslices);
  $import_id = key($impslices);
}  
$p_import_id = q_pack_id($import_id);

// lookup (to_categories) 
$group = GetCategoryGroup($slice_id);
if( $group ) {
  $db->query("SELECT id, name FROM constant 
               WHERE group_id='$group'
               ORDER BY pri");
  $first_time = true;               # in order to The Same to be first in array
  while($db->next_record()) {
    if( $first_time AND !$remote_slices[$import_id]) {  # for remote categories must be set
      $to_categories["0"] = _m("-- The same --");
      $first_time = false;
    } 
    $to_categories[unpack_id($db->f(id))] = $db->f(name);
  }  
}

// lookup (from_categories) and preset form values
if ($feed_id = $remote_slices[$import_id]) {
  $ext_categs = GetExternalCategories($feed_id);
  $imp_count = sizeof($ext_categs);

  if ($ext_categs && is_array($ext_categs)) {
    $v0=current($ext_categs);
    $same = true;
    while (list(,$v) = each($ext_categs)) {
      if ($v[target_category_id] != $v0[target_category_id] || $v[approved] != $v0[approved]) {
        $same = false;
        break;
      }
    }
    if ($same) {
      $all_categories=true;
      $approved_0 = $v0[approved];
      $categ_0 = $v0[target_category_id];
    } else {
      reset($ext_categs);
      while (list ($id,$v) = each($ext_categs)) {
        $chboxcat[$id] = ($v[target_category_id] != "");
        $selcat[$id] = $v[target_category_id];
        $chboxapp[$id] = $v[approved];
      }
    }
  }
} else {   // inner feeding
  $imp_group = GetCategoryGroup($import_id);
  
  // count number of imported categories
  $SQL= "SELECT count(*) as cnt FROM constant 
          WHERE group_id='$imp_group'";
  $db->query($SQL);
  $imp_count = ($db->next_record() ? $db->f(cnt) : 0);
  
  // preset variables due to feeds database
  $SQL= "SELECT category_id, to_category_id, all_categories, to_approved FROM feeds 
         WHERE from_id='$p_import_id' AND to_id='$p_slice_id'";
  $db->query($SQL);
  while($db->next_record()) {
    if( $db->f(all_categories) ) {
      $all_categories=true;
      $approved_0 = $db->f(to_approved);
      $categ_0 = unpack_id($db->f(to_category_id));  // if 0 => the same category
    }else{
      $chboxcat[unpack_id($db->f(category_id))] = true;
      $selcat[unpack_id($db->f(category_id))] = unpack_id($db->f(to_category_id));
      $chboxapp[unpack_id($db->f(category_id))] = $db->f(to_approved);
    }
  }    
}
?>
 <TITLE><?php echo _m("Admin - Content Pooling - Filters");?></TITLE>
<SCRIPT Language="JavaScript"><!--

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
  for( i=1; i<=<?php echo $imp_count?>; i++ ) {
    DisableClick('document.f.all_categories','document.f.chbox_'+i)
    if( <?php echo (( isset($to_categories) AND is_array($to_categories)) ? 1 : 0 ) ?> )
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
  if((typeof document.f.all_categories == 'undefined') ||   // no import cats
     (ChBoxState('document.f.all_categories'))) {
    done = 1
    if( <?php echo (( isset($to_categories) AND is_array($to_categories)) ? 1 : 0 ) ?> ) {
      url += "&all=1&C=" + escape(SelectValue('document.f.categ_0'))
      url += "-" + (ChBoxState('document.f.approved_0') ? 1 : 0)
    } else {
      url += "&all=1&C=0-" + (ChBoxState('document.f.approved_0') ? 1 : 0)
    }
  } else {
    for (var i = 1; i <= <?php echo $imp_count?>; i++) {
      if(ChBoxState('document.f.chbox_'+i)) {
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
// -->
</SCRIPT>
</HEAD>
<?php
  $useOnLoad = true;
  require $GLOBALS[AA_INC_PATH]."menu.php3";
  showMenu ($aamenus, "sliceadmin", "filters");
  
  echo "<H1><B>" . _m("Admin - Content Pooling - Filters") . "</B></H1>";
  PrintArray($err);
  echo $Msg;
  
?>
<form method=post name="f" action="<?php echo $sess->url($PHP_SELF) ?>">
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo _m("Content Pooling - Configure Filters") ?></b></td></tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<tr>
  <td colspan class=tabtxt align=center><b><?php echo _m("Filter for imported slice") . "&nbsp; "?></b></td>
  <td><?php FrmSelectEasy("import_id", $impslices, $import_id, "OnChange=\"ChangeImport()\""); ?></td>
</tr>
</table></td></tr>
<tr><td class=tabtit><b>&nbsp;<?php echo _m("Categories") ?></b></td></tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<tr>
  <td width="40%" colspan=2 class=tabtxt align=center><b><?php echo _m("From") ?></b></td>
  <td width="30%" class=tabtxt align=center><b><?php echo _m("To") ?></b></td>
  <td width="30%" class=tabtxt align=center><b><?php echo _m("Active") ?></b></td>
</tr>  

<tr>
<?php
if ($imp_count) {
   echo "<td align=center>";
   FrmChBoxEasy("all_categories", $all_categories, "OnClick=\"AllCategClick()\"");
   echo "</td>"; 
}
?>
<td class=tabtxt <?php if (!$imp_count) { echo "colspan=2 align=center"; } ?>><?php echo _m("All Categories") ?></td>
</td>

<TD><?php 
  if( isset($to_categories) AND is_array($to_categories) )
    FrmSelectEasy("categ_0", $to_categories, $categ_0);
   else   
    echo "<span class=tabtxt>". _m("No category defined") ."</span>";
?></td>
<td align="CENTER"><?php FrmChBoxEasy("approved_0", $approved_0); ?></td>
</tr>
<tr><td colspan=4><hr></td></tr>
<?php

function PrintOneRow($id, $cat_name, $i) {
  global $chboxcat, $selcat, $chboxapp, $to_categories;

    echo "<tr><td align=CENTER>";
    $chboxname = "chbox_". $i;
     FrmChBoxEasy($chboxname, $chboxcat[$id] );
  echo "</td>\n<td class=tabtxt>". $cat_name. "</td><TD>";
    $selectname = "categ_". $i;
    if( isset($to_categories) AND is_array($to_categories) )
     FrmSelectEasy($selectname, $to_categories, isset($selcat[$id]) ? $selcat[$id] : $id);
     else   
       echo "<span class=tabtxt>". _m("No category defined") ."</span>";
    echo "</td>\n<TD align=CENTER>";
    $chboxname = "approved_". $i;
     FrmChBoxEasy($chboxname, $chboxapp[$id] );
    echo "<input type=hidden name=hid_$i value=$id>";
    echo "</td></tr>";
}

if ($feed_id) {
  if (isset($ext_categs) && is_array($ext_categs)) {
    $i=1;
    reset($ext_categs);
    while (list($id,$v) = each($ext_categs)) {
      PrintOneRow($id,$v[name],$i++);
    }
  }
}
else {
  if( $imp_group ) {
    $db->query("SELECT id, name, value FROM constant
               WHERE group_id='$imp_group'
               ORDER BY name");
    $i=1;
    while($db->next_record()) {
      PrintOneRow(unpack_id($db->f(id)),$db->f(name),$i++);
    }
  }
} 
?>  
<tr><td colspan=4>&nbsp;</td></tr>
</table></tr></td>
<tr><td align="center">
<input type=hidden name="slice_id" value="<?php echo $slice_id ?>">
<input type="button" VALUE="<?php echo _m("Update") ?>" onClick = "UpdateFilters('<?php echo $slice_id ?>','<?php echo $import_id ?>')" align=center>&nbsp;&nbsp;
<input type=submit name=cancel value="<?php echo _m("Cancel") ?>">
</td></tr></table>
</FORM>
<?php
HtmlPageEnd();
page_close()?>
