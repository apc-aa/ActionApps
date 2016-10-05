<?php
/**
 * Anonymous form wizard: Allows to select fields included on the Anonymous
 * form and shows the form.
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
 *  @package UserInput
 *  @version $Id$
 *  @author Jakub Adamek <jakubadamek@ecn.cz>, February 2003
 *  @copyright (C) 1999-2003 Association for Progressive Communications
*/

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."msgpage.php3";
require_once AA_INC_PATH."itemfunc.php3";

/** GetAnonymousForm function
 * @param $slice
 * @param $s_fields
 * @param $show
 * @param $ok_url
 * @param $err_url
 * @param $use_show_result
 * @param $show_result
 * @return string
 */
function GetAnonymousForm(&$slice, &$s_fields, &$show, $ok_url, $err_url, $use_show_result, $show_result) {

    // we do not want anonymous form to use sessions at all
    $sess_bck = $GLOBALS['sess'];
    $GLOBALS['sess'] = null;

    $ret       = '';   // resulting HTML code
    $slice_id  = $slice->getId();
    $form_type = $slice->getProperty('permit_anonymous_edit');

    if ($form_type != ANONYMOUS_EDIT_NOT_ALLOWED) {
        $fillform_url = AA_INSTAL_PATH .'fillform.php3?form=inputform&notrun=1&slice_id='.$slice_id;
        if ($use_show_result) {
            $fillform_url .= "&show_result=$show_result";
        }
        $ret .= "<!--#include virtual=\"$fillform_url\" -->";
    }

    $ret .= '
    <!-- '. _m('ActionApps Anonymous form') .'-->
    <!-- '. _m('Note: If you are using HTMLArea editor in your form, you have to add: %1 to your page.  -->', array("     <body onload=\"HTMLArea.init()\">   ")) ."\n\n";

    // get form - we need to call $form->getForm() before we call
    // $form->getFormStart(), $form->getFormJavascript
    $inputform_settings = array('form_action' => AA_INSTAL_URL.'filler.php3');
    $form               = new inputform($inputform_settings);
    $content4id         = new ItemContent;
    $form_code          = $form->getForm($content4id, $slice, false, $show);
    $CurItem    = new AA_Item($content4id, $slice->aliases(), $form_code);   // just prepare
    $form_code  = $CurItem->get_item();

    $ret .= $form->getFormStart();

    // additional form fields
    $additional = '
    <input type="hidden" name="err_url" value="'.$err_url.'">
    <input type="hidden" name="ok_url" value="'.$ok_url.'">
    <input type="hidden" name="slice_id" value="'.$slice_id.'">
    <input type="hidden" name="use_post2shtml" value="1">
    ';

    if ($form_type != ANONYMOUS_EDIT_NOT_ALLOWED) {
        $additional .= '
    <input type="hidden" name="my_item_id" value="">';
    }

    foreach ($s_fields as $field) {
        if ($field["input_show"] && !$show[$field["id"]]) {
            $additional .= '
    <input type="hidden" name="notshown[v'.unpack_id($field["id"]).']" value="1"> <!--'.$field["name"].'-->';
        }
    }

    $ret .= $additional. "\n";
    $ret .= $form->getFormJavascript();
    $ret .= "\n    <table border=\"0\" cellspacing=\"0\" cellpadding=\"4\" align=\"center\" class=\"tabtxt\">\n";
    $ret .= $form_code;   // show all fields

    $ret .= '
      <tr>
       <td colspan="10" align="center" class="tabtit">
        <input type="submit" name="send" value="'._m("Send").'">
       </td>
      </tr>
    </table>
  </form>
    ';

    if ($form_type != ANONYMOUS_EDIT_NOT_ALLOWED) {
        $ret .= getFrmJavascript( 'if (typeof(fillform_fields) != "undefined")  fillForm();');
    }

    // restore session back
    $GLOBALS['sess'] = $sess_bck;
    return $ret;
}


if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_FIELDS)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change fields settings"), "admin");
    exit;
}

// get all warnings
$warning = array();
$slice   = AA_Slice::getModule($slice_id);


// lookup fields
$SQL = "SELECT id, name, input_pri, required, input_show, in_item_tbl
        FROM field
        WHERE slice_id='$p_slice_id' AND id NOT LIKE '\_%'
        ORDER BY input_pri";
$s_fields = GetTable2Array($SQL);

