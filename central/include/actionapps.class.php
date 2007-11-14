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

    /** username of "access" user
     *  We use the access user acount to get informations about remote AA and
     *  to update the remote AA slices/setting. You should create such user on
     *  remote AA (superuser is enough :-)
     */

    /** local data (central_conf table) in ItemContent structure */
    var $local_data;

    /** cached remote session ID */
    var $_remote_session_id;

    /** cached remote data - like AA name, ... */
    var $_cached;

    /** constructor - create AA_Actionapps object from ItemContent object
     *  grabbed from central_conf table.
     *  There are following fields:
     *    'id', 'dns_conf', 'dns_serial', 'dns_web', 'dns_mx', 'dns_db',
     *    'dns_prim', 'dns_sec', 'web_conf', 'web_path', 'db_server', 'db_name',
     *    'db_user', 'db_pwd', 'AA_SITE_PATH', 'AA_BASE_DIR', 'AA_HTTP_DOMAIN',
     *    'AA_ID', 'ORG_NAME', 'ERROR_REPORTING_EMAIL', 'ALERTS_EMAIL',
     *    'IMG_UPLOAD_MAX_SIZE', 'IMG_UPLOAD_URL', 'IMG_UPLOAD_PATH',
     *    'SCROLLER_LENGTH', 'FILEMAN_BASE_DIR', 'FILEMAN_BASE_URL',
     *    'FILEMAN_UPLOAD_TIME_LIMIT', 'AA_ADMIN_USER', 'AA_ADMIN_PWD',
     *    'status_code'));
     */
    function AA_Actionapps($content4id) {
        $this->local_data          = $content4id;
        $this->_remote_session_id  = null;
        $this->_cached             = array();
    }

    /** url of remote AAs - like "http://example.org/apc-aa/"  */
    function getComunicatorUrl() {
        return Files::makeFile($this->getValue('AA_HTTP_DOMAIN'). $this->getValue('AA_BASE_DIR'), 'central/responder.php');
    }

    /** username of access user */
    function getAccessUsername() {
        return $this->getValue('AA_ADMIN_USER');
    }

    /** password of access user */
    function getAccessPassword() {
        return $this->getValue('AA_ADMIN_PWD');
    }

    /** get value from localy stored data (central_conf) */
    function getValue($field) {
        $ic = $this->local_data;
        return $ic->getValue($field);
    }


    /** name of AA as in local table*/
    function getName() {
        return $this->getValue('ORG_NAME'). ' ('. $this->getValue('AA_HTTP_DOMAIN'). $this->getValue('AA_BASE_DIR'). ')';
    }

    /** @return ORG_NAME of remote AAs
     *  Currently this function is not needed, since name of AA is pased
     *  by constructor (which is much quicker)
     */
    function requestAAName() {
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
    function requestSlices() {
        return $this->requestModules(array('S'));
    }

    /** @return all module names form remote AA
     *  @param $types array of requested module types (A|Alerts|J|Lins|P|S|W)
     */
    function requestModules($types) {
        $response = $this->getResponse( new AA_Request('Get_Modules', array('types'=>$types)) );
        return ($response->isError()) ? array() : $response->getResponse();
    }

    /** @return structure which define all the definition of the slice
     *  (like slice properties, fields, views, ...). It is returned for all the
     *  slices in array
     */
    function requestDefinitions($type, $ids, $limited = false) {
        // We will use rather one call which returns all the data for all the
        // slices, since it is much quicker than separate call for each slice
        $response = $this->getResponse( new AA_Request('Get_Module_Defs', array('type'=>$type, 'ids'=>$ids, 'limited'=>$limited)) );
        return ($response->isError()) ? array() : $response->getResponse();
    }

    /** This command synchronizes the slices base on sync[] array
     *  @return the report on the synchronization
     */
    function synchronize($sync_commands) {
        // We will use rather one call which returns all the data for all the
        // slices, since it is much quicker than separate call for each slice
        $response = $this->getResponse( new AA_Request('Do_Synchronize', array('sync'=>$sync_commands)) );
        return ($response->isError()) ? array() : $response->getResponse();
    }

    /** Imports slice to the current AA. The id of slice is the same as in
     *  definition
     */
    function importModule($definition) {
        // We will use rather one call which returns all the data for all the
        // slices, since it is much quicker than separate call for each slice

        $response = $this->getResponse( new AA_Request('Do_Import_Module', array('definition'=>$definition)) );
        return ($response->isError()) ? array() : $response->getResponse();
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
        return $request->ask($this->getComunicatorUrl(), array('AA_CP_Session'=>$this->_remote_session_id));
    }

    function _authenticate() {
        $request  = new AA_Request('Get_Sessionid');
        $response = $request->ask($this->getComunicatorUrl(), array('free' => $this->getAccessUsername(), 'freepwd' =>$this->getAccessPassword()));
        if ( !$response->isError() ) {
            $this->_remote_session_id = $response->getResponse();
        }
        return $response;
    }

    /// Static methods
    /** create array of all Approved AAs from central database */
    function getArray() {

        $ret    = array();
        $conds  = array();
        $sort[] = array('ORG_NAME' => 'a');
        $zids   = Central_QueryZids($conds, $sort, AA_BIN_APPROVED);
        $aa_ic  = Central_GetAaContent($zids);

        foreach ($aa_ic as $k => $content4id) {
            $ret[$k] = new AA_Actionapps(new ItemContent($content4id));
        }
        return $ret;
    }
}


