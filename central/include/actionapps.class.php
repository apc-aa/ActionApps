<?php

/**
 * File contains definition of AA_Actionapps class - holding information about
 * one AA installation.
 *
 * Should be included to other scripts (as /admin/index.php)
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

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

use AA\Central\ResponderPolicy;
use AA\IO\DB\DB_AA;
use AA\Later\LaterInterface;
use AA\Later\Toexecute;

/**
 * AA_Actionapps class - holds information about one AA installation
 */
class AA_Actionapps
{
    /** username of "access" user
     *  We use the access user acount to get information about remote AA and
     *  to update the remote AA slices/setting. You should create such user on
     *  remote AA (superuser is enough :-)
     */

    /** local data (central_conf table) in ItemContent structure */
    private ItemContent $local_data;

    private $last_error = null;

    /** cached remote session ID */
    private ?string $_remote_session_id = null;

    /** time when we get remote session ID. We do not use sessions older than ...*/
    private int $_remote_session_id_time = 0;

    /** cached remote data - like AA name, ... */
    private array $_cached = [];

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
    public function __construct(ItemContent $content4id)
    {
        $this->local_data          = $content4id;
    }

    /** getActionapps function
     *  main factory static method called like:
     *     $aa = AA_Actionapps::getActionapps($aa_id);
     * @param $aa_id
     * @return mixed
     */
    public static function getActionapps($aa_id)
    {
        static $aas = [];
        if (!isset($aas[$aa_id])) {
            $aa_ic       = Central_GetAaContent(new zids($aa_id, 's'));
            $aas[$aa_id] = new AA_Actionapps(new ItemContent($aa_ic[$aa_id]));
        }
        return $aas[$aa_id];
    }

    /** url of remote AAs - like "https://example.org/apc-aa/"  */
    public function getComunicatorUrl(): string
    {
        return Files::makeFile($this->getValue('AA_HTTP_DOMAIN') . $this->getValue('AA_BASE_DIR'), 'central/responder.php');
    }

    /** username of access user */
    public function getAccessUsername()
    {
        return $this->getValue('AA_ADMIN_USER');
    }

    /** password of access user */
    public function getAccessPassword()
    {
        return $this->getValue('AA_ADMIN_PWD');
    }

    /** Get JWT auth token if available (stored with 'jwt:' prefix in AA_ADMIN_PWD) */
    public function getAuthToken(): ?string
    {
        $pwd = $this->getValue('AA_ADMIN_PWD');
        if ($pwd && str_starts_with($pwd, 'jwt:')) {
            return substr($pwd, 4);
        }
        return null;
    }

    /** Store JWT auth token (with 'jwt:' prefix) in local_data */
    public function setAuthToken(string $jwt): void
    {
        $this->local_data->setValue('AA_ADMIN_PWD', 'jwt:' . $jwt);
    }

    /** Authenticate to remote AA and get JWT token
     *  @return string JWT token on success, empty string on failure
     */
    public function requestJwt(string $username, string $password): string
    {
        $request  = new AA_Request('Get_Jwt');
        $response = $request->ask(
            $this->getComunicatorUrl(),
            ['username' => $username, 'password' => $password],
        );
        if (!$response->isError()) {
            return (string) $response->getResponse();
        }
        $this->last_error = $response->getError();
        return '';
    }

    /** Persist auth token to central_conf database
     *  central_conf.id is int(10) unsigned, not a long-id, so use type 'i' for the WHERE clause.
     */
    public static function saveAuthToken(string $aa_id, string $token): void
    {
        DB_AA::update(
            'central_conf',
            [['AA_ADMIN_PWD', $token]],
            [['id', $aa_id, 'i']],
        );
    }

    /** get value from locally stored data (central_conf) */
    public function getValue($field)
    {
        return $this->local_data->getValue($field);
    }

    /** Get last communication error code */
    public function getLastError()
    {
        return $this->last_error;
    }


    /** name of AA as in local table*/
    public function getName(): string
    {
        return $this->getValue('ORG_NAME') . ' (' . $this->getValue('AA_HTTP_DOMAIN') . $this->getValue('AA_BASE_DIR') . ')';
    }

