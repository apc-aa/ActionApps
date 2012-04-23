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

    /** every authenticated user can get his/her session_id */
    function isPerm() { return true; }

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
class AA_Responder_Get_Modules extends AA_Responder {
    /** array of module types to get */
    var $types;

    function AA_Responder_Get_Modules($param=null) {
        $this->types = is_array($param['types']) ? $param['types'] : array();
    }

    function run() {
        $type_sql = (count($this->types) == 0) ? '' : CVarset::sqlin('type', $this->types) . ' AND ';
        $modules  = GetTable2Array("SELECT id, name FROM module WHERE $type_sql deleted != '1' ORDER BY type, priority, name", "unpack:id", 'name');
        return new AA_Response($modules);
    }
}

/** @return structure which define all the definition of the slice (like slice
 *  properties, fields, views, ...). It is returned for all the slices in array
 */
class AA_Responder_Get_Module_Defs extends AA_Responder {
    var $ids;
    var $limited;  // array - limits the sended definitions
    var $type;     // module type

    function AA_Responder_Get_Module_Defs($param) {
        $this->ids     = is_array($param['ids']) ? $param['ids'] : array();
        $this->limited = is_array($param['limited']) ? $param['limited'] : array();
        $this->type    = $param['type'];
    }

    function run() {
        $ret        = array();
        $class_name = 'AA_Module_Definition_'. $this->type;
        foreach ( $this->ids as $sid ) {
            $ret[$sid] = new $class_name;
            $ret[$sid]->loadForId($sid, $this->limited);
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
class AA_Responder_Do_Import_Module_Chunk extends AA_Responder {
    var $definition_chunk;

    function AA_Responder_Do_Import_Module_Chunk($param) {
        $this->definition_chunk = $param['definition_chunk'];
    }

    function run() {
        AA_Log::write('IMPORT', serialize($this->definition_chunk), 'start');
        $ret[] = $this->definition_chunk->importModuleChunk();
        AA_Log::write('IMPORT', $ret, 'result');
        return new AA_Response($ret);
    }
}

/** @return html widget for the field of item */
class AA_Responder_Get_Widget extends AA_Responder {
    var $field_id;
    var $item_id;
    
    function AA_Responder_Get_Widget($param=null) {
        $this->field_id   = $param['field_id'];
        $this->item_id    = $param['item_id'];
    }

    function isPerm() { return true; }

    function run() {

        $item        = AA_Item::getItem(new zids($this->item_id));
        $iid         = $item->getItemID();
        $slice       = AA_Slices::getSlice($item->getSliceId());
    
        // Use right language (from slice settings) - languages are used for button texts, ...
        $lang        = $slice->getLang();
        mgettext_bind($lang, 'output');
    
        $widget_html = $slice->getWidgetAjaxHtml($this->field_id, $iid);
    
        $encoder = new ConvertCharset;
        $ret     =  $encoder->Convert($widget_html, $slice->getCharset(), 'utf-8');        
        return new AA_Response($ret);
    }
}


/** @return html selectbox of fields in given slice */
class AA_Responder_Get_Fields extends AA_Responder {
    /** array of module types to get */
    var $slice_id;
    var $slice_fields;  // bool

    function AA_Responder_Get_Fields($param=null) {
        $this->slice_id     = $param['slice_id'];
        $this->slice_fields = $param['slice_fields'] ? true : false;
    }

    function isPerm() { return IfSlPerm(PS_FIELDS); }

    function run() {
        require_once AA_INC_PATH."convert_charset.class.php3";
        $encoder = new ConvertCharset;

        // AA_Core_Fields.. holds also templates for special slice fields, like _upload_url.....
        // and we do not want to list it as option for normal fields
        $SQL            = "SELECT * FROM field  WHERE slice_id='".q_pack_id($this->slice_id)."' AND (in_item_tbl = '')". ($this->slice_fields ? " AND (id NOT LIKE '\_%')" : ''). ' ORDER BY name';
        $current_types  = GetTable2Array($SQL);
        $ret            = "\n <select name=\"ftype\">";
        foreach ( $current_types as $k => $v) {
            $ret .=  "\n  <option value=\"". $this->slice_id .'-'. htmlspecialchars($k).'"> '. htmlspecialchars($v['name']) ." </option>";
        }
        $ret           .= "\n </select>";
        $slice          = AA_Slices::getSlice($this->slice_id);
        $ret            = $encoder->Convert($ret, $slice->getCharset(), 'utf-8');
        return new AA_Response($ret);
    }
}

page_open(array("sess" => "AA_CP_Session", "auth" => "AA_CP_Auth"));

// anonymous login
if ($nobody) {
    $_POST['username'] = $free;
    $_POST['password'] = $freepwd;
    $auth->auth["uid"] = $auth->auth_validatelogin();
    if ( !$auth->auth["uid"] ) {
        AA_Response::error(_m("Either your username or your password is not valid."), 1);  // 1 - _m("Either your username or your password is not valid.");
        exit;
    }
}

$request = null;

// we use primarily POST, but manager class actions needs to send GET request
if ( $_POST['request'] ) {
    $request = AA_Request::decode($_POST['request']);
} elseif ($_GET['command']) {  // ajax call (html output expected)

    // switch responses to plain output
    AA_Response::$Response_type = 'html';

    $request = new AA_Request($_GET['command'], $_POST);  // params are in $_POST for ajax
}

if ( !is_object($request)) {
    AA_Response::error(_m("No request sent for responder.php"), 102);  // error code > 0
    exit;
}

$responder = AA_Object::factoryByName('AA_Responder_', $request->getCommand(), $request->getParameters());
if ( empty($responder) ) {
    AA_Response::error(_m("Bad request sent for responder.php - %1", array($request->getCommand())), 103);  // error code > 0
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
