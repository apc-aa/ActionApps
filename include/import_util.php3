<?php
//$Id$
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

define("SHOW_FILE_SIZE",65536);
define("CSVFILE_LINE_MAXSIZE",65536);
define("IMPORTFILE_PREVIEW_ROWS",5);
define("IMPORTFILE_TIME_LIMIT",2000);

define("FILE_PREFIX",'csvdata');
define("CSV_DIRECTORY",'csvdata');

define("NOT_STORE",0);
define("STORE_WITH_NEW_ID",1);
define("UPDATE",2);

define("INSERT",1);
define("UPDATE",2);

/** compare version  phpversion() to $ver (format major.minor.build)
 *  returns -1,0,1 :  if php version is less,equal,greater than $ver
 */
function comparePHPVersion($ver) {
    $v1 = explode(".",phpversion());
    $v2 = explode(".",$ver);
    for ($i=0;$i<3;$i++) {
        if ($v1[$i] < $v2[$i]) return -1;
        if ($v1[$i] > $v2[$i]) return 1;
    }
    return 0;
}

function getCSV($handle,$maxsize = 65536,$delimiter = ";",$enclosure = '"') {
    if (comparePHPVersion("4.3.0") == -1) {
        return fgetcsv($handle,$maxsize,$delimiter);
    } else {
        return fgetcsv($handle,$maxsize,$delimiter, $enclosure);
    }
}

/** create field names from the CSV file
 *  For CSV data: if the first row is used for field names, parse first row
 *                according to additional params (delimiter, enclosure),
 *                otherwise creates field1, field2, ...
 */
function createFieldNames($fileName,&$addParams) {
    $handle = fopen($fileName,"r");
    $fn = getCSV($handle,CSVFILE_LINE_MAXSIZE,$addParams['delimiter'],$addParams['enclosure']);
    fclose($handle);
    if (!is_array($fn)) {
        return $addParams['caption'] ? array($fn=>$fn) : array("Field 1"=>"Field 1");
    }

    if ($addParams['caption']) {
        while (list(,$v) = each($fn)) {
            $fieldNames[$v] = $v;
        }
    } else {
        $l = count($fn);
        for ($i=1; $i<= $l; $i++) {
            $f = "Field ".$i;
            $fieldNames[$f] = $f;
        }
    }
    return $fieldNames;
}

function convertCSV2Items(&$csvRec,&$fieldNames,&$trans_actions,&$slice_fields,&$itemContent) {
    $itemContent1 = new ItemContent;
    $itemContent1->setFromCSVArray($csvRec, $fieldNames);
    $itemContent = new ItemContent;
    $err = $itemContent->transform($itemContent1, $trans_actions, $slice_fields);
    return $err;
}

class Actions {
    var $actions;
    var $globalParams;

    function Actions($actions, $inFields, $html, $params, $globalParams="") {
        foreach( $actions as $f_id => $action) {
            $this->actions[$f_id] = array( "from"=>$inFields[$f_id],
                                           "action"=>new Action($action,$html[$f_id],stripslashes($params[$f_id])));
        }
        $this->globalParams = $globalParams;
    }

    /** Transform the input item content to the output item content according
     *  to the actions
     */
     function transform(&$itemContent, $slice_fields, &$outputItemContent) {
         global $auth;
         
         // id is not part of $slice_fields, unfortunatelly (should be changed)
         if ( !isset($slice_fields['id..............']) ) {
             $slice_fields['id..............'] = 'to be processed in next loop';
         }

         foreach ( $slice_fields as $field_id => $foo ) {
             $action = &$this->actions[$field_id];
             unset( $fieldVal, $v);
 
             // fill up the output field with default value, if the action does not exist for the output field, or the action is "default"
             if (!$action || $action['action']->getAction() == "default") {
                 switch ($field_id) {
                     case "display_count..." : $v = 0; break;
                     case "status_code....." : $v = 1; break; // todo
                     case "flags..........." : $v = ITEM_FLAG_OFFLINE; break;
                     case "publish_date...." : $v =  time(); break;
                     case "post_date......." : $v =  time(); break;
                     case "last_edit......." : $v =  time(); break;
                     case "expiry_date....." : $v =  mktime(0,0,0,date("m"),date("d"),date("Y")+10) ; break;	// expiry in 10 years default : TODO
                     case "posted_by......." : $v = $auth->auth['uid']; break;	// todo
                     case "edited_by......." : $v = $auth->auth['uid']; break;	// todo
                     case "id.............." : $v = $auth->auth['uid']; break;
                     default :
                         if ( $action['from'] && ($action['from'] != '__empty__')) {
                             $action['action']->setAction('store');
                         } elseif ( $action['action']->getParams() ) {
                             $action['action']->setAction('value');
                         } else {
                             $v = "";
                         }
                         break;
                 }
             } elseif ($action['action']->getAction() == "new") {
                 $v = 'new id';
             }
             if ( isset($v) ) {   // $v is set in previous 'default' section
                 $fieldVal[]['value'] = addslashes($v);
             } else {
                 // transform the input field to the output field according the action
                 $err = $action['action']->transform($itemContent,$action['from'],$this->globalParams,$fieldVal );
                 if ($err)
                     return $err;
             }
             // store the output field to the output item content
             $outputItemContent->setFieldValue($field_id,$fieldVal);
        }
    }

    function getOutputFields() {
        return array_keys( $this->actions );
    }

