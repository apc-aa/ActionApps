<?php
/**
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
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

class AA_Jstriggers {
    private static $CONTROL_TRIGGERS = [
        "input"    => ["onBlur", "onClick", "onDblClick", "onFocus", "onChange", "onKeyDown", "onKeyPress", "onKeyUp", "onMouseDown", "onMouseMove", "onMouseOut", "onMouseOver", "onMouseUp", "onSelect"],
        "select"   => ["onBlur", "onFocus", "onChange"],
        "textarea" => ["onBlur", "onClick", "onDblClick", "onFocus", "onChange", "onKeyDown", "onKeyPress", "onKeyUp", "onMouseDown", "onMouseMove", "onMouseOut", "onMouseOver", "onMouseUp", "onSelect"],
        "form"     => ["onClick", "onDblClick", "onKeyDown", "onKeyPress", "onKeyUp", "onMouseDown", "onMouseMove", "onMouseOut", "onMouseOver", "onMouseUp", "onReset", "onSubmit"],
        "body"     => ["onLoad"]
    ];

    private static $js_trig = null;

    /* $js_trig is an array with triggers used, e.g.
    $js_trig["onBlur"] = 1
    $js_trig["onClick"] = 1
    means: aa_onBlur, aa_onClick are defined */
    /** getTrig function
     *
     */
    static function loadTriggers($slice_id) {
        if (!is_null(AA_Jstriggers::$js_trig)) {
            return AA_Jstriggers::$js_trig;
        }
        AA_Jstriggers::$js_trig = [];

        if ($javascript = (string)AA_Slice::getModuleProperty($slice_id,'javascript')) {
            $all_trig = [];
            foreach (AA_Jstriggers::$CONTROL_TRIGGERS as $ctrigs) {
                $all_trig = array_merge($all_trig, $ctrigs);
            }
            $all_trig = array_unique($all_trig);

            $ws = "[ \t\n\r]*";
            foreach ($all_trig as $trg) {
                // match e.g. aa_onSubmit( fieldid ) {
                if (preg_match("/aa_$trg$ws\($ws"."fieldid$ws\)$ws\{/", $javascript)) {
                    AA_Jstriggers::$js_trig[$trg] = true;
                }
            }
        }
        return AA_Jstriggers::$js_trig;
    }

    // $unpacked_fieldid -- the function ignores the first character (usually 'v')

    /** getTriggers function
     * @param $control
     * @param $upacked_fieldid
     * @param $add
     * @return string
     */
    static function get($control, $unpacked_fieldid='', $add="") {

        $js_trig          = AA_Jstriggers::loadTriggers($GLOBALS['slice_id']);
        $control_triggers = AA_Jstriggers::$CONTROL_TRIGGERS[$control];

        if (!$control_triggers OR (!$js_trig AND !$add)) {
            return '';
        }

        if (substr($unpacked_fieldid, -2) == "[]") {
            $unpacked_fieldid = substr($unpacked_fieldid, 0, strlen($unpacked_fieldid) - 2);
        }
        if (substr($unpacked_fieldid, -1) == "x") {
            $unpacked_fieldid = substr($unpacked_fieldid, 0, strlen($unpacked_fieldid) - 1);
        }

        if (preg_match('/^v[0-9a-f]+$/', $unpacked_fieldid)) {
            $fieldid = pack_id(substr($unpacked_fieldid,1));
        } elseif (preg_match('/^[0-9a-f]+$/', $unpacked_fieldid)) {
            $fieldid = pack_id($unpacked_fieldid);
        } else {
            $fieldid = $unpacked_fieldid;
        }

        foreach ($control_triggers as $ctrig) {
            $funcs = "";
            // Omar Martinez fix - 2005/04/28
            // onSubmit is special case - for all other events we can concaternate
            // the commands, but if you do so for onSubmit, you will get:
            //   return BeforeSubmit(); aa_onSubmit('inputform');
            // which is nonsence.
            if (($ctrig == "onSubmit") AND ($add[$ctrig] == "return BeforeSubmit()")) {
                if ($js_trig[$ctrig]){
                    $retval .= " $ctrig=\"if( BeforeSubmit() ){ return aa_$ctrig('$fieldid'); } else { return false; }\"";
                } else {
                    $retval .= " $ctrig=\"$add[$ctrig]\"";
                }
            } else {
                if ($add[$ctrig]) {
                    $funcs = $add[$ctrig].";";
                }
                if ($js_trig[$ctrig]) {
                    $funcs .= "aa_$ctrig('$fieldid');";
                }
                if ($funcs) {
                    $retval .= " $ctrig=\"$funcs\"";
                }
            }
        }
        return $retval;
    }

    static function printSummary() {
        foreach (AA_Jstriggers::$CONTROL_TRIGGERS as $control => $trigs) {
            echo '<tr><td class="tabtxt">'.$control.'</td><td class="tabtxt">'.join($trigs,", ").'</td></tr>';
        }
    }

    static public function isTriggers() {
        return count(AA_Jstriggers::loadTriggers($GLOBALS['slice_id']))>0;
    }
}

