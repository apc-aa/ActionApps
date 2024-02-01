<?php
/**
 * This file could be used inside AA as well as outside of the AA.
 * You can just copy the file to your website and use it for client
 * authentization. The example of the "client authentization" you can find
 * in apc-aa/doc/script/example_auth directory.
 *
 * The fiel has no external requires - it si standalone library
 *
 * It provides:
 *
 *   AA_ClientAuth - for client authentization (@see /doc/script/example_auth)
 *
 *   AA_Request
 *   AA_Response
 *   AA_Http        - three classes used for communication with (and between)
 *                    AA installations. Used for "client auth" as well as for
 *                    Central.
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

class AA_Http {

    use \AA\Util\LastErrTrait;

    /** Move to another page
     *  new version of go_ur() - could use POST redirect
     *  static function - called like AA_Http::go("http://ecn.cz", array('a'=>'my string'), 'POST')
     *  @param string $url - destination url
     *  @param array  $parameters = array('a' => 1, 'b' => 'OK boy')
     *  @param string $type  - preffered type. Could be GET or POST, but if headers
     *                 are already sent, then we use javascript for redirection
     *  @param bool   $sess_close - try to close session, if the session is set
     *                      we do not want to try it, when database connection
     *                      error ocures, for example
     **/
    public static function go(string $url, array $parameters, string $type='GET', bool $sess_close=true) {
        global $sess;

        if (is_object($sess) AND $sess_close) {
            page_close();
        }

        // if headers are already sent, we have to use javascript redirect
        if ( headers_sent() ) {
            AA_Http::_goJs($url, $parameters);
            exit;
        }
        if ($type=='POST') {
            $response = AA_Http::postRequest($url, $parameters);
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

    /** Move (redirect) to page $url using javascript
     *  static function
     */
    protected static function _goJs($url, array $parameters= []) {
        $url = get_url( $url, $parameters);
        echo '
        <script>
            window.location = "'.$url.'";
        </script>
        ';
        exit;
    }


    /** postRequest function
     *  POST data to the url (using POST request and returns resulted data
     * @param       $url
     * @param array $data
     * @param array $headers
     * @return false|string $result[]
     * inspired by http://netevil.org/blog/2006/nov/http-post-from-php-without-curl
     */
    public static function postRequest($url, array $data=[], array $headers=[]) {

        if ($data OR $headers) {
            $opts = null;
            if ($data) {
                $data = http_build_query($data);
                $opts = ['http' => ['method' => 'POST', 'content' => $data]];
            }

            if ($headers) {
                $header = '';
                foreach ($headers as $k => $v) {
                    $header .= "$k: $v\r\n";
                }
                $opts['http']['header'] = $header;
            }
        }

        $ctx = $opts ? stream_context_create($opts) : null;
        $ret = file_get_contents($url, false, $ctx);

        if ($ret === false) {
            AA_Http::lastErr(2, "Problem reading data from url: $url");  // set error code
            return false;
        }
        return $ret;
    }
}

class AA_Response {

    protected $response;

    /** @var int */
    protected $error;

    static $Response_type = 'serialize';

    public function __construct($response = null, int $error = 0) {
        $this->response = $response;
        $this->error = $error;
    }

    function getResponse() {
        return $this->response;
    }

    /**
     * @return int
     */
    function getError() : int  {
        return $this->error;
    }

    /** @return bool   */
    function isError() : bool {
        return ($this->error > 0);
    }

    function respond() {
        switch (AA_Response::$Response_type) {
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
    public static function error($err, int $err_code = 1) {
        $response = new AA_Response($err, $err_code);
        $response->respond();
    }

    public static function ok($ret) {
        $response = new AA_Response($ret);
        $response->respond();
    }
}

class AA_Request {
    var $command;
    var $params;

    function __construct( $command, $params= []) {
        $this->command = $command;
        $this->params  = $params;
    }

    function _requestVal() {
        return serialize($this);
    }

    function _requestArr() : array {
        return ['request' => $this->_requestVal()];
    }

    function encode4Url() {
        return urlencode(base64_encode($this->_requestVal()));
    }

    /** static member function called like:
     *     $request = AA_Request::decode($_POST['request']);
     **/
    public static function decode(string $posted_data) {
        return unserialize($posted_data);
    }

    function getCommand() {
        return $this->command;
    }

    function getParameters() {
        return $this->params;
    }

    /** Sends request to remote AA
     * @param $url - url of remote AA
     * @param $parameters - optional array of additional url parameters 'k'=>'v'
     * @return AA_Response|mixed
     */
    function ask($url, $parameters= [], $headers= []) {
        $ask_arr = $this->_requestArr();
        if (is_array($parameters)) {
            $ask_arr = array_merge($ask_arr, $parameters);
        }

//       if (!strpos($ask_arr['request'], 'Get_Sessionid')) {
//           $r = unserialize($ask_arr['request']);
//           huhl($ask_arr, unserialize($ask_arr['request']), $r->params['sync'][0], unserialize($r->params['sync'][0]), unserialize(str_replace("'", "\'", $r->params['sync'][0])), $url);
//       }
        $result = AA_Http::postRequest($url, $ask_arr, $headers);

        if ( $result === false ) {
            //echo "<br>Error - response: ". AA_Http::lastErrMsg();
            return new AA_Response('No response recieved ('. AA_Http::lastErr() .' - '. AA_Http::lastErrMsg(). ')', 3);
        }
        $response  = unserialize(trim($result));
        if ( $response === false ) {
            return new AA_Response("Bad response", 3);
        }
        return $response;
    }
}

/** @todo - should be updated for new php5 sessions - Honza 2016-09-29 */
class AA_ClientAuth {
    /** path to AA auth script - like: https://example.org/apc-aa/auth.php */
    var $_aa_responder_script;

    /** time in seconds of session validity. If not set, then the session
     *  is valid just for current browser session, 63072000 for two years */
    var $_cookie_lifetime;

    /** caches remote auth object */
    var $_auth = null;

    protected $_reader_slices = [];

    function __construct($options= []) {
        if (!is_array($options)) {
            $options = [];
        }
        $this->_aa_responder_script = $options['aa_url'] . 'central/responder.php';
        $this->_cookie_lifetime     = isset($options['cookie_lifetime']) ? (time() + $options['cookie_lifetime']) : 0;
        $this->_reader_slices       = $options['reader_slices'] ?? [];
    }

    function checkAuth() {
        // we are trying to login
        $request = new AA_Request('Get_Sessionid');
        $params  = [];
        $headers = [];
        if ($_REQUEST['username']) {
            $params = ['free' => $_REQUEST['username'], 'freepwd' =>$_REQUEST['password']];
        }
        elseif ($_COOKIE['AA_Sess']) {
            $headers = ['Cookie' => 'AA_CP_Session='.$_COOKIE['AA_Sess']];
        }
        else {
            $this->logout();
            return false;
        }

        $response = $request->ask($this->_aa_responder_script, $params, $headers);

        if ( !$response->isError() ) {

            $arr = $response->getResponse();
            $session_id  = $arr[0];
            $myauth      = $arr[1];

            if ($myauth->getUname() AND $myauth->getUid() AND (trim($myauth->getUid()) != 'nobody')) {
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

    function getRemoteAuth() {
        return $this->_auth;
    }

    function logout() {
        $request  = new AA_Request('Logout');
        $params   = ['AA_CP_Session'=>$_COOKIE['AA_Sess']];
        $response = $request->ask($this->_aa_responder_script, $params);

        // both is necessary - one for current page, one for next page
        setcookie('AA_Sess', "", time() - 3600, '/');
        $_COOKIE['AA_Sess'] = '';
        // setcookie('AA_Uid', "", time() - 3600, '/');
        // $_COOKIE['AA_Uid']  = '';
    }
}


