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
  MsgPage($sess->url(self_base()."index.php3"), L_NO_PS_FEEDING);
  exit;
}

require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."csn_util.php3";


$err["Init"] = "";          // error array (Init - just for initializing variable

$p_slice_id = q_pack_id($slice_id);

// lookup internal fed slices
$SQL= "SELECT name, id FROM slice, feeds
        LEFT JOIN feedperms ON slice.id=feedperms.from_id
        WHERE slice.id=feeds.from_id
          AND (feedperms.to_id='$p_slice_id' OR slice.export_to_all=1)
          AND feeds.to_id='$p_slice_id' ORDER BY name";
$db->query($SQL);
while($db->next_record())
  $impslices[unpack_id($db->f(id))] = $db->f(name);

// lookup external fed slices
$SQL = "SELECT remote_slice_id, remote_slice_name, node_name
        FROM external_feeds
        WHERE slice_id='$p_slice_id'";
$db->query($SQL);
while($db->next_record()) {
  $impslices[unpack_id($db->f(remote_slice_id))] = $db->f(node_name)." - ".$db->f(remote_slice_name);
  $remote_slices[unpack_id($db->f(remote_slice_id))] = 1;       // mark slice as external
}

// add all slices where I have permission to (for setting of mapping for slices, 
// which is only manualy fed)
reset( $g_modules );
$first=true;
while( list( $k, $v) = each($g_modules) ) {
  if( $impslices[$k] OR $v['type']!='S' OR $k==$slice_id )
    continue;
  if( $first AND isset($impslices) AND is_array($impslices) ) 
    $impslices[0] = '---------------';             # put delimeter there
  $impslices[$k] = $v['name'];
  $first=false;
}  
  
if( !isset($impslices) OR !is_array($impslices)){
  MsgPage(con_url($sess->url(self_base()."se_import.php3"), "slice_id=$slice_id"), L_NO_IMPORTED_SLICE);
  exit;
}

// set from_slice_id
if( $from_slice_id == "" ) {
  reset($impslices);
  $from_slice_id = key($impslices);
}
$p_from_slice_id = q_pack_id($from_slice_id);

// get mapping from table
list($map_to,$field_map) = GetExternalMapping($slice_id, $from_slice_id );

// find out list of "to fields"
$SQL= "SELECT id, name FROM field WHERE slice_id='$p_slice_id' ORDER BY name";
$db->query($SQL);
while($db->next_record())
  $to_fields[$db->f(id)] = $db->f(name);

// find out list of "from fields"
$from_fields[L_MAP_NOTMAP] = L_MAP_NOTMAP;
$from_fields[L_MAP_VALUE] = L_MAP_VALUE;
$from_fields[L_MAP_JOIN] = L_MAP_JOIN;

if (!$remote_slices[$from_slice_id]) {      // local fields : from slice fields
$SQL= "SELECT id, name FROM field WHERE slice_id='$p_from_slice_id' ORDER BY name";
$db->query($SQL);
while($db->next_record())
  $from_fields[$db->f(id)] = $db->f(name);
}
else {                                     // remote fields : from feedmap table
  if (isset($map_to) && is_array($map_to)) {
    while (list($k,$v) = each($map_to)) {
      $from_fields[$k] = $v;
    }
  }
}

reset( $to_fields ) ;
while( list( $k, $v ) = each( $to_fields ) ) {
  if(!isset($field_map[$k]))
     $field_map[$k] =  $from_fields[$k] ? array("feedmap_flag"=>FEEDMAP_FLAG_MAP,"value"=>$k)  :
                                          array("feedmap_flag"=>FEEDMAP_FLAG_EMPTY,"value"=>"") ;
}

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
  var from_sl = SelectValue('document.f.from_slice_id')
  if( from_sl == 0 )
    return;
  url += "&slice_id=<?php echo $slice_id ?>"
  url += "&from_slice_id=" + from_sl
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
<BODY>
<?php
  $useOnLoad = true;
  require $GLOBALS[AA_INC_PATH]."menu.php3";
  showMenu ($aamenus, "sliceadmin","mapping");

  echo "<H1><B>" . L_MAP_TIT . "</B></H1>";
  PrintArray($err);
  echo stripslashes($Msg);

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
                 $val = htmlspecialchars($field_map[$f_id][value]); break;
               case FEEDMAP_FLAG_JOIN :
                 $sel = L_MAP_JOIN;
                 $val = htmlspecialchars($field_map[$f_id][value]); break;
               case FEEDMAP_FLAG_EMPTY: $sel =  L_MAP_NOTMAP; break;
               case FEEDMAP_FLAG_MAP :
               case FEEDMAP_FLAG_EXTMAP :
                  $sel = $field_map[$f_id][value];
             }
             FrmSelectEasy("fmap[$f_id]",$from_fields,$sel);
             echo "</td><td class=tabtxt> <input type=text name=\"fval[$f_id]\" value=\"$val\"></input></td>";
             echo "</tr>\n";
           }
        ?>
      </table>
    </td></tr>
    <tr><td align="center">
      <input type=hidden name="ext_slice" value="<?php echo $remote_slices[$from_slice_id]; ?>" >
      <input type=button value="<?php echo L_UPDATE ?>" onClick = "Submit()" align=center>&nbsp;&nbsp;
      <input type=button VALUE="<?php echo L_CANCEL ?>" onClick = "Cancel()">
     </td></tr>

  </table>
</FORM>
 <?php //p_arr_m($field_map);
HtmlPageEnd();
page_close()?>
