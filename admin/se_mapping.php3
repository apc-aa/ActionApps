<?php
/** se_mapping.php3 - mapping fields settings
 *
 *    expected $slice_id for edit slice
 *    optionaly $from_slice_id for selected imported slice
 *    optionaly $Msg to show under <h1>Headline</h1> (typicaly: Fields' mapping update)
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
require_once AA_INC_PATH."csn_util.php3";


$err["Init"] = "";          // error array (Init - just for initializing variable

$p_slice_id = q_pack_id($slice_id);

// lookup internal fed slices
$SQL= "SELECT name, id FROM feeds, slice
        LEFT JOIN feedperms ON slice.id=feedperms.from_id
        WHERE slice.id=feeds.from_id
          AND (feedperms.to_id='$p_slice_id' OR slice.export_to_all=1)
          AND feeds.to_id='$p_slice_id' ORDER BY name";
$db->query($SQL);
while ($db->next_record()) {
    $impslices[unpack_id($db->f(id))] = $db->f(name);
}

// lookup external fed slices
$SQL = "SELECT remote_slice_id, remote_slice_name, node_name
        FROM external_feeds
        WHERE slice_id='$p_slice_id'";
$db->query($SQL);
while ($db->next_record()) {
    $impslices[unpack_id($db->f(remote_slice_id))] = $db->f(node_name)." - ".$db->f(remote_slice_name);
    $remote_slices[unpack_id($db->f(remote_slice_id))] = 1;       // mark slice as external
}
// lookup RSS feeds
$SQL="SELECT feed_id, server_url, name, slice_id FROM rssfeeds
       WHERE slice_id='$p_slice_id'";
$db->query($SQL);
while ($db->next_record()) {
    $u_remote_slice_id = attr2id($db->f(server_url));
    $impslices[$u_remote_slice_id] = "RSS - ".$db->f(name);
    $remote_slices[$u_remote_slice_id] = 2;       // mark slice as RSS
}

// add all slices where I have permission to (for setting of mapping for slices,
// which is only manualy fed)
$first=true;
foreach ($g_modules as  $k => $v) {
    if ($impslices[$k] OR $v['type']!='S' OR $k==$slice_id) {
        continue;
    }
    if ($first AND isset($impslices) AND is_array($impslices)) {
        $impslices[0] = '---------------';             // put delimiter there
    }
    $impslices[$k] = $v['name'];
    $first=false;
}

if (!isset($impslices) OR !is_array($impslices)){
    MsgPage(con_url($sess->url(self_base()."se_import.php3"), "slice_id=$slice_id"), _m("There are no imported slices"));
    exit;
}

// set from_slice_id
if ($from_slice_id == "") {
    reset($impslices);
    $from_slice_id = key($impslices);
}
$p_from_slice_id = q_pack_id($from_slice_id);

// get mapping from table
list($map_to,$field_map) = GetExternalMapping($slice_id, $from_slice_id );

if (is_null($field_map) && ($remote_slices[$from_slice_id] == 2)) {
    $field_map = $DEFAULT_RSS_MAP;
}

// find out list of "to fields"
$to_fields = GetFields4Select($slice_id);

// find out list of "from fields"
$from_fields[_m("-- Not map --")]           = _m("-- Not map --");
$from_fields[_m("-- Value --")]             = _m("-- Value --");
$from_fields[_m("-- Joined fields --")]     = _m("-- Joined fields --");
$from_fields[_m("-- RSS field or expr --")] = _m("-- RSS field or expr --");

if (!$remote_slices[$from_slice_id]) {      // local fields : from slice fields
    $from_fields += GetFields4Select($from_slice_id);
} else {                                     // remote fields : from feedmap table
    if (isset($map_to) && is_array($map_to)) {
        while (list($k,$v) = each($map_to)) {
            $from_fields[$k] = $v;
        }
    }
}

foreach ($to_fields as  $k => $v) {
    if (!isset($field_map[$k])) {
        $field_map[$k] =  $from_fields[$k] ? array("feedmap_flag"=>FEEDMAP_FLAG_MAP,"value"=>$k)  :
                                             array("feedmap_flag"=>FEEDMAP_FLAG_EMPTY,"value"=>"");
    }
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <title><?php echo _m("Admin - Content Pooling - Fields' Mapping");?></title>
<script Language="JavaScript"><!--

function InitPage() {}

function SelectValue(sel) {
    svindex = eval(sel).selectedIndex;
    if (svindex != -1) { return eval(sel).options[svindex].value; }
    return null;
}


function ChangeFromSlice()
{
    var url = "<?php echo $sess->url(self_base() . "se_mapping.php3")?>"
    var from_sl = SelectValue('document.f.from_slice_id')
    if (from_sl == 0) {
        return;
    }
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
    for ( j=i+1; j<fcnt+1; j++)
      if (e[i].selectedIndex !=0 && SelectValue(e[i]) == SelectValue(e[j])) {
        alert("<?php echo _m("Cannot map to same field") ?>");
        return;
      }
  */
  document.f.submit();
}
// -->
</script>

