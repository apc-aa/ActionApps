<?php
/**
 * Anonymous form wizard: Allows to select fields included on the Anonymous
 * form and shows the form.
 *
 * PHP version 7.2+
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

use AA\IO\DB\DB_AA;

require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../include/varset.php3";
require_once __DIR__."/../include/msgpage.php3";
require_once __DIR__."/../include/itemfunc.php3";

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
function GetAnonymousForm($slice, $s_fields, $show, $ok_url, $err_url, $use_show_result, $show_result) {

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

    $ret .= "\n <!-- ". _m('ActionApps Anonymous form') ."-->\n\n";

    // get form - we need to call $form->getForm() before we call
    // $form->getFormStart(), $form->getFormJavascript
    $inputform_settings = ['form_action' => AA_INSTAL_URL.'filler.php3'];
    $form               = new inputform($inputform_settings);
    $content4id       = new ItemContent;
    $form_code          = '{generate:HEAD}'.$form->getForm($content4id, $slice, false, $show).'{generate:FOOT}';
    //$CurItem    = new AA_Item($content4id, $slice->aliases(), $form_code);   // just prepare
    //$form_code  = $CurItem->get_item();


    $ret .= $form->getFormStart();

    // additional form fields
    $additional = '
    <input type="hidden" name="err_url" value="'.$err_url.'">
    <input type="hidden" name="ok_url" value="'.$ok_url.'">
    <input type="hidden" name="slice_id" value="'.$slice_id.'">
    ';
    //     <input type="hidden" name="use_post2shtml" value="1">   // shtml is not used in sitemodules - not necessary to use special table


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
    $ret .=  AA::Stringexpander()->postprocess(AA::Stringexpander()->unalias($form_code)); // to replace  {generate:HEAD} with dynamic javascripts needed by inputform

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
    go_url( StateUrl(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_FIELDS)) {
    MsgPageMenu(StateUrl(self_base())."index.php3", _m("You have not permissions to change fields settings"), "admin");
    exit;
}

// get all warnings
$warning = [];
$slice   = AA_Slice::getModule($slice_id);

$s_fields = DB_AA::select(['id'=> []],'SELECT id, name, input_pri, required, input_show, in_item_tbl FROM field', [
    ['slice_id', $slice_id, 'l'],
    ['id', '\_%', 'NOT LIKE']
], ['input_pri']);

if ($slice->getProperty('permit_anonymous_post') == 0) {
    $warning[] = _m("WARNING: You did not permit anonymous posting in slice settings.");
}
elseif ($slice->getProperty('permit_anonymous_edit') == ANONYMOUS_EDIT_NOT_ALLOWED) {
    $warning[] = _m("WARNING: You did not permit anonymous editing in slice settings. A form allowing only anonymous posting will be shown.");
}

if ($show_form) {
    $fields = $slice->getFields();
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

$apage = new AA_Adminpageutil('sliceadmin','anonym_wizard');
$apage->setTitle(_m("Admin - Anonymous Form Wizard"));
$apage->printHead(array_merge($err,$warning), $Msg);

$form_buttons= [
    "show_form" => [
        "value"=>_m("Show Form"),
                                         "type" =>"submit"
    ],
                    "cancel"    => ["url"  =>"se_fields.php3"]
];

$helplink = ' <a href="'.AA_INSTAL_PATH.'doc/anonym.html#wizard">'. GetAAImage("help100_simple.gif", _m("Help"),11,18).'<b>'._m("Help - Documentation").'</b></a>';
FrmTabCaption(_m("URLs shown after the form was sent") . $helplink, $form_buttons);
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

FrmTabEnd($form_buttons);

if ($show_form) {
    echo '<tr><td><a id="form_content"></a><textarea cols="70" rows="40" style="width:100%" >';
    $form_content = GetAnonymousForm($slice, $s_fields, $show, $ok_url, $err_url, $use_show_result, $show_result);
    echo myspecialchars($form_content);
    echo "\n</textarea></td></tr>\n";
}

echo "</table>";

$apage->printFoot();
