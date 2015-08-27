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
 *   AA_Client_Auth - for client authentization (@see /doc/script/example_auth)
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
http://www.apc.org/

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
    /** lastErr function
     *  Method returns or sets last file error
     *  The trick for static class variables is used
     * @param $err_id
     * @param $err_msg
     * @param $getmsg
     */
    function lastErr($err_id = null, $err_msg = null, $getmsg = false) {
        static $lastErr;
        static $lastErrMsg;
        if (!is_null($err_id)) {
            $lastErr    = $err_id;
            $lastErrMsg = $err_msg;
        }
        return $getmsg ? $lastErrMsg : $lastErr;
    }

    /** lastErrMsg function
     *  Return last error message - it is grabbed from static variable
     *  of lastErr() method
     */
    function lastErrMsg() {
        return AA_Http::lastErr(null, null, true);
    }

    /** Move to another page
     *  new version of go_ur() - could use POST redirect
     *  static function - called like AA_Http::go("http://ecn.cz", array('a'=>'my string'), 'POST')
     *  @param url - destination url
     *  @param parameters = array('a' => 1, 'b' => 'OK boy')
     *  @param type  - preffered type. Could be GET or POST, but if headers
     *                 are already sent, then we use javascript for redirection
     *  @param sess_close - try to close session, if the session is set
     *                      we do not want to try it, when database connection
     *                      error ocures, for example
     **/
    function go($url, $parameters, $type='GET', $sess_close=true) {
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
    function _goJs($url, $parameters='') {
        $url = get_url( $url, $parameters);
        echo '
        <script language="JavaScript" type="text/javascript"> <!--
            document.location = "'.$url.'";
          //-->
        </script>
        ';
        exit;
    }


    /** postRequest function
     *  POST data to the url (using POST request and returns resulted data
     * @param $url
     * @param $data
     * @return array $result[]
     * inspired by http://netevil.org/blog/2006/nov/http-post-from-php-without-curl
     */
    function postRequest($url, $data = array(), $headers=array() ) {

        if (empty($data)) {
            $fp = @fopen($url, 'rb', false);
        } else {
            $data = http_build_query($data);
            $params = array('http' => array(
                                'method' => 'POST',
                                'content' => $data
                                           )
                           );
            if (!empty($headers)) {
                $header = '';
                foreach ($headers as $k => $v) {
                    $header .= "$k: $v\r\n";
                }
                $params['http']['header'] = $header;
            }
            $ctx = stream_context_create($params);
            $fp  = @fopen($url, 'rb', false, $ctx);
        }

        if (!$fp) {
           AA_Http::lastErr(1, "Can't open url: $url");  // set error code
           return false;
        }
        $response = @stream_get_contents($fp);
        if ($response === false) {
           AA_Http::lastErr(2, "Problem reading data from url: $url");  // set error code
           return false;
        }
        return $response;
    }
}

class AA_Response {
    var $response;
    var $error;

    static $Response_type = 'serialize';

    function __construct($response = null, $error = 0) {
        $this->response = $response;
        $this->error    = $error;
    }

    function getResponse() {
        return $this->response;
    }

    function getError() {
        return $this->response;
    }

    function isError() {
        return $this->error;
    }

    function respond() {
        switch(AA_Response::$Response_type) {
          case 'serialize': echo serialize($this);
                            break;
          case 'html':      if ($this->isError()) {
                                echo "Error $this->error: ". $this->response;
                            } else {
                                echo is_scalar($this->response) ? $this->response : _m('Array returned');
                            }
        }
        return;
    }

    /// Static functions
    function error($err) {
        $response = new AA_Response(null, $err);
        $response->respond();
    }

    function ok($ret) {
        $response = new AA_Response($ret);
        $response->respond();
    }
}

class AA_Request {
    var $command;
    var $params;

    function __construct( $command, $params=array()) {
        $this->command = $command;
        $this->params  = $params;
    }

    function _requestVal() {
        return serialize($this);
    }

    function _requestArr() {
        return array('request' => $this->_requestVal());
    }

