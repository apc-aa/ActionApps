<?php
/**
 * Anonymous form wizard: Allows to select fields included on the Anonymous
 * form and shows the form.
 *
 *  @package UserInput
 *  @version $Id$
 *  @author Jakub Adamek <jakubadamek@ecn.cz>, February 2003
 *  @copyright (C) 1999-2003 Association for Progressive Communications 
*/
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

require_once "../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."msgpage.php3";
require_once $GLOBALS["AA_INC_PATH"]."itemfunc.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!IfSlPerm(PS_FIELDS)) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change fields settings"), "admin");
  exit;
}  

if ($show_not_so_nice) {
    require $GLOBALS["AA_BASE_PATH"]."post2shtml.php3";
    go_url ($sess->url(self_base()."anonym_wizard2.php3?post2shtml_id=$post2shtml_id&slice_id=$slice_id"));
}

// lookup fields
$SQL = "SELECT id, name, input_pri, required, input_show, in_item_tbl 
        FROM field 
        WHERE slice_id='$p_slice_id' 
        ORDER BY input_pri";
$s_fields = GetTable2Array($SQL, $db);

// Either ShowAnonymousForm or the rest of the file is used. Never both.         
//if ($show_form) ShowAnonymousForm();

// -----------------------------------------------------------------------------
// This is the page in which you choose the form type and fields

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo "<TITLE>"._m("Admin - Anonymous Form Wizard")."</TITLE>
</HEAD>";

require_once $GLOBALS["AA_INC_PATH"]."menu.php3";
showMenu ($aamenus, "sliceadmin", "anonym_wizard");  

echo "<H1>"._m("Admin - Anonymous Form Wizard")."</B></H1>";

PrintArray($err);
echo $Msg;  

echo '
<form method="post" action="'.$sess->url($PHP_SELF).'#form_content">
<input type="hidden" name="slice_id" value="'.$slice_id.'">
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="'.COLOR_TABTITBG.'" align="center">
<tr><td class=tabtit><b>&nbsp;'._m("Settings").'</b></td></tr>';

$warning = "";
$slice_info = GetSliceInfo ($slice_id);
if ($slice_info["permit_anonymous_post"] == 0) 
    $warning = _m("WARNING: You did not permit anonymous posting in slice settings.");
if ($slice_info["permit_anonymous_edit"] == ANONYMOUS_EDIT_NOT_ALLOWED)
    $warning = _m("You did not permit anonymous editing in slice settings. A form
        allowing only anonymous posting will be shown.");
        
if (! $form_url) $form_url = "http://FILL_YOUR_URL.shtml";        
if (! $ok_url)   $ok_url = "http://THANK_YOU.shtml";
if (! $err_url)  $err_url = "http://ERROR_OCCURED.shtml";
if (! $show_result) $show_result = "http://SHOW_RESULT.php3";
        
if ($show_form) {        
    list ($fields) = GetSliceFields ($slice_id);
    reset ($fields);    
    while (list ($fid) = each($fields))
        if (substr ($fid,0,14) == "password......") {
            if ($show[$fid] && $slice_info["permit_anonymous_edit"] !=
                ANONYMOUS_EDIT_PASSWORD)
                $warning .= _m("Warning: You want to show password, but you did not set
                    'Authorized by a password field' in Settings - Anonymous editing.");
            break;
        }
}
        
echo '
<tr><td class=tabtxt>
    &nbsp;<a href="'.$AA_INSTAL_PATH.'doc/anonym.html#wizard">'
        .GetAAImage ("help100_simple.gif", _m("Help"))
        .'<b>'._m("Help - Documentation").'</b></a><br>
    &nbsp;<b>'._m("URLs shown after the form was sent").':</b><br>
    <table border=0 cellspacing=0 cellpadding=0>
    <tr><td>&nbsp;</td><td><b>'._m("OK page").':&nbsp;</b></td>
        <td><input type=text name=ok_url size=50 value="'.$ok_url.'"></td></tr>
    <tr><td>&nbsp;</td><td><b>'._m("Error page").':&nbsp;</b></td>
        <td><input type=text name=err_url size=50 value="'.$err_url.'"></td></tr>
    </table>
    &nbsp;<input type=checkbox name=use_show_result'
        .($use_show_result ? " checked" : "").'>
    <B>'._m("Use a PHP script to show the result on the OK and Error pages:").'</B><br>
    &nbsp;<input type=text name=show_result size=60 value="'.$show_result.'">
    </td></tr>
<tr><td class=tabtit>&nbsp;<b>'._m("Fields").'</b></td></tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<tr>
 <td class=tabtxt align=center><b>'._m("Field").'</b></td>
 <td class=tabtxt align=center><b>'._m("Id").'</b></td>
 <td class=tabtxt align=center><b>'._m("Show").'</b></td>
 <td class=tabtxt align=center><b>'._m("Field Id in Form").'</b></td>
</tr>
<tr><td class=tabtxt colspan="4"><hr></td></tr>';

if( is_array($s_fields)) {
    foreach ($s_fields as $field) {
        if ($field["input_show"]) {
            echo '
            <tr><td class=tabtxt><b>'.$field["name"].'</b></td>
                <td class=tabtxt>'.$field["id"].'</td>
                <td class=tabtxt align=center>
                    <input type=checkbox name="show['.$field["id"].']"';
            if (! $show || $show[$field["id"]])
                echo " checked";
            echo "></td>
                <td class=tabtxt>v".unpack_id($field["id"])."</td>
            </tr>";
        }
    }
}

echo '
<tr><td colspan=4 class=tabtxt><hr><b>'
    ._m("Only fields marked as \"Show\" on the \"Fields\" page
         are offered on this page.")
.'</b></td></tr>
</table>
<tr><td align="center">
    <input type=submit name=show_form value="'._m("Show Form").'">&nbsp;&nbsp;
    <input type=submit name=show_not_so_nice value="'._m("Show Not So Nice").'">&nbsp;&nbsp;
    <input type=submit name=cancel value="'._m("Cancel").'">
</td></tr>
';

if ($warning) echo '
<tr><td class=tabtxt><b>&nbsp;'.$warning.'</b><hr></td></tr>';        

if ($show_form) {
    echo '<tr><td><a id="form_content"></a><textarea cols="70" rows="30">';
    require $GLOBALS["AA_BASE_PATH"]."post2shtml.php3";
    $form_content = file (AA_INSTAL_URL
        ."admin/anonym_wizard2.php3?post2shtml_id=$post2shtml_id&slice_id=$slice_id");
    echo HTMLEntities (join ("", $form_content));
    echo "\n</textarea></td></tr>\n";
}

echo "</table></FORM>";
HtmlPageEnd();
page_close();
?>
