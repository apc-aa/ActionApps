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

#             $slice_id
# optionaly $Msg to show under <h1>Headline</h1> (typicaly: Fields' mapping update)

require "../include/init_page.php3";

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING)) {
  MsgPage($sess->url(self_base()."index.php3"), L_NO_PS_FEEDING);
  exit;
}
require $GLOBALS[AA_INC_PATH]."formutil.php3";

$p_slice_id = q_pack_id($slice_id);
switch ($mode) {
  case "delete" :
    list($sel_node, $sel_user) = explode("~",$perms);
    $db->query("DELETE FROM ef_permissions WHERE slice_id='$p_slice_id' AND  node='$sel_node' AND  user='$sel_user'");
    break;
  case "insert" :
    $db->query("SELECT slice_id FROM ef_permissions WHERE slice_id='$p_slice_id' AND  node='$r_nodes' AND  user='$user_name'");
    if ($db->next_record())   // duplicity
      $err["DB"].= "Can't add new permission record";
    else
      $db->query("INSERT INTO ef_permissions VALUES('$p_slice_id','$r_nodes','$user_name')");
    break;
}

$db->query("SELECT * FROM ef_permissions WHERE slice_id='$p_slice_id' ORDER BY node,user ");
$perms="";
while ($db->next_record()) {
  $perms[] = array( node=>$db->f(node), user=>$db->f(user));
}

$db->query("SELECT * FROM nodes ORDER BY name ");
$nodes="";
while ($db->next_record()) {
  $nodes[] = $db->f(name);
}


$err["Init"] = "";          // error array (Init - just for initializing variable
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_EXPORT_TIT;?></TITLE>
<SCRIPT Language="JavaScript"><!--

function InitPage() {}

function SelectValue(sel) {
  return eval(sel).options[eval(sel).selectedIndex].value
}

function Delete() {
  sel = SelectValue('document.f.perms')
  if (sel == undefined) {
    alert('<?php echo L_EXPORT_SEL_NONE; ?>')
    return
  }
  if (!confirm('<?php echo L_EXPORT_CONFIRM_DELETE; ?>'))
    return
  document.f.mode.value='delete';
  document.f.submit();
}

function Cancel() {
  document.location = "<?php echo $sess->url(self_base() . "index.php3")?>"
}
// -->
</SCRIPT>
</HEAD>
<BODY>
<?php
  $useOnLoad = true;
  require $GLOBALS[AA_INC_PATH]."menu.php3";
  showMenu ($aamenus, "sliceadmin", "n_export");

  echo "<H1><B>" . L_EXPORT_TIT . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>

<form method=post name="f" action="<?php echo $sess->url($PHP_SELF) ?>" >
  <table width="400" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
    <tr><td class=tabtit><b>&nbsp;<?php echo L_EXPORT_TIT   ?></b></td></tr>
     <tr><td>
      <table width="100%" border="0" cellspacing="0" cellpadding="2" bgcolor="<?php echo COLOR_TABBG ?>">
      <tr><td colspan=2 ><?php echo L_EXPORT_LIST. "<B>".$r_slice_headline."</B>"; ?></td></tr>
      <tr><td colspan=2 align=center>
        <SELECT name="perms" size=5>
         <?php
           if ($perms && is_array($perms)) {
              reset($perms);
              while(list(,$perm) = each($perms)) {
                $n = (!$perm[node]) ? "All Nodes" : $perm[node];
                $u = (!$perm[user]) ? "All Users" : $perm[user];
                $str = str_replace(" ","&nbsp;",substr($n."                              ",0,30)."  ");
                echo "<option value=\"".$perm[node]."~".$perm[user]."\">".
                  $str.$u."</option>";
              }
           }
          ?>
        </SELECT>
      </td></tr>
      <tr><td colspan=2 align="center">
        <input type=button VALUE="<?php echo L_DELETE ?>" onClick = "Delete()">
       </td></tr>

      <tr><td colspan=2 >&nbsp;</td></tr>
      <tr><td colspan=2><?php echo L_EXPORT_ADD; ?></td></tr>
      <tr><td width="40%"><?php echo L_EXPORT_NODES; ?></td><td align="left">
        <SELECT name="r_nodes" class=tabtxt size=5>
        <?php
          if ($nodes && is_array($nodes)) {
          reset($nodes);
          while(list(,$n) = each($nodes))
            echo "<option value=\"$n\"".($n==$node ? "SELECTED":"")." >$n</option>";
          }
        ?>
        </SELECT>
      </td></tr>
      <tr><td colspan=2><?php echo L_EXPORT_NAME; ?>
          <input type="text" name="user_name" size=40 value="<?php echo safe($user_name)?>" >
      </td></tr>
      <input type="hidden" name="mode" value="insert">
      <tr><td colspan="2" align=center >
           <input type="submit" value="<?php echo L_SUBMIT ?>" >
           <input type=button value="<?php echo L_CANCEL ?>" onClick="Cancel()" >
      </td></tr>
  </table>
  </td></tr>
  </table>
</FORM>
<?php
HtmlPageEnd();
page_close()
?>
