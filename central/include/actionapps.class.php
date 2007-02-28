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

require_once AA_INC_PATH. 'files.class.php3';

/**
 * AA_Actionapps class - holds information about one AA installation
 */
class AA_Actionapps {
    /** url of remote AAs - like "http://example.org/apc-aa/"  */
    var $comunicator_url;       // AA_Searchbar object

    /** username of "access" user
     *  We use the access user acount to get informations about remote AA and
     *  to update the remote AA slices/setting. You should create such user on
     *  remote AA (superuser is enough :-)
     */

    /** username of access user */
    var $access_name;

    /** password of access user */
    var $access_password;

    /** cached remote session ID */
    var $_remote_session_id;

    /** cached remote data - like AA name, ... */
    var $_cached;

    function AA_Actionapps($name, $base_url, $access_name, $access_password) {
        $this->comunicator_url     = Files::makeFile($base_url, 'central/responder.php');
        $this->access_name         = $access_name;
        $this->access_password     = $access_password;
        $this->_remote_session_id  = null;
        $this->_cached             = array();
        // it is possible to get org_name also by asking remote AAs, but this way it is quicker
        $this->_cached['org_name'] = $name;   
    }

    
    /** @return ORG_NAME of remote AAs
     *  Currently this function is not needed, since name of AA is pased 
     *  by constructor (which is much quicker)
     */ 
    function org_name() {
        if ( is_null($this->_cached['org_name'])) {
            $response = $this->getResponse( new AA_Request('Get_Aaname') );
            if ($response->isError()) {
                $this->_cached['org_name'] = _m("Can't get org_name - "). $response->getError();
            } else {
                $response_arr = $response->getResponse();
                $this->_cached['org_name'] = $response_arr['org_name'];
                $this->_cached['domain']   = $response_arr['domain'];
            }
        }
        return $this->_cached['org_name'];
    }
    
    
    /** @return all slice names form remote AA
     *  mention, that the slices are identified by !name! not id for synchronization
     */
    function slices() {
        $response = $this->getResponse( new AA_Request('Get_Slices') );
        if ($response->isError()) {
            return array();
        } else {
            return $response->getResponse();
        }
    }
    
    /** @return structure which define all the definition of the slice 
     *  (like slice properties, fields, views, ...). It is returned for all the
     *  slices in array
     */
    function sliceDefinitions($slice_names) {
        // We will use rather one call which returns all the data for all the 
        // slices, since it is much quicker than separate call for each slice 
        $response = $this->getResponse( new AA_Request('Get_Slice_Defs', array('slice_names'=>$slice_names)) );
        if ($response->isError()) {
            return array();
        } else {
            return $response->getResponse();
        }
    }
    
    /** This commend synchronizes the slices base on sync[] array 
     *  @return the report on the synchronization 
     */
    function synchronize($sync_commands) {
        // We will use rather one call which returns all the data for all the 
        // slices, since it is much quicker than separate call for each slice 
        $response = $this->getResponse( new AA_Request('Do_Synchronize', array('sync'=>$sync_commands)) );
        if ($response->isError()) {
            return array();
        } else {
            return $response->getResponse();
        }
    }

    /** Main communication function - returns AA_Response object */
    function getResponse($request) {
        if ( !$this->_remote_session_id ) {
            $response = $this->_authenticate();
            if ($response->isError()) {
                return $response;
            }
        }
        // _remote_session_id is set
        $url = get_url($this->comunicator_url, array('AA_CP_Session'=>$this->_remote_session_id));
        return AA_Actionapps::_ask($url, $request);
    }

    function _authenticate() {

        $response = AA_Actionapps::_ask(get_url($this->comunicator_url, array('free' => $this->access_name, 'freepwd' =>$this->access_password)), new AA_Request('Get_Sessionid'));
        if ( !$response->isError() ) {
            $this->_remote_session_id = $response->getResponse();
        }
        return $response;
    }