    /** @return string ORG_NAME of remote AAs
     *  Currently this function is not needed, since name of AA is pased
     *  by constructor (which is much quicker)
     */
    public function requestAAName(): string
    {
        if (is_null($this->_cached['org_name'])) {
            $response = $this->getResponse(new AA_Request('Get_Aaname'));
            if ($response->isError()) {
                $this->_cached['org_name'] = _m("Can't get org_name - ") . $response->getError();
            } else {
                $response_arr = $response->getResponse();
                $this->_cached['org_name'] = $response_arr['org_name'];
                $this->_cached['domain']   = $response_arr['domain'];
            }
        }
        return $this->_cached['org_name'];
    }


    /** @return array - all slice names form remote AA
     *  mention, that the slices are identified by !name! not id for synchronization
     */
    public function requestSlices(): array
    {
        return $this->requestModules(['S']);
    }

    /** @return array - all module names form remote AA
     *  @param $types array of requested module types (A|Alerts|J|Lins|P|S|W)
     */
    public function requestModules(array $types): array
    {
        $response = $this->getResponse(new AA_Request('Get_Modules', ['types' => $types]));
        return ($response->isError()) ? [] : (array) $response->getResponse();
    }

    /** @return array - structure which define all the definition of the slice
     *  (like slice properties, fields, views, ...). It is returned for all the
     *  slices in array
     */
    public function requestDefinitions($type, $ids, $limited = []): array
    {
        // We will use rather one call which returns all the data for all the
        // slices, since it is much quicker than separate call for each slice
        $response = $this->getResponse(new AA_Request('Get_Module_Defs', ['type' => $type, 'ids' => $ids, 'limited' => $limited]));
        return ($response->isError()) ? [] : $response->getResponse();
    }

    /** This command synchronizes the slices base on sync[] array
     *  return the report on the synchronization
     */
    public function synchronize($sync_commands)
    {
        // We will use rather one call which returns all the data for all the
        // slices, since it is much quicker than separate call for each slice
        $response = $this->getResponse(new AA_Request('Do_Synchronize', ['sync' => $sync_commands]));
        return ($response->isError()) ? $response->getError() : $response->getResponse();
    }

    /** This command calls optimize method
     *  return the report on the optimize
     */
    public function doOptimize($optimize_class, $optimize_method)
    {
        $response = $this->getResponse(new AA_Request('Do_Optimize', ['class' => $optimize_class, 'method' => $optimize_method]));
        if (!$response->isError()) {
            return $response->getResponse();
        }
        // the remote decides the type of the detail - concatenating an object would throw
        $detail = $response->getResponse();
        return $response->getError() . (is_scalar($detail) ? ' ' . $detail : '');
    }

    /** Imports slice to the current AA. The id of slice is the same as in
     *  definition
     */
    public function importModuleChunk(AA_Definition_Module_Chunk $definition_chunk)
    {
        // We will use rather one call which returns all the data for all the
        // slices, since it is much quicker than separate call for each slice

        $response = $this->getResponse(new AA_Request('Do_Import_Module_Chunk', ['definition_chunk' => $definition_chunk]));
        return ($response->isError()) ? $response->getError() : $response->getResponse();
    }

    /** Main communication function - returns AA_Response object */
    public function getResponse($request)
    {
        $jwt = $this->getAuthToken();
        if ($jwt) {
            // Stateless JWT auth — send token with every request
            $response = $request->ask(
                $this->getComunicatorUrl(),
                [],
                ['Authorization' => 'Bearer ' . $jwt],
            );
            if (!$response->isError()) {
                return $response;
            }
            // JWT might be expired — store error for reporting
            $this->last_error = $response->getError();
            return $response;
        }

        // Legacy: session-based auth with username/password
        if (!$this->_remote_session_id || $this->_remote_session_id_time < (now() - 10 * 60)) {
            $response = $this->_authenticate();
            if ($response->isError()) {
                $this->last_error = $response->getError();
                return $response;
            }
        }
        return $request->ask(
            $this->getComunicatorUrl(),
            ['AA_CP_Session' => $this->_remote_session_id],
            ['Cookie' => 'AA_Session=' . $this->_remote_session_id],
        );
    }

    public function _authenticate()
    {
        $request  = new AA_Request('Get_Sessionid');
        // $response = $request->ask($this->getComunicatorUrl(), array('free' => $this->getAccessUsername(), 'freepwd' =>$this->getAccessPassword()));
        $response = $request->ask($this->getComunicatorUrl(), ['username' => $this->getAccessUsername(), 'password' => $this->getAccessPassword()]);
        if (!$response->isError()) {
            $arr = $response->getResponse();
            $this->setSession((string) $arr[0]);
        } else {
            $this->last_error = $response->getError();
        }
        return $response;
    }

