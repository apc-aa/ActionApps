<?php
/**
 * File contains definition of AA_Actionapps class - holding information about
 * one AA installation.
 *
 * Should be included to other scripts (as /admin/index.php3)
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


if (!function_exists('gzdecode')) {
    function gzdecode($data) {
        $flags = ord(substr($data, 3, 1));
        $headerlen = 10;
        $extralen = 0;
        $filenamelen = 0;
        if ($flags & 4) {
            $extralen = unpack('v' ,substr($data, 10, 2));
            $extralen = $extralen[1];
            $headerlen += 2 + $extralen;
        }
        if ($flags & 8) { // Filename
            $headerlen = strpos($data, chr(0), $headerlen) + 1;
        }
        if ($flags & 16) { // Comment
            $headerlen = strpos($data, chr(0), $headerlen) + 1;
        }
        if ($flags & 2) {// CRC at end of file
            $headerlen += 2;
        }
        $unpacked = gzinflate(substr($data, $headerlen));
        if ($unpacked === FALSE) {
              $unpacked = $data;
        }
        return $unpacked;
     }
}

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
     */
    function postRequest($url, $data = array() ) {
        if (version_compare(phpversion(), "5.0.0", ">=")) {
            return AA_Http::postRequest5($url, $data); // you're on PHP5 or later
        }
        return AA_Http::postRequest4($url, $data); // you're on PHP5 or later
    }

    /** inspired by http://netevil.org/blog/2006/nov/http-post-from-php-without-curl */
    function postRequest5($url, $data) {
        $data = http_build_query($data);
        $params = array('http' => array(
                            'method' => 'POST',
                            'content' => $data
                                        )
                        );
         $ctx = stream_context_create($params);
         $fp = @fopen($url, 'rb', false, $ctx);
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

    /** postRequest function
     *  POST data to the url (using POST request and returns resulted data
     * @param $url
     * @param $data
     * @return array $result[]
     */
    function postRequest4($url, $data = array() ) {
        $request = parse_url($url);

        $host = $request['host'];
        $uri  = $request['path']. (empty($request['query']) ? '' : '?'.$request['query']);

        $reqbody = "";
        foreach($data as $key=>$val) {
            if (!empty($reqbody)) {
                $reqbody.= "&";
            }
            $reqbody.= $key."=".rawurlencode($val);
        }

        $contentlength = strlen($reqbody);
        $reqheader =  "POST $uri HTTP/1.1\r\n".
                      "Host: $host\n". "User-Agent: ActionApps\r\n".
                      "Content-Type: application/x-www-form-urlencoded\r\n".
                      "Content-Length: $contentlength\r\n\r\n".
                      "$reqbody\r\n";

        $socket = fsockopen($host, 80, $errno, $errstr);

        if (!$socket) {
            AA_Http::lastErr($errno, $errstr);  // set error code
            return false;
        }

        fputs($socket, $reqheader);

        $responseHeader = '';
        $responseContent = '';

        do {
            $responseHeader.= fread($socket, 1);
        } while (!preg_match('/\\r\\n\\r\\n$/', $responseHeader));

        if (!strstr($responseHeader, "Transfer-Encoding: chunked")) {
            while (!feof($socket)) {
                $responseContent.= fgets($socket, 128);
            }
        } else {
            while ($chunk_length = hexdec(fgets($socket))) {
                $responseContentChunk = '';
                $read_length = 0;

                while ($read_length < $chunk_length) {
                    $responseContentChunk .= fread($socket, $chunk_length - $read_length);
                    $read_length = strlen($responseContentChunk);
                }

                $responseContent.= $responseContentChunk;

                fgets($socket);
            }
        }
        return $responseContent;
    }
}

class AA_Response {
    var $response;
    var $error;

    function AA_Response($response = null, $error = 0) {
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
        echo serialize($this);
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

// ini_set('unserialize_callback_func', 'myccallback');
//
// function myccallback($class) {
//     echo "--------unserialize problem:$class:";
//     exit;
// }

class AA_Request {
    var $command;
    var $params;

    function AA_Request( $command, $params=array()) {
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

//        if (!strpos($ask_arr['request'], 'Get_Sessionid')) {
//            huhl('xx', $result);
//            exit;
//        }
        if ( $result === false ) {
            echo "<br>Error - response: ". AA_Http::lastErrMsg();
            return new AA_Response('No response recieved ('. AA_Http::lastErr() .' - '. AA_Http::lastErrMsg(). ')', 3);
        }
        $response  = unserialize($result);
        if ( $response == false ) {
            echo "<br>Error - Bad response on request: $url:";
            print_r($result);
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

    function AA_Client_Auth($options=array()) {
        if (!is_array($options)) {
            $options = array();
        }
        $this->_aa_responder_script = $options['aa_url'] . 'central/responder.php';
        $this->_cookie_lifetime    = isset($options['cookie_lifetime']) ? (time() + $options['cookie_lifetime']) : 0;
    }

    function checkAuth() {
        // we are trying to login
        $request  = new AA_Request('Get_Sessionid');
        if ($_POST['username']) {
            $params = array('free' => $_POST['username'], 'freepwd' =>$_POST['password']);
        }
        elseif ($_COOKIE['AA_Sess']) {
            $params = array('AA_CP_Session'=>$_COOKIE['AA_Sess']);
        }
        else {
            return false;
        }

        $response = $request->ask($this->_aa_responder_script, $params);

        if ( !$response->isError() ) {
            $session_id = $response->getResponse();
            $x = setcookie('AA_Sess', $session_id, $this->_cookie_lifetime, '/');
            $_COOKIE['AA_Sess'] = $session_id;
            if ($_POST['username']) {
                $y = setcookie('AA_Uid', $_POST['username'], $this->_cookie_lifetime, '/');
                $_COOKIE['AA_Uid']  = $_POST['username'];  // we need it for current page as well
            }
            return true;
        }
        return false;
    }

    function getUid() {
        return isset($_COOKIE['AA_Uid']) ? $_COOKIE['AA_Uid'] : false;
    }

    function logout() {
        // both is necessary - one for current page, one for next page
        setcookie('AA_Sess', "", time() - 3600, '/');
        $_COOKIE['AA_Sess'] = '';
        setcookie('AA_Uid', "", time() - 3600, '/');
        $_COOKIE['AA_Uid']  = '';
    }
}


?>
