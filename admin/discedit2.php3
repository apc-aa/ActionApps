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

# discedit2.php3 - admin discussion comments
# expected    $item_id for comment's item_id
#             $d_id
# optionaly   $update

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";

if($cancel)
  go_url($sess->url(self_base() . "discedit.php3?item_id=".$item_id));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT_ALL_ITEMS)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_EDIT_ITEMS);
  exit;
}

require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."discussion.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

if ($update) {
  #update discussion table
    ValidateInput("subject", L_D_SUBJECT, &$subject, &$err, true, "text");
    ValidateInput("author", L_D_AUTHOR, &$author, &$err, true, "text");
    ValidateInput("e_mail", L_D_EMAIL, &$e_mail, &$err, false, "text");
    ValidateInput("body", L_D_BODY, &$body, &$err, false, "text");
    ValidateInput("url_address", L_D_URL_ADDRESS, &$url_address, &$err, false, "url");
    ValidateInput("url_description", L_D_URL_DES, &$url_description, &$err, false, "text");
    ValidateInput("remote_addr", L_D_REMOTE_ADDR, &$remote_addr, &$err, true, "text");

    if (count($err)<=1) {
      $varset->add("subject", "quoted", $subject);
      $varset->add("author", "quoted", $author);
      $varset->add("e_mail", "quoted", $e_mail);
      $varset->add("body", "quoted", $body);
      $varset->add("url_address", "quoted", $url_address);
      $varset->add("url_description", "quoted", $url_description);
      $varset->add("remote_addr", "quoted", $remote_addr);

      $SQL = "UPDATE discussion SET ". $varset->makeUPDATE() . " WHERE id='" .q_pack_id($d_id)."'";
      $db->query($SQL);

      $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed -
      $cache->invalidateFor("slice_id=".$slice_id);  # invalidate old cached values

      go_url($sess->url(self_base() . "discedit.php3?item_id=".$item_id));
    }
}

# set variables from table discussion
$SQL= " SELECT * FROM discussion WHERE id='".q_pack_id($d_id)."'";
$db->query($SQL);
if ($db->next_record())
  while (list($key,$val,,) = each($db->Record)) {
     if( EReg("^[0-9]*$", $key))
      continue;
    $$key = $val; // variables and database fields have identical names
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<TITLE><?php echo L_EDITDISC;?></TITLE>
<SCRIPT Language="JavaScript"><!--
function InitPage() {}
// -->
</SCRIPT>


</HEAD>
<BODY>
<?php
  echo "<H1><B>" . L_D_EDITDISC . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
  <form method=post action="<?php echo $sess->url($PHP_SELF . "?d_id=".$d_id) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_D_EDITDISC_TABTIT ?></b></td></tr>
<tr><td>
<table width="540" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
  FrmStaticText("id", $d_id);
  FrmInputText("subject",L_D_SUBJECT, $subject, 99, 50, true);
  FrmInputText("author",L_D_AUTHOR, $author, 60, 25, true);
  FrmInputText("e_mail",L_D_EMAIL, $e_mail, 60, 25, false);
  FrmTextArea("body", L_D_BODY, $body, 10, 40, false);
  FrmInputText("url_address",L_D_URL_ADDRESS, $url_address, 99, 50, false);
  FrmInputText("url_description", L_D_URL_DES, $url_description, 60, 25, false);
  FrmInputText("remote_addr",L_D_REMOTE_ADDR, $remote_addr, 60, 25, false);
?>
</table>
<tr><td align="center">
<?php
  echo "<input type=hidden name=d_id value=".$d_id.">";
  echo "<input type=hidden name=item_id value=".unpack_id($item_id).">";
  echo "<input type=submit name=update value=". L_UPDATE .">&nbsp;&nbsp;";
  echo "<input type=reset value=". L_RESET .">&nbsp;&nbsp;";
  echo "<input type=submit name=cancel value=". L_CANCEL .">";
?>
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()
?>
