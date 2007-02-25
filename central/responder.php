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
/** Used for autentication
 *  @return Session ID
 */
class AA_Responder_Get_Sessionid extends AA_Object {

    function AA_Responder_Get_Sessionid($param=null) {}

    function run() {
        global $sess;
        return new AA_Response($sess->id);
    }
}

/** @return array of informations about AA - org_name, domaun */
class AA_Responder_Get_Aaname extends AA_Object {
    function AA_Responder_Get_Aaname($param=null) {}

    function run() {
        return new AA_Response(array('org_name'=>ORG_NAME, 'domain'=>AA_HTTP_DOMAIN));
    }
}

/** @return array of informations about AA - org_name, domaun */
class AA_Responder_Get_Slices extends AA_Object {
    function AA_Responder_Get_Slices($param=null) {}

    function run() {
        $slices = GetTable2Array("SELECT id, name FROM module WHERE type = 'S' AND deleted != '1' ORDER BY priority, name", "unpack:id", 'name');
        return new AA_Response($slices);
    }
}

/** @return structure which define all the definition of the slice (like slice 
 *  properties, fields, views, ...). It is returned for all the slices in array
 */
class AA_Responder_Get_Slice_Defs extends AA_Object {
    var $slice_names;
    
    function AA_Responder_Get_Slice_Defs($slice_names) {
        $this->slice_names = is_array($slice_names) ? $slice_names : array(); 
    }

    function run() {
        $ret = array();
        $slice_def = new AA_Slice_Definition();
        foreach ( $this->slice_names as $slice_name ) {
            $slice_def->loadForSliceName($slice_name);
            $ret[$slice_name] = $slice_def;
        }
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
        AA_Response::error("Either your username or your password is not valid.", 1);  // 1 - _m("Either your username or your password is not valid.");
        exit;
    }
}

if (!IsSuperadmin()) {
    AA_Response::error("You don't have permissions to synchronize slices.", 101);  // error code > 0
    exit;
}

$request = unserialize($_POST['request']);
if ( !is_object($request)) {
    AA_Response::error("No request sent for responder.php", 102);  // error code > 0
    exit;
}

$responder = AA_Object::factoryByName('AA_Responder_', $request->getCommand(), $request->getParameters());
if ( empty($responder) ) {
    AA_Response::error("Bad request sent for responder.php", 103);  // error code > 0
    exit;
}

$response = $responder->run();
page_close();

$response->respond();
exit;


?>
