<?php
/**
 * In this file only the function ColumnFunctions() is defined.
 * This function is meant to be called from @link Tabledit::ShowColumnValuesClass.
 * DOCUMENTATION: @link doc/tabledit.html,
 *                @link doc/tabledit_developer.html,
 *                @link doc/tableview.html
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
 * @package   TableEdit
 * @version   $Id$
 * @author    Jakub Adamek, Econnect
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (c) 2002-3 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
*/


/** gdf_error function
 * @param $x
 */
function gfd_error($x) {
    echo "Unrecognized date format charcacter $x";
    exit;
}

/**  get_formatted_date function
 * @param $datestring
 * @param $format
 *   @return the Unix timestamp counted from the formatted date string.
 *   Does not check the date format, rather returns nonsence values for
 *   wrong date strings.
 *   Uses non-format letters as separators only,
 *   i.e. "2.3.2002" is parsed the same as "2/3/2002" or even "2;3#2002".
 */
function get_formatted_date($datestring, $format) {
    // don't work with empty string
    if (!$datestring) {
        return "";
    }

    // Split the date into parts consisting only of digits or only of letters
    for ( $i=0, $ino=strlen($datestring); $i<$ino; ++$i) {
        if (ctype_alpha($datestring[$i]) && ($s == "" || ctype_alpha($datestring[$i-1]))) {
            $s .= $datestring[$i];
        } elseif (ctype_digit($datestring[$i]) && ($s == "" || ctype_digit($datestring[$i-1]))) {
            $s .= $datestring[$i];
        } elseif ($s) {
            $dateparts[] = $s;
            $s = "";
        }
    }
    if ($s) {
        $dateparts[] = $s;
    }

    // Split the format into parts consisting of one letter
    for ( $i=0, $ino=strlen($format); $i<$ino; ++$i) {
        if (ctype_alpha($format[$i])) {
            $formatparts[] = $format[$i];
        }
    }

    $month_names = array ("January"=>1,"February"=>2,"March"=>3,"April"=>4,"May"=>5,"June"=>6,
                          "July"=>7,"August"=>8,"September"=>9,"October"=>10,"November"=>11,"December"=>12);
    $month3_names = array ("Jan"=>1,"Feb"=>2,"Mar"=>3,"Apr"=>4,"May"=>5,"Jun"=>6,"Jul"=>7,"Aug"=>8,"Sep"=>9,"Oct"=>10,"Nov"=>11,"Dec"=>12);

    // assing date parts to format parts
    for ($i=0, $ino=count($dateparts); $i<$ino; ++$i) {
        $d = $dateparts[$i];
        switch ($formatparts[$i]) {
            case 'a': $pm = $d == "pm"; break;
            case 'A': $pm = $d == "PM"; break;
            case 'B': gfd_error('B'); break;
            case 'd': $day = $d; break;
            case 'D': break;
            case 'F': $month = $month_names[$d]; break;
            case 'g':
            case 'h': $hour = $d; $use_pm = true; break;
            case 'H':
            case 'G': $hour = $d; $use_pm = false; break;
            case 'i': $minute = $d; break;
            case 'I': break;
            case 'j': $day = $d; break;
            case 'l': break;
            case 'L': break;
            case 'n':
            case 'm': $month = $d; break;
            case 'M': $month = $month3_names[$d]; break;
            case 'O': break;
            case 'r': gfd_error('r'); break;
            case 's': $second = $d; break;
            case 'S': break;
            case 't': break;
            case 'T': break;
            case 'U': return $d; break;
            case 'w': break;
            case 'W': gfd_error('W'); break;
            case 'Y': $year = $d; break;
            case 'y': $year = $d; break; // mktime works with 2-digit year
            case 'z': $day = $d; break;
            case 'Z': break;
        }
    }

    //echo "hour $hour minute $minute second $second month $month day $day year $year pm $pm";

    if ($use_pm && $pm) {
        $hour += 12;
    }

    // mktime replaces missing values by today's values
    if (!isset ($year)) {
        if (!isset ($day)) {
            if (!isset ($month)) {
                return mktime ( $hour, $minute, $second);
            }
            else return mktime ( $hour, $minute, $second, $month);
        }
        else return mktime ( $hour, $minute, $second, $month, $day);
    }
    else return mktime ( $hour, $minute, $second, $month, $day, $year);
}

