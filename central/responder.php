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
if ($_REQUEST['free']) {   // can't be just POST - we use it in live Tags, which sends GET request
    $nobody = true;
}

require_once __DIR__. "/include/init_central.php";

/** AA_Responder base class - defines some useful common methods
 */
class AA_Responder extends AA_Object {

    function run()    { return new AA_Response();}

    /** by default only superadmins are allowed to perform remote operations */
    function isPerm() { return IsSuperadmin(); }

    function name()   { return str_replace('AA_Responder_', '', get_class($this)); }
}

/** Used for autentication
 *  @return Session ID
 */
class AA_Responder_Get_Sessionid extends AA_Responder {

    function __construct($param=null) {}

    /** every authenticated user can get his/her session_id */
    function isPerm() { return true; }

    function run() {
        global $sess, $auth;
        return new AA_Response(array($sess->id, $auth));
    }
}

/** Used for autentication
 *  @return Session ID
 */
class AA_Responder_Logout extends AA_Responder {

    function __construct($param=null) {}

    /** every authenticated user can get his/her session_id */
    function isPerm() { return true; }

    function run() {
        global $sess;
        $ret = array('sessid'=>$sess->id, 'command' => $GLOBALS['request']->getCommand());
        if (is_object($sess)) {
            $sess->delete();
        }
        return new AA_Response($ret);
    }
}

/** @return array of informations about AA - org_name, domaun */
class AA_Responder_Get_Aaname extends AA_Responder {
    function __construct($param=null) {}

    function run() {
        return new AA_Response(array('org_name'=>ORG_NAME, 'domain'=>AA_HTTP_DOMAIN));
    }
}

/** @return array of informations about AA - org_name, domain */
class AA_Responder_Get_Modules extends AA_Responder {
    /** array of module types to get */
    var $types;

    function __construct($param=null) {
        $this->types = is_array($param['types']) ? $param['types'] : array();
    }

