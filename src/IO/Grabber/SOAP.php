<?php
/**
 *
 * PHP versions 5 and 7
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
 * @link      http://www.apc.org/ APC
 *
 */

namespace AA\IO\Grabber;

use AA_Property;
use Exception;
use ItemContent;

/**
 * Class SOAP
 * @package AA\IO\Grabber
 */
class SOAP extends \AA\IO\Grabber\XML {

    // imported form XML Grabber
    //protected $file;
    /** file to grab - internal array */
    //protected $item_xpath;
    //private $_items;

    /**
     * SOAP constructor.
     * @param        $file
     * @param        $soap_body
     * @param string $item_xpath
     */
    function __construct($file, $soap_body, $item_xpath = '') {
        $post_body = '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Header/><soap:Body>'.$soap_body.'</soap:Body></soap:Envelope>';
        parent::__construct($file, $post_body, '', $item_xpath );
    }

    /** Name of the grabber - used for grabber selection box */
    function name() {
        return _m('SOAP');
    }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    function description() {
        return _m('General SOAP request');
    }

    /** getClassProperties function of AA_Serializable
     *  Used for importer and htmlSettings()
     */
    static function getClassProperties() {
        $properties = parent::getClassProperties(); //   id         name        type    multi  persistent - validator, required, help, morehelp, example
        $properties['body'] = new AA_Property('body', _m("SOAP body request"), 'string', false, true);
        return $properties;
    }

    // used from parent
    // function prepare() {}
    // function getItem() {
}