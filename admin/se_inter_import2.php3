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

# se_inter_import2.php3 - Inter node feed import settings

#           $slice_id
# optionaly $Msg to show under <h1>Headline</h1> (typicaly: Fields' mapping update)

require_once "../include/init_page.php3";

if(!IfSlPerm(PS_FEEDING)) {
  MsgPage($sess->url(self_base()."index.php3"), _m("You have not permissions to change feeding setting"));
  exit;
}
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";
require_once $GLOBALS["AA_INC_PATH"]."xml_fetch.php3";
require_once $GLOBALS["AA_INC_PATH"]."xml_rssparse.php3";
require_once $GLOBALS["AA_INC_PATH"]."csn_util.php3";

$db->query("SELECT server_url, password FROM nodes WHERE name='$rem_nodes'");
if ($db->next_record()) {
  $server_url = $db->f(server_url);
  $password = $db->f(password);
}

if (!($data = xml_fetch($server_url, ORG_NAME, $password, $auth->auth["uname"],"",0,""))) {
  MsgPage($sess->url(self_base() . "se_inter_import.php3"), _m("Unable to connect and/or retrieve data from the remote node. Contact the administrator of the local node.") );
}

// find out first character of fetched data: if it is not '<' exit
if (substr($data,0,1) != "<") {
  writeLog("CSN","Establishing mode: $data");
  switch ($data) {
    case ERR_NO_SLICE : $err_msg = _m("No slices available. You have not permissions to import any data of that node. Contact the administrator of the remote slice and check, that he obtained your correct username."); break;
    case ERR_PASSWORD : $err_msg = _m("Invalid password for the node name:") . " ".ORG_NAME . ". "._m("Contact the administrator of the local node."); break;
    default:            $err_msg = _m("Remote server returns following error:") . " $data"; break;
  }
  MsgPage($sess->url(self_base() . "se_inter_import.php3"), $err_msg); // $data contains error message
}                                                                   // from the server module

// try to parse xml document
if (!($aa_rss = aa_rss_parse($data,"establish_mode"))) {
  writeLog("CSN","Establishing mode: Unable to parse XML data");
  MsgPage($sess->url(self_base() . "se_inter_import.php3"), _m("Unable to connect and/or retrieve data from the remote node. Contact the administrator of the local node.") );
}

while (list($id,) = each($aa_rss[channels])) {
  $chan[$id] = $aa_rss[channels][$id][title];
}

$err["Init"] = "";          // error array (Init - just for initializing variable
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<TITLE><?php echo _m("Inter node import settings");?></TITLE>
<HEAD>
<SCRIPT Language="JavaScript"><!--
function InitPage() {}

function SelectValue(sel) {
  svindex = eval(sel).selectedIndex;
  if (svindex != -1) { return eval(sel).options[svindex].value; }
  return null;
}

function Cancel() {
  document.location = "<?php echo $sess->url(self_base() . "se_inter_import.php3")?>"
}
// -->
</SCRIPT>
</HEAD>
<BODY>
<?php
  $useOnLoad = true;
  require_once $GLOBALS["AA_INC_PATH"]."menu.php3";
  showMenu ($aamenus, "sliceadmin","n_import");

  echo "<H1><B>" . _m("Inter node import settings"). "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form enctype="multipart/form-data" method=post name="f" action="<?php echo $sess->url(self_base() . "se_inter_import3.php3")?>">
  <table width="400" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
    <tr><td class=tabtit><b>&nbsp;<?php echo _m("Inter node import settings") ?></b></td></tr>
     <tr><td>
      <table width="100%" border="0" cellspacing="0" cellpadding="2" bgcolor="<?php echo COLOR_TABBG ?>">
      <tr><td><?php echo _m("List of available slices from the node ") . "<b>" . $rem_nodes . "</b>" ?></td></tr>
      <tr><td align=center>
        <SELECT name="f_slices[]" size=5>
         <?php
           if ($chan && is_array($chan)) {
              reset($chan);
              while(list($id,$title) = each($chan)) {
                echo "<option value=\"$id\">$title</option>";
              }
           }
          ?>
        </SELECT>
      </td></tr>
      <tr><td align=center >
          <input type=submit value="<?php echo _m("Choose slice") ?>" >
          <input type=button VALUE="<?php echo _m("Cancel") ?>" onClick = "Cancel()">
          <input type=hidden name="remote_node_name" value="<?php echo $rem_nodes ?>">
          <input type=hidden name="aa" value="<?php echo htmlspecialchars(serialize($aa_rss)); ?>">
      </tr>
  </table>
  </td></tr>
  </table>
</FORM>
<?php
HtmlPageEnd();
page_close()
?>