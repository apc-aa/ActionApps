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

# se_compact.php3 - assigns html format for compact view
# expected $slice_id for edit slice
# optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";     // GetAliasesFromField funct def 
require $GLOBALS[AA_INC_PATH]."pagecache.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_COMPACT)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_COMPACT, "admin");
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$p_slice_id = q_pack_id($slice_id);

if( $r_fields )
  $fields = $r_fields;
else
  list($fields,) = GetSliceFields($slice_id);

if( $update )
{
  do
  {
    ValidateInput("odd_row_format", L_ODD_ROW_FORMAT, $odd_row_format, $err, true, "text");
    ValidateInput("compact_top", L_COMPACT_TOP, $compact_top, $err, false, "text");
    ValidateInput("compact_bottom", L_COMPACT_BOTTOM, $compact_bottom, $err, false, "text");
    ValidateInput("compact_remove", L_COMPACT_REMOVE, $compact_remove, $err, false, "text");
    ValidateInput("noitem_msg", L_NOITEM_MSG, $noitem_msg, $err, false, "text");
    if( $even_odd_differ )
      ValidateInput("even_row_format", L_EVEN_ROW_FORMAT, $even_row_format, $err, true, "text");
    if( $group_by ) {
      ValidateInput("category_top", L_CATEGORY_TOP, $category_top, $err, false, "text");
      ValidateInput("category_format", L_CATEGORY_FORMAT, $category_format, $err, true, "text");
      ValidateInput("category_bottom", L_CATEGORY_BOTTOM, $category_bottom, $err, false, "text");
    }  
    if( count($err) > 1)
      break;

    $varset->add("odd_row_format", "quoted", $odd_row_format);
    $varset->add("even_row_format", "quoted", $even_row_format);
    $varset->add("group_by","quoted",$group_by);
    $varset->add("gb_direction","quoted",$gb_direction);
    $varset->add("gb_header","number",$gb_header);
    $varset->add("category_top", "quoted", $category_top);
    $varset->add("category_format", "quoted", $category_format);
    $varset->add("category_bottom", "quoted", $category_bottom);
    $varset->add("compact_top", "quoted", $compact_top);
    $varset->add("compact_bottom", "quoted", $compact_bottom);
    $varset->add("compact_remove", "quoted", $compact_remove);
    $varset->add("even_odd_differ", "number", $even_odd_differ ? 1 : 0);
    $varset->add("category_sort", "number", $category_sort ? 1 : 0);
      # if not filled, store " " - the empty value displays "No item found" for
      # historical reasons
    $varset->add("noitem_msg", "quoted", $noitem_msg ? $noitem_msg : " " );

    if( !$db->query("UPDATE slice SET ". $varset->makeUPDATE() . " 
                      WHERE id='".q_pack_id($slice_id)."'")) {
      $err["DB"] = MsgErr( L_ERR_CANT_CHANGE );
      break;   # not necessary - we have set the halt_on_error
    }     
    
    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
    $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
    
  }while(false);
  if( count($err) <= 1 )
    $Msg = MsgOK(L_COMPACT_OK);
}

if( $slice_id!="" ) {  // set variables from database - allways
/*  $SQL= " SELECT odd_row_format, even_row_format, even_odd_differ, compact_top, 
                 compact_bottom, compact_remove, category_sort, category_format,
                 category_top, category_bottom, noitem_msg */
	$SQL = " SELECT *
          FROM slice WHERE id='". q_pack_id($slice_id)."'";
  $db->query($SQL);
  if ($db->next_record()) {
    $odd_row_format = $db->f(odd_row_format);
    $even_row_format = $db->f(even_row_format);
    $category_top = $db->f(category_top);
    $category_format = $db->f(category_format);
    $category_bottom = $db->f(category_bottom);
    $compact_top = $db->f(compact_top);
    $compact_bottom = $db->f(compact_bottom);
    $compact_remove = $db->f(compact_remove);
    $even_odd_differ = $db->f(even_odd_differ);
    $group_by = $db->f(group_by);
    $gb_direction = $db->f(gb_direction);
    $gb_header = $db->f(gb_header);
    $category_sort = $db->f(category_sort);
    if ($group_by) $category_sort = 0;
    $noitem_msg = $db->f(noitem_msg);
    if (!$group_by && $category_sort) {
      $db->query ("SELECT id FROM field WHERE id LIKE 'category.......%' AND slice_id='".q_pack_id($slice_id)."'");
      if ($db->next_record()) {
        $group_by = $db->f("id");
        $gb_direction  = "a";
        $gb_header = 0;
        $category_sort = 0;
      }
    }
  }  
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_COMPACT_TIT;?></TITLE>
<SCRIPT Language="JavaScript"><!--
function Defaults()
{
  document.f.odd_row_format.value = '<?php echo DEFAULT_ODD_HTML ?>'
  document.f.even_row_format.value = '<?php echo DEFAULT_EVEN_HTML ?> '
  document.f.group_by.selectIndex = -1;
  document.f.gb_direction.selectIndex = 0;
  document.f.gb_header.selectIndex = 0;
  document.f.category_top.value = '<?php echo DEFAULT_CATEGORY_TOP ?>'
  document.f.category_format.value = '<?php echo DEFAULT_CATEGORY_HTML ?>'
  document.f.category_bottom.value = '<?php echo DEFAULT_CATEGORY_BOTTOM ?>'
  document.f.compact_top.value = '<?php echo DEFAULT_TOP_HTML ?>'
  document.f.compact_remove.value = '<?php echo DEFAULT_COMPACT_REMOVE ?>'
  document.f.even_odd_differ.checked = <?php echo (DEFAULT_EVEN_ODD_DIFFER ? "true" : "false"). "\n"; ?>
  document.f.noitem_msg.value = ''
  InitPage()
}

function InitPage() {
  EnableClick('document.f.even_odd_differ','document.f.even_row_format')
  //EnableClick('document.f.category_sort','document.f.category_format')
}

function EnableClick(cond,what) {
  eval(what).disabled=!(eval(cond).checked);
  // property .disabled supported only in MSIE 4.0+
}   


// -->
</SCRIPT>
</HEAD>

<?php
  $useOnLoad = true;
  require $GLOBALS[AA_INC_PATH]."menu.php3";
  showMenu ($aamenus, "sliceadmin", "compact");

  echo "<H1><B>" . L_A_COMPACT . "</B></H1>&nbsp;" . L_COMPACT_HELP;
  PrintArray($err);
  echo $Msg;
?>
<form name=f method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_COMPACT_HDR?></b><BR>
</td>
</tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
  # lookup slice fields
  $db->query("SELECT id, name FROM field
               WHERE slice_id='$p_slice_id' ORDER BY name");
  $lookup_fields[''] = " ";  # default - none
  while($db->next_record())
    $lookup_fields[$db->f(id)] = $db->f(name);

  FrmTextarea("compact_top", L_COMPACT_TOP, $compact_top, 4, 50, false,
               L_TOP_HLP, DOCUMENTATION_URL, 1);
  FrmTextarea("odd_row_format", L_ODD_ROW_FORMAT, $odd_row_format, 6, 50, false,
               L_FORMAT_HLP, DOCUMENTATION_URL, 1); 
  FrmInputChBox("even_odd_differ", L_EVEN_ODD_DIFFER, $even_odd_differ, true, "OnClick=\"EnableClick('document.f.even_odd_differ','document.f.even_row_format')\"");
  FrmTextarea("even_row_format", L_EVEN_ROW_FORMAT, $even_row_format, 6, 50, false,
               L_EVEN_ROW_HLP, DOCUMENTATION_URL, 1); 
  FrmTextarea("compact_bottom", L_COMPACT_BOTTOM, $compact_bottom, 4, 50, false,
               L_BOTTOM_HLP, DOCUMENTATION_URL, 1); 
  echo "<tr><td class=tabtxt><b>".L_GROUP_BY."</b></td><td>";
  FrmSelectEasy ("group_by", $lookup_fields, $group_by);
  echo "<br>".L_GROUP_BY_HLP;
  echo "</td></tr>
  <tr><td>&nbsp;</td><td>";
  FrmSelectEasy ("gb_header", array (L_WHOLE_TEXT,L_FIRST_LETTER,"2 ".L_LETTERS,"3 ".L_LETTERS), $gb_header);
  FrmSelectEasy("gb_direction", array( 'a'=>L_ASCENDING, 'd' => L_DESCENDING, '1' => L_ASCENDING_PRI, '9' => L_DESCENDING_PRI  ), 
                $gb_direction);
  PrintHelp( L_SORT_DIRECTION_HLP );
  echo "<input type=hidden name='category_sort' value='$category_sort'>";
  echo "</td></tr>";
  FrmTextarea("category_top", L_CATEGORY_TOP, $category_top, 4, 50, false,
               L_TOP_HLP, DOCUMENTATION_URL, 1);
  FrmTextarea("category_format", L_CATEGORY_FORMAT, $category_format, 6, 50, false,
               L_FORMAT_HLP, DOCUMENTATION_URL, 1); 
  FrmTextarea("category_bottom", L_CATEGORY_BOTTOM, $category_bottom, 4, 50, false,
               L_BOTTOM_HLP, DOCUMENTATION_URL, 1); 
  FrmInputText("compact_remove", L_COMPACT_REMOVE, $compact_remove, 254, 50, false,
               L_REMOVE_HLP, DOCUMENTATION_URL);
  FrmInputText("noitem_msg", L_NOITEM_MSG, $noitem_msg, 254, 50, false,
               L_NOITEM_MSG_HLP, DOCUMENTATION_URL);
?>
</table></td></tr>
<?php
  PrintAliasHelp(GetAliasesFromFields($fields));
?>
<tr><td align="center">
<?php 
  echo "<input type=hidden name=\"update\" value=1>";
  echo "<input type=hidden name=\"slice_id\" value=$slice_id>";
  echo '<input type=submit name=update value="'. L_UPDATE .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. L_CANCEL .'">&nbsp;&nbsp;';
  echo '<input type=button onClick = "Defaults()" align=center value="'. L_DEFAULTS .'">&nbsp;&nbsp;';
?>
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()?>