    private function setSession(string $session_id): void
    {
        $this->_remote_session_id      = $session_id;
        $this->_remote_session_id_time = now();
    }

    /// Static methods
    /** create array of all Approved AAs from central database
     * @return AA_Actionapps[]
     */
    public static function getArray(): array
    {
        $ret    = [];
        $set = new AA_Set(null, null, [['ORG_NAME' => 'a']], AA_BIN_APPROVED);  // list of sort specs - a bare map sorts by field "a"
        $zids   = Central_QueryZids($set);
        $aa_ic  = Central_GetAaContent($zids);

        foreach ($aa_ic as $k => $content4id) {
            $ret[$k] = new AA_Actionapps(new ItemContent($content4id));
        }
        return $ret;
    }

    /** create array of all Approved AAs from central database */
    // not used, yet
    // function getCurrent($sess) {
    //
    //     $aaic = new ItemContent();
    //     $aaic->setValue('ORG_NAME',       ORG_NAME);
    //     $aaic->setValue('AA_HTTP_DOMAIN', AA_HTTP_DOMAIN);
    //     $aaic->setValue('AA_BASE_DIR',    AA_BASE_DIR);
    //
    //     $aa = new AA_Actionapps($aaic);
    //     $aa->setSession($sess->id());
    //
    //     $ret    = array();
    //     $conds  = array();
    //     $sort[] = array('ORG_NAME' => 'a');
    //     $zids   = Central_QueryZids($conds, $sort, AA_BIN_APPROVED);
    //     $aa_ic  = Central_GetAaContent($zids);
    //
    //     foreach ($aa_ic as $k => $content4id) {
    //         $ret[$k] = new AA_Actionapps(new ItemContent($content4id));
    //     }
    //     return $ret;
    // }
}


class AA_Definition_Module
{
    /** module id is unpacked */
    protected $module_id;
    /** data for the module */
    protected $data;

    public function __construct()
    {
        $this->clear();
    }

    public function clear(): void
    {
        $this->module_id = null;
        $this->data      = [];
    }

    public function loadForId($module_id, $limited = false): void
    {
        $this->module_id = $module_id;
        $this->clear();
        // should be overloaded in childs
    }

    public function getArray()
    {
        return $this->data;
    }

    /** Parts loadForId() loads: ['definitions' => true, 'items' => true]. Bool true is the pre-2008
     *  form (= definitions only); anything else loads nothing.
     *  @return array<string, true> */
    public static function normalizeLimited($limited): array
    {
        if ($limited === true) {
            return ['definitions' => true];
        }
        $ret = [];
        foreach (['definitions', 'items'] as $part) {
            if (is_array($limited) && !empty($limited[$part])) {
                $ret[$part] = true;
            }
        }
        return $ret;
    }

    public function getId()
    {
        return $this->module_id;
    }

    /** returns module name */
    public function getName()
    {
        return $this->data['module']['name'];
    }

    public function compareWith($dest_def)
    {
        // should be overloaded in childs
    }

    /** used when you want to export module to another AA
        - could be used for the same AA as well, but we instead now have
        moduleImport() method, which do not need special http requests and is
        quicker. Also, there was some problem when there was different encoding
        in the modules.
    */
    public function planModuleImport($aa, $replaces = null): int
    {
        $chunks    = $this->_getChunks($replaces);
        $toexecute = new Toexecute();
        foreach ($chunks as $chunk) {
            $import_task = new AA_Task_Import_Module_Chunk($chunk, $aa);
            $toexecute->userQueue($import_task, [], 'AA_Task_Import_Module_Chunk');
        }
        return count($chunks);
    }

    /** special import - to this AA - no need to plan, no need to chunk
     *  @return list<string> report lines of the chunks ('OK: ...', 'Err: ...') */
    public function moduleImport($replaces = null): array
    {
        $report = [];
        foreach ($this->_getChunks($replaces) as $chunk) {
            $report[] = $chunk->importModuleChunk();
        }
        return array_merge(...$report);
    }

