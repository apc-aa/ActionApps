<?php

use AA\Http\Client;
use AA\Http\ClientInterface;
use AA\Http\TransportException;
use AA\Util\LastErrTrait;

/**
 * How one AA installation talks to another: Central's wire protocol, and client authentization
 * (@see /doc/script/example_auth).
 *
 * It provides:
 *
 *   AA_Request / AA_Response - a command travels as a serialized AA_Request in the form field
 *                    "request", the answer comes back as a serialized AA_Response. Both ends may
 *                    run different AA versions, so neither the class names nor the shape may drift.
 *
 *   AA_ClientAuth  - for client authentization
 *
 * The header used to say this file was a standalone library to copy onto your own website. It has
 * not been one for a long time - it needs AA's autoloader - and the transport now comes from
 * AA\Http\Client, so it needs AA proper.
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

class AA_Http
{
    use LastErrTrait;

    /** Move to another page
     *  new version of go_url() - could use POST redirect
     *  static function - called like AA_Http::go("https://ecn.cz", ['a'=>'my string'], 'POST')
     *  @param string $url - destination url
     *  @param array  $parameters = array('a' => 1, 'b' => 'OK boy')
     *  @param string $type  - preferred type. Could be GET or POST, but if headers
     *                 are already sent, then we use javascript for redirection
     *  @param bool   $sess_close - try to close session, if the session is set
     *                      we do not want to try it, when database connection
     *                      error occurs, for example
     */
    public static function go(string $url, array $parameters, string $type = 'GET', bool $sess_close = true): never
    {
        global $sess;

        if (is_object($sess) && $sess_close) {
            Session::page_close();
        }

        // if headers are already sent, we have to use javascript redirect
        if (headers_sent()) {
            self::_goJs($url, $parameters);
            exit;
        }
        if ($type === 'POST') {
            $response = self::request($url, $parameters, [], 'POST');
            if ($response !== false) {
                // POST request OK
                echo $response;
                exit;
            }
        }
        // get request
        $url = get_url($url, $parameters);
        header("HTTP/1.1 Status: 302 Moved Temporarily");
        header("Location: $url");
        exit;
    }

    /** Move (redirect) to page $url using javascript */
    private static function _goJs($url, array $parameters = []): never
    {
        $url = get_url($url, $parameters);
        echo '
        <script>
            window.location = "' . $url . '";
        </script>
        ';
        exit;
    }


    /** Request to the url (using POST request or other methods and returns resulted data)
     *
     * @param                $url
     * @param  string|array  $data    - parameters
     * @param  array         $headers
     * @param  string  $method  - default is POST
     * @param  string  $err     -
     *
     * @return false|string $result[]
     * inspired by http://netevil.org/blog/2006/nov/http-post-from-php-without-curl
     */
    public static function request($url, string|array $data = [], array $headers = [], string $method = '', string $err = ''): false|string
    {
        $opts = [];
        if ($data || $headers || $method) {
            $method = in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'], true) ? $method : 'POST';  // all possible HTTP methods
            if ($data && is_array($data)) {
                $opts['content'] = http_build_query($data);
                $headers['Content-Length'] = strlen($opts['content']);
            } elseif (is_string($data) && $data !== '') {
                $opts['content'] = $data;
                $headers['Content-Length'] = strlen($opts['content']);
            }

            if ($headers) {
                $header = '';
                foreach ($headers as $k => $v) {
                    $header .= "$k: $v\r\n";
                }
                $opts['header'] = $header;
            }
            if ($opts || $method !== 'GET') {
                $opts['method'] = $method;
            }
        }

        $ctx = $opts ? stream_context_create(['http' => $opts]) : null;
        $ret = file_get_contents($url, false, $ctx);

        if ($ret === false) {
            self::lastErr(2, sprintf("Problem reading data from url: %s (%s)", $url, error_get_last()["message"]));  // set error code
            if ($err) {
                if (str_contains($err, '_#')) {
                    $statusCode = (isset($http_response_header) && preg_match('{HTTP\/\S*\s(\d{3})}', $http_response_header[0], $match)) ? $match[1] : '';
                    $ret = str_replace(['_#status', '_#errmsg'], [$statusCode, error_get_last()["message"]], $err);
                } else {
                    $ret = $err;
                }
            }
            return $ret;
        }
        return $ret;
    }

}