class AA_Module_Definition {
    /** module id is unpacked */
    var $module_id;
    /** data for the module */
    var $data;

    function AA_Module_Definition() {
        $this->clear();
    }

    function clear() {
        $this->module_id = null;
        $this->data      = array();
    }

    function loadForId($module_id, $limited=false) {
        $this->module_id = $module_id;
        $this->clear();
        // should be overloaded in childs
    }

    function getArray() {
        return $this->data;
    }

    function getId() {
        return $this->module_id;
    }

    /** returns module name */
    function getName() {
        return $this->data['module']['name'];
    }

    function importModule() {

/*
        huhl($this->data);
        exit;
 */
        foreach ($this->data as $table => $records) {
            if ( empty($records) ) {
                continue;
            }
            $varset = new Cvarset();
            foreach ($records as $data) {
                $varset->resetFromRecord($data);
                $varset->doInsert($table);
            }
        }
        return 'ok';
    }

    function compareWith($dest_def) {
        // should be overloaded in childs
    }
}



class AA_Module_Definition_Slice extends AA_Module_Definition {

    function loadForId($module_id, $limited=false) {
        $this->clear();
        $this->module_id = $module_id;
        $qp_slice_id     = q_pack_id($module_id);

        $this->data['slice']    = GetTable2Array("SELECT * FROM slice WHERE id              = '$qp_slice_id'", 'id', 'aa_fields');
        $this->data['field']    = GetTable2Array("SELECT * FROM field WHERE slice_id        = '$qp_slice_id'", 'id', 'aa_fields');
        $this->data['view']     = GetTable2Array("SELECT * FROM view  WHERE slice_id        = '$qp_slice_id'", 'id', 'aa_fields');
        $this->data['email']    = GetTable2Array("SELECT * FROM email WHERE owner_module_id = '$qp_slice_id'", 'id', 'aa_fields');
        // @todo - do it better - check the fields setting, and get all the constants used
        $this->data['constant'] = GetTable2Array("SELECT constant.* FROM constant,constant_slice WHERE constant.group_id=constant_slice.group_id AND constant_slice.slice_id = '$qp_slice_id'", 'id', 'aa_fields');
        if ( !$limited ) {
            $this->data['module']         = GetTable2Array("SELECT *            FROM module          WHERE id                 = '$qp_slice_id'", 'id', 'aa_fields');
            $this->data['item']           = GetTable2Array("SELECT *            FROM item            WHERE slice_id           = '$qp_slice_id'", 'id', 'aa_fields');
            $this->data['content']        = GetTable2Array("SELECT content.*    FROM content,item    WHERE content.item_id    = item.id AND item.slice_id = '$qp_slice_id'", '', 'aa_fields');
            $this->data['discussion']     = GetTable2Array("SELECT discussion.* FROM discussion,item WHERE discussion.item_id = item.id AND item.slice_id = '$qp_slice_id'", 'id', 'aa_fields');
            $this->data['email_notify']   = GetTable2Array("SELECT *            FROM email_notify    WHERE slice_id           = '$qp_slice_id'", '', 'aa_fields');
            $this->data['profile']        = GetTable2Array("SELECT *            FROM profile         WHERE slice_id           = '$qp_slice_id'", 'id', 'aa_fields');
            $this->data['rssfeeds']       = GetTable2Array("SELECT *            FROM rssfeeds        WHERE slice_id           = '$qp_slice_id'", 'feed_id', 'aa_fields');
            $this->data['constant_slice'] = GetTable2Array("SELECT *            FROM constant_slice  WHERE slice_id           = '$qp_slice_id'", 'group_id', 'aa_fields');
        }
    }

/*    function getArray() {
        return array ( 'data'      => $this->slice_data,
                       'fields'    => $this->fields_data,
                       'views'     => $this->views_data,
                       'emails'    => $this->emails_data,
                       'constants' => $this->constants_data
                     );
    }
*/