    function getActions() {
        foreach ( $this->actions as $v ) { $f[] = $v['action']; }
        return $f;
    }

    function getHtml() {
        foreach ( $this->actions as $v ) { $f[] = $v['action']->getHtml(); }
        return $f;
    }

    function getParams() {
        foreach ( $this->actions as $v ) { $f[] = $v['action']->getParams(); }
        return $f;
    }

    function getGlobalParams() {
        return $this->globalParams;
    }
}

/** Represents an action, which transform a field from an input ItemContent to
 *  one field of an output ItemContent.
 *	Each action has two additional parameters:
 *      $html   - if set => store as HTML
 *  	$params - values or array of values specific to each action
 */
class Action {
    var $action;
    var $html;
    var $params;

    function Action($action, $html, $params) {
        $this->action = $action;
        $this->html = $html;
        $this->params = $params;
    }

    function getAction()        { return $this->action; }
    function getHtml()          { return $this->html;   }
    function getParams()        { return $this->params; }
    function setAction($action) { $this->action = $action; }

    /* transforms a value from $itemContent[$from] to $fvalues */
    function transform(&$itemContent, $from, &$globalParams, &$fvalues ) {
        switch ( $this->action) {
            case "store": {
                $fvalues[][value] = $itemContent->GetValue($from);		// todo - pokud neexistuje pole s $from , co delat?
                break;
            }
            case "pack_id": {
                $fvalues[][value] = pack_id($itemContent->GetValue($from));		// todo - pokud neexistuje pole s $from , co delat?
                break;
            }
            case "removestring": {
                $v =  $itemContent->GetValue($from);
                $fvalues[][value] = $this->params ? ereg_replace($this->params, "", $v) :$v;
                break;
            }
            case "formatdate": {
                $v = strtotime($itemContent->GetValue($from));
                if ($v == -1)
                    return "Invalid date: ".$itemContent->GetValue($from);
                $fvalues[][value] = $v;
                break;
            }
            case "convertvalue": {
                // ???
                $fvalues[][value] = $globalParams["table"][$itemContent->GetValue($from)]["return"];
                break;
            }
            case "web": {
                $value = $itemContent->GetValue($from);
                if( $value ) {
                    if (strtolower(substr($value, 0, 4)) != "http")
                    $value = "http://". $value;
                }
                $fvalues[][value] = $value;
                break;
                }
            case "storemultiasone" : {
                // $param is delimiter
                if (!is_array($from)) $from = array($from);
                $save = "";
                foreach ( $from as $tostore ) {
                    $savenext = trim($itemContent->GetValue($tostore));
                    if ($savenext != "") {
                        if ($save != "") {
                            $save = $save . $this->params;
                        }
                        $save = $save . $savenext;
                    }
                }
                $fvalues[]['value'] = $save;
                break;
            }
            case "storeasmulti": {
                if (!is_array($from)) $from = array($from);
                foreach ( $from as $tostore ) {
                    $save = trim($itemContent->GetValue($tostore));
                        $fvalues[]['value'] = $save;
                }
                break;
            }
            case "storeparsemulti": {
                $items = explode ($this->params,trim($itemContent->GetValue($from)));
                foreach ( $items as $save ) {
                    $fvalues[]['value'] = $save;
                }
                break;
            }
            case "string2id": {
                // from the same string we create allways the same id. This is
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
            default: {
                  return "Unknown function: $this->action";
            }
        }
        // set HTML flag and add slashes because of SQL syntax
        foreach ( $fvalues as $k => $v ) {
            if ($this->html) {
                $v['html'] = $this->html;
            }
            $v['value'] = addslashes($v['value']);
            $fvalues[$k] = $v;
        }
        return "";
    }
}

/** Get List of actions */
function getActions() {
    $a = array("store","removestring","formatdate", "web", "storeparsemulti","value","string2id","default");
    // not used:  "convertvalue","storemultiasone", "storeasmulti", "not map"
    foreach ( $a as $v ) { $actions[$v] = $v; }
    return $actions;
}

/** Delete all files with the format : {ident}_{hash20}_mmddyyyy older than
 *  7 days (used as temporary upload files)
 */
function DeleteOldFiles($ident,$upload_dir) {
    if ($handle = opendir($upload_dir)) {
        while (false !== ($file = readdir($handle))) {
            if (strlen($ident)+42 != strlen($file) || (substr($file,0,strlen($file)-42) != $ident))
                continue;
            $date=mktime(0,0,0,date("m"),date("d")-7,date("Y")) ;
            $filedate = mktime (0,0,0,substr($file,-8,2) ,substr($file,-6,2),substr($file,-4,4));
            $fileName = $upload_dir . $file;
            if ($filedate < $date) {
                if (unlink($fileName)) {
                    writeLog("FILE IMP.",_m("Ok : file deleted "). $fileName);
                } else {
                    writeLog("FILE IMP.",_m("Error: Cannot delete file"). $fileName);
                }
            }
        }
        closedir($handle);
    } else {
        writeLog("FILE IMP:",_m("Error: Invalid directory") .$upload_dir);
    }
}

function GetUploadFileName($ident) {
    return $ident . "_" . md5(uniqid(rand(),1))  . "_" . date("mdY");
}

function GetUploadDir($slice_id) {
    return IMG_UPLOAD_PATH . $slice_id . "/";
}
?>
