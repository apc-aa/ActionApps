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

# se_import.php3 - feeding settings
# expected $slice_id for edit slice
# optionaly $Msg to show under <h1>Hedline</h1> (typicaly: Category update successful)

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_FEEDING);
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable

// lookup all slices without this one
$SQL= "SELECT id, short_name FROM slices WHERE id<>'$p_slice_id' ORDER BY short_name";
$db->query($SQL);
while($db->next_record())
  $all_slices[unpack_id($db->f(id))] = $db->f(short_name);

// lookup export_to slices
$SQL= "SELECT short_name, id FROM slices, feedperms WHERE slices.id=feedperms.to_id 
                   AND feedperms.from_id='$p_slice_id' ORDER BY short_name";
$db->query($SQL);
while($db->next_record())
  $export_to[unpack_id($db->f(id))] = $db->f(short_name);

// lookup importable slices
$SQL= "SELECT short_name, id FROM slices LEFT JOIN feedperms ON slices.id=feedperms.from_id 
       WHERE (feedperms.to_id='$p_slice_id' OR slices.export_to_all=1) AND slices.id<>'$p_slice_id' ORDER BY short_name";
$db->query($SQL);
while($db->next_record())
  $importable[unpack_id($db->f(id))] = $db->f(short_name);

// lookup imported slices
$SQL= "SELECT short_name, id FROM slices, feeds WHERE slices.id=feeds.from_id 
                                AND feeds.to_id='$p_slice_id' ORDER BY short_name";
$db->query($SQL);
while($db->next_record())
  $imported[unpack_id($db->f(id))] = $db->f(short_name);

// export_to_all setting
$SQL= "SELECT export_to_all FROM slices WHERE slices.id='$p_slice_id'";
$db->query($SQL);
if($db->next_record())
  $export_to_all = $db->f(export_to_all);
  
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
include $GLOBALS[AA_INC_PATH]."js_lib.js";
?>
 <TITLE><?php echo L_A_SLICE_TIT;?></TITLE>


<SCRIPT Language="JavaScript"><!--

function ExportAllClick() {
  document.f.export_y.disabled=document.f.export_to_all.checked;
  document.f.export_n.disabled=document.f.export_to_all.checked;
}

function InitPage() {
  ExportAllClick()
}

