<?php
/**
 *  Anonymous form wizard: This script outputs the anonymous form.
 *  The output is shown in a text area in the anonym_wizard.php3 page.
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

require_once "../include/config.php3";
require_once $GLOBALS["AA_INC_PATH"]."locsess.php3";
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."msgpage.php3";
require_once $GLOBALS["AA_INC_PATH"]."itemfunc.php3";
require_once $GLOBALS["AA_INC_PATH"]."util.php3";

// lookup fields
$SQL = "SELECT id, name, input_pri, required, input_show, in_item_tbl
        FROM field
        WHERE slice_id='".q_pack_id($slice_id)."'
        ORDER BY input_pri";
$s_fields = GetTable2Array($SQL);

add_post2shtml_vars();
ShowAnonymousForm();

// -----------------------------------------------------------------------------
// This is the form created

function ShowAnonymousForm() {
    global $s_fields, $show, $ok_url, $err_url, $use_show_result, $show_result,
           $fields, $prifields, $slice_id;
    $db = getDB();
    $db->query ("SELECT permit_anonymous_edit, type FROM slice
        WHERE id='".q_pack_id($slice_id)."'");
    $db->next_record();
    $form_type = $db->f("permit_anonymous_edit");
    freeDB($db);

    foreach ($s_fields as $field) {
        if ($field["input_show"] && !$show[$field["id"]]) {
            $notshown["v".unpack_id($field["id"])] = 1;
        }
    }

    $slice = new slice($slice_id);
    ValidateContent4Id($err, $slice, "edit", 0, false, $notshown);
    list($fields, $prifields) = $slice->fields();

    $show_func_used = $slice->get_show_func_used('edit', 0, $notshown);

    if ( $show_func_used['fil']) { # uses fileupload?
        $html_form_type = ' enctype="multipart/form-data"';
    }

    if ($form_type != ANONYMOUS_EDIT_NOT_ALLOWED)
         echo
    '<!--#include virtual="'.$GLOBALS["AA_INSTAL_PATH"]
        .'fillform.php3?';

    if ($use_show_result)
        echo "show_result=$show_result&";

    if ($form_type != ANONYMOUS_EDIT_NOT_ALLOWED)
        echo 'form=inputform&notrun=1&slice_id='.$slice_id.'"-->';



    echo '
    <!-- '. _m('ActionApps Anonymous form') .'-->
    <!-- '. _m('Note: If you are using HTMLArea editor in your form, you have to add: %1 to your page.  -->', array("     <body onload=\"HTMLArea.init()\">   ")) .'

    <FORM name="inputform"'.$html_form_type.' method="post" '
    .'action="'.AA_INSTAL_URL.'filler.php3"'
    .getTriggers("form","v".unpack_id("inputform"),array("onSubmit"=>"return BeforeSubmit()"))
    .'>

    <input type="hidden" name="err_url" value="'.$err_url.'">
    <input type="hidden" name="ok_url" value="'.$ok_url.'">';

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

    echo GetFormJavascript($show_func_used, $slice->get_js_validation('edit', 0, $notshown));

    // Destroy the ? help links at each field
    foreach ($fields as $field => $foo) {
        $fields[$field]["input_morehlp"] = "";
    }

    // Show all fields
    echo
    '<TABLE border="0" cellspacing="0" cellpadding="4" align="center" class="tabtxt">
    ';

    // Replaces old ShowForm("", $fields, $prifields, 0, $show);
    $inputform_settings = array();
    $form = new inputform($inputform_settings);
    $content4id = null;  // in getForm we have to pass it by reference
    echo $form->getForm($content4id, $slice, false, $show);

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

?>
