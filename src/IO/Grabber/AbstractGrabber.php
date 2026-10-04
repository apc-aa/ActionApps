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

use AA\Http\ClientInterface;
use AA\Http\ResponseTooLargeException;
use AA\Http\TransportException;
use AA\IO\SourceFailure;
use AA\IO\SourceRefused;
use AA\IO\SourceUnavailable;
use AA\Util\NamedInterface;
use AA\Util\ReportTrait;
use AA_Components;
use AA_Content;
use AA_Form;
use ConvertCharset;
use Exception;
use ItemContent;

/** AA\IO\AbstractGrabber\AbstractGrabber - Base class, which is used as abstraction for data input
 *  From this class we derive concrete data grabbers, like RSS, AARSS, CSV, ...
 */
abstract class AbstractGrabber implements NamedInterface
{
    use ReportTrait;

    /** @var array of domain and time of the last request to them - used with $load_frequency to  limit request frequency to domains*/
    protected static array $last_request_4_domain = [];

    /** @var float|null wait in order we do not contact the same domain more often than $load_frequency miliseconds */
    protected ?float $load_frequency = null;

    /** fetches instead of file_get_contents() when set; never serialized */
    private ?ClientInterface $http = null;

    /** @todo - not yet implemented */
    public static function factoryFromFile($file): AbstractGrabber
    {
        $grabbers = AA_Components::getClassNames('AA\\IO\\Grabber\\AbstractGrabber');
        exit;
    }

    /** true, if grabber returns already prepared item. false, when just parsed source structure is returned which must be converted by transformations
     * @return bool
     */
    public function isDirectfill(): bool
    {
        return true;
    }

    /** name function
     *  Name of the grabber - used for grabber selection box
     */
    public static function name(): string
    {
        return '';
    }

    /** description function
     *  Description of the grabber - used as help text for the users.
     *  Description is in HTML
     */
    public static function description(): string
    {
        return '';
    }


    /** Called before loadUrl to make authentication - used in loadData() as higher level function
     * @return bool
     */
    protected function loadAuth(): bool
    {
        return true;
    }