    protected function _getChunks($replaces): array
    {
        $chunks = [];
        // replaced on a copy - the same definition is imported to more AAs, each with its own new module ids
        $data   = is_array($replaces) ? recursive_array_replace(array_keys($replaces), array_values($replaces), $this->data) : $this->data;

        foreach ($data as $table => $rows) {
            $chunks[] = AA_Definition_Module_Chunk::factoryArray($this->module_id, $table, $rows);
        }
        return array_merge(...$chunks);  // avoiding array_merge() in loop
    }
}

/** AA_Definition_Module could be splited to more pieces (chunks) for easier
 *  transmission and import
 */
class AA_Definition_Module_Chunk
{
    /** module id is unpacked */
    protected $module_id;
    /** part of the data for the module definition */
    protected $data;

    /** Creates array of chunks from given data. The data are split to more chunks, if it is bigger than limit. */
    public static function factoryArray($module_id, $table, $rows): array
    {
        $chunks = [];

        // maximum 1000 rows in one chunk
        $chunks_data = array_chunk($rows, 1000, true);
        foreach ($chunks_data as $chunk_data) {
            $chunks[] = new AA_Definition_Module_Chunk($module_id, $table, $chunk_data);
        }
        return $chunks;
    }

    public function __construct($module_id, $table, $rows)
    {
        $this->module_id = $module_id;
        $this->data      = [$table => $rows];
    }

    public function importModuleChunk(): array
    {

        $metabase    = AA::Metabase();

        // a table gets into ok/err with its first row there - no "Err: 0 field ()" for a clean import
        $msgs = ['ok' => [], 'err' => []];

        foreach ($this->data as $table => $records) {
            if (empty($records)) {
                continue;
            }

            $keys = $metabase->getKeys($table);   // just for reporting
            // now we packing the data in the AA_Metabase::do*() functions
            // $metabase->packIds($table, $records);    // pack ids ...
            foreach ($records as $data) {
                $ident = [];
                foreach ($keys as $col) {
                    $ident[] = $data[$col];
                }

                if ($res = $metabase->doInsert($table, $data, 'nohalt')) {
                    $msgs['ok'][$table][] = $ident;
                } else {
                    $msgs['err'][$table][] = $ident;
                }

            }
        }
        $ret = [];
        if ($msg = $this->_formatReport($msgs['ok'])) {
            $ret[] = 'OK: ' . $msg;
        }
        if ($msg = $this->_formatReport($msgs['err'])) {
            $ret[] = 'Err: ' . $msg;
        }
        return $ret;
    }

    public function _formatReport($arr): string
    {
        $ret = '';
        foreach ($arr as $table => $idents) {
            $ret .= count($idents) . " $table (";
            foreach ($idents as $ident) {
                $ret .= '[' . implode(',', $ident) . ']';
            }
            $ret .= '); ';
        }
        return $ret;
    }
}

class AA_Definition_Module_Slice extends AA_Definition_Module
{
    public function loadForId($module_id, $limited = []): void
    {
        $this->clear();
        $this->module_id = $module_id;
        $metabase        = AA::Metabase();
        $limited         = self::normalizeLimited($limited);

        if (isset($limited['definitions'])) {
            $this->data['module']       = $metabase->getModuleRows('module', $module_id);
            $this->data['slice']        = $metabase->getModuleRows('slice', $module_id);
            $this->data['field']        = $metabase->getModuleRows('field', $module_id);
            $this->data['view']         = $metabase->getModuleRows('view', $module_id);
            $this->data['email']        = $metabase->getModuleRows('email', $module_id);
            $this->data['email_notify'] = $metabase->getModuleRows('email_notify', $module_id);
            $this->data['profile']      = $metabase->getModuleRows('profile', $module_id);
            $this->data['rssfeeds']     = $metabase->getModuleRows('rssfeeds', $module_id);

            // @todo - do it better - check the fields setting, and get all the constants used
            $this->data['constant_slice'] = $metabase->getModuleRows('constant_slice', $module_id);
            $this->data['constant']       = $metabase->getModuleRows('constant', $module_id);
            if (is_array($this->data['constant'])) {
                $constant_groups = [];
                foreach ($this->data['constant'] as $k => $v) {
                    $constant_groups[$v['group_id']] = true;   // get list of all groups
                }

                $SQL = "SELECT * FROM constant WHERE group_id='lt_groupNames' AND " . Cvarset::sqlin('value', array_keys($constant_groups));

                $groups = GetTable2Array($SQL, "unpack:id", 'aa_fields');
                if (!is_array($groups)) {
                    $groups = [];
                }
                foreach ($groups as $k => $v) {
                    $groups[$k]['id'] = unpack_id($v['id']);
                }
                $this->data['constant'] = array_merge($this->data['constant'], $groups);
            }
        }

        if (isset($limited['items'])) {
            $this->data['item']       = $metabase->getModuleRows('item', $module_id);
            $this->data['content']    = $metabase->getModuleRows('content', $module_id);
            $this->data['discussion'] = $metabase->getModuleRows('discussion', $module_id);
        }
    }