</head>
<body>
<?php

$useOnLoad = true;
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "sliceadmin","mapping");

echo "<h1><b>" . _m("Admin - Content Pooling - Fields' Mapping") . "</b></h1>";
PrintArray($err);
echo stripslashes($Msg);

$form_buttons = array("ext_slice"=>array("type"=>"hidden",
                                         "value"=>$remote_slices[$from_slice_id]),
                      "btn_upd"=>array("type"=>"button",
                                       "value"=>_m("Update"),
                                       "accesskey"=>"S",
                                       "add"=>'onclick="Submit()"'),
                      "cancel"=>array("url"=>"se_fields.php3"));
?>
<form enctype="multipart/form-data" method="post" name="f" action="<?php echo $sess->url(self_base() . "se_mapping2.php3")?>">
<?php
FrmTabCaption(_m("Content Pooling - Fields' mapping"),'','',$form_buttons, $sess, $slice_id);
?>
        <tr>
          <td align="left" class="tabtxt" align="center"><b><?php echo _m("Mapping from slice") . "&nbsp; "?></b>
          <?php FrmSelectEasy("from_slice_id", $impslices, $from_slice_id, "OnChange=\"ChangeFromSlice()\""); ?></td>
         </tr>
<?php
FrmTabSeparator(_m("Fields' mapping"));
?>
    <tr><td>
      <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
        <tr>
          <td class="tabtxt" align="center" colspan=2><b><?php echo _m("To") ?></b></td>
          <td class="tabtxt" align="center" colspan=2><b><?php echo _m("From") ?></b></td>
          <td class="tabtxt" align="center"><b><?php echo _m("Value") ?></b></td>
        </tr>
<?php
foreach ($to_fields as $f_id => $f_name) {
    echo "<tr><td class=\"tabtxt\"><b>$f_name</b></td>\n";
    echo "<td class=\"tabtxt\">$f_id</td>\n";
    echo "<td>";
    $val = "";

    switch ($field_map[$f_id]['feedmap_flag']) {
        case FEEDMAP_FLAG_VALUE :
            $sel = _m("-- Value --");
            $val = myspecialchars($field_map[$f_id]['value']); break;
        case FEEDMAP_FLAG_JOIN :
            $sel = _m("-- Joined fields --");
            $val = myspecialchars($field_map[$f_id]['value']); break;
        case FEEDMAP_FLAG_EMPTY:
            $sel =  _m("-- Not map --");
            break;
        case FEEDMAP_FLAG_MAP :
        case FEEDMAP_FLAG_EXTMAP :
            $sel = $field_map[$f_id]['value'];
            break;
        case FEEDMAP_FLAG_RSS :
            $v = $field_map[$f_id]['value'];
            $sel =  ($from_fields[$v]) ? $v : _m("-- RSS field or expr --");
            $val = myspecialchars($field_map[$f_id]['value']);
            break;
    }
    FrmSelectEasy("fmap[$f_id]",$from_fields,$sel);
    echo "</td><td class=\"tabtxt\">$sel</td>";
    echo "<td class=\"tabtxt\"> <input type=\"text\" name=\"fval[$f_id]\" value=\"$val\"></td>";
    echo "</tr>\n";
}
FrmTabEnd($form_buttons, $sess, $slice_id);
?>
</form>
<?php
HtmlPageEnd();
page_close();
?>
