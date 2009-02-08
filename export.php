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

/** @param format    csv|xls|...  (just csv now supported) - corresponds to AA_Exporter_* classes
 *  @param slice_id  unpacked slice id to export
 *  @param sort      optional - sorting
 *  @param conds     optional - conditions when just some items shoud be exported
 *  @param filename  optional - name of the file
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

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename='.basename($file_name));
        header('Content-Transfer-Encoding: binary');
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

        $first = true;
        while ($content4id = $grabber->getItem()) {

            $item = GetItemFromContent($content4id);

            if ($first) {
                fwrite($temp_file, $this->_outputStart($item));
                $first = false;
            }
            fwrite($temp_file, $this->_outputItem($item));
            $old_item = $item;
        }
        fwrite($temp_file, $this->_outputEnd($old_item));
        return $temp_file;
    }

    function _outputStart($item) { return ''; }
    function _outputItem($item)  { return ''; }
    function _outputEnd($item)   { return ''; }
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

set_time_limit(120);

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