    /** @return AA_Difference[] - an INFO row alone when a side is missing; an empty destination gives NEW rows */
    public function compareWith($dest_def): array
    {
        // nothing loaded (unknown id, remote error) - comparing would offer pre-checked DELETEs of the destination slice
        if (empty($this->data['module']) || empty($this->data['slice'])) {
            return [new AA_Difference('INFO', _m('Template slice %1 has no definition', [safe((string) $this->module_id)]))];
        }
        if (!($dest_def instanceof AA_Definition_Module)) {
            return [new AA_Difference('INFO', _m("Comparison slice (%1) does not exist", [safe((string) $this->module_id)]))];
        }
        /** @todo check the state, when the name contains "->" */
        $dest_module_id = $dest_def->getId();
        $dest_data      = $dest_def->getArray();
        $diff           = [];
        foreach (AA_Sync_Action::TABLES as $table) {
            $template    = $this->data[$table] ?? null;
            $destination = $dest_data[$table] ?? null;
            $identifier  = new AA_Identifier($dest_module_id, $table);
            if ($table === 'module' || $table === 'slice') {  // one row per module - compare just its data
                $template    = is_array($template) ? reset($template) : null;
                $destination = is_array($destination) ? reset($destination) : null;
                $identifier  = new AA_Identifier($dest_module_id, $table, $dest_module_id);
            }
            $diff = array_merge($diff, AA_Difference::_compareArray($template, $destination, $identifier, [AA_Metabase::getModuleFields()[$table] ?? null]));
        }
        return $diff;
    }
}

class AA_Definition_Module_Site extends AA_Definition_Module
{
    public function loadForId($module_id, $limited = false): void
    {
        $this->clear();
        $this->module_id = $module_id;
        $metabase        = AA::Metabase();

        $this->data['module']    = $metabase->getModuleRows('module', $module_id);
        $this->data['site']      = $metabase->getModuleRows('site', $module_id);
        $this->data['site_spot'] = $metabase->getModuleRows('site_spot', $module_id);
    }

    public function compareWith($dest_def) {}
}

class AA_Definition_Module_Alerts extends AA_Definition_Module
{
    public function loadForId($module_id, $limited = false): void
    {
        $this->clear();
        $this->module_id = $module_id;
        $metabase        = AA::Metabase();

        $this->data['module']                     = $metabase->getModuleRows('module', $module_id);
        $this->data['alerts_collection']          = $metabase->getModuleRows('alerts_collection', $module_id);
        $this->data['alerts_collection_filter']   = $metabase->getModuleRows('alerts_collection_filter', $module_id);
        $this->data['alerts_collection_howoften'] = $metabase->getModuleRows('alerts_collection_howoften', $module_id);
        // @todo - filter is not copied since it need double jopined tabled in metabase's  getModuleRows()
        // $this->data['alerts_filter']              = $metabase->getModuleRows('alerts_filter', $module_id);
    }

    public function compareWith($dest_def) {}
}


class AA_Difference
{
    private string $description;
    private string $type;           // INFO | DIFFERENT_ROW | DIFFERENT_VALUE | NEW | DELETED
    /** array of AA_Sync_Actions defining, what we can do with this difference */
    private array $actions;

    /** */
    public function __construct(string $type, string $description, $actions = [])
    {
        $this->type        = $type;
        $this->description = $description;
        $this->actions     = empty($actions) ? [] : (is_array($actions) ? $actions : [$actions]);
    }

    public function printOut(): void
    {
        echo "\n<tr class=\"diff_" . strtolower($this->type) . "\"><td>" . $this->description . '</td><td>';
        foreach ($this->actions as $action) {
            $action->printToForm();
        }
        echo '</td></tr>';
    }

    /// Static

