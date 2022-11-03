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

use AA_Property;
use Exception;
use ItemContent;

/**
 * Class JSON-LD
 * @package AA\IO\Grabber
 */
class JsonLD extends AbstractGrabber
{

    /** @var $file string url of the file */
    protected $file = '';

    /** @var $authdata string[] array for auth ['username'=> , 'password'=>, 'tokenurl'=> ] */
    protected $authdata = [];

    /** @var $token string JWT token */
    protected $token = '';

    /** file to grab - internal array */
    protected $_items;

    /**
     * JsonLD constructor.
     * @param        $file
     */
    function __construct($file) {
        $this->file = $file;
    }

    /** Name of the grabber - used for grabber selection box */
    public static function name(): string {
        return _m('JSON-LD');
    }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    public static function description(): string {
        return _m('General JSON-LD file');
    }

    /** true, if grabber returns already prepared item. false, when just parsed source structure is returned which must be converted by transformations
     * @return bool
     */
    public function isDirectfill(): bool {
        return false;
    }

    /** getClassProperties function of AA_Serializable
     *  Used for importer and htmlSettings()
     * @return array
     */
    public static function getClassProperties(): array {
        $properties = [];//                        id             name        type    multi  persistent - validator, required, help, morehelp, example
        $properties['file']         = new AA_Property('file',         _m("URL of the file"), 'string', false, true);
//        $properties['post_body']    = new AA_Property('post_body',    _m("POST request body"), 'string', false, true);
//        $properties['direct_input'] = new AA_Property('direct_input', _m("use direct text instead of file"), 'string', false, true);
//        $properties['item_xpath']   = new AA_Property('item_xpath',   _m("XPath to one item"), 'string', false, true, 'string', false, _m("Specify, how to grab one item from the file using XPath expression"), '', _m("//item"));
        return $properties;
    }

    protected function loadAuth(): bool {
        if (!$this->token) {
            $post_body = json_encode(["username"=>$this->authdata['username'], "password"=>$this->authdata['password']]);
            $data      = $this->loadUrl($this->authdata['tokenurl'], $post_body);
            $data      = json_decode($data, true);
            $this->token = $data ? $data['token'] : '';
        }
        return (bool)$this->token;
    }

    public function setAuth(array $authdata) {
        $this->authdata['username'] = $authdata['username'];
        $this->authdata['password'] = $authdata['password'];
        $this->authdata['tokenurl'] = $authdata['tokenurl'];
    }

    /** the real low level get data from url - used in loadData() as higher level function
     * @param string      $url
     * @param string|null $post_body
     * @return false|string data
     */
    protected function loadUrl(string $url, string $post_body = ''): string {
        $header =  "Content-Type: application/json";
        if ($this->token) {
            //$header .= "\r\nAuthorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJpYXQiOjE2MTY5ODYwMjMsImV4cCI6MTYxNzA3MjQyMywicm9sZXMiOlsiUk9MRV9VU0VSIl0sInVzZXJuYW1lIjoibWFsaWtAbnN6bS5jeiJ9.oRpPze6fLueBpySTGo_m4NrZl5dFBhj7NyJ1HHZL5RjOaREXCiMB1z15j4UPwLaWVH8sUi2h74bCLs3KY1yeiZoIBGKcvU1663JqiT2V6OjpUJKLlIHz2cEMeOVTVD4Lps-LQ7elKc91GMqzEuAHitnoKQ-ys6vMyIL1slJgooO8hugUzFi7YZ91nCn040VYfu0It3O9dzAkCl6E35aA-pz_5B01D9GdFHf-ZQ5CxAuJwvzlNTPArRK6CCrSVDQqx_gdMz04rm8vsDhnqHkPpKLHIFdfV0wIjtbWwGl8fDC4B_-fYN61B1sdyhdAiduvMdTV5fq8qKNrECTVfvottwpXFsMlMTO0durs4WHIsR-jy7wPUtmWLf4N5KRjWi03hqljuw3Pi4F_Jth5EdO23eWXtm74u9EU2pALcTHXBfYw8_80Jkmbd1Aweck57eeNR_uWJEIJN94Ms4lSxhh7KGYi17vcoecVPXdQSYAXVd8ETyzRnV6IOneoA1tyfihyihk8b3bPsLyCd2jeH6-S0hDRiztMSfDX4M-EoW1ISC8cm1oMTO9W519TT3RI6Lt_1yXiq60iCInKZokvHjwCy580wBHwKO4iOSvtWm1yQAd7lVPH42kePCgqj22YKKlWGe78e-PjrtWh-zRBxQIPBtpSK2utANz1s5ZSYJVk6LU";
            $header .= "\r\nAuthorization: Bearer {$this->token}";
        }
        $context_opts = ['http' => ['method'  => 'GET', 'header'  => $header]];

        if ($post_body) {
            $context_opts['http']['content'] = $post_body;
            $context_opts['http']['method']  = 'POST';
        }

        $context = stream_context_create($context_opts);
        return file_get_contents($url, false, $context);
    }

    protected function nextPage($json): string {
        $url = '';
        if ($next = $json['hydra:view']['hydra:next']) {
            $needle = substr($next,0,(max(3,strlen($next)/2)));
            $url = substr($this->file,0,strrpos($this->file,$needle)).$next;
            huhl('NP',$next, $needle, $url);
            
        }
        return $url;
    }

    /** loads all the data based on grabber setting and return it */
    public function getSources(): array {
        $ret = [];
        $next_url = $this->file;
        while ($next_url) {
            $data = $this->loadData($next_url);
            $next_url = $this->nextPage(json_decode($data, true));
            $ret[] = $data;
        }
        return $ret;
    }


    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     * @throws Exception
     */
    function prepare() {
        $this->_items = [];

        $data = $this->getSources();
        foreach ($data as $chunk) {
            $json = json_decode($chunk, true);
            if (is_array($json['hydra:member'])) {
                $this->_items =  array_merge($this->_items, $json['hydra:member']);
            }
        }
        
            //    [hydra:view] => Array
            //        (
            //            [@id] => /api/ukazatel?page=1
            //            [@type] => hydra:PartialCollectionView
            //            [hydra:next] => /api/ukazatel?page=2

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

        $item = new ItemContent();
        foreach ($data as $k => $v) {
            $item->setValue($k, $v);
        }
        return $item;
    }
}