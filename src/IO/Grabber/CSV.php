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
class CSV extends \AA\IO\Grabber\AbstractGrabber {

    protected $file;
    /** file to grab - internal array */
    protected $item_xpath;


    /** name function
     * Name of the grabber - used for grabber selection box
     */
    function name() {
        return _m('CSV');
    }

    /** description function
     *  Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() {
        return _m('Import data from CSV (Comma Separated Values) format');
    }

    /** getClassProperties function of AA_Serializable
     *  Used for importer and htmlSettings()
     */
    static function getClassProperties() {
        $properties = array();   //                   id             name        type    multi  persistent - validator, required, help, morehelp, example
        $properties['file'] = new AA_Property('file', _m("URL of the file"), 'string', false, true);
        $properties['delimiter'] = new AA_Property('delimiter', _m("Delimiter of fields"), 'string', false, true, array(
            'enum',
            array(
                ',' => ', (comma)',
                ';' => '; (semicolon)',
                '\t' => '\t (tabulator)',
                '|' => '| (pipe)',
                '~' => '~ (tilde)',
                '' => 'other'
            )
        ), false, '', '', ',');
        $properties['enclosure'] = new AA_Property('enclosure', _m("Enclosure"), 'string', false, true, array(
            'enum',
            array('"' => '" (double quote )', "'" => '\' (single quote)')
        ), false, '', '', '"');
        $properties['caption'] = new AA_Property('caption', _m("Use first row as field names"), 'bool', false, true);
        return $properties;
    }


    /** getItem function
     * Method called by the AA\IO\Saver to get next item from the data input
     */
    function getItem() {
    }

    /** prepare function
     *  Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
    }

    /** finish function
     *  Function called by AA\IO\Saver after we get the last item from the data
     *  input
     */
    function finish() {
    }
}