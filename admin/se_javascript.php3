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

/* The Field Triggers edit page
    (c) Jakub Adamek, June 2002
*/

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."msgpage.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));
  

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS)) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change fields settings"), "admin");
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$db = new DB_AA;
$s_fields = GetTable2Array($SQL, $db);

// update database or get the value

if (get_magic_quotes_gpc() && $javascript) 
    $javascript = stripslashes ($javascript);

if ($p_slice_id && $update) 
    $db->query ("UPDATE slice SET javascript=\"".myaddslashes($javascript)."\" 
        WHERE id='$p_slice_id'");
else {
    $db->query ("SELECT javascript FROM slice WHERE id='$p_slice_id'");
    if ($db->next_record())
        $javascript = $db->f("javascript");
}
         
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Field Triggers");?></TITLE>

</HEAD>
<?php 
  require $GLOBALS[AA_INC_PATH]."menu.php3"; 
  showMenu ($aamenus, "sliceadmin","javascript");
  
  echo "<H1><B>" . _m("Field Triggers") . "</B></H1>";
  PrintArray($err);
  echo $Msg;  
?>
<form method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtxt><?php echo _m("Enter code in the JavaScript language. It will be included in the Add / Edit item page (itemedit.php3).") ?></td></tr>
<tr><td class=tabtxt><hr></td></tr>
<tr><td class=tabtxt><textarea name="javascript" cols=100 rows=20>
<?php echo $javascript.'</textarea></td></tr>
    <tr><td class=tabtit colspan=2 align="center">
    <input type=hidden name=\"update\" value=1>
    <input type=submit name=update value="'. _m("Update") .'">&nbsp;&nbsp;
    <input type=submit name=cancel value="'. _m("Cancel") .'">
    </td></tr></table>
</FORM>';

$SQL = "SELECT id FROM field
        WHERE slice_id='$p_slice_id'
        ORDER BY id";
$db = new DB_AA;
$db->query ($SQL);
echo '<table border="0" cellspacing="0" cellpadding="1" bgcolor="'.COLOR_TABTITBG.'" align="center">
<tr><td valign=top><table border="0" cellspacing="0" cellpadding="1" bgcolor="'.COLOR_TABTITBG.'">
<tr><td class=tabtit>'._m("Field IDs").':</td></tr>';
while ($db->next_record()) 
    echo "<tr><td class=tabtxt>".$db->f("id")."</td></tr>";
echo '</table>
</td>
<td valign=top><table border="0" cellspacing="0" cellpadding="1" bgcolor="'.COLOR_TABTXTBG.'">
<tr><td class=tabtxt>'._m("Triggers").':</td></tr>
<tr><td class=tabtit>'._m("Write trigger functions like \"aa_onSubmit (fieldid) { }\"").', <a href="http://apc-aa.sourceforge.net/faq/#triggers" target="_blank">'._m("see FAQ</a> for more details and examples").'</td></td></tr>
<tr><td class=tabtxt><table border="1" cellspacing="0" cellpadding="1" bgcolor="'.COLOR_TABTXTBG.'">';
echo '<tr><td class=tabtit><b>'._m("Field Type").'</b></td><td class=tabtxt><b>'._m("Triggers Available -- see some JavaScript help for when a trigger is run").'</b></td></tr>';
reset ($js_triggers);
while (list ($control,$trigs) = each ($js_triggers)) 
    echo '<tr><td class=tabtit>'.$control.'</td><td class=tabtxt>'.join($trigs,", ").'</td></tr>';
echo '
</table></td></tr>
</table></td>
</tr></table>';
HtmlPageEnd();
page_close()?>
