<?php 
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

# se_mapping.php3 - mapping fields settings

# expected $slice_id for edit slice
# optionaly $from_slice_id for selected imported slice
# optionaly $Msg to show under <h1>Headline</h1> (typicaly: Fields' mapping update)

require "../include/init_page.php3";

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_FEEDING);
  exit;
}

require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."feeding.php3";

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

if( !isset($impslices) OR !is_array($impslices)){
  MsgPage(con_url($sess->url(self_base()."se_import.php3"), "slice_id=$slice_id"), L_NO_IMPORTED_SLICE);
  exit;
}

if( $from_slice_id == "" ) {
  reset($impslices);
  $from_slice_id = key($impslices);
}
$p_from_slice_id = q_pack_id($from_slice_id);

$from_fields[L_MAP_NOTMAP] = L_MAP_NOTMAP;
$from_fields[L_MAP_VALUE] = L_MAP_VALUE;
$SQL= "SELECT id, name FROM field WHERE slice_id='$p_from_slice_id' ORDER BY name";
$db->query($SQL);
while($db->next_record())
  $from_fields[$db->f(id)] = $db->f(name);

$SQL= "SELECT id, name FROM field WHERE slice_id='$p_slice_id' ORDER BY name";
$db->query($SQL);
while($db->next_record())
  $to_fields[$db->f(id)] = $db->f(name);

$field_map = GetFieldMapping($from_slice_id,$slice_id,$from_fields,$to_fields);

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_MAP_TIT;?></TITLE>
<SCRIPT Language="JavaScript"><!--

function InitPage() {}

function SelectValue(sel) {
  return eval(sel).options[eval(sel).selectedIndex].value
}


function ChangeFromSlice()
{
  var url = "<?php echo $sess->url(self_base() . "se_mapping.php3")?>"
  url += "&slice_id=<?php echo $slice_id ?>"
  url += "&from_slice_id=" + SelectValue('document.f.from_slice_id')
  document.location=url
}

function Cancel() {
  document.location = "<?php echo $sess->url(self_base() . "index.php3")?>"
}

function Submit() {
/*  var e = document.f.elements;
  fcnt = <?php echo count($from_fields)?>

  // test for duplicity
  for ( i=1; i < fcnt; i++)
    for( j=i+1; j<fcnt+1; j++)
      if (e[i].selectedIndex !=0 && SelectValue(e[i]) == SelectValue(e[j])) {
        alert("<?php echo L_MAP_DUP ?>");
        return;
      }
  */
  document.f.submit();
}
// -->
</SCRIPT>

</HEAD>
<?php
  $xx = ($slice_id!="");
  $useOnLoad = true;
  $show = Array("main"=>true, "slicedel"=>$xx, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx,
                "views"=>$xx, "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx, "mapping"=>false);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable
  echo "<H1><B>" . L_MAP_TIT . "</B></H1>";
  PrintArray($err);
  echo $Msg;

?>
<form enctype="multipart/form-data" method=post name="f" action="<?php echo $sess->url(self_base() . "se_mapping2.php3")?>">
  <table width="600" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
    <tr><td class=tabtit><b>&nbsp;<?php echo L_MAP_TABTIT ?></b></td></tr>

    <tr><td>
      <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
        <tr>
          <td align=left class=tabtxt align=center><b><?php echo L_MAP_FROM_SLICE . "&nbsp; "?></b>
          <?php FrmSelectEasy("from_slice_id", $impslices, $from_slice_id, "OnChange=\"ChangeFromSlice()\""); ?></td>
         </tr>
      </table>
    </td></tr>

    <tr><td class=tabtit><b>&nbsp;<?php echo L_MAP_FIELDS ?></b></td></tr>
    <tr><td>
      <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
        <tr>
          <td class=tabtxt align=center><b><?php echo L_MAP_TO ?></b></td>
          <td class=tabtxt align=center><b><?php echo L_MAP_FROM ?></b></td>
          <td class=tabtxt align=center><b><?php echo L_MAP_VALUE2 ?></b></td>
        </tr>
        <?php
           reset($to_fields);
           while (list($f_id, $f_name) = each($to_fields)) {
             echo "<tr><td class=tabtxt><b>$f_name</b></td>\n";
             echo "<td>";

             $val = "";
             switch ($field_map[$f_id][feedmap_flag]) {
               case FEEDMAP_FLAG_VALUE :
                 $sel = L_MAP_VALUE;
                 $val = htmlspecialchars($field_map[$f_id][val]); break;
               case FEEDMAP_FLAG_EMPTY: $sel =  L_MAP_NOTMAP; break;
               case FEEDMAP_FLAG_MAP : $sel = $field_map[$f_id][val];
             }
             FrmSelectEasy("fmap[$f_id]",$from_fields,$sel);
             echo "</td><td class=tabtxt> <input type=text name=\"fval[$f_id]\" value=\"$val\"></input></td>";
             echo "</tr>\n";
           }
        ?>
      </table>
    </td></tr>
    <tr><td align="center">
      <input type=hidden name="slice_id" value="<?php echo $slice_id ?>">
      <input type=button value="<?php echo L_UPDATE ?>" onClick = "Submit()" align=center>&nbsp;&nbsp;
      <input type=button VALUE="<?php echo L_CANCEL ?>" onClick = "Cancel()">
     </td></tr>

  </table>
</FORM>
 <?php //p_arr_m($field_map);
 ?>
</BODY>
</HTML>
<?php
page_close()?>
