<?php

use AA\IO\Grabber\AbstractGrabber;
use AA\IO\Grabber\Discussion;
use AA\IO\Grabber\Slice;

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

//require_once __DIR__."/PHPExcel/PHPExcel.php";
//require_once __DIR__."/PHPExcel/PHPExcel/Writer/Excel5.php";

class AA_Exporter extends AA_Object {
    var $field_set;

    /**
     * @var AbstractGrabber
     */
    var $grabber;

    function __construct($params) {
        // params: field_set, grabber
        $this->field_set = $params['field_set'];
        $this->grabber   = $params['grabber'];
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
        exit;
    }

    /** exporter function
     *  Generate the output and write to a temporary file
     *  I'm assuming $export_slices contains UNPACKED slice ids
     * @param $slice_id
     * @param $export_slices
     * @param $new_slice_id
     * @return bool|resource
     */
    function _createTmpFile() {
        $temp_file = tmpfile();

        if ( !$temp_file ) {
            echo _m("Can't create temporary file.");
            exit;
        }

        $this->grabber->prepare();       // maybe some initialization in grabber

        $index = 0;
        while ($content4id = $this->grabber->getItem()) {

            $item = GetItemFromContent($content4id);

            if ($index == 0) {
                fwrite($temp_file, $this->_outputStart($item));
            }
            $index++;

            fwrite($temp_file, $this->_outputItem($item));
            $old_item = $item;
        }

        if ($index > 0) {
            fwrite($temp_file, $this->_outputEnd($old_item));
        }
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

    /* returns default file extension. By default it is last part of this class
     * name, but you can redefine it in the subclasses, of course
     */
    function getExtension() {
        return strtolower(substr(get_class($this),12));
    }
}

class AA_Exporter_Csv extends AA_Exporter {

    /** exporter function
     *  Generate the output and write to a temporary file
     *  I'm assuming $export_slices contains UNPACKED slice ids
     * @param $slice_id
     * @param $export_slices
     * @param $new_slice_id
     * @return bool|resource
     */
    function _createTmpFile() {
        $temp_file = tmpfile();

        if ( !$temp_file ) {
            echo _m("Can't create temporary file.");
            exit;
        }

        if ( !$temp_file ) {
            echo _m("Can't create temporary file.");
            exit;
        }

        $this->grabber->prepare();       // maybe some initialization in grabber

        $index = 0;
        while ($content4id = $this->grabber->getItem()) {

            $item = GetItemFromContent($content4id);

            if ($index == 0) {
                $this->_outputStartFile($temp_file, $item);
            }
            $index++;

            $this->_outputItemFile($temp_file, $item);
            // $old_item = $item;
        }
        return $temp_file;
    }


    function _outputStartFile($file, $item)  {
        $fs      = $this->field_set;
        $out_arr = [];
        $count   = $fs->fieldCount();

        for ($i=0; $i < $count; $i++) {
            $out_arr[]  = $fs->getName($i) . ' ('.$fs->getDefinition($i).')';
        }
        fputcsv($file, $out_arr);
    }

    function _outputItemFile($file, $item)  {
        fputcsv($file, $this->field_set->getValueArray($item));
    }
}


/*
class AA_Exporter_Excel extends AA_Exporter {

    var $current_row;

    function __construct($params) {
        $this->current_row = -1;
        parent::__construct($params);
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
        if (ctype_digit((string)$value)) {
            $ret = pack("sssss", 0x203, 14, $row, $col, 0x0) . pack("d", $value);
        } elseif (is_string($value)) {
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

class AA_Exporter_Html extends AA_Exporter {

    function getCharset() {
        static $_charset = null;
        if (is_null($_charset)) {
            $_charset = $this->grabber->getCharset();
        }
        return $_charset;
    }

    function _contentHeaders($file_name)    {
        header('Content-Type: text/html; charset='.$this->getCharset());
    }

    function _outputEnd($item)   {
        return "\n</table></body></html>";  // EOF
    }

    function _outputStart($item)  {
        $ret  = '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">';
        $ret .= '<html><head><meta http-equiv="Content-Type" content="text/html; charset='.$this->getCharset().'"><title>ActionApps Export</title></head><body><table border="1">';

        $arr = $this->field_set->getNameArray();
        array_walk($arr, ['AA_Exporter_Html', '_valueSanity'], 'th');
        return $ret. "\n <tr>" . join("\n  ", $arr)."\n </tr>";
    }

    function _valueSanity(&$value, $key, $entity) {
        $value = "<$entity>". myspecialchars($value) ."</$entity>";
    }

    function _outputItem($item)  {
        $arr = $this->field_set->getValueArray($item);
        array_walk($arr, ['AA_Exporter_Html', '_valueSanity'], 'td');
        return "\n <tr>" . join("\n  ", $arr)."\n </tr>";
    }
}

class AA_Exporter_Html_Download extends AA_Exporter_Html {
    function _contentHeaders($file_name)    {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename='.basename($file_name));
        header('Content-Transfer-Encoding: binary');
    }

    /* returns default file extension.  */
    function getExtension() {
        return 'html';
    }
}