    function compareWith($dest_def) {
        /** @todo check the state, when the name contains "->" */
        $dest_module_id = $dest_def->getId();
        $diff =                    AA_Difference::_compareArray($this->data['slice'],    $dest_def->data['slice'],    new AA_Identifier($dest_module_id, 'slice'),   array('id'));
        $diff = array_merge($diff, AA_Difference::_compareArray($this->data['field'],    $dest_def->data['field'],    new AA_Identifier($dest_module_id, 'field'),    array('slice_id')));
        $diff = array_merge($diff, AA_Difference::_compareArray($this->data['view'],     $dest_def->data['view'],     new AA_Identifier($dest_module_id, 'view'),     array('slice_id')));
        $diff = array_merge($diff, AA_Difference::_compareArray($this->data['email'],    $dest_def->data['email'],    new AA_Identifier($dest_module_id, 'email'),    array('owner_module_id')));
        $diff = array_merge($diff, AA_Difference::_compareArray($this->data['constant'], $dest_def->data['constant'], new AA_Identifier($dest_module_id, 'constant'), array()));
        return $diff;
    }
}


class AA_Module_Definition_Site extends AA_Module_Definition {

    function loadForId($module_id, $limited=false) {
        $this->clear();
        $this->module_id = $module_id;
        $qp_site_id      = q_pack_id($module_id);

        $this->data['module']    = GetTable2Array("SELECT * FROM module    WHERE id      = '$qp_site_id'", 'id', 'aa_fields');
        $this->data['site']      = GetTable2Array("SELECT * FROM site      WHERE id      = '$qp_site_id'", 'id', 'aa_fields');
        $this->data['site_spot'] = GetTable2Array("SELECT * FROM site_spot WHERE site_id = '$qp_site_id'", 'id', 'aa_fields');
    }

    function compareWith($dest_def) {
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
        echo "\n<tr class=\"diff_".strtolower($this->type)."\"><td>". $this->description .'</td><td>';
        foreach ($this->actions as $action) {
            $action->printToForm();
        }
        echo '</td></tr>';
    }

    /// Static

