<?php
/**
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
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

/** @param format    csv|excel|html - corresponds to AA_Exporter_* classes
 *  @param slice_id  unpacked slice id to export
 *  @param sort      optional - sorting
 *  @param conds     optional - conditions when just some items shoud be exported
 *  @param filename  optional - name of the file
 *
 *  @example http://example.org/apc-aa/export.php?slice_id=2a3e435461667d7f7c7c748b2a15a8b1&format=csv&filename=export.csv
 *  @example http://example.org/apc-aa/export.php?slice_id=2a3e435461667d7f7c7c748b2a15a8b1&format=excel&filename=export.xls
 */

/** Handle with PHP magic quotes - unquote the variables if quoting is set on */
function StripslashesDeep($value) {
    return is_array($value) ? array_map('StripslashesDeep', $value) : stripslashes($value);
}

if ( get_magic_quotes_gpc() ) {
    $_POST    = StripslashesDeep($_POST);
    $_GET     = StripslashesDeep($_GET);
    $_COOKIE  = StripslashesDeep($_COOKIE);
}

require_once "./include/config.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."item.php3";
require_once AA_INC_PATH."grabber.class.php3";
require_once AA_INC_PATH."searchlib.php3";
require_once AA_INC_PATH."locsess.php3";    // DB_AA object definition

class AA_Exporter {
    var $set;
    var $field_set;

    function AA_Exporter($params) {
        $this->set       = $params['set'];
        $this->field_set = $params['field_set'];
    }

    function sendFile($file_name) {

        $temp_file = $this->_createTmpFile();

        $this->_contentHeaders($file_name);
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        $fstats = fstat($temp_file);
        header('Content-Length: ' . $fstats['size']);

        ob_clean();
        flush();
        rewind($temp_file);
        while (!feof($temp_file)) {
            $buffer = fread($temp_file, 4096);
            echo $buffer;
        }
        fclose($temp_file);
    }

    /** exporter function
     *  Generate the output and write to a temporary file
     *  I'm assuming $export_slices contains UNPACKED slice ids
     * @param $slice_id
     * @param $export_slices
     * @param $new_slice_id
     */
    function _createTmpFile() {
        $temp_file = tmpfile();

        $grabber = new AA_Grabber_Slice($this->set);
        $grabber->prepare();       // maybe some initialization in grabber

        $index = 0;
        while ($content4id = $grabber->getItem()) {

            $item = GetItemFromContent($content4id);

            if ($index == 0) {
                fwrite($temp_file, $this->_outputStart($item));
            }
            $index++;
            
            fwrite($temp_file, $this->_outputItem($item));
            $old_item = $item;
            
//            if ($index==6000) {
//                break;
//            }
        }
        fwrite($temp_file, $this->_outputEnd($old_item));
        return $temp_file;
    }

    function _outputStart($item)  { return ''; }
    function _outputItem($item)   { return ''; }
    function _outputEnd($item)    { return ''; }
    function _contentHeaders($file_name)    {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename='.basename($file_name));
        header('Content-Transfer-Encoding: binary');
    }
}

class AA_Exporter_Csv extends AA_Exporter {

    function _outputStart($item)  {
        $fs      = $this->field_set;
        $out_arr = array();
        $count   = $fs->fieldCount();

        for ($i=0; $i < $count; $i++) {
            $out_arr[]  = $fs->getName($i) . ' ('.$fs->getDefinition($i).')';
        }
        return join(',',$out_arr)."\n";
    }

    function _outputItem($item)  {
        $fs      = $this->field_set;
        $out_arr = array();
        $count   = $fs->fieldCount();

        for ($i=0; $i < $count; $i++) {
            $definition = $fs->getDefinition($i);
            $recipe     = $fs->isField($i) ? "{alias:$definition:f_t:{@$definition:|}:csv}" : "{csv:{$definition}}";
            $out_arr[]  = $item->unalias($recipe);
        }
        return join(',',$out_arr)."\n";
    }
}


