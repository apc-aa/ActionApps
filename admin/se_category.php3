<?php  #se_category.php3 - assigns categories to specified slice
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

# expected $slice_id for edit slice
# optionaly $Msg to show under <h1>Hedline</h1> (typicaly: Category update successful)

require "../include/init_page.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CATEGORY)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_CATEGORY);
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_SLICE_TIT;?></TITLE>
</HEAD>

<?php
  $xx = ($slice_id!="");
  $show = Array("main"=>true, "config"=>$xx, "category"=>false, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx, 
                "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

  echo "<H1><B>" . L_A_SLICE_CAT . "</B></H1>";
  PrintArray($err);
  echo $Msg;

// lookup (apc-wide categories) 
$apc_categories = GetConstants('lt_apcCategories', $db);

  
if( $import_id == "" ) {
  reset($impslices);
  $import_id = key($impslices);
}  
$p_import_id = q_pack_id($import_id);

// count number of imported categories
$SQL= "SELECT count(*) as cnt FROM categories, catbinds WHERE catbinds.category_id=categories.id 
         AND catbinds.slice_id='$p_import_id'";
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
    
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_FILTERS_TIT;?></TITLE>
<SCRIPT Language="JavaScript"><!--

function ChBoxState(chbox) {
  return eval(chbox).checked
}

function SelectValue(sel) {
  return eval(sel).options[eval(sel).selectedIndex].value  
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
    alert ( "<?php echo L_FLT_NONE ?>" )
  } else {
    document.location=url
  }
}  
// -->
</SCRIPT>
</HEAD>
<?php
  $xx = ($slice_id!="");
  $useOnLoad = true;
  $show = Array("main"=>true, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx, 
                "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>false);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable
  echo "<H1><B>" . L_A_FILTERS_FLT . "</B></H1>";
  PrintArray($err);
  echo $Msg;
  
?>
<form enctype="multipart/form-data" method=post name="f" action="<?php echo $sess->url($PHP_SELF) ?>">
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="#584011" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_FLT_SETTING ?></b></td></tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<tr>
	<td colspan class=tabtxt align=center><b><?php echo L_FLT_FROM_SL . "&nbsp; "?></b></td>
  <td><?php FrmSelectEasy("import_id", $impslices, $import_id, "OnChange=\"ChangeImport()\""); ?></td>
</tr>
</table></td></tr>
<tr><td class=tabtit><b>&nbsp;<?php echo L_FLT_CATEGORIES ?></b></td></tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<tr>
	<td width="40%" colspan=2 class=tabtxt align=center><b><?php echo L_FLT_FROM ?></b></td>
	<td width="30%" class=tabtxt align=center><b><?php echo L_FLT_TO ?></b></td>
	<td width="30%" class=tabtxt align=center><b><?php echo L_FLT_APPROVED ?></b></td>
</tr>  

<tr>
<?php
if ($imp_count) {
   echo "<td align=center>";
   FrmChBoxEasy("all_categories", $all_categories, "OnClick=\"AllCategClick()\"");
   echo "</td>"; 
}
?>
<td class=tabtxt <?php if (!$imp_count) { echo "colspan=2 align=center"; } ?>><?php echo L_ALL_CATEGORIES ?></td>
</td>

<TD><?php 
  if( isset($to_categories) AND is_array($to_categories) )
    FrmSelectEasy("categ_0", $to_categories, $categ_0);
   else   
    echo "<span class=tabtxt>". L_NO_CATEGORY ."</span>";
?></td>
<td align="CENTER"><?php FrmChBoxEasy("approved_0", $approved_0); ?></td>
</tr>
<tr><td colspan=4><hr></td></tr>
<?php

$SQL= "SELECT id, name FROM categories, catbinds WHERE catbinds.category_id=categories.id 
         AND catbinds.slice_id='$p_import_id' ORDER BY name";
$db->query($SQL);
$i=1;
while($db->next_record()) {
  $id = unpack_id($db->f(id));
  echo "<tr><td align=CENTER>";
  $chboxname = "chbox_". $i;
   FrmChBoxEasy($chboxname, $chboxcat[$id] );
  echo "</td>\n<td class=tabtxt>". $db->f(name). "</td><TD>";
  $selectname = "categ_". $i;
  if( isset($to_categories) AND is_array($to_categories) )
     FrmSelectEasy($selectname, $to_categories, $selcat[$id]); 
   else   
     echo "<span class=tabtxt>". L_NO_CATEGORY ."</span>";
  echo "</td>\n<TD align=CENTER>";
  $chboxname = "approved_". $i;
   FrmChBoxEasy($chboxname, $chboxapp[$id] );
  echo "<input type=hidden name=hid_$i value=$id>";
  echo "</td></tr>";
  $i++;
}  
?>  
<tr><td colspan=4>&nbsp;</td></tr>
</table></tr></td>
<tr><td align="center">
<input type=hidden name="slice_id" value="<?php echo $slice_id ?>">
<input type="button" VALUE="<?php echo L_UPDATE ?>" onClick = "UpdateFilters('<?php echo $slice_id ?>','<?php echo $import_id ?>')" align=center>&nbsp;&nbsp;
<input type=submit name=cancel value="<?php echo L_CANCEL ?>">
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()?>











?>
<form enctype="multipart/form-data" method=post name="f" action="<?php echo $sess->url($PHP_SELF) ?>">
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="#584011" align="center"><tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<tr bgcolor="#584011" align="center">
	<td width="45%" class=tabtit><b>&nbsp;<?php echo L_CAT_LIST ?></b></td>
	<td width="10%">&nbsp;</td>
	<td width="45%" class=tabtit><b>&nbsp;<?php echo L_CAT_SELECT ?></b></td>
</tr>
<tr>
<td align="CENTER" valign="TOP">
<SELECT name="slisel" size=1 class=tabtxt onChange="SliceChange()">
  <option value=0 selected> <?php echo L_ALL ?> </option>
  <?
  $SQL= "SELECT category_id, name, slice_id, short_name FROM slices, catbinds 
           LEFT JOIN categories ON categories.id=catbinds.category_id 
           WHERE catbinds.slice_id=slices.id ORDER BY short_name, name";
  $db->query($SQL);
  $foo="";          // comma delimeted list of categories for actual slice
  $counter = 1;     // begin with 1
  $oldslice = "";
  if ($db->next_record()) {
    do{ 
      if( $oldslice_id != $db->f(slice_id) ) {
        if($foo != "") {
          echo "<option value=\"$foo\"> ". $oldshort_name ." </option>";   //in value property are listed all categories from this slice
        }
        $oldshort_name = $db->f(short_name);
        $oldslice_id = $db->f(slice_id);
        $foo = "";
      }  
      if( $catnumbers[$db->f(category_id)] == "") {  // not assigned category, yet
        $catnumbers[$db->f(category_id)]=$counter;   // category id are shortered - renumbered
        $foo .= $counter++;
      }
      else                                           // assigned category
        $foo .= $catnumbers[$db->f(category_id)];     
      $foo .= ",";  
      if( !$db->next_record() )
        break;
    } while(true);
  }  
  echo "<option value=\"$foo\"> ". $oldshort_name ." </option>";   
  ?>
</SELECT>
</td>
<td>&nbsp;</td>
<td align="CENTER" valign="TOP">
  <?
  $SQL= "SELECT short_name FROM slices WHERE id ='". q_pack_id($slice_id). "'";
  $db->query($SQL);
  if($db->next_record())
    echo "<span class=tabtxt>". $db->f(short_name) ."</span>"; ?>
</td>
</tr>
<tr>
<td align="CENTER" valign="TOP">
<SELECT name="source" size=8 class=tabtxt>
  <?
  $SQL= "SELECT id, name FROM categories ORDER BY name";
  $db->query($SQL);
  while($db->next_record()) {
    $foo = $catnumbers[$db->f(id)];
    if( $foo=="" ) $foo = 0;
    echo "<option value=\"$foo:" . unpack_id($db->f(id)). "\"> ". $db->f(name) ." </option>"; 
  }  ?>
</SELECT>
</td>
<td><input type="button" VALUE="  >>  " onClick = "MoveCateg()" align=center></td>
<td align="CENTER" valign="TOP">
<SELECT name="dest" size=8 class=tabtxt>
  <?
  $SQL= "SELECT name, id FROM categories LEFT JOIN catbinds ON categories.id = catbinds.category_id WHERE catbinds.slice_id='".q_pack_id($slice_id)."'";
  $db->query($SQL);
  while($db->next_record()) 
    echo "<option value=".unpack_id($db->f(id)). "> ". $db->f(name) ." </option>"; ?>
</SELECT>
</td>
</tr>
<tr>
<td>&nbsp;</td>
<td>&nbsp;</td>
<td align="center" class=tabtxt><a href="javascript:NewCateg()"><?php echo L_NEW?></a>&nbsp;&nbsp;
                   <a href="javascript:DelCateg()"><?php echo L_REMOVE?></a>
</td>
</tr>
</table></td></tr>
<tr><td align="center">
<input type=hidden name="slice_id" value="<?php echo $slice_id ?>">
<input type="button" VALUE="<?php echo L_UPDATE ?>" onClick = "UpdateCateg('<?php echo $slice_id ?>')" align=center>&nbsp;&nbsp;
<input type=submit name=cancel value="<?php echo L_CANCEL ?>">
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()
/*
$Log$
Revision 1.3  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.2  2000/08/03 12:49:22  kzajicek
English editing

Revision 1.1.1.1  2000/06/21 18:39:58  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:47  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.16  2000/06/12 19:58:23  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.15  2000/06/09 15:14:10  honzama
New configurable admin interface

Revision 1.14  2000/04/28 09:48:13  honzama
Small bug in user/group search fixed.

Revision 1.13  2000/04/24 16:45:02  honzama
New usermanagement interface.

Revision 1.12  2000/03/29 14:34:12  honzama
Better Netscape Navigator support in javascripts.

Revision 1.11  2000/03/22 09:36:43  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/
?>
