<?php
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */



namespace AA\IO\Grabber;

use AA_Components;
use AA_Content;
use AA_Form;
use ItemContent;

/** AA\IO\AbstractGrabber\AbstractGrabber - Base class, which is used as abstraction for data input
 *  From this class we derive concrete data grabbers, like RSS, AARSS, CSV, ...
 */
abstract class AbstractGrabber implements \AA\Util\NamedInterface {
    use \AA\Util\ReportTrait;

    /** @var array of domain and time of the last request to them - used with $load_frequency to  limit request frequency to domains*/
    protected static $last_request_4_domain = [];

    /** @var float wait in order we do not contact the same domain more often than $load_frequency miliseconds */
    protected $load_frequency = null;

    /** @todo - not yet implemented */
    static function factoryFromFile($file) {
        $grabbers = AA_Components::getClassNames('AA\\IO\\Grabber\\AbstractGrabber');
        exit;
    }

    /** true, if grabber returns already prepared item. false, when just parsed source structure is returned which must be converted by transformations
     * @return bool
     */
    public function isDirectfill(): bool {
        return true;
    }

    /** name function
     *  Name of the grabber - used for grabber selection box
     */
    public static function name() : string { return ''; }

    /** description function
     *  Description of the grabber - used as help text for the users.
     *  Description is in HTML
     */
    public static function description() : string { return ''; }


    /** Called before loadUrl to make authentication - used in loadData() as higher level function
     * @return bool
     */
    protected function loadAuth(): bool { return true; }

    /** the real low level get data from url - used in loadData() as higher level function
     * @param string      $url
     * @param string|null $post_body
     * @return false|string data
     */
    protected function loadUrl(string $url, ?string $post_body = ''): string {
        $context = !($post_body) ? null : stream_context_create(['http' => ['method'  => 'POST', 'header'  => 'Content-Type: text/xml', 'content' => $post_body ]]);
        return file_get_contents($url, false, $context);
    }

    /** loads data form, url or direct input
     * @param string $url
     * @param string $direct_input
     * @param string $post_body
     * @param string $source_encoding
     * @return string
     * @throws \Exception
     */
    protected function loadData($url, $direct_input='', $post_body='', $source_encoding='') {
        if (strlen($direct_input)) {
            return $direct_input;
        }

        // wait in order we do not contact the same domain more often than $protect miliseconds
        if ($domain = parse_url($url, PHP_URL_HOST)) {
            $this->waitForDomain($domain);
        }

        if (false === $this->loadAuth()) {
            throw new \Exception('Authentication error: ' . $url);
        }

        if (false === ($data = $this->loadUrl($url, $post_body))) {
            throw new \Exception('file not loaded: ' . $url);
        }
        $this->message(__CLASS__.' -> '. __FUNCTION__);
        $this->message("read ". strlen($data). " Bytes of data <br><pre>".safe(substr($data,0,1000))."...</pre>");

        if ($source_encoding AND ($source_encoding!='utf-8')) {
            $data = \ConvertCharset::singleton()->Convert($data, $source_encoding, 'utf-8');
            $this->message("Data converted from $source_encoding to utf-8");
        }

        return $data;
    }

    /** loads all the data based on grabber setting and return it */
    public function getSources(): array {
        return [];
    }

    /** htmlSetting function
     *  HTML code for parameters - defines parameters of this grabber.
     *  Each grabber could have its own parameters (like separator for CSV, ...)
     * @param $input_prefix
     * @param $params
     * @return string
     */
    static function htmlSetting($input_prefix, $params) {     // $input_prefix - here grabber_id
        $properties = static::getClassProperties();
        $form = new AA_Form;
        foreach ($properties as $id => $property) {
            $property->addPropertyFormrows($form);
        }
        $content = new AA_Content();
        $content->setId($input_prefix);
        return $form->getRowsHtml($content);
    }

    /** getClassProperties function of AA_Serializable - used for htmlSetting()
     * @return array
     */
    public static function getClassProperties(): array {
        return [];
    }

    /** getItem function
     *  Method called by the AA\IO\Saver to get next item from the data input
     * @return ItemContent|AA_Content
     */
    function getItem() {
    }

    /** getAllItems function
     *  Shortcut method to get array of all the Items
     * @param string $key if you want to get items as indexed array
     * @return ItemContent[]|AA_Content[]
     * @throws \Exception
     */
    public function getAllItems(string $key=''): array {
        $this->prepare();
        $ret = [];
        if ($key) {
            while ($item = $this->getItem()) {
                $ret[$item->getValue($key)] = $item;
            }
        } else {
            while ($item = $this->getItem()) {
                $ret[] = $item;
            }
        }
        //huhl($ret);
        $this->finish();
        return $ret;
    }


    /** If AA\IO\Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     * @see also getStoreMode() method
     */
    function getIdMode() {
        return 'combined';
    }

    /** If AA\IO\Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     * @see also getIdMode() method
     */
    function getStoreMode() {
        return 'insert_if_new';
    }

    /** prepare function
     *  Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     *  @throws \Exception
     */
    function prepare() {
    }

    /** get data encoding - like UTF-8, windows-1250, ... */
    function getCharset() {
        return 'UTF-8';
    }

    /** Function called by AA\IO\Saver after we get the last item from the data input  */
    function finish() {
    }

    /**
     * @param float $load_frequency - how often we can contact domain in miliseconds - 1000.0 = 1s
     * @return AbstractGrabber
     */
    public function setLoadFrequency($load_frequency) {
        $this->load_frequency = $load_frequency;
        return $this;
    }

    /** wait in order we do not contact the same domain more often than $protect miliseconds
     * @param string $domain
     */
    protected function waitForDomain($domain) {
        $time_diff = microtime(true) - (self::$last_request_4_domain[$domain] ?: 0);
        if ($time_diff < ($this->load_frequency / 1000.0)) {   // protect is in miliseconds, $time_diff in seconds
            usleep((int)(1000000 * (($this->load_frequency / 1000.0) - $time_diff)));
        }
        self::$last_request_4_domain[$domain] = microtime(true);
    }
}