    function encode4Url() {
        return urlencode(base64_encode($this->_requestVal()));
    }

    /** static member function called like:
     *     $request = AA_Request::decode($_POST['request']);
     **/
    function decode($posted_data) {
        return unserialize($posted_data);
    }

    function getCommand() {
        return $this->command;
    }

    function getParameters() {
        return $this->params;
    }

    /** Sends request to remote AA
     *  @param $url - url of remote AA
     *  @param $parameters - optional array of additional url parameters 'k'=>'v'
     */
    function ask($url, $parameters=array()) {
        $ask_arr = $this->_requestArr();
        if (is_array($parameters)) {
            $ask_arr = array_merge($ask_arr, $parameters);
        }

//       if (!strpos($ask_arr['request'], 'Get_Sessionid')) {
//           $r = unserialize($ask_arr['request']);
//           huhl($ask_arr, unserialize($ask_arr['request']), $r->params['sync'][0], unserialize($r->params['sync'][0]), unserialize(str_replace("'", "\'", $r->params['sync'][0])), $url);
//       }
        $result = AA_Http::postRequest($url, $ask_arr);

//        print_r($ask_arr);
//        print_r($result);
//        exit;
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


class AA_Client_Auth {
    /** path to AA auth script - like: http://example.org/apc-aa/auth.php */
    var $_aa_responder_script;

    /** time in seconds of session validity. If not set, then the session
     *  is valid just for current browser session, 63072000 for two years */
    var $_cookie_lifetime;

    /** caches remote auth object */
    var $_auth = null;

    protected $_reader_slices = array();

    function __construct($options=array()) {
        if (!is_array($options)) {
            $options = array();
        }
        $this->_aa_responder_script = $options['aa_url'] . 'central/responder.php';
        $this->_cookie_lifetime     = isset($options['cookie_lifetime']) ? (time() + $options['cookie_lifetime']) : 0;
        $this->_reader_slices       = isset($options['reader_slices'])   ? $options['reader_slices'] : array();
    }

    function checkAuth() {
        // we are trying to login
        $request  = new AA_Request('Get_Sessionid');
        if ($_REQUEST['username']) {
            $params = array('free' => $_REQUEST['username'], 'freepwd' =>$_REQUEST['password']);
        }
        elseif ($_COOKIE['AA_Sess']) {
            $params = array('AA_CP_Session'=>$_COOKIE['AA_Sess']);
        }
        else {
            $this->logout();
            return false;
        }

        $response = $request->ask($this->_aa_responder_script, $params);

        if ( !$response->isError() ) {

            $arr = $response->getResponse();
            $session_id  = $arr[0];
            $myauth      = $arr[1];

            if ($myauth->auth['uname'] AND $myauth->auth['uid'] AND (trim($myauth->auth['uid']) != 'nobody')) {
                $x                  = setcookie('AA_Sess', $session_id, $this->_cookie_lifetime, '/');
                $y                  = setcookie('AA_Uid', $myauth->auth['uname'], $this->_cookie_lifetime, '/');
                $_COOKIE['AA_Sess'] = $session_id;
                $_COOKIE['AA_Uid']  = $myauth->auth['uname'];  // we need it for current page as well
                $this->_auth        = $myauth;
                return true;
            }
        }
        $this->logout();
        return false;
    }

    function getUid() {
        return isset($_COOKIE['AA_Uid']) ? $_COOKIE['AA_Uid'] : false;
    }

    function getRemoteAuth() {
        return $this->_auth;
    }

    function logout() {
        $request  = new AA_Request('Logout');
        $params   = array('AA_CP_Session'=>$_COOKIE['AA_Sess']);
        $response = $request->ask($this->_aa_responder_script, $params);

        // both is necessary - one for current page, one for next page
        setcookie('AA_Sess', "", time() - 3600, '/');
        $_COOKIE['AA_Sess'] = '';
        setcookie('AA_Uid', "", time() - 3600, '/');
        $_COOKIE['AA_Uid']  = '';
    }
}

?>
