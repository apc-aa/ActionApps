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

// lookup fields
$SQL = "SELECT id, name, input_pri, required, input_show, in_item_tbl 
        FROM field 
        WHERE slice_id='$p_slice_id' 
        ORDER BY input_pri";
$s_fields = GetTable2Array($SQL, $db);

// Either ShowAnonymousForm or the rest of the file is used. Never both.         
if ($show_form) ShowAnonymousForm();

// -----------------------------------------------------------------------------
// This is the form created

function ShowAnonymousForm () {
    global $s_fields, $show, $form_url,
        $show_func_used, $js_proove_fields, $fields, $prifields, $slice_id, $db;
    
    $db->query ("SELECT permit_anonymous_edit, type FROM slice 
        WHERE id='".q_pack_id($slice_id)."'");
    $db->next_record();
    $form_type = $db->f("permit_anonymous_edit");
        
    foreach ($s_fields as $field)
        if ($field["input_show"] && !$show[$field["id"]])
            $notshown ["v".unpack_id($field["id"])] = 1;

    ValidateContent4Id (&$err, $slice_id, "edit", 0, false, $notshown);
    
    if ( $show_func_used ['fil'])  # uses fileupload?
        $html_form_type = ' enctype="multipart/form-data"';

    if ($form_type != ANONYMOUS_EDIT_NOT_ALLOWED) 
         echo
    '<!--#include virtual="'.$GLOBALS["AA_INSTAL_PATH"]
        .'fillform.php3?form=inputform&notrun=1&slice_id='.$slice_id.'"-->';
    
    echo '    
    <FORM name="inputform"'.$html_form_type.' method="post" '
    .'action="'.AA_INSTAL_URL.'filler.php3"'
    .getTriggers ("form","v".unpack_id("inputform"),array("onSubmit"=>"return BeforeSubmit()"))
    .'>
    
    <input type="hidden" name="err_url" value="'.$form_url.'">
    <input type="hidden" name="ok_url" value="'.$form_url.'">';
    
    if ($form_type != ANONYMOUS_EDIT_NOT_ALLOWED) echo '
    <input type="hidden" name="my_item_id" value="">';
       
    echo '
    <input type="hidden" name="slice_id" value="'.$slice_id.'">
    <input type="hidden" name="use_post2shtml" value="1">
    ';
    
    foreach ($s_fields as $field)
        if ($field["input_show"] && !$show[$field["id"]])
            echo '
    <input type="hidden" name="notshown[v'.unpack_id ($field["id"]).']" value="1"> '
                .'<!--'.$field["name"].'-->';

    echo "\n";
    echo GetFormJavascript ($show_func_used, $js_proove_fields);

    // Destroy the ? help links at each field
    reset ($fields);
    while (list ($field) = each ($fields)) 
        $fields[$field]["input_morehlp"] = "";
    
    // Show all fields
    echo 
    '<TABLE border="0" cellspacing="0" cellpadding="4" align="center" class="tabtxt">
    ';
    ShowForm("", $fields, $prifields, 0, $show);
    echo '
    <tr><td colspan="10" align="center" class="tabtit">
        <input type="submit" name="send" value="'._m("Send").'"></td></tr>
    </TABLE></FORM>';
    
    if ($form_type != ANONYMOUS_EDIT_NOT_ALLOWED) echo '    
    <SCRIPT language="JavaScript">
    <!-- 
        if (typeof (fillform_fields) != "undefined")
            fillForm();
    // -->
    </SCRIPT>';
    exit;
}  

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
<form method="post" action="'.$sess->url($PHP_SELF).'">
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
        
if ($warning) echo '
<tr><td class=tabtxt><b>&nbsp;'.$warning.'</b><hr></td></tr>';        
echo '
<tr><td class=tabtxt>&nbsp;<b>'._m("URL where the form will be shown").':<br>&nbsp;
    <input type=text name=form_url size=60 value="http://FILL_YOUR_URL.shtml">
    </b></td></tr>
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
    foreach ($s_fields as $field)
        if ($field["input_show"]) 
        echo '
        <tr><td class=tabtxt><b>'.$field["name"].'</b></td>
            <td class=tabtxt>'.$field["id"].'</td>
            <td class=tabtxt align=center>
                <input type=checkbox name="show['.$field["id"].']" checked></td>
            <td class=tabtxt>v'.unpack_id($field["id"]).'</td>
        </tr>';
}

echo '
<tr><td colspan=4 class=tabtxt><hr><b>'
    ._m("Only fields marked as \"Show\" on the \"Fields\" page
         are offered on this page.")
.'</b></td></tr>
</table>
<tr><td align="center">
    <input type=submit name=show_form value="'._m("Show Form").'">&nbsp;&nbsp;
    <input type=submit name=cancel value="'._m("Cancel").'">
</td></tr></table>
</FORM>';
HtmlPageEnd();
page_close();
?>