    function _compareArray($template_arr, $destination_arr, $identifier, $ignore) {
        $diff       = array();
        if (! is_array($template_arr) AND is_array($destination_arr)) {
            return array( 0 => new AA_Difference('DELETED', _m('%1 is not array in template slice', array($identifier->toString())), new AA_Sync_Action('DELETE', $identifier)));
        }
        if ( is_array($template_arr) AND !is_array($destination_arr)) {
            return array( 0 => new AA_Difference('NEW', _m('%1 is not array in destination slice', array($identifier->toString())), new AA_Sync_Action('INSERT', $identifier, $template_arr)));
        }
        if ( !is_array($template_arr) AND !is_array($destination_arr)) {
            return array( 0 => new AA_Difference('INFO', _m('%1 is not defined for both AAs', array($identifier->toString()))));
        }
        foreach ($template_arr as $key => $value) {
            $sub_identifier = clone($identifier);
            $sub_identifier->sub($key);

            // some fields we do not want to compare (like slice_ids)
            if (in_array($key, $ignore)) {
                // we need to clear the destination array in order we can know,
                // that there are some additional keys in it (compated to template)
                unset($destination_arr[$key]);
                continue;
            }
            if (is_array($value)) {
                $diff = array_merge($diff, AA_Difference::_compareArray($value, $destination_arr[$key], $sub_identifier, $ignore));
                // we need to clear the destination array in order we can know,
                // that there are some additional keys in it (compated to template)
                unset($destination_arr[$key]);
            }
            elseif (!array_key_exists($key,$destination_arr)) {
                $diff[] = new AA_Difference('DIFFERENT', _m('There is no such key (%1) in destination slice for %2', array($key, $identifier->toString())), new AA_Sync_Action('UPDATE', $sub_identifier, $value));
            }
            elseif ($value != $destination_arr[$key]) {
                $code = '{htmltoggle:&gt;&gt;::&lt;&lt;:'. AA_Stringexpand::quoteColons('
                       <div style="background-color:#FFE0E0;border: solid 1px #F88;">'._m('Destination').':<br>'.safe($destination_arr[$key]).'</div>
                       <br>
                       <div style="background-color:#E0E0FF;border: solid 1px #88F;">'._m('Template').':<br>'.safe($value).'</div>'). '}';
                $diff[] = new AA_Difference('DIFFERENT', _m('The value for key %1 in %2 array is different %3', array($key, $identifier->toString(), AA_Stringexpand::unalias($code))), new AA_Sync_Action('UPDATE', $sub_identifier, $value));
                // we need to clear the destination array in order we can know,
                // that there are some additional keys in it (compated to template)
                unset($destination_arr[$key]);
            } else {
                unset($destination_arr[$key]);
            }
        }
        foreach ($destination_arr as $key => $value) {
            $sub_identifier = clone($identifier);
            $sub_identifier->sub($key);

            // there are no such keys in template
            if ( is_array($value) ) {
                // I know - we can define the difference right here, but it is better to use the same method as above
                $diff = array_merge($diff, AA_Difference::_compareArray('',$destination_arr[$key], $sub_identifier, $ignore));
            } else {
                $diff[] = new AA_Difference('DELETED', _m('There is no such key (%1) in template slice for %2', array($key, $sub_identifier->toString())), new AA_Sync_Action('UPDATE', $sub_identifier, ''));
            }
        }
        if ( count($diff) < 1 ) {
            $diff[] = new AA_Difference('INFO', _m('%1 are identical', array($identifier->toString())));
        }
        return $diff;
    }
}

class AA_Identifier {

    /**  $path[0] ~ module_id, [1] ~ table, [2] ~ row, [3] ~ column */
    var $path;

    function AA_Identifier($module_id=null, $table=null, $row=null, $column=null) {
        $this->path = array();
        if ($module_id) {
            $this->path[0] = $module_id;
            if ($table) {
                $this->path[1] = $table;
                if ($row) {
                    $this->path[2] = $row;
                    if ($column) {
                        $this->path[3] = $column;
                    }
                }
            }
        }
    }

    function getModuleId() { return $this->path[0]; }
    function getTable()    { return $this->path[1]; }
    function getRow()      { return $this->path[2]; }
    function getColumn()   { return $this->path[3]; }

    /** Parses the identifier string (like "Configuración->field->category........")
     *  static member function - called like $idf = AA_Identifier::factoryFromString($idf_string)
     */
    function factoryFromString($idf) {
        list($module_id, $table, $row, $column) = explode('->', $idf);
        return new AA_Identifier($module_id, $table, $row, $column);
    }

