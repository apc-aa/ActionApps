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
 * @version   $Id$
 * @author    Ondrej Mazanec, Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\Util\Langs;

const SHOW_FILE_SIZE          = 65536;
const IMPORTFILE_PREVIEW_ROWS = 5;
const IMPORTFILE_TIME_LIMIT   = 600; // 10 minutes

const NOT_STORE         = 0;
const STORE_WITH_NEW_ID = 1;

const INSERT = 1;
const UPDATE = 2;

/** getCSV function
 * @param        $handle
 * @param string $delimiter
 * @param string $enclosure
 */
function getCSV($handle, $delimiter = ';', $enclosure = '"'): array
{
    if ($delimiter === '\t') {
        $delimiter = chr(9);  // tabulator ascii
    }
    if (strlen($enclosure) !== 1) {
        $enclosure = 0x00;  // unlikely character (not possible to use '' with fgetcsv) - @todo - test it
    }

    return fgetcsv($handle, 0, $delimiter, $enclosure) ?: [];
}

/** createFieldNames function
 *  create field names from the CSV file
 *  For CSV data: if the first row is used for field names, parse first row
 *                according to additional params (delimiter, enclosure),
 *                otherwise creates field1, field2, ...
 * @param $fileName
 * @param $addParams
 * @return array
 */
function createFieldNames($fileName, $addParams): array
{
    $handle = fopen($fileName, 'rb');
    $fn = getCSV($handle, $addParams['delimiter'], $addParams['enclosure']);
    fclose($handle);
    if (!$fn) {
        return $addParams['caption'] ? [] : ["Field 1" => "Field 1"];
    }
    $fieldNames = [];
    if ($addParams['caption']) {
        foreach ($fn as $v) {
            $fieldNames[$v] = $v;
        }
    } else {
        $l = count($fn);
        for ($i = 1; $i <= $l; $i++) {
            $f = "Field " . $i;
            $fieldNames[$f] = $f;
        }
    }
    return $fieldNames;
}

/** convertCSV2Items function
 * @param             $csvRec
 * @param             $fieldNames
 * @param Actions     $trans_actions
 * @param AA_Fields   $slice_fields
 * @param ItemContent $itemContent
 * @return string|null     err or empty string
 */
function convertCSV2Items($csvRec, $fieldNames, Actions $trans_actions, AA_Fields $slice_fields, &$itemContent): ?string
{
    $itemContent1 = new AA_Content();
    $itemContent1->setFromCSVArray($csvRec, $fieldNames);
    $itemContent  = new ItemContent();
    return $itemContent->transform($itemContent1, $trans_actions, $slice_fields);
}

class Actions
{
    /** @var array */
    public $actions;
    public $globalParams;
    /** Actions function
     * @param $actions[]
     * @param $inFields
     * @param $html
     * @param $params
     * @param $globalParams
     */
    public function __construct(array $actions, $inFields, $html, $params, $globalParams = "")
    {
        foreach ($actions as $f_id => $action) {
            $this->actions[$f_id] = [
                "from"   => $inFields[$f_id],
                "action" => new Action($action, $html[$f_id], stripslashes($params[$f_id])),
            ];
        }
        $this->globalParams = $globalParams;
    }

