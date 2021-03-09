<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:07
 */

namespace AA\IO\Grabber;

use AA_Condition;
use AA_Set;
use ItemContent;

/** AA\IO\AbstractGrabber\AbstractGrabber\PohodaStocks - Imports stock from POHODA accounting system
 */
class PohodaStocks extends AbstractGrabber
{

    protected $file;
    /** list if files to grab - internal array */
    private $_items;
    private $_last_store_mode;

    function __construct($file) {
        $this->file = $file;
    }

    /** Name of the grabber - used for grabber selection box */
    public function name(): string {
        return _m('Pohoda (www.stormware.cz) - Stocks');
    }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    public function description(): string {
        return _m('Imports stock from POHODA accounting system');
    }

    /** If AA\IO\Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     * @see also getIdMode() method
     */
    function getStoreMode() {
        return $this->_last_store_mode;
    }

    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        $data = file_get_contents($this->file);
        // remove namespaces (it is pain to work with it in simple XML interface)
        $data = str_replace([
            '<rsp:',
            '</rsp:',
            '<lst:',
            '</lst:',
            '<stk:',
            '</stk:',
            '<typ:',
            '</typ:'
        ], ['<rsp_', '</rsp_', '<lst_', '</lst_', '<stk_', '</stk_', '<typ_', '</typ_'], $data);
        $xml = simplexml_load_string($data);

        $items = $xml->rsp_responsePackItem->lst_listStock->lst_stock;
        foreach ($items as $v) {
            $this->_items[] = $v->stk_stockHeader;
        }

//        $this->_items = $xml->rsp_responsePackItem->lst_listStock->lst_stock;
//       huhl($this->_items);
    }

    /** Method called by the AA\IO\Saver to get next item from the data input */
    function getItem() {
        $VAT_RATES = ['none' => 0, 'low' => 10, 'high' => 20];

        if (!($polozka = current($this->_items))) {
            return false;
        }
        next($this->_items);

        $set = new AA_Set('3c121c4f40ded336375a6206c85baf1e', new AA_Condition('number.........1', '==', $polozka->stk_PLU), null, AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING);
        $zids = $set->query();

        $item = new ItemContent();
        $item->setValue('source.........1', (string)$polozka->stk_name);
        $item->setValue('source.........2', (string)$polozka->stk_count);               // stav zasob
        $item->setValue('source.........3', (string)$polozka->stk_countReceivedOrders); // objednavky
        $item->setValue('source.........4', (string)$polozka->stk_reservation);         // rezervace
        $item->setValue('source.........5', (string)$polozka->stk_sellingPrice);        // cena bez DPH

        $dan = $VAT_RATES[(string)$polozka->stk_sellingRateVAT];
        $cena = round((string)$polozka->stk_sellingPrice * (($dan + 100.0) / 100.0), 1);
        $cena = ((float)round($cena) == (float)$cena) ? round($cena) : $cena;
        $jedn = (string)$polozka->stk_unit;

        $item->setValue('number..........', $dan);         // DPH

        if ($jedn == 'ks') {
            $item->setValue('price..........1', $cena);
            $item->setValue('price..........2', '');
        } else {
            $item->setValue('price..........1', '');
            $item->setValue('price..........2', $cena);
        }

        // new PLUs INSERT into database - current just update (price, stock)
        if ($zids->count()) {
            $this->_last_store_mode = 'update';
            $item_id = $zids->longids(0);
        } else {
            $this->_last_store_mode = 'insert';
            $item_id = new_id();
            $item->setValue('number.........1', (string)$polozka->stk_PLU);
            $item->setValue('headline........', (string)$polozka->stk_name);
            $item->setValue('status_code.....', '2');  // nove importujeme do zasobniku
        }
        $item->setItemID($item_id);

        return $item;
    }
}