    /** creates identifier which identifies one part of the current idenftifier
     *  say '534633'->view->32  ---> .sub('name') ---> '534633'->view->32->name
     */
    function sub($sub_id) {
        $this->path[] = $sub_id;
        return;
    }

    function toString() {
        return join('->',$this->path);
    }
}

/** Class which defines synchronization actions */
class AA_Sync_Action {
    /** action type  - DELETE | INSERT | UPDATE */
    var $type;

    /** AA_Identifier object holding something like 'My Slice->view->678->name' */
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
        $state = isset($_POST['sync']) ? in_array($packed_action, (array)$_POST['sync']) : true;
        FrmChBoxEasy('sync[]', $state, '', $packed_action);
        switch ( $this->type ) {
            case 'DELETE': echo _m("Delete"); break;
            case 'INSERT': echo _m("Create new"); break;
            case 'UPDATE': echo _m("Update"); break;
        }
        echo '</div>';
    }

    /** returns data array as we need it for $metabase->doInsert/Update */
    function _getDataArray() {
        $idf    = $this->identifier;
        $column = $idf->getColumn();
        return $column ? array( $column => $this->params) : $this->params;
    }

    /** do synchronization action in destination slice */
    function doAction() {

        // commands are stored as tree - like:
        // 6353636737->field->category........
        // 6353636737 is id of slice - we use slice name as identifier
        // here, because slice_id is different in remote slices and we want
        // to synchronize the slices of the same name
        $idf         = $this->identifier;
        $module_id   = $idf->getModuleId();
        $table       = $idf->getTable();
        $row         = $idf->getRow();
        $column      = $idf->getColumn();

        $p_module_id = pack_id($module_id);

        $metabase    = AA_Metabase::singleton();

        if (!$row) {
            return _m('Wrong command - row is not defined - %1', array($idf->toString()));
        }
        // $fid = $row;
        if ( $this->type == 'UPDATE' ) {
            $metabase->getKeys($table);
            $data = $this->_getDataArray();
            $metabase->doUpdate($table, $metabase->fillKeys($data, $idf));
            return _m('%1 %2 in slice %3 updated', array($table, $row, $module_id));
        }
        if ( $this->type == 'INSERT' ) {
            $data = $this->params;
            $metabase->reassignModule($data, $table, $module_id);
            $metabase->doInsert($table, $data);
            return _m('%1 %2 inserted into slice %3', array($table, $row, $module_id));  // field xy inserted into slice yz
        }
        if ( $this->type == 'DELETE' ) {
            $metabase->doDelete($table, $data);
            return _m('%1 %2 deleted from slice %3', array($table, $row, $module_id));
        }
        return _m("Unknown action (%1) for field %2 in slice %3", array($this->type, $row, $module_id));
    }

    /** do synchronization action in destination slice */
    function doActionOld() {
        /** @todo convert to class variable after move to PHP5 */
        global $slice_id_cache;

        // commands are stored as tree - like:
        // 6353636737->field->category........
        // 6353636737 is id of slice - we use slice name as identifier
        // here, because slice_id is different in remote slices and we want
        // to synchronize the slices of the same name
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
                            // it makes no sense to update id (also, the id is alredy set a few rows above)
                            if ($key != 'id') {
                                $varset->add($key, 'text', $val);
                            }
                        }
                    }
                    $varset->doUpdate('slice');
                    return _m('Slice %1 updated', array($cmd[0]));
                }
                if ( $this->type == 'INSERT' ) {
                    /** @todo Add it to the module table, as well */
                    $varset->addkey('id', 'unpacked', new_id());
                    foreach ( $this->params as $key => $val ) {
                        // it makes no sense to update id (also, the id is alredy set a few rows above)
                        if ($key != 'id') {
                            $varset->add($key, 'text', $val);
                        }
                    }
                    $varset->doInsert('slice');
                    return _m('Slice %1 Inserted', array($cmd[0]));
                }
                /** @todo DELETE */
                return _m('Operation %1 not supported, yet - Slice %2', array($this->type, $cmd[0]));
            case 'field':
                if (!isset($cmd[2])) {
                    return _m('Wrong command - Field id is not defined - Slice->Field %1->%2', array($cmd[0],$cmd[2]));
                }
                $fid = $cmd[2];
                if ( $this->type == 'UPDATE' ) {
                    $varset->addkey('slice_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id',       'text',   $fid);
                    if ( isset($column) ) {
                        // single value
                        if (($column != 'id') AND ($column != 'slice_id')) {
                            // it makes no sense to update id (also, the id is alredy set a few rows above)
                            $varset->add($column, 'text', $this->params);
                        }
                    } else {
                        // whole slice record
                        foreach ( $this->params as $key => $val ) {
                            // it makes no sense to update id (also, the id is alredy set a few rows above)
                            if (($key != 'id') AND ($key != 'slice_id')) {
                                $varset->add($key, 'text', $val);
                            }
                        }
                    }
                    $varset->doUpdate('field');
                    return _m('Field %1 in slice %2 updated', array($fid, $cmd[0]));
                }
                if ( $this->type == 'INSERT' ) {
                    $varset->addkey('slice_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id', 'text', $fid);
                    foreach ( $this->params as $key => $val ) {
                        // it makes no sense to update id (also, the id is alredy set a few rows above)
                        if (($key != 'id') AND ($key != 'slice_id')) {
                            $varset->add($key, 'text', $val);
                        }
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
                    return _m('Wrong command - View id is not defined - Slice->View %1->%2', array($cmd[0],$cmd[2]));
                }
                $vid = $cmd[2];
                if ( $this->type == 'UPDATE' ) {
                    $varset->addkey('slice_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id',       'text',   $vid);
                    if ( isset($cmd[3]) ) {
                        // single value
                        if (($cmd[3] != 'id') AND ($cmd[3] != 'slice_id')) {
                            // it makes no sense to update id (also, the id is alredy set a few rows above)
                            $varset->add($cmd[3], 'text', $this->params);
                        }
                    } else {
                        // whole slice record
                        foreach ( $this->params as $key => $val ) {
                            // it makes no sense to update id (also, the id is alredy set a few rows above)
                            if (($key != 'id') AND ($key != 'slice_id')) {
                                $varset->add($key, 'text', $val);
                            }
                        }
                    }
                    $varset->doUpdate('view');
                    return _m('View %1 in slice %2 updated', array($vid, $cmd[0]));
                }
                if ( $this->type == 'INSERT' ) {
                    $varset->addkey('slice_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id', 'text', $vid);
                    foreach ( $this->params as $key => $val ) {
                        // it makes no sense to update id (also, the id is alredy set a few rows above)
                        if (($key != 'id') AND ($key != 'slice_id')) {
                            $varset->add($key, 'text', $val);
                        }
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
                    return _m('Wrong command - View id is not defined - Slice->Email %1->%2', array($cmd[0],$cmd[2]));
                }
                $emailid = $cmd[2];
                if ( $this->type == 'UPDATE' ) {
                    $varset->addkey('owner_module_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id',              'text',   $emailid);
                    if ( isset($cmd[3]) ) {
                        // single value
                        if (($cmd[3] != 'owner_module_id') AND ($cmd[3] != 'id')) {
                            // it makes no sense to update id (also, the id is alredy set a few rows above)
                            $varset->add($cmd[3], 'text', $this->params);
                        }
                    } else {
                        // whole slice record
                        foreach ( $this->params as $key => $val ) {
                            // it makes no sense to update id (also, the id is alredy set a few rows above)
                            if (($key != 'owner_module_id') AND ($key != 'id')) {
                                $varset->add($key, 'text', $val);
                            }
                        }
                    }
                    $varset->doUpdate('email');
                    return _m('Email %1 in slice %2 updated', array($emailid, $cmd[0]));
                }
                if ( $this->type == 'INSERT' ) {
                    $varset->addkey('owner_module_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id',              'text',   $emailid);
                    foreach ( $this->params as $key => $val ) {
                        // it makes no sense to update id (also, the id is alredy set a few rows above)
                        if (($key != 'owner_module_id') AND ($key != 'id')) {
                            $varset->add($key, 'text', $val);
                        }
                    }
                    $varset->doInsert('email');
                    return _m('Email %1 inserted into slice %2', array($emailid, $cmd[0]));
                }
                if ( $this->type == 'DELETE' ) {
                    $varset->addkey('owner_module_id', 'quoted', $qp_slice_id);
                    $varset->addkey('id',              'text',   $emailid);
                    $varset->doDelete('email');
                    return _m('Email %1 deleted from slice %2', array($emailid, $cmd[0]));
                }
                return _m("Unknown action (%1) for email %2 in slice %3", array($this->type, $emailid, $cmd[0]));
            case 'constant':  /** @todo work with constant */
        }
        return _m("Unknown action for data %1 in slice %2", array($cmd[1], $cmd[0]));
    }
}


