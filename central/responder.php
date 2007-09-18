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

// anonymous authentication - locauth calls extauthnobody
if ($free) {
    $nobody = true;
}

require_once dirname(__FILE__). "/include/init_central.php";

/** AA_Responder base class - defines some useful common methods
 */
class AA_Responder extends AA_Object {

    function run()    { return new AA_Response();}
    
    /** by default only superadmins are allowed to perform remote operations */
    function isPerm() { return IsSuperadmin(); }
    
    function name()   { return str_replace('AA_Responder_', '', __CLASS__); }
}



/** Used for autentication
 *  @return Session ID
 */
class AA_Responder_Get_Sessionid extends AA_Responder {

    function AA_Responder_Get_Sessionid($param=null) {}

    function run() {
        global $sess;
        return new AA_Response($sess->id);
    }
}

/** @return array of informations about AA - org_name, domaun */
class AA_Responder_Get_Aaname extends AA_Responder {
    function AA_Responder_Get_Aaname($param=null) {}

    function run() {
        return new AA_Response(array('org_name'=>ORG_NAME, 'domain'=>AA_HTTP_DOMAIN));
    }
}

/** @return array of informations about AA - org_name, domain */
class AA_Responder_Get_Slices extends AA_Responder {
    function AA_Responder_Get_Slices($param=null) {}

    function run() {
        $slices = GetTable2Array("SELECT id, name FROM module WHERE type = 'S' AND deleted != '1' ORDER BY priority, name", "unpack:id", 'name');
        return new AA_Response($slices);
    }
}

/** @return structure which define all the definition of the slice (like slice 
 *  properties, fields, views, ...). It is returned for all the slices in array
 */
class AA_Responder_Get_Slice_Defs extends AA_Responder {
    var $slice_names;
    var $complete;
    
    function AA_Responder_Get_Slice_Defs($param) {
        $this->slice_names = is_array($param['slice_names']) ? $param['slice_names'] : array(); 
        $this->complete    = (bool)$param['complete']; 
    }

    function run() {
        $ret = array();
        foreach ( $this->slice_names as $slice_name ) {
            $ret[$slice_name] = new AA_Slice_Definition();
            $ret[$slice_name]->loadForSliceName($slice_name, $this->complete);
        }
        return new AA_Response($ret);
    }
}

/** @return structure which define all the definition of the slice (like slice 
 *  properties, fields, views, ...). It is returned for all the slices in array
 */
class AA_Responder_Do_Synchronize extends AA_Responder {
    var $sync_commands;
    
    function AA_Responder_Do_Synchronize($param) {
        $this->sync_commands = is_array($param['sync']) ? $param['sync'] : array(); 
    }

    function run() {
        $ret = array();
        $slice_id_cache = array();
        foreach ( $this->sync_commands as $serialized_command ) {
            $cmd = unserialize($serialized_command);
            $ret[] = $cmd->doAction();
        }
        return new AA_Response($ret);
    }
}

/** @return imports the slice to the database */
class AA_Responder_Do_Import_Slice extends AA_Responder {
    var $slice_def;
    
    function AA_Responder_Do_Import_Slice($param) {
        $this->slice_def = $param['slice_def']; 
    }

    function run() {
        $ret[] = $this->slice_def->importSlice();
        return new AA_Response($ret);
    }
}

page_open(array("sess" => "AA_CP_Session", "auth" => "AA_CP_Auth"));

// anonymous login
if ($nobody) {
    $username = $free;
    $password = $freepwd;
    $auth->auth["uid"] = $auth->auth_validatelogin();
    if ( !$auth->auth["uid"] ) {
        AA_Response::error(_m("Either your username or your password is not valid."), 1);  // 1 - _m("Either your username or your password is not valid.");
        exit;
    }
}

$request = null;

// we use primarily POST, but manager class actions needs to send GET request
if ( $_POST['request'] ) {
    $request = unserialize($_POST['request']);
}

if ( !is_object($request)) {
    AA_Response::error(_m("No request sent for responder.php"), 102);  // error code > 0
    exit;
}

$responder = AA_Object::factoryByName('AA_Responder_', $request->getCommand(), $request->getParameters());
if ( empty($responder) ) {
    AA_Response::error(_m("Bad request sent for responder.php". $request->getCommand()), 103);  // error code > 0
    exit;
}

if (!$responder->isPerm()) {
    AA_Response::error(_m("You don't have permissions to run %1.", array($responder->name())), 101);  // error code > 0
    exit;
}

$response = $responder->run();
page_close();

$response->respond();
exit;


?>