    /** the real low level get data from url - used in loadData() as higher level function
     *
     * @param  string       $url
     * @param  string|null  $post_body
     *
     * @return string data
     */
    protected function loadUrl(#[\SensitiveParameter] string $url, #[\SensitiveParameter] ?string $post_body = ''): string
    {
        if ($this->hasHttp()) {
            return $post_body ? $this->fetch('POST', $url, $post_body, ['Content-Type' => 'text/xml']) : $this->fetch('GET', $url);
        }
        $context = $post_body ? stream_context_create(['http' => ['method'  => 'POST', 'header'  => 'Content-Type: text/xml', 'content' => $post_body ]]) : null;
        return file_get_contents($url, false, $context);
    }

    /** loads data form, url or direct input
     *
     * @param  string  $url
     * @param  string  $direct_input
     * @param  string  $post_body
     * @param  string  $source_encoding
     *
     * @return false|string
     * @throws Exception
     */
    protected function loadData(#[\SensitiveParameter] $url, $direct_input = '', #[\SensitiveParameter] $post_body = '', $source_encoding = ''): false|string
    {
        if (strlen($direct_input)) {
            return $direct_input;
        }

        // wait in order we do not contact the same domain more often than $protect miliseconds
        if ($domain = parse_url($url, PHP_URL_HOST)) {
            $this->waitForDomain($domain);
        }

        if (false === $this->loadAuth()) {
            if ($this->hasHttp()) {
                throw new SourceRefused('AUTH', 'Authentication failed for ' . self::host($url));
            }
            throw new Exception('Authentication error: ' . $url);
        }

        if (false === ($data = $this->loadUrl($url, $post_body))) {
            throw new Exception('file not loaded: ' . $url);
        }
        $this->message(__CLASS__ . ' -> ' . __FUNCTION__);
        $this->message("read " . strlen($data) . " Bytes of data");
        // the data may be personal or carry keys of the source; only for whoever debugs the grabber
        $this->message("<pre>" . safe(substr($data, 0, 1000)) . "...</pre>", 2);

        if ($source_encoding && $source_encoding !== 'utf-8') {
            $data = ConvertCharset::singleton()->Convert($data, $source_encoding, 'utf-8');
            $this->message("Data converted from $source_encoding to utf-8");
        }

        return $data;
    }

    /** loads all the data based on grabber setting and return it */
    public function getSources(): array
    {
        return [];
    }

    /**
     * Fetch through this client instead of file_get_contents(). A failed fetch then throws
     * SourceUnavailable (worth asking again) or SourceRefused, and so does an unreadable answer.
     * Only for grabbers whose fetchesViaLoadData() is true - the others would ignore it.
     */
    public function setHttp(?ClientInterface $http): static
    {
        if (!is_null($http) && !$this->fetchesViaLoadData()) {
            throw new \LogicException(static::class . ' does not fetch through loadData()');
        }
        $this->http = $http;
        return $this;
    }

    /** true when the grabber gets its data through loadData(), so setHttp() has an effect */
    public function fetchesViaLoadData(): bool
    {
        return false;
    }

    /**
     * Feed grabbers wait in the queue serialized; a client holds connections and belongs to the
     * run that set it. Everything else as before, private properties of subclasses included.
     */
    public function __sleep(): array
    {
        return array_keys(array_diff_key((array) $this, ["\0" . self::class . "\0http" => null]));
    }

    protected function hasHttp(): bool
    {
        return !is_null($this->http);
    }

    /**
     * The body of a 2xx answer from the client of setHttp(). The message names only the host:
     * the URL may carry a key, and SourceFailure messages are stored.
     * @param  array<string, string> $headers
     * @throws SourceFailure
     */
    protected function fetch(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] ?string $body = null, #[\SensitiveParameter] array $headers = []): string
    {
        $http = $this->http ?? throw new \LogicException('No client set by setHttp()');
        try {
            $answer = $http->request($method, $url, $body, $headers);
        } catch (TransportException $e) {
            throw self::malformed($e)
                ? new SourceRefused('SOURCE', 'Malformed address of ' . self::host($url))
                : new SourceUnavailable('TRANSPORT', 'No answer from ' . self::host($url));
        } catch (ResponseTooLargeException) {
            throw new SourceRefused('SOURCE', 'The answer from ' . self::host($url) . ' is too large');
        }
        $failure = SourceFailure::forStatus($answer->status, 'HTTP ' . $answer->status . ' from ' . self::host($url));
        if (!is_null($failure)) {
            throw $failure;
        }
        return $answer->body;
    }

    /** a bad URL or scheme - asking again gets the same; the library reports it as a transport failure */
    private static function malformed(\Throwable $e): bool
    {
        for ($cause = $e->getPrevious(); !is_null($cause); $cause = $cause->getPrevious()) {
            if (($cause instanceof \InvalidArgumentException) || ($cause instanceof \TypeError)) {
                return true;
            }
        }
        return false;
    }

    /** scheme://host:port, the default port filled in; '' when the URL has no host */
    protected static function origin(#[\SensitiveParameter] string $url): string
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return '';
        }
        $port = $parts['port'] ?? (['http' => 80, 'https' => 443][$scheme] ?? 0);
        return "$scheme://$host:$port";
    }

    protected static function host(#[\SensitiveParameter] string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && ($host !== '') ? $host : 'the source';
    }

    /** htmlSetting function
     *  HTML code for parameters - defines parameters of this grabber.
     *  Each grabber could have its own parameters (like separator for CSV, ...)
     * @param $input_prefix
     * @param $params
     * @return string
     */
    public static function htmlSetting($input_prefix, $params): string
    {
        // $input_prefix - here grabber_id
        $properties = static::getClassProperties();
        $form = new AA_Form();
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
    public static function getClassProperties(): array
    {
        return [];
    }

    /** getItem function
     *  Method called by the AA\IO\Saver to get next item from the data input
     *
     * @return AA_Content|null
     */
    public function getItem(): ?AA_Content
    {
        return null;
    }

    /** getAllItems function
     *  Shortcut method to get array of all the Items
     * @param string $key if you want to get items as indexed array
     * @return AA_Content[]  or ItemContent[]
     * @throws Exception
     */
    final public function getAllItems(string $key = ''): array
    {
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

    /**  Go through items and return the first (should be only one) with given id)
     *
     * @param  string  $id
     *
     * @return AA_Content|null
     * @throws Exception
     */
    public function getItemWithId(string $id): ?AA_Content
    {
        $ret = null;
        $this->prepare();
        while ($item = $this->getItem()) {
            if ($item->getId() === $id) {
                $ret = $item;
                break;
            }
        }
        $this->finish();
        return $ret;
    }

    /**  Get first item
     *
     * @return AA_Content|null
     * @throws Exception
     */
    public function getFirstItem(): ?AA_Content
    {
        $this->prepare();
        $item = $this->getItem();
        $this->finish();
        return $item;
    }


    /** If AA\IO\Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     * @see also getStoreMode() method
     */
    public function getIdMode(): string
    {
        return 'combined';
    }

    /** If AA\IO\Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     * @see also getIdMode() method
     */
    public function getStoreMode(): string
    {
        return 'insert_if_new';
    }

    /** prepare function
     *  Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     *  @throws Exception
     */
    public function prepare() {}

    /** get data encoding - like UTF-8, windows-1250, ... */
    public function getCharset(): string
    {
        return 'utf-8';
    }

    /** Function called by AA\IO\Saver after we get the last item from the data input  */
    public function finish() {}

    /**
     * @param  float  $load_frequency  - how often we can contact domain in miliseconds - 1000.0 = 1s
     *
     * @return AbstractGrabber
     */
    public function setLoadFrequency(float $load_frequency): static
    {
        $this->load_frequency = $load_frequency;
        return $this;
    }

    /** wait in order we do not contact the same domain more often than $protect miliseconds
     * @param string $domain
     */
    protected function waitForDomain($domain): void
    {
        $time_diff = microtime(true) - (self::$last_request_4_domain[$domain] ?? 0);
        if ($time_diff < ($this->load_frequency / 1000.0)) {   // protect is in miliseconds, $time_diff in seconds
            usleep((int) (1000000 * (($this->load_frequency / 1000.0) - $time_diff)));
        }
        self::$last_request_4_domain[$domain] = microtime(true);
    }
}