    /// Static methods
    function _ask($url, $request) {
//        huhl($url, $request);
        $result = HttpPostRequest($url, $request->requestArr());
//        huhl($result);
        if (isset($result["errno"])) {
            huhl("<br>Error - response:", $result);
            return new AA_Response('No response recieved ('. $result["errno"] .' - '. $result["errstr"]. ')', 3);
        }
        $response  = unserialize($result[0]);
        if ( $response == false ) {
            huhl("<br>Error - Bad response:", $result ,"<br>on request: $url", $request);
            return new AA_Response("Bad response", 3);
        }
        return $response;
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

class AA_Request {
    var $command;
    var $params;

    function AA_Request( $command, $params=array()) {
        $this->command = $command;
        $this->params  = $params;
    }

    function requestArr() {
        return array('request' => serialize($this));
    }

    function getCommand() {
        return $this->command;
    }

    function getParameters() {
        return $this->params;
    }
}

class AA_Slice_Definition {
    var $slice_data;
    var $fields_data;
    var $views_data;
    var $constants_data;
    var $email_data;
    
    function AA_Slice_Definition() {
        $this->clear();
    }
    
    function clear() {
        $this->slice_data     = array();
        $this->fields_data    = array();
        $this->views_data     = array();
        $this->constants_data = array();
        $this->emails_data    = array();
    }
    
    function loadForSliceName($slice_name) {
        $this->clear();
        $this->slice_data  = GetTable2Array("SELECT * FROM slice WHERE name = '".quote($slice_name)."'", 'aa_first', 'aa_fields');
        $p_slice_id = $this->slice_data['id'];
        if ( empty($p_slice_id)) {
            return false;
        }
        $qp_slice_id = quote($p_slice_id);
        $this->fields_data = GetTable2Array("SELECT * FROM field WHERE slice_id        = '$qp_slice_id'", 'id', 'aa_fields');
        $this->views_data  = GetTable2Array("SELECT * FROM view  WHERE slice_id        = '$qp_slice_id'", 'id', 'aa_fields');
        $this->emails_data = GetTable2Array("SELECT * FROM email WHERE owner_module_id = '$qp_slice_id'", 'id', 'aa_fields');
    }
    
    function getArray() {
        return array ( 'data'      => $this->slice_data,
                       'fields'    => $this->fields_data,   
                       'views'     => $this->views_data,  
                       'emails'    => $this->emails_data,   
                       'constants' => $this->constants_data 
                     );
    }

    function compareWith($dest_def) {
        /** @todo chack the state, when the name contains "->" */
        $slice_name = $dest_def->slice_data['name'];
        $diff =                    AA_Difference::_compareArray($this->slice_data,     $dest_def->slice_data,     $slice_name.'->slice');
        $diff = array_merge($diff, AA_Difference::_compareArray($this->fields_data,    $dest_def->fields_data,    $slice_name.'->field'));
        $diff = array_merge($diff, AA_Difference::_compareArray($this->views_data,     $dest_def->views_data,     $slice_name.'->view'));
        $diff = array_merge($diff, AA_Difference::_compareArray($this->emails_data,    $dest_def->emails_data,    $slice_name.'->email'));
        $diff = array_merge($diff, AA_Difference::_compareArray($this->constants_data, $dest_def->constants_data, $slice_name.'->constant'));
        return $diff;
    }
}

class AA_Difference {
    
    var $description;
    var $type;           // INFO | DIFFERENT | NEW | DELETED
    /** array of AA_Sync_Actions defining, what we can do with this difference */
    var $actions;
       
    /** */
    function AA_Difference($type, $description, $actions=array()) {
        $this->type        = $type;        
        $this->description = $description;
        $this->actions     = empty($actions) ? array() : (is_array($actions) ? $actions : array($actions));        
    }
    
    function printOut() {
        echo "\n<tr><td>". $this->description .'</td><td>';
        foreach ($this->actions as $action) {
            $action->printToForm();
        }
        echo '</td></tr>';
    }
    
    /// Static

    function _compareArray($template_arr, $destination_arr, $name) {
        $diff = array();
        if (! is_array($template_arr) AND is_array($destination_arr)) {
            return array( 0 => new AA_Difference('DELETED', _m('%1 is not array in template slice', array($name)), new AA_Sync_Action('DELETE', $name)));
        }
        if ( is_array($template_arr) AND !is_array($destination_arr)) {
            return array( 0 => new AA_Difference('NEW', _m('%1 is not array in destination slice', array($name)), new AA_Sync_Action('NEW', $name, $template_arr)));
        }
        if ( !is_array($template_arr) AND !is_array($destination_arr)) {
            return array( 0 => new AA_Difference('INFO', _m('%1 is not defined for both AAs', array($name))));
        }
        foreach ($template_arr as $key => $value) {
            if (is_array($value)) {
                $diff = array_merge($diff, AA_Difference::_compareArray($value,$destination_arr[$key], $name."->$key")); 
                // we need to clear the destination array in order we can know, 
                // that there are some additional keys in it (compated to template)
                unset($destination_arr[$key]);
            }
            elseif (!array_key_exists($key,$destination_arr)) {
                $diff[] = new AA_Difference('DIFFERENT', _m('There is no such key (%1) in destination slice for %2', array($key, $name)), new AA_Sync_Action('UPDATE', $name."->$key", $value));
            }
            elseif ($value != $destination_arr[$key]) {
                $code = '{htmltoggle:&gt;&gt;::&lt;&lt;:
                       <div style="background-color#:#FFE0E0;border#: solid 1px #F88;">'.AaSafe(safe($destination_arr[$key])).'</div>
                       <br>
                       <div style="background-color#:#E0E0FF;border#: solid 1px #88F;">'.AaSafe(safe($value)).'</div>}';
                $diff[] = new AA_Difference('DIFFERENT', _m('The value for key %1 in %2 array is different %3', array($key, $name, AA_Stringexpand::unalias($code))), new AA_Sync_Action('UPDATE', $name."->$key", $value));
                // we need to clear the destination array in order we can know, 
                // that there are some additional keys in it (compated to template)
                unset($destination_arr[$key]);
            } else {
                unset($destination_arr[$key]);
            }
        }
        foreach ($destination_arr as $key => $value) {
            // there are no such keys in template 
            if ( is_array($value) ) {
                // I know - we can define the difference right here, but it is better to use the same method as above
                $diff = array_merge($diff, AA_Difference::_compareArray('',$destination_arr[$key], $name."->$key"));
            } else {
                $diff[] = new AA_Difference('DELETED', _m('There is no such key (%1) in template slice for %2', array($key, $name)), new AA_Sync_Action('UPDATE', $name."->$key", ''));
            }
        }
        if ( count($diff) < 1 ) {
            $diff[] = new AA_Difference('INFO', _m('%1 are identical', array($name)));
        }
        return $diff;
    }
}

/** Makes the string safe for AA stringexpand
 *  @todo do it better - it is not functional since the characters are changed
 */
function AaSafe($string) {
    return str_replace(array(':','{','}'), array('#:','#[','#]'), $string);
}

/** Class which defines synchronization actions */
class AA_Sync_Action {
    /** action type  - DELETE | NEW | UPDATE */
    var $type;
    
