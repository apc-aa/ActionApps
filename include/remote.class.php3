<?php
//$Id$
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

class AA_RemoteResponse {
    var $response;
    var $error;
    
    function AA_RemoteResponse($response = null, $error = 0) {
        $this->response = $response;
        $this->error    = $error;
    }
    
    function get() {
        return $this->response;
    }
    
    function error() {
        return $this->error;
    }

    function respond() {
        echo serialize($this);
    }
}

class AA_RemoteCommunicator {
    /** Remote script for communication */
    var $url;
    
    var $_remote_session_id;
    
    function AA_RemoteCommunicator($url) {
        $this->url = $url;
        $this->_remote_session_id = null;
    }
    
    function authenticate($username, $password) {
        $filename = get_url($this->url, array('free='.$username, 'freepwd='.$password, 'cmd=GetSessionId'));
        $response_text = file_get_contents(get_url($this->url, array('free='.rawurlencode($username), 'freepwd='.rawurlencode($password), 'cmd=GetSessionId')));
        huhl($response_text);
        $response      = unserialize($response_text);
        if ( $response == false ) {
            return false;
        }
        if ( $response->error() ) {
            return false;
        }
        $this->_remote_session_id = $response->get();
        return true;
    }
    
/*    function recieve() {
        echo serialize($this);
    }
    
    function recieve() {
        $response_text = file_get_contents(get_url($this->url, array('free='.$username, 'freepwd='.$password, 'cmd=GetSessionId')));
        echo serialize($this);
    }
*/
}

class AA_Remote {
    function run() {}
}

class AA_Remote_GetSessionId {
    function run() {
        global $sess;
        return new AA_RemoteResponse($sess->id); //$sess->get_id() doesn't work 
    }
}

?>
