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

# se_inter_import.php3 - Inter node feed import settings

#           $slice_id
#           $feed_id - if set, then delete this feed

# optionaly $Msg to show under <h1>Headline</h1> (typicaly: Fields' mapping update)

require_once "../include/init_page.php3";

if(!IfSlPerm(PS_FEEDING)) {
  MsgPage($sess->url(self_base()."index.php3"), _m("You have not permissions to change feeding setting"));
  exit;
}
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";

$p_slice_id = q_pack_id($slice_id);

if (isset($feed_id)) {
  // delete mode

  // delete mapping from feedmap table
  $db->query("SELECT remote_slice_id FROM external_feeds WHERE feed_id='$feed_id' AND slice_id='$p_slice_id'");
  if ($db->next_record()) {
    $remote_slice_id = quote($db->f('remote_slice_id'));
    $db->query("DELETE FROM feedmap WHERE from_slice_id='$remote_slice_id' AND to_slice_id='$p_slice_id'");
  }
  $db->query("DELETE FROM ef_categories WHERE feed_id='$feed_id'");      // delete categories
  $db->query("DELETE FROM external_feeds WHERE feed_id='$feed_id'");     // delete feed
}

$SQL       = "SELECT feed_id, name, node_name, remote_slice_id, remote_slice_name
                FROM external_feeds LEFT JOIN nodes ON external_feeds.node_name = nodes.name
               WHERE slice_id='$p_slice_id' ORDER BY name";
$ext_feeds = GetTable2Array($SQL, 'feed_id');
$nodes     = GetTable2Array('SELECT * FROM nodes ORDER BY name', 'NoCoLuMn');

$err["Init"] = "";          // error array (Init - just for initializing variable
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Inter node import settings");?></TITLE>
<SCRIPT Language="JavaScript"><!--

function InitPage() {}

function SelectValue(sel) {
  svindex = eval(sel).selectedIndex;
  if (svindex != -1) { return eval(sel).options[svindex].value; }
  return null;
}

function Delete() {
  sel = SelectValue('document.f.feed_id')
  if (sel == null) {
    alert('<?php echo _m("No selected import"); ?>')
    return
  }
  if (!confirm('<?php echo _m("Are you sure you want to delete the import?"); ?>'))
    return

  var url = "<?php echo $sess->url(self_base() . "se_inter_import.php3"); ?>"
  url += "&feed_id=" + sel
  document.location = url
}

function Submit() {
  if (SelectValue(document.f.rem_nodes) == null) {
     alert('<?php echo _m("No selected node"); ?>')
     return false
  }
}

// -->
</SCRIPT>

</HEAD>
<BODY>
<?php
  $useOnLoad = true;
  require_once $GLOBALS["AA_INC_PATH"]."menu.php3";
  showMenu ($aamenus, "sliceadmin","n_import");

  echo "<H1><B>" . _m("Inter node import settings") . "</B></H1>";
  PrintArray($err);
  echo $Msg;
  $form_buttons = array("submit"=>array("value" => _m("Create new feed from node")),
                        "cancel"=>array("url"=>"se_fields.php3"));
?>
<form method=post name="f" action="<?php echo $sess->url(self_base() ."se_inter_import2.php3") ?>" onSubmit="return Submit()" >
<?php

  FrmTabCaption(_m("Inter node import settings"));
/*
  <table width="400" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
    <tr><td class=tabtit><b>&nbsp;<?php echo _m("Inter node import settings") ?></b></td></tr>
     <tr><td>
      <table width="100%" border="0" cellspacing="0" cellpadding="2" bgcolor="<?php echo COLOR_TABBG ?>">*/
?>
      <tr><td ><?php echo _m("Existing remote imports into the slice ") ."<b>" .$r_slice_headline ."</b>" ?></td></tr>
      <tr><td align=center>
        <SELECT name="feed_id" size=5>
         <?php
           if ($ext_feeds && is_array($ext_feeds)) {
               foreach($ext_feeds as $k => $v) {
                   $node_name = ( ( $v['node_name'] == $v['name'] ) ?
                       $v['node_name'] :
                       $v['node_name'].' - '. _m('Missing!!!'));
                   $str = str_replace(" ","&nbsp;",str_pad($node_name,30)."  ");
                   echo "<option value=\"$k\">".$str.$v['remote_slice_name']."</option>";
              }
           }
          ?>
        </SELECT>
      </td></tr>

      <tr><td align="center">
        <input type=button VALUE="<?php echo _m("Delete") ?>" onClick = "Delete()">
       </td></tr>
<?php
       FrmTabSeparator(_m("All remote nodes"));

/*      <tr><td ><?php echo _m("All remote nodes"); ?>
      </td></tr>*/
?>
      <tr><td align="center">
        <SELECT name="rem_nodes" class=tabtxt size=5>
        <?php
          reset($nodes);
          while(list(,$n) = each($nodes))
            echo "<option value=\"".$n[name]."\"".($n[name]==$node ? "selected":"")." >".$n[name]."</option>";
        ?>
        </SELECT>
      </td></tr>
<?php
/*      <tr><td align=center ><input type=submit value="<?php echo _m("Create new feed from node") ?>" ></td></tr>
  </table>
  </td></tr>
  </table>*/
  FrmTabEnd($form_buttons, $sess, $slice_id);
?>
</FORM>
<?php
HtmlPageEnd();
page_close()
?>
