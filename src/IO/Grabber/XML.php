<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:03
 */

namespace AA\IO\Grabber;

use AA_Property;
use Exception;
use ItemContent;

/** AA_Grabber_XML
 */
class XML extends \AA\IO\Grabber\AbstractGrabber {

    protected $file;
    /** file to grab - internal array */
    protected $item_xpath;
    private $_items;

    function __construct($file, $item_xpath = '') {
        $this->file = $file;
        $this->item_xpath = $item_xpath;
    }

    /** Name of the grabber - used for grabber selection box */
    function name() {
        return _m('XML');
    }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() {
        return _m('General XML file');
    }

    /** true, if grabber returns already prepared item. false, when just parsed source structure is returned which must be converted by transformations */
    function isDirectfill() {
        return false;
    }

    /** getClassProperties function of AA_Serializable
     *  Used for importer and htmlSettings()
     */
    static function getClassProperties() {
        $properties = array();//                        id             name        type    multi  persistent - validator, required, help, morehelp, example
        $properties['file'] = new AA_Property('file', _m("URL of the file"), 'string', false, true);
        $properties['item_xpath'] = new AA_Property('item_xpath', _m("XPath to one item"), 'string', false, true, 'string', false, _m("Specify, how to grab one item from the file using XPath expression"), '', _m("//item"));
        return $properties;
    }

    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        if (false === ($data = file_get_contents($this->file))) {
            throw new Exception('file not loaded: ' . $this->file);
        }
        // remove namespaces (it is pain to work with it in simple XML interface)
        // $data = str_replace(array('<rsp:', '</rsp:'), array('<rsp_', '</rsp_'), $data);
        if (false === ($xml = simplexml_load_string($data))) {
            throw new Exception('xml parse error: ' . $data);
        }

        if ($this->item_xpath) {
            $splitted = $xml->xpath($this->item_xpath);
        } else {
            $splitted = $xml;
            //$items = $xml->rsp_responsePackItem->lst_listStock->lst_stock;
        }

        $this->_items = array();
        foreach ($splitted as $item) {
            $this->_items[] = $item;
        }
    }

    /** Method called by the AA\IO\Saver to get next item from the data input */
    function getItem() {

        if (!($polozka = current($this->_items))) {
            return false;
        }
        next($this->_items);

        // huhl($this,count($this->_items), $i, $polozka->asXML());
        // if (!--$i) {
        //     exit;
        // }

        $item = new ItemContent();
        $item->setValue('initem', $polozka->asXML());    // stav importu
        return $item;
    }
}