/** Stores the synchronization action which should be performed on remote AA
 *  Objects are stored into AA_Toexecute queue for running from Task Manager
 **/
class AA_Sync_Task {
    /** AA_Sync_Action object - Action to do */
    var $sync_action;

    /** AA_Actionapps object  - In which AA we have to do the action */
    var $actionapps;

    function AA_Sync_Task($sync_action, $actionapps) {
        $this->sync_action = $sync_action;
        $this->actionapps  = $actionapps;
    }

    function toexecutelater() {
        // synchronize accepts array of sync_actions, so it is possible
        // to do more action by one call
        $this->actionapps->synchronize(array($this->sync_action));
    }
}

/** Central_GetAaContent function for loading content of AA configuration
 *  for manager class
 *
 * Loads data from database for given AA ids (called in itemview class)
 * and stores it in the 'Abstract Data Structure' for use with 'item' class
 *
 * @see GetItemContent(), itemview class, item class
 * @param array $zids array if ids to get from database
 * @return array - Abstract Data Structure containing the links data
 *                 {@link http://apc-aa.sourceforge.net/faq/#1337}
 */
function Central_GetAaContent($zids) {
    $content = array();
    $ret     = array();

    // construct WHERE clausule
    $sel_in = $zids->sqlin( false );
    $SQL = "SELECT * FROM central_conf WHERE id $sel_in";
    StoreTable2Content($content, $SQL, '', 'id');
    // it is unordered, so we have to sort it:
    for($i=0; $i<$zids->count(); $i++ ) {
        $ret[(string)$zids->id($i)] = $content[$zids->id($i)];
    }
    return $ret;
}

