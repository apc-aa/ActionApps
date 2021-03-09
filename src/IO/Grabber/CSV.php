<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:04
 */

namespace AA\IO\Grabber;

use AA_Property;

/** AA\IO\AbstractGrabber\AbstractGrabber\CSV - CSV (Comma Separated Values) format grabber */
class CSV extends AbstractGrabber
{

     /** @var string url of the file */
    protected $file = '';

    /** @var string use direct text instead of file */
    protected $direct_input = '';

    /** @var string CSV delimiter */
    protected $delimiter = '';

    /** @var string CSV enclosure */
    protected $enclosure = '';

    /** @var bool use first row as column names */
    protected $caption = '';

    /** @var string source_encoding - like windows-1250, ... - data will be converted to utf-8 */
    protected $source_encoding = '';

    /** @var string[] column names */
    protected $_colnames;
    protected $_items;


    /**
     * CSV constructor.
     * @param string $file
     * @param string $direct_input
     * @param string $delimiter
     * @param string $enclosure
     * @param bool   $caption
     * @param string $source_encoding
     */
    function __construct($file, $direct_input='', $delimiter=',', $enclosure='"', $caption=true, $source_encoding='') {
        $this->file             = $file;
        $this->direct_input     = $direct_input;
        $this->delimiter        = strlen($delimiter) ? (($delimiter=='\t') ? chr(9) : $delimiter) : ','; // tabulator ascii
        $this->enclosure        = strlen($enclosure) ? $enclosure : '"';
        $this->caption          = $caption;
        $this->source_encoding  = $source_encoding;
    }

    /** name function
     * Name of the grabber - used for grabber selection box
     */
    public function name() : string {
        return _m('CSV');
    }

    /** description function
     *  Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    public function description(): string {
        return _m('Import data from CSV (Comma Separated Values) format');
    }

    /** true, if grabber returns already prepared item. false, when just parsed source structure is returned which must be converted by transformations */
    function isDirectfill() {
        return false;
    }

    /** getClassProperties function of AA_Serializable
     *  Used for importer and htmlSettings()
     */
    static function getClassProperties() {
        $properties = [];   //                   id             name        type    multi  persistent - validator, required, help, morehelp, example
        $properties['file'] = new AA_Property('file', _m("URL of the file"), 'string', false, true);
        $properties['direct_input'] = new AA_Property('direct_input', _m("use direct text instead of file"), 'string', false, true);
        $properties['delimiter'] = new AA_Property('delimiter', _m("Delimiter of fields"), 'string', false, true, [
            'enum',
            [
                ',' => ', (comma)',
                ';' => '; (semicolon)',
                '\t' => '\t (tabulator)',
                '|' => '| (pipe)',
                '~' => '~ (tilde)',
                '' => 'other'
            ]
        ], false, '', '', ',');
        $properties['enclosure'] = new AA_Property('enclosure', _m("Enclosure"), 'string', false, true, [
            'enum',
            ['"' => '" (double quote )', "'" => '\' (single quote)']
        ], false, '', '', '"');
        $properties['caption'] = new AA_Property('caption', _m("Use first row as field names"), 'bool', false, true);
        return $properties;
    }


    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        $this->_items    = [];
        $this->_colnames = [];
        $data = $this->loadData($this->file, $this->direct_input, '', $this->source_encoding);

        // fgetcsv works on streams - we have to use stream (we do notr want to use str_getcsv() - it is hard to work with \n inside fields)
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $data);
        rewind($stream);
        $array = fgetcsv($stream, 0, $this->delimiter, $this->enclosure);

        if (!$array) {
            return;
        }

        if ($this->caption) {
            $this->_colnames = $array;
            // read the first data row
            $array = fgetcsv($stream, 0, $this->delimiter, $this->enclosure);
        } else {
            $this->_colnames = array_fill(0,count($array),'field');
            array_walk($this->_colnames, function (&$v, $k) {$v .= $k+1;});
        }

        while ($array !== false) {
            $this->_items[] = $array;
            $array = fgetcsv($stream, 0, $this->delimiter, $this->enclosure);
        }
        $this->message(__CLASS__.' -> '. __FUNCTION__);
        $this->message("parsed items ". count($this->_items));
        reset($this->_items);
    }

    /** Method called by the AA\IO\Saver to get next item from the data input */
    function getItem() {
        if (false === ($data = current($this->_items))) {
            return false;
        }
        next($this->_items);

        $item = new \ItemContent();
        foreach ($this->_colnames as $k => $colname) {
            $item->setValue($colname, $data[$k]);    // stav importu
        }
        return $item;
    }

    /** finish function
     *  Function called by AA\IO\Saver after we get the last item from the data
     *  input
     */
    function finish() {
    }
}