<?php
/**
 *
 * PHP versions 4 and 5
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
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

class AA_RemoteResponse {
    var $response;
    var $error;
    /** AA_RemoteResponse function
     * @param $response
     * @param $error
     */
    function AA_RemoteResponse($response = null, $error = 0) {
        $this->response = $response;
        $this->error    = $error;
    }
    /** get function
     *
     */
    function get() {
        return $this->response;
    }
    /** error function
     *
     */
    function error() {
        return $this->error;
    }
    /** respond function
     *
     */
    function respond() {
        echo serialize($this);
    }
}

class AA_RemoteCommunicator {
    /** Remote script for communication */
    var $url;

    var $_remote_session_id;
    /** AA_RemoteCommunicator function
     * @param $url
     */
    function AA_RemoteCommunicator($url) {
        $this->url = $url;
        $this->_remote_session_id = null;
    }
    /** authenticate function
     * @param $username
     * @param $password
     */
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
    /** run function
     *
     */
    function run() {
        global $sess;
        return new AA_RemoteResponse($sess->id); //$sess->get_id() doesn't work
    }
}

?>
