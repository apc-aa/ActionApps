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

/* Change Log:
	Mitra 25 Nov 2002  Copied and edited from se_nodes.php3 
*/
# se_rssfeeds.php3 - RSS Feed administration

# expected    $mode
#             $name
#             $server_url
#             $password
# optionaly $Msg to show under <h1>Headline</h1> (typicaly: Fields' mapping update)
#             $old_rssfeed_name
#             $sel_rssfeed_name

require "../include/init_page.php3";

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING)) {
  MsgPage($sess->url(self_base()."index.php3"), _m("You have not permissions to change feeding setting"));
  exit;
}

require $GLOBALS[AA_INC_PATH]."varset.php3";

$err["Init"]="";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
$qp_slice_id=q_pack_id($slice_id);

 if ($mode == "edit") {
    $db->query("SELECT * FROM rssfeeds WHERE name='$sel_rssfeed_name' AND slice_id = '$qp_slice_id'");
    if ($db->next_record()) {
      $old_rssfeed_name = $sel_rssfeed_name;
      $rssfeed_name = $db->f(name);
      $server_url = $db->f(server_url);
      $new_mode="update";
    }
  }
  else {
    switch ($mode) {
      case "delete" :
        $db->query("DELETE FROM rssfeeds WHERE name='$sel_rssfeed_name' AND slice_id = '$qp_slice_id'");
        // $db->query("DELETE FROM ef_permissions WHERE node='$sel_rssfeed_name'");
        break;

      case "insert" :
        $db->query("SELECT * FROM rssfeeds WHERE name='$rssfeed_name' AND slice_id = '$qp_slice_id'");
        if ($db->next_record()) {
          $err["DB"] .= MsgErr("Can't add RSS Feed $rssfeed_name");
        } else {		
		  $catVS = new Cvarset();
		  $catVS->add("slice_id", "unpacked", $slice_id);
		  $catVS->add("name", "quoted", $rssfeed_name);
		  $catVS->add("server_url","quoted",$server_url);
		  $SQL = "INSERT INTO rssfeeds" . $catVS->makeINSERT();
          if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
            $err["DB"] .= MsgErr("Can't add RSS Feed $rssfeed_name");
          }
        }
        break;

      case "update" :
		  $catVS = new Cvarset();
		  $catVS->add("name", "quoted", $rssfeed_name);
		  $catVS->add("server_url","quoted",$server_url);
		  $SQL = "INSERT INTO rssfeeds" . $catVS->makeINSERT();
        $db->query("UPDATE rssfeeds SET ". $catVS->makeUPDATE()." WHERE name='$old_rssfeed_name' AND slice_id = '$qp_slice_id'");
        break;

      case "add" : $new_mode = "insert"; break;
    }
    $rssfeed_name = $server_url = "";
    $new_mode = "insert";

   }

$db->query("SELECT * FROM rssfeeds WHERE slice_id = '$qp_slice_id' ORDER BY name ");
$rssfeeds="";
while ($db->next_record()) {
  $rssfeeds[] = $db->f(name);
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Remote RSS Feed administration");?></TITLE>
<SCRIPT Language="JavaScript"><!--

function InitPage() {}

function SelectValue(sel) {
  svindex = eval(sel).selectedIndex;
  if (svindex != -1) { return eval(sel).options[svindex].value; }
  return null;
}

function Submit(mode) {
  if (mode== 'add') {
    document.f.mode.value = mode;
    document.f.submit();
  } else {

  sel = SelectValue('document.f.rssfeeds')
  if (sel == null)
    alert('<?php echo _m("No selected rssfeed"); ?>')
  else {
    if (mode == 'delete')
      if (!confirm('<?php echo _m("Are you sure you want to delete the rssfeed?"); ?>'))
        return
    document.f.sel_rssfeed_name.value = sel
    document.f.mode.value = mode;
    document.f.submit();
  }
 }
}

function checkData() {
  if (document.f.rssfeed_name.value=="") {
     alert('<?php echo _m("Error: RSS node empty"); ?>')
     return false
  }
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
  showMenu ($aamenus, "sliceadmin","rssfeeds");

  echo "<H1><B>" . _m("Remote RSS Feed administration") . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form method=post name="f" action="<?php echo $sess->url($PHP_SELF) ?>" onSubmit="return checkData()">
  <table width="400" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
    <tr><td class=tabtit><b>&nbsp;<?php echo _m("Remote RSS Feed administration") ?></b></td></tr>
     <tr><td>
      <table width="100%" border="0" cellspacing="0" cellpadding="2" bgcolor="<?php echo COLOR_TABBG ?>">
      <tr><td colspan=2><?php echo _m("Current remote rssfeeds") ?></td></tr>
      <tr><td align=center colspan=2>
      <SELECT name="rssfeeds" class=tabtxt size=5>
      <?php
        if (isset($rssfeeds) && is_array($rssfeeds)) {
          reset($rssfeeds);
          while(list(,$name) = each($rssfeeds))
            echo "<option value=\"$name\">$name</option>";
        }
      ?>
      </SELECT>
    <tr><td colspan=2 align="center">
      <input type=button value="<?php echo _m("Edit") ?>" onClick = "Submit('edit')" >
      <input type=button VALUE="<?php echo _m("Delete") ?>" onClick = "Submit('delete')">
      <input type=button VALUE="<?php echo _m("Add") ?>" onClick = "Submit('add')">
     </td></tr>
    <tr><td colspan=2>&nbsp;</td></tr>
    <tr><td colspan=2><?php echo ($new_mode=="insert" ? _m("Add new rssfeed") :
                                                   _m("Edit rssfeed data")) ?>
    </td></tr>
    <tr><td><?php echo _m("RSS Feed name") ?></td>
        <td><input type="text" name="rssfeed_name" size=40 value="<?php echo safe($rssfeed_name)?>" ><br><?php echo _m("New rssfeed name")?></tr>
    <tr><td><?php echo _m("URL of the feed") ?></td>
         <td><input type="text" name="server_url" size=40 value="<?php echo safe($server_url)?>" ><br><?php echo _m("e.g. http://www.someplace.com/rss/index.xml")?>"
    <input type="hidden" name="mode" value="<?php echo safe($new_mode) ?>">
    <input type="hidden" name="old_rssfeed_name" value="<?php echo safe($old_rssfeed_name) ?>">
    <input type="hidden" name="sel_rssfeed_name">
</tr>
    <tr><td colspan=2 align="center"><input type="submit" value="<?php echo _m("Submit") ?>" >
        <input type=button value="<?php echo _m("Cancel") ?>" onClick="Cancel()" ></td>
    </tr>
  </table>
  </td></tr>
  </table>
</FORM>
<?php
HtmlPageEnd();
page_close()
?>