    /** Remote data (keys, row ids, values) goes into the descriptions escaped - never as HTML
     *  or as an AA template, since the remote AA may be compromised. */
    public static function _compareArray($template_arr, $destination_arr, $identifier, $ignore)
    {
        $diff       = [];
        $idf        = safe($identifier->toString());
        if (! is_array($template_arr) && is_array($destination_arr)) {
            return [0 => new AA_Difference('DELETED', _m('%1 is not array in template slice', [$idf]), new AA_Sync_Action('DELETE', $identifier))];
        }
        if (is_array($template_arr) && !is_array($destination_arr)) {
            return [0 => new AA_Difference('NEW', _m('%1 is not array in destination slice', [$idf]), new AA_Sync_Action('INSERT', $identifier, $template_arr))];
        }
        if (!is_array($template_arr) && !is_array($destination_arr)) {
            return [0 => new AA_Difference('INFO', _m('%1 is not defined for both AAs', [$idf]))];
        }

        // if we're comparing row values, we can also update whole row at once - so we mark it
        $is_different = false;
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
                $diff = array_merge($diff, self::_compareArray($value, $destination_arr[$key], $sub_identifier, $ignore));
                // we need to clear the destination array in order we can know,
                // that there are some additional keys in it (compated to template)
                unset($destination_arr[$key]);
            } elseif (!array_key_exists($key, $destination_arr)) {
                $diff[] = new AA_Difference('DIFFERENT_VALUE', '&nbsp;&nbsp;&nbsp;-&nbsp;' . _m('There is no such key (%1) in destination slice for %2', [safe($key), $idf]), new AA_Sync_Action('UPDATE_VALUE', $sub_identifier, $value));
            } elseif ($value != $destination_arr[$key] && !(empty($value) && empty($destination_arr[$key]))) {  // '' and 0 is considered equal
                // if we're comparing row values, we can also update whole row at once - so we mark it
                $is_different = true;

                $code = '<details><summary>&gt;&gt;</summary>
                       <div style="background-color:#FFE0E0;border: solid 1px #F88;">' . _m('Destination') . ':<br>' . safe($destination_arr[$key]) . '</div>
                       <br>
                       <div style="background-color:#E0E0FF;border: solid 1px #88F;">' . _m('Template') . ':<br>' . safe($value) . '</div></details>';
                $diff[] = new AA_Difference('DIFFERENT_VALUE', '&nbsp;&nbsp;&nbsp;-&nbsp;' . _m('The value for key %1 in %2 array is different %3', [safe($key), $idf, $code]), new AA_Sync_Action('UPDATE_VALUE', $sub_identifier, $value));
                // we need to clear the destination array in order we can know,
                // that there are some additional keys in it (compated to template)
                unset($destination_arr[$key]);
            } else {
                unset($destination_arr[$key]);
            }
        }
        if ($is_different) {
            array_unshift($diff, new AA_Difference('DIFFERENT_ROW', _m('The record for %1 is different', [$idf]), new AA_Sync_Action('UPDATE_ROW', $identifier, $template_arr)));
        }

        foreach ($destination_arr as $key => $value) {
            $sub_identifier = clone($identifier);
            $sub_identifier->sub($key);

            // there are no such keys in template
            if (is_array($value)) {
                // I know - we can define the difference right here, but it is better to use the same method as above
                $diff = array_merge($diff, self::_compareArray('', $destination_arr[$key], $sub_identifier, $ignore));
            } else {
                $diff[] = new AA_Difference('DELETED', '&nbsp;&nbsp;&nbsp;-&nbsp;' . _m('There is no such key (%1) in template slice for %2', [safe($key), safe($sub_identifier->toString())]), new AA_Sync_Action('UPDATE_VALUE', $sub_identifier, ''));
            }
        }
        if (count($diff) < 1) {
            $diff[] = new AA_Difference('INFO', _m('%1 are identical', [$idf]));
        }
        return $diff;
    }
}

class AA_Identifier
{
    /**  $path[0] ~ module_id, [1] ~ table, [2] ~ row, [3] ~ column */
    private array $path = [];