function UpdateImportExport(slice_id)
{
  var url = "<?php echo $sess->url(self_base() . "se_import2.php3")?>"
  url += "&slice_id=" + slice_id
  url += "&to_all=" + (document.f.export_to_all.checked ? '1' : '0')
  for (var i = 0; i < document.f.import_y.options.length; i++) {
    if(document.f.import_y.options[i].value != "0")    // imported slices
      url += "&I%5B" + i + "%5D=" + escape(document.f.import_y.options[i].value)
  }  
  for (var i = 0; i < document.f.export_y.options.length; i++) {
    if(document.f.export_y.options[i].value != "0")    // exported to slices
      url += "&E%5B" + i + "%5D=" + escape(document.f.export_y.options[i].value)
  }  
  document.location=url
}  
// -->
</SCRIPT>
</HEAD>
<?php
  $xx = ($slice_id!="");
  $useOnLoad = true;
  $show = Array("main"=>true, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx, 
                "addusers"=>$xx, "newusers"=>$xx, "import"=>false, "filters"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

  echo "<H1><B>" . L_A_SLICE_IMP . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form enctype="multipart/form-data" method=post name="f" action="<?php echo $sess->url($PHP_SELF) ?>">
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="#584011" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_IMP_EXPORT ?></b></td></tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<tr>
	<td width="45%" class=tabtxt align=center><b><?php echo L_IMP_EXPORT_N ?></b></td>
	<td width="10%">&nbsp;</td>
	<td width="45%" class=tabtxt align=center><b><?php echo L_IMP_EXPORT_Y ?></b></td>
</tr>
<tr>
<td align="CENTER" valign="TOP">
<SELECT name="export_n" size=8 class=tabtxt>
  <?
  reset($all_slices);
  if( isset($export_to) AND is_array($export_to)) {
    while(list($s_id,$short_name) = each($all_slices))
      if( $export_to[$s_id] == "" )
        echo "<option value=\"$s_id\"> $short_name </option>"; 
  }else 
    while(list($s_id,$short_name) = each($all_slices))
      echo "<option value=\"$s_id\"> $short_name </option>"; 
  ?>
</SELECT></td>
<td><input type="button" VALUE="  >>  " onClick = "MoveSelected('document.f.export_n','document.f.export_y')" align=center>
    <br><br><input type="button" VALUE="  <<  " onClick = "MoveSelected('document.f.export_y','document.f.export_n')" align=center></td>
<td align="CENTER" valign="TOP">
<SELECT name="export_y" size=8 class=tabtxt>
  <?
  if( isset($export_to) AND is_array($export_to)) {
    reset($export_to);
    while(list($s_id,$short_name) = each($export_to))
      echo "<option value=\"$s_id\"> $short_name </option>"; 
  }    ?>
</SELECT>
</td>
</tr>
<tr><td colspan=3><table>
<?php
  FrmInputChBox("export_to_all", L_EXPORT_TO_ALL, $export_to_all, true, "OnClick=\"ExportAllClick()\"");
/*
$Log$
Revision 1.2  2000/08/03 12:49:22  kzajicek
English editing

Revision 1.1.1.1  2000/06/21 18:40:02  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:50  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.14  2000/06/12 19:58:24  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.13  2000/06/09 15:14:10  honzama
New configurable admin interface

Revision 1.12  2000/04/24 16:45:02  honzama
New usermanagement interface.

Revision 1.11  2000/03/29 14:34:12  honzama
Better Netscape Navigator support in javascripts.

Revision 1.10  2000/03/22 09:36:43  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/
?>  
</table></td></tr>
<tr><td colspan=3>&nbsp;</td></tr>
</table></tr></td>
<tr><td class=tabtit><b>&nbsp;<?php echo L_IMP_IMPORT ?></b></td></tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
<tr>
	<td width="45%" class=tabtxt align=center><b><?php echo L_IMP_IMPORT_N ?></b></td>
	<td width="10%">&nbsp;</td>
	<td width="45%" class=tabtxt align=center><b><?php echo L_IMP_IMPORT_Y ?></b></td>
</tr>
<tr>
<td align="CENTER" valign="TOP">
<SELECT name="import_n" size=8 class=tabtxt>
  <?
  if( isset($importable) AND is_array($importable)) {
    reset($importable);
    while(list($s_id,$short_name) = each($importable))
      if( $imported[$s_id] == "" )
        echo "<option value=\"$s_id\"> $short_name </option>"; 
  }
  ?>
</SELECT></td>
<td><input type="button" VALUE="  >>  " onClick = "MoveSelected('document.f.import_n','document.f.import_y')" align=center>
    <br><br><input type="button" VALUE="  <<  " onClick = "MoveSelected('document.f.import_y','document.f.import_n')" align=center></td>
<td align="CENTER" valign="TOP">
<SELECT name="import_y" size=8 class=tabtxt>
  <?
  if( isset($imported) AND is_array($imported)) {
    reset($imported);
    while( list($id, $name) = each($imported)) {
      echo "<option value=$id> $name </option>";
    }  
  }     ?>
</SELECT>
</td>
</tr>
<tr><td colspan=3>&nbsp;</td></tr>
</table></tr></td>
<tr><td align="center">
<input type=hidden name="slice_id" value="<?php echo $slice_id ?>">
<input type="button" VALUE="<?php echo L_UPDATE ?>" onClick = "UpdateImportExport('<?php echo $slice_id ?>')" align=center>&nbsp;&nbsp;
<input type=submit name=cancel value="<?php echo L_CANCEL ?>">
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()?>
