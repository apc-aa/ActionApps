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
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\IO\Grabber;

use AA_Property;
use Exception;
use ItemContent;

/**
 * Class XML
 * @package AA\IO\Grabber
 */
class XML extends \AA\IO\Grabber\AbstractGrabber {

    /** @var $file string url of the file */
    protected $file = '';

    /** @var if not null, POST request used using this body */
    protected $post_body = null;

    /** @var use direct text instead of file */
    protected $direct_input = '';


    /** @var bool $fake_namespaces - simpleXML PHP interface work with namespaces is nightmare, so if this config option is true, the namespaces
     *                               are converted to underscore
     *                               soap:Body -> soap_Body, ...
     *                             - i tdoes not work well, when namespace string are in the xml text part - "<soap:Body"...
     */
    protected $fake_namespaces = false;

    /** file to grab - internal array */
    protected $item_xpath;
    protected $_items;

    /**
     * XML constructor.
     * @param        $file
     * @param null   $post_body
     * @param string $direct_input
     * @param string $item_xpath
     */
    function __construct($file, $post_body=null, $direct_input='', $item_xpath = '') {
        $this->file = $file;
        $this->post_body = $post_body;
        $this->direct_input = $direct_input;
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
        $properties['file']         = new AA_Property('file',         _m("URL of the file"), 'string', false, true);
        $properties['post_body']    = new AA_Property('post_body',    _m("POST request body"), 'string', false, true);
        $properties['direct_input'] = new AA_Property('direct_input', _m("use direct text instead of file"), 'string', false, true);
        $properties['item_xpath']   = new AA_Property('item_xpath',   _m("XPath to one item"), 'string', false, true, 'string', false, _m("Specify, how to grab one item from the file using XPath expression"), '', _m("//item"));
        return $properties;
    }

    public function useFakeNamespaces() {
        $this->fake_namespaces = true;
        return $this;
    }

    /**
     * @return bool|string
     * @throws Exception
     */
    protected function loadData() {
        if (strlen($this->direct_input)) {
            return $this->direct_input;
        }

        // wait in order we do not contact the same domain more often than $protect miliseconds
        if ($domain = parse_url($this->file, PHP_URL_HOST)) {
            $this->waitForDomain($domain);
        }

        $context = !($this->post_body) ? null : stream_context_create(array('http' => array('method'=>'POST','header'=>'Content-Type: text/xml','content'=>$this->post_body)));
        if (false === ($data = file_get_contents($this->file, false, $context))) {
            throw new Exception('file not loaded: ' . $this->file);
        }
        $this->message(__CLASS__.' -> '. __FUNCTION__);
        $this->message("read ". strlen($data). "Bytes of data <br><pre>".safe(substr($data,0,1000))."...</pre>");
        return $data;
    }


    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        $data = $this->loadData();

        // remove namespaces (it is pain to work with it in simple XML interface)
        // $data = str_replace(array('<rsp:', '</rsp:'), array('<rsp_', '</rsp_'), $data);
        //   - now nort necessary - we  registerXPathNamespace so we can use //rsp:xxx  in the xpath ...
        if (false === ($xml = simplexml_load_string($data))) {
            throw new Exception('xml parse error: ' . $data);
        }

        if ( $nss = $xml->getNamespaces(true) ) {
            if ($this->fake_namespaces) {
                $search = array();
                foreach ($nss as $prefix => $url) {
                    $search[] = "<$prefix:";
                    $search[] = "</$prefix:";
                    $search[] = "xmlns:$prefix";
                    $replace[] = "<$prefix".'_';
                    $replace[] = "</$prefix".'_';
                    $replace[] = "xmlns_$prefix";
                }
                $data = str_replace($search, $replace, $data);
                if (false === ($xml = simplexml_load_string($data))) {
                    throw new Exception('xml parse of modiffied data error: ' . $data);
                }
            } else {
                foreach ($nss as $prefix => $url) {
                    $xml->registerXPathNamespace($prefix, $url);
                }
            }
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
        $this->message(__CLASS__.' -> '. __FUNCTION__);
        $this->message("parsed items ". count($this->_items));
        reset($this->_items);
    }

    /** Method called by the AA\IO\Saver to get next item from the data input */
    function getItem() {
        if (false === ($xml = current($this->_items))) {
            return false;
        }
        next($this->_items);

        // if (!--$i) {
        //     exit;
        // }

        $item = new ItemContent();
        $item->setValue('initem', $xml->asXML());    // stav importu
        return $item;
    }
}