/** ColumnFunctions function
 * Does column type-specific work.
 *
 * @param $cview
 * @param string $function "show" prints HTML code showing the column value
 *                       "form" transforms the returned value if needed and returns it
 * @param mixed $val     value to be shown ("show") or to be changed ("form")
 * @param $new_record
 * @param array $record  the whole record for the current row, to be used by "calculated" fields
 * @return nothing
 */

function ColumnFunctions($cview, &$val, $function, $name="", $new_record=false, $record="") {
    global $err;
    // value to be shown if the requested value is not a part of the select box array
    $unknown_select_value = "????????";

    if ($function == "show" && $cview["readonly"]) {
        $function = "show_ro";
    }

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
            echo "<input type=\"hidden\" name=\"$name\" value=\"".$val."\">\n";
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
            echo "<textarea name=\"$name\""
                ." rows=\"".$cview["size"]["rows"]."\" cols=\"".$cview["size"]["cols"]."\">\n"
                .$val."</textarea>";
            break;
        case 'show_ro':
            ShowColumnValueReadOnly($cview, $val, $val, $name);
            break;
        }
        return;

    // ********************** SELECT **************************
    case 'select':

        switch ($function) {
        case 'show':
            // show ****** for undefined values in select box, but not for new records
            if (!isset ($cview["source"][$val]) && !$new_record) {
                $cview["source"][$val] = $unknown_select_value;
            }
            FrmSelectEasy($name, $cview["source"], $val);
            break;
        case 'show_ro':
            // show ****** for undefined values in select box, but not for new records
            if (!isset ($cview["source"][$val]) && !$new_record) {
                $cview["source"][$val] = $unknown_select_value;
            }
            ShowColumnValueReadOnly($cview, $cview["source"][$val], $val, $name);
            break;
        }
        return;

    // *********************** DATE ****************************
    case 'date':


        switch ($function) {
        case 'show':
            if ($val) $val = date($cview["format"], $val);
            $maxlen = $column["len"] ? "maxlength=".$column['len'] : "";
            echo "<input type=\"text\" $maxlen size=\"".$cview["size"]["cols"]."\" name=\"$name\"
                value=\"".$val."\">";
            break;
        case 'show_ro':
            if ($val) {
                $show_val = @date($cview["format"], $val);
                if (!$show_val) {
                    $show_val = $val;
                }
            }
            ShowColumnValueReadOnly($cview, $show_val, $show_val, $name);
            break;
        case 'form':
            $val = get_formatted_date($val, $cview["format"]);
            break;
        }
        return;

    // ********************** CHECKBOX **************************
    case 'checkbox':

        switch ($function) {
        case 'show':
            //echo "<input type=\"checkbox\" name=\"$name\"".($val ? " checked" : "").">";
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
            $maxlen = $cview["maxlen"] ? "maxlength=".$cview['maxlen'] : "";
            echo "<input type=\"text\" $maxlen size=\"".$cview["size"]["cols"]."\" name=\"$name\"
                value=\"".$val."\">";
            break;
        case 'show_ro':
            ShowColumnValueReadOnly($cview, $val, $val, $name);
            break;
        }
        return;

    }
}

/** ShowColumnValueReadOnly function
 * Shows a column value for a read-only column.
 *
 * @param $cview
 * @param $show_val
 * @param $val
 * @param $name if given, a hidden box with the field value will be added
 */
function ShowColumnValueReadOnly($cview, $show_val, $val, $name="") {
    if ($name) {
        echo "<input type=\"hidden\" name=\"$name\" value=\"".
            str_replace ('"', '&quot;',$val)."\">\n";
    }
    if ($show_val) {
        if (!$cview["html"]) {
            $show_val = htmlspecialchars ($show_val);
        }
    }
    elseif (($show_val == 0) && is_field_type_numerical($cview["dbtype"]) && ($cview["type"] != "date")) {
        $show_val = "0";
    }
    else {
        $show_val = "&nbsp;";
    }

    if ($cview["maxlen"] && strlen ($show_val) > $cview["maxlen"]) {
        $show_val = substr ($show_val, 0, $cview["maxlen"])." ...";
    }

    echo $show_val;
}

?>