/*
class AA_Exporter_Excel extends AA_Exporter {

    var $current_row;

    function AA_Exporter_Excel($params) {
        $this->current_row = -1;
        parent::AA_Exporter($params);
    }

    function _outputEnd($item)   {
        return pack("ss", 0x0A, 0x00);  // EOF
    }

    function _outputStart($item)  {
        $ret .= pack("ssssss", 0x809, 0x8, 0x0, 0x10, 0x0, 0x0);  // BOF
        $ret .= pack('ss', 0x0042, 0x0002). pack('s',  0x04E4);   // or 0x04B0 ? codepage

        $fs      = $this->field_set;
        $count   = $fs->fieldCount();

        for ($i=0; $i < $count; $i++) {
            $ret .= $this->__getCell($fs->getName($i), 0, $i);
            $ret .= $this->__getCell('('.$fs->getDefinition($i).')', 1, $i);
        }
        $this->current_row = 1;
        return $ret;
    }

    function _outputItem($item)  {
        $ret     = '';

        $fs      = $this->field_set;
        $count   = $fs->fieldCount();
        $this->current_row++;

        for ($i=0; $i < $count; $i++) {
            $definition = $fs->getDefinition($i);
            $recipe     = $fs->isField($i) ? "{@$definition:|}" : $definition;
            $ret       .= $this->__getCell($item->unalias($recipe), $this->current_row, $i);
        }
        return $ret;
    }

    function __getCell($value,$row,$col) {
        $ret = '';
        if (is_numeric($value)) {
            $ret = pack("sssss", 0x203, 14, $row, $col, 0x0) . pack("d", $value);
        } elseif(is_string($value)) {
            $value = UTF8toBIFF8UnicodeShortchr(255).chr(254).mb_convert_encoding( $value, 'UTF-16LE', 'UTF-8');
            $len = mb_strlen($value);
            $ret = pack("ssssss", 0x204, 8 + $len, $row, $col, 0x0, $len) . $value;
        }
        return $ret;
    }

    function UTF8toBIFF8UnicodeShort($value) {
        if (function_exists('mb_strlen') and function_exists('mb_convert_encoding')) {
            // character count
            $ln = mb_strlen($value, 'UTF-8');

            // option flags
            $opt = 0x0001;

            // characters
            $chars = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
        } else {
            // character count
            $ln = strlen($value);

            // option flags
            $opt = 0x0000;

            // characters
            $chars = $value;
        }

        $data = pack('CC', $ln, $opt) . $chars;
        return $data;
    }
}
*/

class AA_Exporter_Excel extends AA_Exporter_Html {
    function _contentHeaders($file_name)    {
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.ms-excel;charset:UTF-8');
        header('Content-Disposition: attachment; filename='.basename($file_name));
        header('Content-Transfer-Encoding: binary');
    }
}

class AA_Exporter_Html extends AA_Exporter {

    function _contentHeaders($file_name)    {
        header('Content-Type: text/html; charset=utf-8');
    }

    function _outputEnd($item)   {
        return "\n</table></body></html>";  // EOF
    }

    function _outputStart($item)  {
        $ret  = '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">';
        $ret .= '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title>ActionApps Export</title></head><body><table border="1"><tr>';

        $fs      = $this->field_set;
        $count   = $fs->fieldCount();

        for ($i=0; $i < $count; $i++) {
            $ret .= "\n  <th>". htmlspecialchars($fs->getName($i), ENT_COMPAT, 'UTF-8') .'<br>('. $fs->getDefinition($i).')</th>';
        }
        return $ret."\n </tr>";
    }

    function _outputItem($item)  {
        $ret = "\n <tr>";

        $fs      = $this->field_set;
        $count   = $fs->fieldCount();

        for ($i=0; $i < $count; $i++) {
            $definition = $fs->getDefinition($i);
            $recipe     = $fs->isField($i) ? "{@$definition:|}" : $definition;
            $ret       .= "\n  <td>". htmlspecialchars($item->unalias($recipe), ENT_COMPAT, 'UTF-8') .'</td>';
        }
        return $ret."\n </tr>";
    }
}


class AA_Fieldset {

    /** array of fields or definitions
     *  (element could be "headline........" as well as "_#HEADLINE _#ABSTRACT")
     *  the type is stored in $_type array
     * /
    var $_fields;

    /** array of types of fields - corresponds to $_fields array
     *  values are [f|d]  (= field | definition)
     */
    var $_types;

    /** array of field names - corresponds to $_fields array    */
    var $_names;

    function AA_Fieldset() {
        $this->_fields = array();
        $this->_types  = array();
        $this->_names  = array();
    }

    function addField($field_id, $field_name='') {
        $this->_fields[] = $field_id;
        $this->_names[]  = $field_name;
        $this->_types[]  = 'f';
    }

    function fieldCount()          {  return count($this->_fields); }
    function getDefinition($index) { return $this->_fields[$index]; }
    function getName($index)       { return $this->_names[$index]; }
    function isField($index)       { return $this->_types[$index] == 'f'; }
}

$slice  = AA_Slices::getSlice($_GET['slice_id']);
if (!$slice->isValid()) {
    echo _m('No slice specified - specify slice_id as url parameter');
    exit;
}

$fields     = $slice->getFields();
$fields_arr = $fields->getPriorityArray();
$fs         = new AA_Fieldset;

foreach ($fields_arr as $field_id) {
    // skip packed fields
    if ( in_array($field_id, array('id..............', 'slice_id........'))) {
        continue;
    }
    $fs->addField($field_id, $fields->getProperty($field_id,'name'));
}
$fs->addField('u_slice_id......', 'Slice ID');
$fs->addField('unpacked_id.....', 'Item ID');

set_time_limit(1200);

$set = new AA_Set($_GET['conds'], $_GET['sort'], array($_GET['slice_id']));
$exporter = AA_Object::factoryByName('AA_Exporter_', $_GET['format'], array('set'=>$set, 'field_set'=>$fs));
if (is_null($exporter)) {
    echo _m('Bad file format - specify format as url parameter (csv, xls, ...)');
    exit;
}

$filename = $_GET['filename'] ? $_GET['filename'] : 'export.'.$_GET['format'];

$exporter->sendFile($filename);

exit;
?>
