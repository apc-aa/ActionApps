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

# se_nodes.php3 - Remote node administration

# expected    $mode
#             $name
#             $server_url
#             $password
# optionaly $Msg to show under <h1>Headline</h1> (typicaly: Fields' mapping update)
#             $old_node_name
#             $sel_node_name

require_once "../include/init_page.php3";

if( !isSuperadmin() ) {
  MsgPage($sess->url(self_base()."index.php3"), _m("You have not permissions to manage nodes"));
  exit;
}
$err["Init"]="";
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";

 if ($mode == "edit") {
    $db->query("SELECT * FROM nodes WHERE name='$sel_node_name'");
    if ($db->next_record()) {
      $old_node_name = $sel_node_name;
      $node_name = $db->f(name);
      $server_url = $db->f(server_url);
      $password = $db->f(password);
      $new_mode="update";
    }
  }
  else {
    switch ($mode) {
      case "delete" :
        $db->query("DELETE FROM nodes WHERE name='$sel_node_name'");
        $db->query("DELETE FROM ef_permissions WHERE node='$sel_node_name'");
        break;

      case "insert" :
        $db->query("SELECT * FROM nodes WHERE name='$node_name'");
        if ($db->next_record()) {
          $err["DB"] .= MsgErr("Can't add node $node_name");
        } else {
          $SQL = "INSERT INTO nodes VALUES('$node_name','$server_url','$password')";
          if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
            $err["DB"] .= MsgErr("Can't add node $node_name");
          }
        }
        break;

      case "update" :
        $db->query("UPDATE nodes SET name='$node_name', server_url='$server_url',
                                    password='$password' WHERE name='$old_node_name'");
        break;

      case "add" : $new_mode = "insert"; break;
    }
    $node_name = $server_url = $password = "";
    $new_mode = "insert";

   }

$db->query('SELECT * FROM nodes ORDER BY name ');
$nodes="";
while ($db->next_record()) {
  $nodes[] = $db->f(name);
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <title><?php echo _m("Remote node administration");?></title>
<script language="javascript"><!--

function InitPage() {}

function SelectValue(sel) {
  svindex = eval(sel).selectedIndex;
  if (svindex != -1) { return eval(sel).options[svindex].value; }
  return null;
}

function ActionGo(mode) {
  if (mode== 'add') {
    document.frm.mode.value = mode;
    document.frm.submit();
  } else {

  sel = SelectValue('document.frm.nodes')
  if (sel == null)
    alert('<?php echo _m("No selected node"); ?>')
  else {
    if (mode == 'delete')
      if (!confirm('<?php echo _m("Are you sure you want to delete the node?"); ?>'))
        return
    document.frm.sel_node_name.value = sel
    document.frm.mode.value = mode;
    document.frm.submit();
  }
 }
}

function checkData() {
  if (document.frm.node_name.value=="") {
     alert('<?php echo _m("Node empty"); ?>')
     return false
  }
}

function Cancel() {
  document.location = "<?php echo $sess->url(self_base() . "index.php3")?>"
}

// -->
</script>

</head>

<?php
  $useOnLoad = true;
  require_once $GLOBALS["AA_INC_PATH"]."menu.php3";
  showMenu ($aamenus, "sliceadmin","nodes");

  echo "<H1><B>" . _m("Remote node administration") . "</B></H1>";
  PrintArray($err);
  echo $Msg;
  
  $form_buttons = array("submit","cancel"=>array("url"=>"se_fields.php3"));
  
?>

<form method="post" name="frm" action="<?php echo $sess->url($PHP_SELF) ?>" onsubmit="return checkData()">
<?php

    FrmTabCaption(_m("Remote node administration"));
?>
      <tr><td colspan=2><?php echo _m("Known remote nodes") ?></td></tr>
      <tr><td align=center colspan=2>
      <select name="nodes" class=tabtxt size=5>
      <?php
        if (isset($nodes) && is_array($nodes)) {
          reset($nodes);
          while(list(,$name) = each($nodes))
            echo "<option value=\"$name\">$name</option>";
        }
      ?>
      </select>
    <tr><td colspan=2 align="center">
      <input type=button value="<?php echo _m("Edit") ?>" onclick="ActionGo('edit');">
      <input type=button value="<?php echo _m("Delete") ?>" onclick="ActionGo('delete');">
      <input type=button value="<?php echo _m("Add") ?>" onclick="ActionGo('add');">
     </td></tr>
    <tr><td colspan=2>&nbsp;</td></tr>
<?php

    FrmTabSeparator(($new_mode=="insert" ? _m("Add new node") : _m("Edit node data")));

?>    
    <tr><td><?php echo _m("Node name") ?></td>
        <td><input type="text" name="node_name" size=40 value="<?php echo safe($node_name)?>" ><br><?php echo _m("Your node name")?>: "<?php echo ORG_NAME ?>"
    <tr><td><?php echo _m("URL of the getxml.php3") ?></td>
         <td><input type="text" name="server_url" size=40 value="<?php echo safe($server_url)?>" ><br><?php echo _m("Your getxml is")?>: "<?php echo $AA_INSTAL_PATH ?>admin/getxml.php3"
    <tr><td><?php echo _m("Password") ?></td>
         <td><input type="text" name="password" size=40 value="<?php echo safe($password)?>" >
    <input type="hidden" name="mode" value="<?php echo safe($new_mode) ?>">
    <input type="hidden" name="old_node_name" value="<?php echo safe($old_node_name) ?>">
    <input type="hidden" name="sel_node_name">
<?php
    FrmTabEnd($form_buttons, $sess, $slice_id); 

    echo "  
    </form>";

HtmlPageEnd();
page_close()
?>
