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

ini_set('unserialize_callback_func', 'myccallback');

function myccallback($class) {
    echo "--------unserialize problem:$class:";
    exit;
}

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
     *     $request = AA_Request::decode$_POST['request']);
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

        if (!strpos($ask_arr['request'], 'Get_Sessionid')) {
            $r = unserialize($ask_arr['request']);
            huhl($ask_arr, unserialize($ask_arr['request']), $r->params['sync'][0], unserialize($r->params['sync'][0]), unserialize(str_replace("'", "\'", $r->params['sync'][0])), $url);
        }
        $result = AA_Http::postRequest($url, $ask_arr);

        if (!strpos($ask_arr['request'], 'Get_Sessionid')) {
            huhl('xx', $result);
            exit;
        }
        if ( $result === false ) {
            huhl("<br>Error - response:", AA_Http::lastErrMsg());
            return new AA_Response('No response recieved ('. AA_Http::lastErr() .' - '. AA_Http::lastErrMsg(). ')', 3);
        }
        $response  = unserialize($result);
        if ( $response == false ) {
            huhl("<br>Error - Bad response:", $result ,"<br>on request: $url");
            return new AA_Response("Bad response", 3);
        }
        return $response;
    }
}

?>
