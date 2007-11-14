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

class AA_Request {
    var $command;
    var $params;

    function AA_Request( $command, $params=array()) {
        $this->command = $command;
        $this->params  = $params;
    }

    function _requestVal() {
        return gzencode(serialize($this));
    }

    function _requestArr() {
        return array('request' => $this->_requestVal());
    }
    
    function encode4Url() {
        return urlencode(base64_encode($this->_requestVal()));
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
        $result = HttpPostRequest($url, $ask_arr);
        if (isset($result["errno"])) {
            huhl("<br>Error - response:", $result);
            return new AA_Response('No response recieved ('. $result["errno"] .' - '. $result["errstr"]. ')', 3);
        }
        $response  = unserialize($result[0]);
        if ( $response == false ) {
            huhl("<br>Error - Bad response:", $result ,"<br>on request: $url");
            return new AA_Response("Bad response", 3);
        }
        return $response;
    }
}

?>
