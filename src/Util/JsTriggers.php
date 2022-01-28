<?php

namespace AA\Util;

use AA;
use AA_Slice;
use function pack_id;

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
class JsTriggers {
    
    use Singleton4idTrait;

    private const CONTROL_TRIGGERS = [
        "input"    => ["onBlur", "onClick", "onDblClick", "onFocus", "onChange", "onKeyDown", "onKeyPress", "onKeyUp", "onMouseDown", "onMouseMove", "onMouseOut", "onMouseOver", "onMouseUp", "onSelect",],
        "select"   => ["onBlur", "onFocus", "onChange"],
        "textarea" => ["onBlur", "onClick", "onDblClick", "onFocus", "onChange", "onKeyDown", "onKeyPress", "onKeyUp", "onMouseDown", "onMouseMove", "onMouseOut", "onMouseOver", "onMouseUp", "onSelect",],
        "form"     => ["onClick", "onDblClick", "onKeyDown", "onKeyPress", "onKeyUp", "onMouseDown", "onMouseMove", "onMouseOut", "onMouseOver", "onMouseUp", "onReset", "onSubmit",],
        "body"     => ["onLoad"],
    ];

    protected $js_trig    = null;
    protected $slice_id   = null;
    protected $javascript = '';

    /** called from Singleton4idTrait */
    protected function __construct($id) {
        $this->slice_id = $id;
    }

    /* $js_trig is an array with triggers used, e.g.
    $js_trig["onBlur"] = 1
    $js_trig["onClick"] = 1
    means: aa_onBlur, aa_onClick are defined */
    /** getTrig function
     *
     */
    protected function loadTriggers() {
        if (!is_null($this->js_trig)) {
            return $this->js_trig;
        }
        $this->js_trig = [];

        if ($this->javascript = (string)AA_Slice::getModuleProperty($this->slice_id, 'javascript')) {
            $all_trig = [];
            foreach (JsTriggers::CONTROL_TRIGGERS as $ctrigs) {
                $all_trig = array_merge($all_trig, $ctrigs);
            }
            $all_trig = array_unique($all_trig);

            $ws = "[ \t\n\r]*";
            foreach ($all_trig as $trg) {
                // match e.g. aa_onSubmit( fieldid ) {
                if (preg_match("/aa_$trg$ws\($ws" . "fieldid$ws\)$ws\{/", $this->javascript)) {
                    $this->js_trig[$trg] = true;
                }
            }
        }
        return $this->js_trig;
    }

    // $unpacked_fieldid -- the function ignores the first character (usually 'v')

    /** getTriggers function
     * @param string $control
     * @param string $unpacked_fieldid
     * @param array  $add
     * @return string
     */
    public function get(string $control, string $unpacked_fieldid = '', array $add = []): string {

        $this->loadTriggers();
        $control_triggers = JsTriggers::CONTROL_TRIGGERS[$control];
        $retval  = '';

        if (!$control_triggers or (!$this->js_trig and !$add)) {
            return '';
        }

        if (substr($unpacked_fieldid, -2) == "[]") {
            $unpacked_fieldid = substr($unpacked_fieldid, 0, strlen($unpacked_fieldid) - 2);
        }
        if (substr($unpacked_fieldid, -1) == "x") {
            $unpacked_fieldid = substr($unpacked_fieldid, 0, strlen($unpacked_fieldid) - 1);
        }

        if (preg_match('/^v[0-9a-f]+$/', $unpacked_fieldid)) {
            $fieldid = pack_id(substr($unpacked_fieldid, 1));
        } elseif (preg_match('/^[0-9a-f]+$/', $unpacked_fieldid)) {
            $fieldid = pack_id($unpacked_fieldid);
        } else {
            $fieldid = $unpacked_fieldid;
        }

        foreach ($control_triggers as $ctrig) {
            $funcs = "";
            // Omar Martinez fix - 2005/04/28
            // onSubmit is special case - for all other events we can concatenate
            // the commands, but if you do so for onSubmit, you will get:
            //   return BeforeSubmit(); aa_onSubmit('inputform');
            // which is nonsense.
            if (($ctrig == "onSubmit") and ($add[$ctrig] == "return BeforeSubmit()")) {
                if ($this->js_trig[$ctrig]) {
                    $retval .= " $ctrig=\"if( BeforeSubmit() ){ return aa_$ctrig('$fieldid'); } else { return false; }\"";
                } else {
                    $retval .= " $ctrig=\"$add[$ctrig]\"";
                }
            } else {
                if ($add[$ctrig]) {
                    $funcs = $add[$ctrig] . ";";
                }
                if ($this->js_trig[$ctrig]) {
                    $funcs .= "aa_$ctrig('$fieldid');";
                }
                if ($funcs) {
                    $retval .= " $ctrig=\"$funcs\"";
                }
            }
        }
        return $retval;
    }

    public function getJavascriptAndRegisterRequires() {
        $this->loadTriggers();
        if ($this->javascript) {
            // modern version of js for field can use own libs - say {require:aa-fillform@1} - without unnecessary aa-jslib@1
            if (strpos($this->javascript,'{require:') === false) {
                AA::Stringexpander()->addRequire('aa-jslib@1');
                AA::Stringexpander()->addRequire('aa-fillform@1');
            }

            $events = [];
            foreach ($this->js_trig as $trig => $foo) {
                foreach (JsTriggers::CONTROL_TRIGGERS as $el => $evs) {
                    if (in_array($trig,$evs)) {
                        $events[$trig][] = $el;
                    }
                }
            }
            $listeners = [];
            foreach ($events as $e => $els) {
                $listeners[] = 'document.querySelectorAll("form[name=inputform] '. join(', form[name=inputform] ', $els).'").forEach(function(el) { el.addEventListener("'.strtolower(substr($e,2)).'", aa_runevent, false); });';
            }
            if ($listeners) {
                AA::Stringexpander()->addRequire(join("\n",$listeners), 'AA_Req_Load');
            }
        }
        return $this->javascript;
    }


    /** @deprecated  */
    public static function getFromGlobalSliceId(string $control, string $unpacked_fieldid = '', array $add = []): string {
        global $slice_id;
        return $slice_id ? JsTriggers::singleton4id($slice_id)->get($control, $unpacked_fieldid, $add) : '';
    }

    public static function printSummary() {
        foreach (JsTriggers::CONTROL_TRIGGERS as $control => $trigs) {
            echo '<tr><td class="tabtxt">' . $control . '</td><td class="tabtxt">' . join(", ", $trigs) . '</td></tr>';
        }
    }

    public function isTriggers(): bool {
        return count($this->loadTriggers()) > 0;
    }
}