class AA_Exporter_Excel extends AA_Exporter_Html {
    function _contentHeaders($file_name)    {
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.ms-excel;charset:UTF-8');
        header('Content-Disposition: attachment; filename='.basename($file_name));
        header('Content-Transfer-Encoding: binary');
    }

    /* returns default file extension.  */
    function getExtension() {
        return 'xls';
    }
}

/*
class AA_Exporter_Excel5 extends AA_Exporter {
    var $sheet;
    var $_ar;

    function _contentHeaders($file_name)    {
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.ms-excel;charset:UTF-8');
        header('Content-Disposition: attachment; filename='.basename($file_name));
        header('Content-Transfer-Encoding: binary');

        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
//        $fstats = fstat($temp_file);
//        header('Content-Length: ' . $fstats['size']);

    }

    function _outputStart($item)  {
        $this->_ar[] = $this->field_set->getNameArray(true);
        return "";
    }

    function _outputEnd($item)   {

        // Create new PHPExcel object
        $this->sheet = new PHPExcel();

        // Set properties
        $this->sheet->getProperties()->setCreator("ActionApps Excel Export");
        $this->sheet->getProperties()->setTitle("ActionApps Excel Export");
        $this->sheet->getProperties()->setSubject("ActionApps Excel Export");
        $this->sheet->getProperties()->setDescription("ActionApps Excel Export");

        // Add the data
        $this->sheet->setActiveSheetIndex(0);
        if (!empty($this->_ar)) {
            $this->sheet->getActiveSheet()->fromArray($this->_ar);
        }

        // Rename sheet
        $this->sheet->getActiveSheet()->setTitle('Simple');

        // Echo done
        return "";  // EOF
    }

    function _outputItem($item)  {
        $this->_ar[] = $this->field_set->getValueArray($item);
        return "";
    }


    function sendFile($file_name) {

        $temp_file = $this->_createTmpFile();

        if ( !$temp_file ) {
            echo _m("Can't create temporary file.");
            exit;
        }

        // Save Excel 5 file
        $objWriter = new PHPExcel_Writer_Excel5($this->sheet);

        $this->_contentHeaders($file_name);
        $objWriter->save('-');
    }
}
*/

class AA_Fieldset {

    /** array of fields or definitions
     *  (element could be "headline........" as well as "_#HEADLINE _#ABSTRACT")
     *  the type is stored in $_type array
     */
    var $_fields;

    /** array of types of fields - corresponds to $_fields array
     *  values are [f|a]  (= field | aliases )
     */
    var $_types;

    /** array of field names - corresponds to $_fields array    */
    var $_names;

    function __construct() {
        $this->_fields = [];
        $this->_types  = [];
        $this->_names  = [];
    }

    function addField($field_id, $field_name='') {
        $this->_fields[] = $field_id;
        $this->_names[]  = $field_name;
        $this->_types[]  = 'f';
    }

    function addAlias($field_id, $field_name='') {
        $this->_fields[] = $field_id;
        $this->_names[]  = $field_name;
        $this->_types[]  = 'd';
    }

    function fieldCount()          { return count($this->_fields); }
    function getDefinition($index) { return $this->_fields[$index]; }
    function getName($index)       { return $this->_names[$index]; }
    function isField($index)       { return $this->_types[$index] == 'f'; }