class AA_Response
{
    private mixed $response;  // could be string, array, object, etc. - depends on the request

    /** @var int */
    private int $error;

    public static $Response_type = 'serialize';

    public function __construct($response = null, int $error = 0)
    {
        $this->response = $response;
        $this->error = $error;
    }

    public function getResponse(): mixed
    {
        return $this->response;
    }

    /**
     * @return int
     */
    public function getError(): int
    {
        return $this->error;
    }

    /** @return bool   */
    public function isError(): bool
    {
        return ($this->error > 0);
    }

    public function respond(): void
    {
        switch (self::$Response_type) {
            case 'serialize':
                echo serialize($this);
                break;
            case 'html':
                if ($this->isError()) {
                    echo "Error $this->error: " . $this->response;
                } else {
                    echo is_scalar($this->response) ? $this->response : _m('Array returned');
                }
        }
    }

    /// Static functions
    public static function error($err, int $err_code = 1): void
    {
        $response = new AA_Response($err, $err_code);
        $response->respond();
    }

    public static function ok($ret): void
    {
        $response = new AA_Response($ret);
        $response->respond();
    }
}

class AA_Request
{
    public $command;
    public $params;

    /** One per process: a sync makes several calls to the same Central and curl keeps the
     *  connection open across them. Static, not a property - ask() serialises $this onto the
     *  wire (_requestVal()), so a client in a field would travel to the remote side. */
    private static ?ClientInterface $shared_http = null;

    public function __construct($command, $params = [])
    {
        $this->command = $command;
        $this->params  = $params;
    }

    public function _requestVal(): string
    {
        return serialize($this);
    }

    public function _requestArr(): array
    {
        return ['request' => $this->_requestVal()];
    }

    public function encode4Url(): string
    {
        return rawurlencode(base64_encode($this->_requestVal()));
    }

    /** static member function called like:
     *     $request = AA_Request::decode($_POST['request']);
     */
    public static function decode(string $posted_data)
    {
        return unserialize($posted_data, ['allowed_classes' => [
            AA_Request::class,
            AA_Definition_Module_Chunk::class,    // Do_Import_Module_Chunk carries a chunk in params
        ]]);
    }

    public function getCommand()
    {
        return $this->command;
    }

    public function getParameters()
    {
        return $this->params;
    }

    /** Sends request to remote AA
     * @param $url - url of remote AA
     * @param $parameters - optional array of additional url parameters 'k'=>'v'
     * @param ?ClientInterface $http - tests hand in a fake
     * @return AA_Response|mixed   error 3 when the remote AA did not answer, or answered
     *                             anything but a serialized AA_Response
     */
    public function ask($url, $parameters = [], $headers = [], ?ClientInterface $http = null)
    {
        $ask_arr = $this->_requestArr();
        if (is_array($parameters)) {
            $ask_arr = array_merge($ask_arr, $parameters);
        }
        // responder.php reads $_POST['request'], which PHP fills only for a form-encoded body.
        // The old path never set this header - PHP's http wrapper added it by itself.
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';

        try {
            // a synchronize or a module import runs for minutes on the remote side (copyslice2.php
            // gives itself five); 60 s of silence is what the old default_socket_timeout allowed.
            // No redirects: the Central url has to be exact, and a redirected POST loses its body.
            $answer = ($http ?? self::$shared_http ??= new Client(timeout: 300, idle: 60))->request('POST', $url, http_build_query($ask_arr), $headers);
        } catch (TransportException $e) {
            return new AA_Response('No response received from ' . $url . ' (' . $e->getMessage() . ')', 3);
        }
        if (!$answer->ok()) {
            // The status and, for a 3xx, where it pointed - but not a word of the remote body.
            // Central_Optimize joins these messages with <br> and the Manager renders them as HTML
            // in a superadmin's page, so a remote install behind a captive portal or a compromised
            // one could put a script there. The redirect target is ours to read and is what an
            // admin needs: it names the address AA_Request should have been configured with.
            $where = $answer->redirect === '' ? '' : ' - it points at ' . $answer->redirect;
            return new AA_Response('Remote AA answered HTTP ' . $answer->status . $where, 3);
        }
        $response  = unserialize(trim($answer->body), ['allowed_classes' => [
            AA_Response::class,
            // Central module Get_Module_Defs returns these wrapped in AA_Response, so we need to allow them for unserialization as wellS
            AA_Definition_Module::class,
            AA_Definition_Module_Slice::class,
            AA_Definition_Module_Site::class,
            AA_Definition_Module_Alerts::class,
            AA_Definition_Module_Chunk::class,
        ]]);
        if ($response === false) {
            return new AA_Response("Bad response", 3);
        }
        return $response;
    }
}