/** Central_QueryZids - Finds link IDs for links according to given  conditions
 *  @param array  $conds    - search conditions (see FAQ)
 *  @param array  $sort     - sort fields (see FAQ)
 *  @param string $type     - bins as known from items
 *       AA_BIN_ACTIVE | AA_BIN_HOLDING | AA_BIN_TRASH | AA_BIN_ALL
 *  @global int  $QueryIDsCount - set to the count of IDs returned
 *  @global bool $debug=1       - many debug messages
 *  @global bool $nocache       - do not use cache, even if use_cache is set
 */
function Central_QueryZids($conds, $sort="", $type="app") {
    global $debug;                 // displays debug messages
    global $nocache;               // do not use cache, if set

    if ( $debug ) huhl( "<br>Conds:", $conds, "<br>--<br>Sort:", $sort, "<br>--");

    $metabase    = AA_Metabase::singleton();

    $fields      = $metabase->getSearchArray('central_conf');
    $join_tables = array();   // not used in this function

    $SQL  = 'SELECT DISTINCT id FROM central_conf WHERE ';
    $SQL .= CreateBinCondition($type, 'central_conf');
    $SQL .= MakeSQLConditions($fields, $conds, $fields, $join_tables);
    $SQL .= MakeSQLOrderBy($fields, $sort, $join_tables);

    return GetZidsFromSQL($SQL, 'id');
}

?>
