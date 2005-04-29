<?php
/**
 * In this file only the function ColumnFunctions() is defined.
 * This function is meant to be called from @link Tabledit::ShowColumnValuesClass.
 * DOCUMENTATION: @link doc/tabledit.html,
 *                @link doc/tabledit_developer.html,
 *                @link doc/tableview.html
 * @package TableEdit
 * @version $Id$
 * @author Jakub Adamek, Econnect
 * @copyright (c) 2002-3 Association for Progressive Communications
*/
/*
Copyright (C) 1999-2003 Association for Progressive Communications
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

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
* Does column type-specific work.
*
* @param string $function "show" prints HTML code showing the column value
*                       "form" transforms the returned value if needed and returns it
* @param mixed $val     value to be shown ("show") or to be changed ("form")
* @param array $record  the whole record for the current row, to be used by "calculated" fields
* @return nothing
*/

function ColumnFunctions($cview, &$val, $function, $name="", $new_record=false, $record="")
{
    global $err;
    // value to be shown if the requested value is not a part of the select box array
    $unknown_select_value = "????????";

    if ($function == "show" && $cview["readonly"])
        $function = "show_ro";

    // ********************* UNPACKED *************************
    if ($cview["unpacked"]) {

        switch ($function) {
        case 'show':
        case 'show_ro':
            $val = unpack_id ($val);
            break;
        case 'form':
            $val = pack_id ($val);
        }

    }


    switch ($cview["type"]) {

    // ********************** IGNORE **************************
    case 'ignore':
        return;

    // ********************** HIDE** **************************
    case 'hide':

        switch ($function) {
        case 'show':
        case 'show_ro':
            echo "<INPUT type=\"hidden\" name=\"$name\" value=\"".$val."\">\n";
            break;
        }
        return;

    // ******************** AREA = BLOB ***********************
    case 'area':
    case 'blob':

        switch ($function) {
        case 'show':
            $val = str_replace ('<','&lt;',$val);
            $val = str_replace ('>','&gt;',$val);
            echo "<TEXTAREA name=\"$name\""
                ." rows=\"".$cview["size"]["rows"]."\" cols=\"".$cview["size"]["cols"]."\">\n"
                .$val."</textarea>";
            break;
        case 'show_ro':
            ShowColumnValueReadOnly ($cview, $val, $val, $name);
            break;
        }
        return;

    // ********************** SELECT **************************
    case 'select':

        switch ($function) {
        case 'show':
            // show ****** for undefined values in select box, but not for new records
            if (!isset ($cview["source"][$val]) && !$new_record)
                $cview["source"][$val] = $unknown_select_value;
            FrmSelectEasy($name, $cview["source"], $val);
            break;
        case 'show_ro':
            // show ****** for undefined values in select box, but not for new records
            if (!isset ($cview["source"][$val]) && !$new_record)
                $cview["source"][$val] = $unknown_select_value;
            ShowColumnValueReadOnly ($cview, $cview["source"][$val], $val, $name);
            break;
        }
        return;

    // *********************** DATE ****************************
    case 'date':


        switch ($function) {
        case 'show':
            if ($val) $val = date($cview["format"], $val);
            $maxlen = $column["len"] ? "maxlength=$column[len]" : "";
            echo "<INPUT type=\"text\" $maxlen size=\"".$cview["size"]["cols"]."\" name=\"$name\"
                value=\"".$val."\">";
            break;
        case 'show_ro':
            if ($val) {
                $show_val = @date($cview["format"], $val);
                if (!$show_val) $show_val = $val;
            }
            ShowColumnValueReadOnly ($cview, $show_val, $show_val, $name);
            break;
        case 'form':
            $val = get_formatted_date ($val, $cview["format"]);
            break;
        }
        return;

    // ********************** CHECKBOX **************************
    case 'checkbox':

        switch ($function) {
        case 'show':
            //echo "<INPUT type=\"checkbox\" name=\"$name\"".($val ? " checked" : "").">";
            FrmSelectEasy($name, array (0 => _m("no"), 1 => _m("yes")), $val ? 1 : 0);
            break;
        case 'show_ro':
            echo $val ? _m("yes") : _m("no");
            break;
        }
        return;

    // ********************** USERDEF ***************************
    case "userdef" :

        switch ($function) {
        case 'show_ro':
            $fnc = $cview["function"];
            ShowColumnValueReadOnly($cview, $fnc($val), $val);
            break;
        default:
            $err[] = "Only readonly fields may be viewed by userdef function.";
            break;
        }
        return;

    // ********************** CALCULATED ***************************
    case "calculated" :

        switch ($function) {
        case 'show_ro':
            $fnc = $cview["function"];
            ShowColumnValueReadOnly($cview, $fnc($record), $val);
            break;
        default:
            $err[] = "Only readonly fields may be viewed by calculated function.";
            break;
        }
        return;

    // ******************* TEXT = DEFAULT *************************
    case 'text':
    default:

        switch ($function) {
        case 'show':
            $val = str_replace ('"','&quot;',$val);
            $maxlen = $cview["maxlen"] ? "maxlength=$cview[maxlen]" : "";
            echo "<INPUT type=\"text\" $maxlen size=\"".$cview["size"]["cols"]."\" name=\"$name\"
                value=\"".$val."\">";
            break;
        case 'show_ro':
            ShowColumnValueReadOnly($cview, $val, $val, $name);
            break;
        }
        return;

    }
}

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
* Shows a column value for a read-only column.
*
* @param $name if given, a hidden box with the field value will be added
*/
function ShowColumnValueReadOnly ($cview, $show_val, $val, $name="") {
    if ($name)
        echo "<INPUT type=\"hidden\" name=\"$name\" value=\"".
            str_replace ('"', '&quot;',$val)."\">\n";
    if ($show_val) {
        if (!$cview["html"]) {
            $show_val = htmlspecialchars ($show_val);
        }
    }
    elseif (($show_val == 0) && is_field_type_numerical($cview["dbtype"]) && ($cview["type"] != "date")) {
        $show_val = "0";
    }
    else $show_val = "&nbsp;";

    if ($cview["maxlen"] && strlen ($show_val) > $cview["maxlen"])
        $show_val = substr ($show_val, 0, $cview["maxlen"])." ...";

    echo $show_val;
}

?>