    /** transform function
     *  Transform the input item content to the output item content according
     *  to the actions
     * @param AA_Content $itemContent
     * @param AA_Fields  $slice_fields
     * @param ItemContent $outputItemContent
     * @return string|null     err or empty string
     */
    public function transform(AA_Content $itemContent, AA_Fields $slice_fields, ItemContent $outputItemContent): ?string
    {

        $fieldIds = $slice_fields->getPriorityArray();

        // id is not part of $slice_fields, unfortunately (should be changed)
        if (!in_array('id..............', $fieldIds)) {
            $fieldIds[] = 'id..............';           // 'to be processed in next loop';
        }

        foreach ($fieldIds as $field_id) {
            $action = &$this->actions[$field_id];
            unset($fieldVal, $v);
            $done = false;

            // fill up the output field with default value, if the action does not exist for the output field, or the action is "default"
            if (!$action || $action['action']->getAction() === "default") {
                // case "display_count..." : $v = 0; break;
                /* Removed - it created data for fields, so it is then updated on Rewrite/Update action, which is not correct
                  switch ($field_id) {
                     case 'status_code.....' : $done = true; $fieldVal[]['value'] = 1; break; // todo
                     case 'flags...........' : $done = true; $fieldVal[]['value'] = ITEM_FLAG_OFFLINE; break;
                     case 'post_date.......':
                     case 'last_edit.......':
                     case 'publish_date....' : $done = true; $fieldVal[]['value'] = time(); break;
                     case 'expiry_date.....' : $done = true; $fieldVal[]['value'] = mktime(0,0,0,date("m"),date("d"),date("Y")+10) ; break;	// expiry in 10 years default : TODO
                     case 'edited_by.......':
                     case 'posted_by.......' : $done = true; $fieldVal[]['value'] = AuthGetUid(); break;	// todo
                     // todo
                     case 'id..............' : $done = true; $fieldVal[]['value'] = new_id(); break;
                     default :
                   */
                if ($action['from'] && ($action['from'] !== '__empty__')) {
                    $action['action']->setAction('store');
                } elseif ($action['action']->getParams()) {
                    $action['action']->setAction('value');
                } else {
                    $done = true;
                }
            } elseif ($action['action']->getAction() === "new") {
                $done = true;
                $fieldVal[]['value'] = new_id();
            }
            if (!$done) {

                // transform the input field to the output field according the action
                /** @var Action $act */
                $act = $action['action'];
                $err = $act->transform($itemContent, $action['from'], $this->globalParams, $fieldVal);
                if ($err) {
                    return $err;
                }
            }
            if (isset($fieldVal) && is_array($fieldVal)) {
                // store the output field to the output item content
                if ($field_id === 'id..............') {
                    $outputItemContent->setItemId($fieldVal[0]['value']);
                } else {
                    $outputItemContent->setAaValue($field_id, new AA_Value($fieldVal));
                }
            }
        }
        return null;
    }

    /** getHtml function
     *
     */
    public function getHtml(): array
    {
        foreach ($this->actions as $v) {
            $f[] = $v['action']->getHtml();
        }
        return $f;
    }

    /** getParams function
     */
    public function getParams(): array
    {
        $f = [];
        foreach ($this->actions as $v) {
            $f[] = $v['action']->getParams();
        }
        return $f;
    }
}

/** Represents an action, which transform a field from an input ItemContent to
 *  one field of an output ItemContent.
 *	Each action has two additional parameters:
 *      $html   - if set => store as HTML
 *  	$params - values or array of values specific to each action
 */
class Action
{
    public $action;
    public $html;
    public $params;
    /** Action function
     * @param $action
     * @param $html
     * @param $params
     */
    public function __construct($action, $html, $params)
    {
        $this->action = $action;
        $this->html = ($html ? FLAG_HTML : 0);
        $this->params = $params;
    }
    /** getAction function
     *
     */
    public function getAction()
    {
        return $this->action;
    }
    /** getHtml function
     *
     */
    public function getHtml()
    {
        return $this->html;
    }
    /** getParams function
     *
     */
    public function getParams()
    {
        return $this->params;
    }
    /** setAction function
     * @param $action
     */
    public function setAction($action): void
    {
        $this->action = $action;
    }