    /** identifier string (like 'view->678->name') */
    var $identifier;
    
    /** action parameters (field's data). Could be scalar as well as array */
    var $params;
    
    function AA_Sync_Action($type, $identifier, $params=null) {
        $this->type       = $type;
        $this->identifier = $identifier;
        $this->params     = $params;
    }
    
    function printToForm() {
        $packed_action = serialize($this);
        echo '<div>';
        FrmChBoxEasy('sync[]', in_array($packed_action, (array)$_POST['sync']), '', $packed_action);
        switch ( $this->type ) {
            case 'DELETE': echo _m("Delete"); break;
            case 'NEW':    echo _m("Create new"); break;
            case 'UPDATE': echo _m("Update"); break;
        }
        echo '</div>';
    }
    
    /** do synchronization action in destination slice */
    function doAction() {
        /** @todo convert to class variable after move to PHP5 */
        global $slice_id_cache;
        
        $cmd = explode('->', $this->identifier);
        if ( !isset($slice_id_cache[$cmd[0]])) {
            $slice_id_cache[$cmd[0]] = GetTable2Array("SELECT id FROM slice WHERE name='".quote($cmd[0])."'", 'aa_first', 'unpack:id');
        }
        if (!$slice_id_cache[$cmd[0]]) {
            return _m('Slice not found: %1',array($cmd[0]));
        }
        $qp_slice_id = q_pack_id($slice_id_cache[$cmd[0]]);
        
        $varset = new Cvarset();
        switch( $cmd[1] ) {
            case 'slice':
                if ( $this->type == 'UPDATE' ) {
                    $varset->addkey('id', 'quoted', $qp_slice_id);
                    if ( isset($cmd[2]) ) {
                        // single value
                        $varset->add($cmd[2], 'text', $this->params);
                    } else {
                        // whole slice record
                        foreach ( $this->params as $key => $val ) {
                            $varset->add($key, 'text', $val);
                        }
                    }
                    $varset->doUpdate('slice');
                    return _m('Slice %1 updated', array($cmd[0]));
                }
                if ( $this->type == 'NEW' ) {
                    /** @todo Add it to the module table, as well */
                    $varset->addkey('id', 'unpacked', new_id());
                    foreach ( $this->params as $key => $val ) {
                        $varset->add($key, 'text', $val);
                    }
                    $varset->doInsert('slice');
                    return _m('Slice %1 Inserted', array($cmd[0]));
                }
                /** @todo DELETE */
                return _m('Operation not supprted, yet - Slice %1', array($cmd[0]));
            case 'field':
                if (!isset($cmd[2])) {
                    return _m('Operation not supprted, yet - Slice->Field %1->%2', array($cmd[0],$cmd[2]));
                }
                $fid = $cmd[2];
                if ( $this->type == 'UPDATE' ) {
                    $varset->addkey('slice_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id',       'text',   $fid);
                    if ( isset($cmd[3]) ) {
                        // single value
                        $varset->add($cmd[3], 'text', $this->params);
                    } else {
                        // whole slice record
                        foreach ( $this->params as $key => $val ) {
                            $varset->add($key, 'text', $val);
                        }
                    }
                    $varset->doUpdate('field');
                    return _m('Field %1 in slice %2 updated', array($fid, $cmd[0]));
                }
                if ( $this->type == 'NEW' ) {
                    $varset->addkey('slice_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id', 'text', $fid);
                    foreach ( $this->params as $key => $val ) {
                        $varset->add($key, 'text', $val);
                    }
                    $varset->doInsert('field');
                    return _m('Field %1 inserted into slice %2', array($fid, $cmd[0]));
                }
                if ( $this->type == 'DELETE' ) {
                    $varset->addkey('slice_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id',       'text',   $fid);
                    $varset->doDelete('field');
                    return _m('Field %1 deleted from slice %2', array($fid, $cmd[0]));
                }
                return _m("Unknown action (%1) for field %2 in slice %3", array($this->type, $fid, $cmd[0]));
            case 'view':
                if (!isset($cmd[2])) {
                    return _m('Operation not supprted, yet - Slice->View %1->%2', array($cmd[0],$cmd[2]));
                }
                $vid = $cmd[2];
                if ( $this->type == 'UPDATE' ) {
                    $varset->addkey('slice_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id',       'text',   $vid);
                    if ( isset($cmd[3]) ) {
                        // single value
                        $varset->add($cmd[3], 'text', $this->params);
                    } else {
                        // whole slice record
                        foreach ( $this->params as $key => $val ) {
                            $varset->add($key, 'text', $val);
                        }
                    }
                    $varset->doUpdate('view');
                    return _m('View %1 in slice %2 updated', array($vid, $cmd[0]));
                }
                if ( $this->type == 'NEW' ) {
                    $varset->addkey('slice_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id', 'text', $vid);
                    foreach ( $this->params as $key => $val ) {
                        $varset->add($key, 'text', $val);
                    }
                    $varset->doInsert('view');
                    return _m('View %1 inserted into slice %2', array($vid, $cmd[0]));
                }
                if ( $this->type == 'DELETE' ) {
                    $varset->addkey('slice_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id',       'text',   $vid);
                    $varset->doDelete('view');
                    return _m('View %1 deleted from slice %2', array($vid, $cmd[0]));
                }
                return _m("Unknown action (%1) for view %2 in slice %3", array($this->type, $vid, $cmd[0]));
            case 'email':
                if (!isset($cmd[2])) {
                    return _m('Operation not supprted, yet - Slice->Email %1->%2', array($cmd[0],$cmd[2]));
                }
                $emailid = $cmd[2];
                if ( $this->type == 'UPDATE' ) {
                    $varset->addkey('slice_id',        'quoted', $qp_slice_id);
                    $varset->addkey('owner_module_id', 'text',   $emailid);
                    if ( isset($cmd[3]) ) {
                        // single value
                        $varset->add($cmd[3], 'text', $this->params);
                    } else {
                        // whole slice record
                        foreach ( $this->params as $key => $val ) {
                            $varset->add($key, 'text', $val);
                        }
                    }
                    $varset->doUpdate('email');
                    return _m('Email %1 in slice %2 updated', array($emailid, $cmd[0]));
                }
                if ( $this->type == 'NEW' ) {
                    $varset->addkey('slice_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id', 'text', $emailid);
                    foreach ( $this->params as $key => $val ) {
                        $varset->add($key, 'text', $val);
                    }
                    $varset->doInsert('email');
                    return _m('Email %1 inserted into slice %2', array($emailid, $cmd[0]));
                }
                if ( $this->type == 'DELETE' ) {
                    $varset->addkey('slice_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id',       'text',   $emailid);
                    $varset->doDelete('email');
                    return _m('Email %1 deleted from slice %2', array($emailid, $cmd[0]));
                }
                return _m("Unknown action (%1) for email %2 in slice %3", array($this->type, $emailid, $cmd[0]));
            case 'constant':  /** @todo work with constant */ 
        }
        return _m("Unknown action for data %1 in slice %2", array($cmd[1], $cmd[0]));
    }
}

?>