    public function __construct($module_id = null, $table = null, $row = null, $column = null)
    {
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

    public function getModuleId()
    {
        return $this->path[0] ?? null;
    }
    public function getTable()
    {
        return $this->path[1] ?? null;
    }
    public function getRow()
    {
        return $this->path[2] ?? null;
    }
    public function getColumn()
    {
        return $this->path[3] ?? null;
    }

    /** Parses the identifier string (like "Configuraci�n->field->category........")
     *  static member function - called like $idf = AA_Identifier::factoryFromString($idf_string)
     */
    public function factoryFromString($idf): AA_Identifier
    {
        [$module_id, $table, $row, $column] = explode('->', $idf);
        return new AA_Identifier($module_id, $table, $row, $column);
    }

    /** creates identifier which identifies one part of the current idenftifier
     *  say '534633'->view->32  ---> .sub('name') ---> '534633'->view->32->name
     */
    public function sub($sub_id): void
    {
        $this->path[] = $sub_id;
        return;
    }

    public function toString(): string
    {
        return implode('->', $this->path);
    }
}

/** Class which defines synchronization actions */
class AA_Sync_Action
{
    /** The only tables an action may write - the ones compareWith() compares. Metabase itself
     *  would accept ~50, including toexecute (unserialized later), users and central_conf. */
    public const TABLES = ['module', 'slice', 'field', 'view', 'email', 'constant_slice', 'constant'];

    /** action type  - DELETE | INSERT | UPDATE_ROW | UPDATE_VALUE */
    private string $type;

    /** AA_Identifier object holding something like 'My Slice->view->678->name' */
    private AA_Identifier $identifier;

    /** action parameters (field's data). Could be scalar as well as array */
    private $params;

    public function __construct(string $type, AA_Identifier $identifier, $params = null)
    {
        $this->type       = $type;
        $this->identifier = $identifier;
        $this->params     = $params;
    }

    /** The checkbox value is base64: serialize() wraps private property names in NUL bytes, which
     *  browsers post back as U+FFFD - the action would no longer unserialize. */
    public function printToForm(): void
    {
        $form_value = base64_encode(serialize($this));
        echo '<div>';
        $state = isset($_POST['sync']) ? in_array($form_value, (array) $_POST['sync'], true) : ($this->type !== 'UPDATE_VALUE');
        FrmChBoxEasy('sync[]', $state, '', $form_value);
        switch ($this->type) {
            case 'DELETE': echo _m("Delete");
                break;
            case 'INSERT': echo _m("Create new");
                break;
            case 'UPDATE_ROW':
            case 'UPDATE_VALUE': echo _m("Update");
                break;
        }
        echo '</div>';
    }

    /** Action from a printToForm() checkbox value; null for anything else - the value is request input. */
    public static function fromFormValue(string $form_value): ?AA_Sync_Action
    {
        $serialized = base64_decode($form_value, true);
        if ($serialized === false) {
            return null;
        }
        try {
            // @: malformed data only warns and returns false, rejected below
            $action = @unserialize($serialized, ['allowed_classes' => ResponderPolicy::syncAllowedClasses()]);
        } catch (\Throwable) {  // e.g. a foreign object in the typed $identifier
            return null;
        }
        return ($action instanceof self && isset($action->type, $action->identifier)) ? $action : null;
    }

    /** returns data array as we need it for $metabase->doInsert/Update */
    public function _getDataArray()
    {
        $idf    = $this->identifier;
        $column = $idf->getColumn();
        return $column ? [$column => $this->params] : $this->params;
    }

    /** do synchronization action in destination slice */
    public function doAction()
    {

        // commands are stored as tree - like:
        // 6353636737->field->category........
        // 6353636737 is id of slice - we use slice name as identifier
        // here, because slice_id is different in remote slices and we want
        // to synchronize the slices of the same name
        $idf         = $this->identifier;
        $module_id   = $idf->getModuleId();
        $table       = $idf->getTable();
        $row         = $idf->getRow();
        //$column      = $idf->getColumn();

        if (!in_array($table, self::TABLES, true)) {
            return _m('Wrong command - this table is not synchronized');
        }
        $metabase    = AA::Metabase();

        if (!$row) {
            return _m('Wrong command - row is not defined - %1', [$idf->toString()]);
        }
        if ($this->type === 'UPDATE_ROW' || $this->type === 'UPDATE_VALUE') {
            $data = $this->_getDataArray();
            $metabase->fillKeys($data, $idf);
            $metabase->doUpdate($table, $data);
            return _m('%1 %2 in slice %3 updated', [$table, $row, $module_id]);
        }
        if ($this->type === 'INSERT') {
            $data = $this->params;
            $metabase->fillKeys($data, $idf);
            $metabase->doInsert($table, $data);
            return _m('%1 %2 inserted into slice %3', [$table, $row, $module_id]);  // field xy inserted into slice yz
        }
        if ($this->type === 'DELETE') {
            $data = [];
            $metabase->fillKeys($data, $idf);
            $metabase->doDelete($table, $data);
            return _m('%1 %2 deleted from slice %3', [$table, $row, $module_id]);
        }
        return _m("Unknown action (%1) for field %2 in slice %3", [$this->type, $row, $module_id]);
    }
}


/** Stores the synchronization action which should be performed on remote AA
 *  Objects are stored into AA\Later\Toexecute queue for running from Task Manager
 */
class AA_Task_Sync implements LaterInterface
{
    public function __construct(
        /** AA_Sync_Action object - Action to do */
        public $sync_action,
        /** AA_Actionapps object  - In which AA we have to do the action */
        public AA_Actionapps $actionapps,
    ) {}