if ($slice->getProperty('permit_anonymous_post') == 0) {
    $warning[] = _m("WARNING: You did not permit anonymous posting in slice settings.");
}
elseif ($slice->getProperty('permit_anonymous_edit') == ANONYMOUS_EDIT_NOT_ALLOWED) {
    $warning[] = _m("WARNING: You did not permit anonymous editing in slice settings. A form allowing only anonymous posting will be shown.");
}

if ($show_form) {
    $fields = $slice->fields('record');
    foreach ($fields as $fid => $foo) {
        if (substr ($fid,0,13) == "password.....") {
            if ($show[$fid] && $slice->getProperty('permit_anonymous_edit') != ANONYMOUS_EDIT_PASSWORD) {
                $warning[] = _m("WARNING: You want to show password, but you did not set 'Authorized by a password field' in Settings - Anonymous editing.");
            }
            break;
        }
    }
}

if (!$form_url) {
    $form_url = "http://FILL_YOUR_URL.shtml";
}
if (!$ok_url) {
    $ok_url = "http://THANK_YOU.shtml";
}
if (!$err_url) {
    $err_url = "http://ERROR_OCCURED.shtml";
}
if (!$show_result) {
    $show_result = "http://SHOW_RESULT.php3";
}


// -----------------------------------------------------------------------------
// This is the page in which you choose the form type and fields

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo "<title>"._m("Admin - Anonymous Form Wizard")."</title>
</head>";

require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "sliceadmin", "anonym_wizard");

echo "<h1>"._m("Admin - Anonymous Form Wizard")."</b></h1>";

PrintArray($err);
PrintArray($warning);
echo $Msg;

$form_buttons=array("show_form" => array("value"=>_m("Show Form"),
                                         "type" =>"submit"),
                    "cancel"    => array("url"  =>"se_fields.php3"));

echo '
<form method="post" action="'.$sess->url($_SERVER['PHP_SELF']).'#form_content">';

$helplink = ' <a href="'.AA_INSTAL_PATH.'doc/anonym.html#wizard">'. GetAAImage("help100_simple.gif", _m("Help")).'<b>'._m("Help - Documentation").'</b></a>';
FrmTabCaption(_m("URLs shown after the form was sent"). $helplink,'','',$form_buttons, $sess, $slice_id);
FrmInputText('ok_url',  _m("OK page"),    $ok_url,  254, 60);
FrmInputText('err_url', _m("Error page"), $err_url, 254, 60);
FrmInputChBox('use_show_result', _m("Use a PHP script to show the result on the OK and Error pages:"), $use_show_result, true);
FrmInputText('show_result', '', $show_result, 254, 60);
FrmTabSeparator(_m("Fields"));

echo '
<tr>
 <td class="tabtxt" align="center"><b>'._m("Field").'</b></td>
 <td class="tabtxt" align="center"><b>'._m("Id").'</b></td>
 <td class="tabtxt" align="center"><b>'._m("Show").'</b></td>
 <td class="tabtxt" align="center"><b>'._m("Field Id in Form").'</b></td>
</tr>
<tr><td class="tabtxt" colspan="4"><hr></td></tr>';

if ( is_array($s_fields)) {
    foreach ($s_fields as $field) {
        if ($field["input_show"]) {
            echo '
            <tr><td class="tabtxt"><b>'.$field["name"].'</b></td>
                <td class="tabtxt">'.$field["id"].'</td>
                <td class="tabtxt" align="center">
                    <input type="checkbox" name="show['.$field["id"].']"';
            if (! $show || $show[$field["id"]])
                echo " checked";
            echo "></td>
                <td class=\"tabtxt\">v".unpack_id($field["id"])."</td>
            </tr>";
        }
    }
}

echo '
<tr><td colspan="4" class="tabtxt"><hr><b>'
    ._m("Only fields marked as \"Show\" on the \"Fields\" page
         are offered on this page.")
.'</b></td></tr>';

FrmTabEnd($form_buttons, $sess, $slice_id);

if ($show_form) {
    echo '<tr><td><a id="form_content"></a><textarea cols="70" rows="40" style="width:100%" >';
    $form_content = GetAnonymousForm($slice, $s_fields, $show, $ok_url, $err_url, $use_show_result, $show_result);
    echo myspecialchars($form_content);
    echo "\n</textarea></td></tr>\n";
}

echo "</table></form>";
HtmlPageEnd();
page_close();
?>