/** @todo - should be updated for new php5 sessions - Honza 2016-09-29 */
class AA_ClientAuth
{
    /** path to AA auth script - like: https://example.org/apc-aa/auth.php */
    public $_aa_responder_script;

    /** time in seconds of session validity. If not set, then the session
     *  is valid just for current browser session, 63072000 for two years */
    public $_cookie_lifetime;

    /** caches remote auth object */
    public $_auth = null;

    private $_reader_slices = [];

    public function __construct($options = [])
    {
        if (!is_array($options)) {
            $options = [];
        }
        $this->_aa_responder_script = $options['aa_url'] . 'central/responder.php';
        $this->_cookie_lifetime     = isset($options['cookie_lifetime']) ? (time() + $options['cookie_lifetime']) : 0;
        $this->_reader_slices       = $options['reader_slices'] ?? [];
    }

    public function checkAuth(): false
    {
        // we are trying to login
        $request = new AA_Request('Get_Sessionid');
        $params  = [];
        $headers = [];
        if ($_REQUEST['username']) {
            $params = ['free' => $_REQUEST['username'], 'freepwd' => $_REQUEST['password']];
        } elseif ($_COOKIE['AA_Sess']) {
            $headers = ['Cookie' => 'AA_CP_Session=' . $_COOKIE['AA_Sess']];
        } else {
            $this->logout();
            return false;
        }

        $response = $request->ask($this->_aa_responder_script, $params, $headers);

        if (!$response->isError()) {

            $arr = $response->getResponse();
            $session_id  = $arr[0];
            $myauth      = $arr[1];

            if ($myauth->getUname() && $myauth->getUid() && trim($myauth->getUid()) !== 'nobody') {
                $x                  = setcookie('AA_Sess', $session_id, $this->_cookie_lifetime, '/');
                //$y                  = setcookie('AA_Uid', $myauth->getUname(), $this->_cookie_lifetime, '/');
                $_COOKIE['AA_Sess'] = $session_id;
                //$_COOKIE['AA_Uid']  = $myauth->getUname();  // we need it for current page as well
                $this->_auth        = $myauth;
                return $myauth->getUname();
            }
        }
        $this->logout();
        return false;
    }

    // function getUid() {
    //     return isset($_COOKIE['AA_Uid']) ? $_COOKIE['AA_Uid'] : false;
    // }

    public function getRemoteAuth()
    {
        return $this->_auth;
    }

    public function logout(): void
    {
        $request  = new AA_Request('Logout');
        $params   = ['AA_CP_Session' => $_COOKIE['AA_Sess']];
        $response = $request->ask($this->_aa_responder_script, $params);

        // both is necessary - one for current page, one for next page
        setcookie('AA_Sess', "", time() - 3600, '/');
        $_COOKIE['AA_Sess'] = '';
        // setcookie('AA_Uid', "", time() - 3600, '/');
        // $_COOKIE['AA_Uid']  = '';
    }
}