    function getValueArray($item)  {
        $arr     = [];

        foreach ($this->_fields as $index => $definition) {
            $recipe = ($this->_types[$index]=='f') ? "{@$definition:|}" : $definition;
            $arr[]  = AA::Stringexpander()->unalias($recipe, '', $item);
        }
        return $arr;
    }

    function getNameArray($short=false)  {
        $arr     = [];
        foreach ($this->_fields as $index => $definition) {
            $arr[]  = $this->_names[$index] . ($short ? '' : " ($definition)");
        }
        return $arr;
    }
}

/** Export settings */
class AA_Exportsetings extends AA_Object {

    // must be protected or public - AA_Object needs to read it
    protected $grabber_type;
    protected $format;
    protected $type;
    protected $bins;
    protected $filename;
    protected $conds;
    protected $sort;
    protected $fields;

    /** allows storing form in database
     *  AA_Object's method
     * @return array
     */
    static function getClassProperties(): array {

        $bins_arr = [
            AA_BIN_ALL      => _m('All'),
            AA_BIN_ACTIVE   => _m('Active'),
            AA_BIN_PENDING  => _m('Pending'),
            AA_BIN_EXPIRED  => _m('Expired'),
            AA_BIN_APPROVED => _m('Approved'),
            AA_BIN_HOLDING  => _m('Holding'),
            AA_BIN_TRASH    => _m('Trash')
        ];

        $types_arr = [
            'db'    => _m('Database backup (as stored in DB)'),
            'human' => _m('Human - dates converted to Y-m-d format, ...')
        ];

        $format_arr     = [];
        $format_classes = AA_Components::getClassNames('AA_Exporter_');
        foreach ($format_classes as $fclass) {
            $format_arr[$fclass] = substr($fclass,12);
        }

        $grabber_arr = [
           'AA\IO\Grabber\Slice' => _m('Item Contents'),
           'AA\IO\Grabber\Discussion' => _m('Discussion')
        ];

        $grabber_type = new AA_Property( 'grabber_type', _m("What to export"), 'string', false, true, '', true);
        $format       = new AA_Property( 'format',       _m("Output Format"),  'string', false, true, '', true);
        $type         = new AA_Property( 'type',         _m("Type"),           'string', false, true, '', true);
        $bins         = new AA_Property( 'bins',         _m("Bins"),           'int',    false, true, '', true);

        $grabber_type->setConstants($grabber_arr);
        $format->setConstants($format_arr);
        $type->setConstants($types_arr);
        $bins->setConstants($bins_arr);

        return [ //           id           name                type        multi  persist validator, required, help, morehelp, example
            'grabber_type' => $grabber_type,
            'format'       => $format,
            'type'         => $type,
            'bins'         => $bins,
            'fields'       => new AA_Property( 'fields',   _m("Fields"),        'string', true,  true, '', false, _m('you can put there field id (like headline........) or any AA expression (like _#HEADLINE)<br>for export all the fields, letf this field blank')),
            'filename'     => new AA_Property( 'filename', _m("Filename"),      'string', false, true, '', false, _m('save as...')),
            'conds'        => new AA_Property( 'conds',    _m("Conditions"),    'text'  , false, true, '', false, _m('conditions are in "d-..." or "conds[]" form - just like:<br> &nbsp; d-headline........,category.......1-BEGIN-Bio (d-&lt;fields&gt;-&lt;operator&gt;-&lt;value&gt;-&lt;fields&gt;-&lt;op...)<br> &nbsp; conds[0][category........]=first&conds[1][switch.........1]=1 (default operator is RLIKE, here!)')),  // it is not absolutet necessary to use alphanum only, but it is easier to use, then
            'sort'         => new AA_Property( 'sort',     _m("Sort"),          'string', false, true, '', false, _m('like: publish_date....-'))  // it is not absolutet necessary to use alphanum only, but it is easier to use, then
        ];
    }

