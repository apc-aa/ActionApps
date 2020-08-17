<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:07
 */

namespace AA\IO\Grabber;

use AA;
use ItemContent;

/** AA\IO\AbstractGrabber\AbstractGrabber\PohodaStocks - Imports stock from POHODA accounting system
 */
class PohodaOrdersResult extends AA\IO\Grabber\AbstractGrabber {

    protected $file;
    /** list if files to grab - internal array */
    private $_items;

    function __construct($file) {
        $this->file = $file;
    }

    /** Name of the grabber - used for grabber selection box */
    public function name(): string {
        return _m('Pohoda (www.stormware.cz) - Orders import result');
    }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    public function description(): string {
        return _m('Process Order import results from POHODA accounting system');
    }

    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        $data = file_get_contents($this->file);
        // remove namespaces (it is pain to work with it in simple XML interface)
        $data = str_replace(['<rsp:', '</rsp:'], ['<rsp_', '</rsp_'], $data);
        $xml = simplexml_load_string($data);

        $this->_items = [];
        //$items = $xml->rsp_responsePackItem->lst_listStock->lst_stock;
        foreach ($xml as $v) {
            $this->_items[] = $v;
        }

    }

    /** Method called by the AA\IO\Saver to get next item from the data input */
    function getItem() {
        if (!($polozka = current($this->_items))) {
            return false;
        }
        next($this->_items);

        $response_att = $polozka->attributes();


        //$zids     = new zids($response_att['id'], 's');
        $item_id = AA::Stringexpander()->unalias('{item:' . $response_att['id'] . ':_#ITEM_ID_}');
        $poznamka = AA::Stringexpander()->unalias('{item:' . $response_att['id'] . ':source.........2}');

        $item = new ItemContent();
        $item->setItemID($item_id);

        $item->setValue('source.........1', (string)$response_att['state']);    // stav importu
        // poznamku pripojujeme na zacatek
        $item->setValue('source.........2', date('ymd-His') . (string)$response_att['note'] . "\n" . $poznamka);     // import poznamka
        return $item;
    }

}