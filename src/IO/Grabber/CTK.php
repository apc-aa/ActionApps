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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\IO\Grabber;

use AA_Value;
use ItemContent;


/** AA\IO\AbstractGrabber\AbstractGrabber\CTK - Imports items from CTK xml export
 */
class CTK extends \AA\IO\Grabber\AbstractGrabber {

    protected $file;
    /** list if files to grab - internal array */
    private $_items;

    function __construct($file) {
        $this->file = $file;
    }

    /** Name of the grabber - used for grabber selection box */
    public function name(): string {
        return _m('CTK - xml import');
    }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    public function description(): string {
        return _m('Process import xml file xported from CTK');
    }

    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        #$data = file_get_contents($this->file);
        #$xml  = simplexml_load_string($data);
        $xml = simplexml_load_file($this->file);

        $this->_items = [];
        //$items = $xml->rsp_responsePackItem->lst_listStock->lst_stock;
        foreach ($xml->NewsItem as $v) {
            $this->_items[] = $v;
        }

    }

    /** Method called by the AA\IO\Saver to get next item from the data input */
    function getItem() {
        if (!($polozka = current($this->_items))) {
            return false;
        }
        next($this->_items);

        $domicil = '';
        foreach ($polozka->DescriptiveMetadata->Location->Property as $prop) {
            switch ((string)$prop['FormalName']) { // Get attributes as element indices
                case 'City':
                    $domicil = (string)$prop['Value'];
                    break;
            }
        }

        $ftext = '';
        foreach ($polozka->ContentItem->DataContent->body->p as $f) {
            $ftext .= $f->asXML() . "\n";
        };
	// cisteni kodu
	$EraseCTKStrings = ['<span class="linkTagNoBg">', '<i class="icon-ico-arrow-up icon"/></span>'];
	$ftext = str_replace($EraseCTKStrings,'',$ftext);

        $item = new ItemContent();
        $item->setItemID(new_id());
        $item->setValue('headline........', iconv('UTF-8', 'windows-1250', (string)$polozka->NewsComponent->NewsLines->Headline));      // Název
        $item->setValue('place..........3', iconv('UTF-8', 'windows-1250', mb_strtoupper($domicil, 'UTF-8')));                          // Domicil
        $item->setValue('publish_date....', strtotime($polozka->NewsManagement->ThisRevisionCreated));                                  // Datum zveřejnění - 20160127T145301+01:00
//      $item->setValue('expiry_date.....', strtotime($polozka->NewsManagement->ThisRevisionCreated)+3600*24*365);                      // Datum expirace - rok po zveřejnění - zruseno dle emailu 7.12.2016
        $item->setValue('expiry_date.....', '2145913199');                                                                              // Nekonecno
        $item->setAaValue('full_text......1', new AA_Value(iconv('UTF-8', 'windows-1250', $ftext), FLAG_HTML));                         // Plný text
        $item->setValue('source.........1', iconv('UTF-8', 'windows-1250', 'ČTK'));                                                                                     // Zdroj
        $item->setValue('source_href.....', 'http://www.ctk.cz');                                                                       // URL zdroje
        $item->setValue('status_code.....', 2);                                                                                         // Do zasobniku
        return $item;
        /*
        */

    }

}