    function run() {
        $type_sql = (count($this->types) == 0) ? '' : CVarset::sqlin('type', $this->types) . ' AND ';
        $modules  = GetTable2Array("SELECT id, name FROM module WHERE $type_sql deleted=0  ORDER BY type, priority, name", "unpack:id", 'name');
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

    function __construct($param) {
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

    function __construct($param) {
        $this->sync_commands = is_array($param['sync']) ? $param['sync'] : array();
    }

    function run() {
        $ret = array();
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

    function __construct($param) {
        $this->definition_chunk = $param['definition_chunk'];
    }

    function run() {
        AA_Log::write('IMPORT', serialize($this->definition_chunk), 'start');
        $ret = $this->definition_chunk->importModuleChunk();
        AA_Log::write('IMPORT', $ret, 'result');
        return new AA_Response($ret);
    }
}

/** @return html widget for the field of item */
class AA_Responder_Get_Widget extends AA_Responder {
    var $field_id;
    var $item_id;
    var $widget_type;
    var $widget_properties;

    function __construct($param=null) {
        $this->field_id             = $param['field_id'];
        $this->item_id              = $param['item_id'];
        $this->widget_type          = $param['widget_type'];
        $this->widget_properties    = $param['widget_properties'];
    }

    function isPerm() { return true; }  //return true;

    function run() {

        $item         = AA_Item::getItem(new zids($this->item_id));
        $iid          = $item->getItemID();
        $slice        = AA_Slice::getModule($item->getSliceID());

        // Use right language (from slice settings) - languages are used for button texts, ...
        $lang         = $slice->getLang();
        mgettext_bind($lang, 'output');
        AA::$lang     = strtolower(substr($lang,0,2));   // actual language - two letter shortcut cz / es / en
        AA::$langnum  = array(AA_Content::getLangNumber(AA::$lang));   // array of prefered languages in priority order.
        AA::$encoding = AA_Langs::getCharset($lang);

        $field = $slice->getField($this->field_id);
        $widget_html = $field ? $field->getWidgetAjaxHtml($iid, null, null, $this->widget_type, json2asoc($this->widget_properties)) : '';
        return new AA_Response(ConvertCharset::singleton()->Convert($widget_html, $slice->getCharset(), 'utf-8'));
    }
}

///** @return html widget for the field of item */
//class AA_Responder_Get_Hco_Widget_Options extends AA_Responder {
//    var $field_id;
//    var $slice_id;
//    var $value;
//
//    function __construct($param=null) {
//        $this->field_id = $param['field_id'];
//        $this->slice_id = $param['slice_id'];
//        $this->value    = $param['value'];
//    }
//
//    function isPerm() { return true; }
//
//    function run() {
//
//        $slice       = AA_Slice::getModule($this->slice_id);
//
//        // Use right language (from slice settings) - languages are used for button texts, ...
//        $lang        = $slice->getLang();
//        mgettext_bind($lang, 'output');
//
//        $field  = $slice->getField($this->field_id);
//        $widget = $field ? $field->getWidget() : '';
//
//        $ret = $widget->getOptions4Value($this->value);
//
//        $encoder = new ConvertCharset;
//        $ret     =  $encoder->Convert($ret, $slice->getCharset(), 'utf-8');
//        return new AA_Response($ret);
//    }
//}


/** @return html selectbox of fields in given slice */
class AA_Responder_Get_Fields extends AA_Responder {
    /** array of module types to get */
    var $slice_id;
    var $slice_fields;  // bool

    function __construct($param=null) {
        $this->slice_id     = $param['slice_id'];
        $this->slice_fields = $param['slice_fields'] ? true : false;
    }

    function isPerm() { return IfSlPerm(PS_FIELDS); }

    function run() {
        $encoder = new ConvertCharset;

        // AA_Core_Fields.. holds also templates for special slice fields, like _upload_url.....
        // and we do not want to list it as option for normal fields
        $SQL            = "SELECT * FROM field  WHERE slice_id='".q_pack_id($this->slice_id)."' AND (in_item_tbl = '')". ($this->slice_fields ? " AND (id NOT LIKE '\_%')" : ''). ' ORDER BY name';
        $current_types  = GetTable2Array($SQL);
        $ret            = "\n <select name=\"ftype\">";
        foreach ( $current_types as $k => $v) {
            $ret .=  "\n  <option value=\"". $this->slice_id .'-'. myspecialchars($k).'"> '. myspecialchars($v['name']) ." </option>";
        }
        $ret           .= "\n </select>";
        $slice          = AA_Slice::getModule($this->slice_id);
        $ret            = $encoder->Convert($ret, $slice->getCharset(), 'utf-8');
        return new AA_Response($ret);
    }
}

/** @return html selectbox of fields in given slice */
class AA_Responder_Tags extends AA_Responder {
    /** array of module types to get */
    var $slice_id;
    var $input;
    var $field_id;

    function  __construct($param=null) {
        // the slice id and the field id defines the field, where the widget is DEFINED
        $this->slice_id = $param['s'];
        $this->field_id = $param['f'];
        $this->input    = $param['q'];
    }

    function isPerm() { return true; }

    function run() {
        $ret    = array();
        $trimed = trim($this->input);
        $field  = AA_Slice::getModule($this->slice_id)->getField($this->field_id);
        $found  = false;
        if ($field AND $trimed) {
            $widget = $field->getWidget();
            $opts   = $widget->getFormattedOptions(null, false, $this->input);
            $i=6;
            foreach ($opts as $id => $text) {
                if (trim($text)==trim($this->input)) {
                    // to the front
                    array_unshift($ret, array('id'=>$id, 'text'=>$text));
                    $found = true;
                } else {
                    $ret[] = array('id'=>$id, 'text'=>$text);
                }
                if (!(--$i)) {
                    break;
                }
            }
        }
        if ($trimed AND !$found) {
            array_unshift($ret, array('id'=>$this->input, 'text'=>$this->input));
        }
        header("Content-type: application/json");
        return new AA_Response(json_encode($ret));
    }
}

/** @return html selectbox of fields in given slice */
class AA_Responder_Widget_Selection extends AA_Responder {
    /** array of module types to get */
    var $slice_id;
    var $input;
    var $field_id;

    function  __construct($param=null) {
        // the slice id and the field id defines the field, where the widget is DEFINED
        $this->slice_id = $param['s'];
        $this->field_id = $param['f'];
        $this->input    = $param['q'];
    }

    function isPerm() { return true; }

    function run() {
        $ret   = '';
        $slice = AA_Slice::getModule($this->slice_id);
        if ($slice AND ($widget = $slice->getWidget($this->field_id))) {
            $ret = $widget->getFilterSelection($this->input);
        }
        return new AA_Response(ConvertCharset::singleton()->Convert($ret, $slice->getCharset(), 'utf-8'));
    }
}

/** @return sends one item in mail to alert irregular readers collection */
class AA_Responder_Alertitem extends AA_Responder {
    /** array of module types to get */
    protected $item_id;
    protected $collection_id;
    protected $test_email;

    function  __construct($param=null) {
        // the slice id and the field id defines the field, where the widget is DEFINED
        $this->item_id       = $param['item_id'];
        $this->collection_id = $param['collection_id'];
        $this->test_email    = strpos($param['test_email'],'@') ? array($param['test_email']) : array();
    }

    function isPerm() {
        if (is_long_id($this->item_id)) {
            if ($item = AA_Items::getItem(new zids($this->item_id, 'l'))) {
                // @todo should we check also collection_id???
                return IfSlPerm(PS_EDIT_ALL_ITEMS, $item->getSliceID());
            }
        }
        return false;
    }

    function run() {
        $ret = '0';
        if (is_long_id($this->item_id) AND (strlen($this->collection_id)==5)) {
            //initialize_last();
            //set_time_limit(600); // This can take a while
            $ret = send_emails("irregular", array($this->collection_id), $this->test_email, true, $this->item_id);
            // We must reset moved2active so that the item is not re-sent on update.
            //$db->query ("UPDATE item SET moved2active = 0 WHERE id='" .q_pack_id($item_id)."'");    }
        }
        return new AA_Response($ret);
    }
}

pageOpen('nobody');

// in order {xuser:id} alias wors in widgets, for example
$GLOBALS['apc_state']['xuser'] = $auth->auth["uname"];

$request = null;

// we use primarily POST, but manager class actions needs to send GET request
if ( $_POST['request'] ) {
    $request = AA_Request::decode($_POST['request']);
} elseif ($_GET['command']) {  // ajax call (html output expected)

    // switch responses to plain output
    AA_Response::$Response_type = 'html';

    $request = new AA_Request($_GET['command'], $_REQUEST);  // params are in $_POST for ajax
}

if ( !is_object($request)) {
    AA_Response::error(_m("No request sent for responder.php"), 102);  // error code > 0
    exit;
}

$responder = AA_Responder::factoryByName($request->getCommand(), $request->getParameters());
if ( empty($responder) ) {
    AA_Response::error(_m("Bad request sent for responder.php - %1", array($request->getCommand())), 103);  // error code > 0
    exit;
}

if (!$responder->isPerm()) {
    AA_Response::error(_m("You (%2) don't have permissions to run %1", array($responder->name(), $auth->auth["uid"])), 101);  // error code > 0
    exit;
}

$response = $responder->run();

if ($request->getCommand() != 'Logout') {
    page_close();
}

$response->respond();
exit;