    function export($restict_zids=null) {
        $slice_id = $this->aa_owner;
        $slice  = AA_Slice::getModule($slice_id);

        if (!$slice OR !$slice->isValid()) {
            echo _m('No slice specified');
            exit;
        }

        // cleanup fields
        $this->fields = array_filter(array_map('trim', $this->fields), 'strlen');

        $set = new AA_Set($slice_id, AA::Stringexpander()->unalias($this->conds), AA::Stringexpander()->unalias($this->sort), $this->bins);
        $fs  = new AA_Fieldset;

        if ($this->grabber_type== 'AA\IO\Grabber\Discussion') {
            $possible_fields = ['d_id............', 'd_parent........','d_item_id.......', 'd_subject.......', 'd_body..........', 'd_author........', 'd_e_mail........', 'd_url_address...', 'd_url_descript..', 'd_date..........', 'd_remote_addr...', 'd_state.........'];
            $grabber         = new Discussion($set);
        } else {
            $fields          = $slice->getFields();
            $possible_fields = $fields->getPriorityArray();
            $grabber         = new Slice($set, $restict_zids);
        }

        if (is_array($this->fields) AND count($this->fields) ) {
            foreach ($this->fields as $f) {
                if (in_array($f, $possible_fields)) {
                    // just use unpacked variants
                    $fs->addField(str_replace(['id..............', 'slice_id........'], ['unpacked_id.....', 'u_slice_id......'], $f));
                } else {
                    $fs->addAlias($f);
                }
            }
        } else {
            if ($this->grabber_type== 'AA\IO\Grabber\Discussion') {
                foreach ($possible_fields as $field_id) {
                    $fs->addField($field_id);
                }
            } else {
                foreach ($possible_fields as $field_id) {
                    // skip packed fields
                    if ( in_array($field_id, ['id..............', 'slice_id........'])) {
                        continue;
                    }
                    $field = $fields->getField($field_id);
                    if ($this->type == 'human') {
                        // try to convert the data to human readable form
                        [$field_type,] = $field->getSearchType();
                        switch ($field_type) {
                            case 'date':     $fs->addAlias('{date:Y-m-d H#:i:{'.$field_id.'}}', $field->getProperty('name')); break;
                            case 'relation': $fs->addAlias('{item:{@'.$field_id.':-}:_#HEADLINE:|}', $field->getProperty('name')); break;
                            default:         $fs->addField($field_id, $field->getProperty('name'));
                        }
                    } else {
                        $fs->addField($field_id, $field->getProperty('name'));
                    }
                }
                $fs->addField('u_slice_id......', 'Slice ID');
                $fs->addField('unpacked_id.....', 'Item ID');
            }
        }

        set_time_limit(5000);

        $exporter = AA_Object::factory($this->format, ['field_set'=>$fs, 'grabber'=>$grabber]);
        if (is_null($exporter)) {
            echo _m('Bad file format - specify format');
            exit;
        }

        $filename = $this->filename ? AA::Stringexpander()->unalias($this->filename) : date("ymd").'-'.StrExpand('AA_Stringexpand_Seoname', [$slice->getName(), '', $slice->getCharset()]). (($this->grabber_type== 'AA\IO\Grabber\Discussion') ? '-Disc' : ''). '.'.$exporter->getExtension();

        $exporter->sendFile($filename);
    }

    /** Manager row HTML
     *  could be changed in child classes
     * @param AA\Util\Searchfields $fields
     * @param $aliases
     * @param $links
     * @return string
     */
    protected static function getManagerRowHtml($fields, $aliases, $links) {
        return '
            <tr>
              <td style="white-space:nowrap;">'.
            a_href($links['Edit'], _m('Edit'), 'aa-button-edit').' '.
            a_href(get_aa_url('export.php', ['id=_#AA_ID___'], false), _m('Export'), 'aa-button-show') . ' ' .
            a_href($links['Delete'], _m('Delete'), 'aa-button-delete', ['onclick'=>"return confirm('". _m("Do you really want to delete this object?"). "')"]).' '.
            '</td>          
              <td>'.join("</td>\n<td>", array_keys($aliases)).'</td>
            </tr>
            ';
    }

    // static function factoryFromForm($oowner, $otype=null)        ... could be redefined here, but we use the standard one from AA_Object
    // static function getForm($oid=null, $owner=null, $otype=null) ... could be redefined here, but we use the standard one from AA_Object
}