    /** transform function
     *  transforms a value from $itemContent[$from] to $fvalues
     *
     * @param  AA_Content  $itemContent
     * @param              $from
     * @param              $globalParams
     * @param              $fvalues
     *
     * @return string
     */
    public function transform(AA_Content $itemContent, $from, $globalParams, &$fvalues): string
    {
        switch ($this->action) {
            case "expression": {
                $fvalues[]['value'] = $itemContent->unalias(str_replace('_#this', '{' . $from . '}', $this->params));
                break;
            }
            case "store": {
                $ind = 0;
                if (strlen($this->params) == 2) {  // cz | en | de | ..
                    $ind = Langs::getLangName2Num(strtolower($this->params));
                }
                $fvalues[$ind]['value'] = $itemContent->GetValue($from);		// todo - pokud neexistuje pole s $from , co delat?
                break;
            }
            case "pack_id": {
                $fvalues[]['value'] = pack_id($itemContent->GetValue($from));		// todo - pokud neexistuje pole s $from , co delat?
                break;
            }
            case "unpack_id": {
                $fvalues[]['value'] = unpack_id($itemContent->GetValue($from));  // todo - pokud neexistuje pole s $from , co delat?
                break;
            }
            case "removestring": {
                $v =  $itemContent->GetValue($from);
                $fvalues[]['value'] = $this->params ? preg_replace('`' . str_replace('`', '\`', $this->params) . '`', "", $v) : $v;
                break;
            }
            case "formatdate": {
                $v = strtotime($itemContent->GetValue($from));
                if ($v == -1) {
                    return "Invalid date: " . $itemContent->GetValue($from);
                }
                $fvalues[]['value'] = $v;
                break;
            }
            case "convertvalue": {
                // ???
                $fvalues[]['value'] = $globalParams["table"][$itemContent->GetValue($from)]["return"];
                break;
            }
            case "web": {
                $value = $itemContent->GetValue($from);
                if ($value && stripos($value, "http") !== 0) {
                    $value = "https://" . $value;
                }
                $fvalues[]['value'] = $value;
                break;
            }
            case "storemultiasone" : {
                // $param is delimiter
                if (!is_array($from)) {
                    $from = [$from];
                }
                $save = "";
                foreach ($from as $tostore) {
                    $savenext = trim($itemContent->GetValue($tostore));
                    if ($savenext != "") {
                        if ($save != "") {
                            $save .= $this->params;
                        }
                        $save .= $savenext;
                    }
                }
                $fvalues[]['value'] = $save;
                break;
            }
            case "storeasmulti": {
                if (!is_array($from)) {
                    $from = [$from];
                }
                foreach ($from as $tostore) {
                    $save = trim($itemContent->GetValue($tostore));
                    $fvalues[]['value'] = $save;
                }
                break;
            }
            case "storeparsemulti": {
                $delimiter = $this->params;
                $langs     = [];
                if (strpos($this->params, '##')) {
                    $langs     = explode('##', $this->params);
                    $delimiter = array_shift($langs);
                }
                $items = explode($delimiter, trim($itemContent->GetValue($from)));
                foreach ($items as $k => $save) {
                    $ind = $k;
                    if ($langs[$k] && strlen($langs[$k]) == 2) {  // cz | en | de | ..
                        $ind = Langs::getLangName2Num(strtolower($langs[$k]));
                    }
                    $fvalues[$ind]['value'] = trim($save);
                }
                break;
            }
            case "storeparsemultiunpack": {
                $items = explode($this->params, trim($itemContent->GetValue($from)));
                foreach ($items as $save) {
                    $fvalues[]['value'] = unpack_id(trim($save));
                }
                break;
            }
            case "string2id": {
                // from the same string we create always the same id. This is
                // true in case user provide param (which is strongly
                // recommended or within the same slice (because used slice_id)
                $string = $itemContent->GetValue($from) . get_if($this->params, $GLOBALS['slice_id']);
                $fvalues[]['value'] = string2id($string);
                break;
            }
            case "not map": {
                $fvalues[0]['value'] = "";
                break;
            }
            case "value" : {
                $fvalues[0]['value'] = $this->params;
                break;
            }
            case "password": {
                $fvalues[]['value'] = ParamImplode(['AA_PASSWD',$itemContent->GetValue($from)]);
                break;
            }
            default: {
                return "Unknown function: $this->action";
            }
        }
        // set HTML flag and add slashes because of SQL syntax
        if (isset($fvalues) && is_array($fvalues)) {
            foreach ($fvalues as $k => $v) {
                if ($this->html) {
                    $v['flag'] = $this->html;
                }
                $fvalues[$k] = $v;
            }
        }
        return "";
    }
}

/** getActions function
 *  Get List of actions
 */
function getActions(): array
{
    $a = ["expression", "store","removestring","formatdate", "web", "storeparsemulti","storeparsemultiunpack", "value","string2id", 'password', "default"];
    // not used:  "convertvalue","storemultiasone", "storeasmulti", "not map"
    foreach ($a as $v) {
        $actions[$v] = $v;
    }
    return $actions;
}