    /**
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     */
    public function toexecutelater($params = []): string
    {
        // synchronize accepts array of sync_actions, so it is possible
        // to do more action by one call
        $ret = $this->actionapps->synchronize([$this->sync_action]);
        return implode("\n", array_filter((array) $ret, 'is_scalar'));  // one report line per action
    }
}

/** Stores the synchronization action which should be performed on remote AA
 *  Objects are stored into AA\Later\Toexecute queue for running from Task Manager
 */
class AA_Task_Import_Module_Chunk implements LaterInterface
{
    /** @var AA_Definition_Module_Chunk $module_definition_chunk -  object or similar for import */
    protected AA_Definition_Module_Chunk $module_definition_chunk;

    /** @var AA_Actionapps $actionapps object in which AA we have to do the action */
    protected $actionapps;

    public function __construct(AA_Definition_Module_Chunk $module_definition_chunk, AA_Actionapps $actionapps)
    {
        $this->module_definition_chunk = $module_definition_chunk;
        $this->actionapps              = $actionapps;
    }

    /**
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = []): string
    {
        // the remote answers with the report lines (or an error string); the return type is string and
        // a TypeError here would leave the task in the queue and import the chunk again next run
        $ret = $this->actionapps->importModuleChunk($this->module_definition_chunk);
        return implode("\n", array_filter((array) $ret, 'is_scalar'));
    }
}


/** Central_GetAaContent function for loading content of AA configuration
 *  for manager class
 *
 * Loads data from database for given AA ids (called in itemview class)
 * and stores it in the 'Abstract Data Structure' for use with 'item' class
 *
 * @see GetItemContent(), itemview class, item class
 * @param zids $zids array if ids to get from database
 * @return array - Abstract Data Structure containing the links data
 *                 {@link https://actionapps.org/faq/#1337}
 */
function Central_GetAaContent($zids): array
{
    $content = [];
    $ret     = [];

    if ($zids->isEmpty()) {
        return $ret;
    }

    // construct WHERE clausule
    $sel_in = $zids->sqlin(false);
    $SQL = "SELECT * FROM central_conf WHERE id $sel_in";
    StoreTable2Content($content, $SQL, '', 'id');
    // it is unordered, so we have to sort it:
    for ($i = 0, $ino = $zids->count(); $i < $ino; $i++) {
        $id = $zids->id($i);
        $ret[(string) $id] = $content[$id];
    }
    return $ret;
}

/** Central_QueryZids - Finds link IDs for links according to given  conditions
 * @param AA_Set $set
 * @return zids
 * @deprecated - now we are using Metabases methods
 */
function Central_QueryZids(AA_Set $set): zids
{
    // @param array  $conds - search conditions (see FAQ)
    // @param array  $sort - sort fields (see FAQ)
    // @param string $type - bins as known from items AA_BIN_ACTIVE | AA_BIN_HOLDING | AA_BIN_TRASH | AA_BIN_ALL

    $conds = $set->getConds();
    $sort  = $set->getSort();
    $type  = $set->getBins();

    if ($type === AA_BIN_ACTIVE) {
        $type = AA_BIN_APPROVED;   // we do not work with dates, here
    }

    $fields      = AA::Metabase()->getSearchArray('central_conf');
    $join_tables = [];   // not used in this function

    $SQL  = 'SELECT DISTINCT `id` FROM `central_conf` WHERE ';
    $SQL .= CreateBinCondition($type, 'central_conf');
    $SQL .= MakeSQLConditions($fields, $conds, $fields, $join_tables);
    $SQL .= MakeSQLOrderBy($fields, $sort, $join_tables);

    return GetZidsFromSQL($SQL, 's');
}
