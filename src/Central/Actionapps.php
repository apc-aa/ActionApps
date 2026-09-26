<?php

declare(strict_types=1);

namespace AA\Central;

use AA;
use AA\IO\DB\DB_AA;
use AA_Definition_Module_Chunk;
use AA_Request;
use AA_Response;
use AA_Set;
use Files;
use ItemContent;
use zids;

/**
 * One AA installation as Central sees it - its central_conf row and the commands Central sends
 * to its responder (central/responder.php).
 *
 * AA_Actionapps stays an alias: old callers use it and older toexecute rows hold it (see __unserialize()).
 */
class Actionapps
{
    /** local data (central_conf table) in ItemContent structure */
    private ItemContent $local_data;

    private $last_error = null;

    /** cached remote data - like AA name, ... */
    private array $_cached = [];

    /** @param ItemContent $content4id a central_conf row */
    public function __construct(ItemContent $content4id)
    {
        $this->local_data          = $content4id;
    }

    /** Older toexecute rows hold O:13:"AA_Actionapps" with private names like "\0AA_Actionapps\0local_data",
     *  which PHP would not match to this class - each value is taken by its bare name. */
    public function __unserialize(array $data): void
    {
        foreach (SerializedProps::bare($data) as $name => $value) {
            // a subclass's own private property can't be written from here (Error) - no such subclass is serialized
            if (property_exists($this, $name)) {
                $this->$name = $value;
            }
        }
    }

    /** the AA of the central_conf row, cached; an AA with no data when there is no such row
     * @param $aa_id
     * @return Actionapps
     */
    public static function getActionapps($aa_id)
    {
        static $aas = [];
        if (!isset($aas[$aa_id])) {
            $aa_ic       = self::loadContent(new zids($aa_id, 's'));
            $aas[$aa_id] = new Actionapps(new ItemContent($aa_ic[$aa_id]));
        }
        return $aas[$aa_id];
    }

    /** The AA of the central_conf row (whatever its bin), or null when there is no such row - unlike
     *  getActionapps() it never makes an AA of nothing. Cached for the request, so the tasks for one AA
     *  in one queue run share the AA. */
    public static function find(int $aa_id): ?Actionapps
    {
        static $aas = [];
        if (!array_key_exists($aa_id, $aas)) {
            $aa_ic       = self::loadContent(new zids((string) $aa_id, 's'));
            $aas[$aa_id] = isset($aa_ic[$aa_id]) ? new Actionapps(new ItemContent($aa_ic[$aa_id])) : null;
        }
        return $aas[$aa_id];
    }

    /** central_conf.id of this AA; null for an AA not loaded from central_conf */
    public function getId(): ?int
    {
        $id = isset($this->local_data) ? $this->getValue('id') : null;   // unset in an AA unserialized from nothing
        return (is_numeric($id) && ((int) $id > 0)) ? (int) $id : null;
    }

    /** The address of a page of that AA, like "central/installapp.php" - from the row, never from a request.
     *  null when the domain is no absolute http(s) address, or when a domain without a trailing slash is glued
     *  to a relative base dir ("https://example.org" + "aa/" names another host): the row is edited by hand
     *  and "javascript:" would run in our page. */
    public function getPageUrl(string $page): ?string
    {
        $domain = (string) $this->getValue('AA_HTTP_DOMAIN');
        $base   = (string) $this->getValue('AA_BASE_DIR');
        if (!preg_match('~^https?://[^/?#\s@\\\\]+(/[^?#\s]*)?$~iD', $domain)) {
            return null;
        }
        if (!str_ends_with($domain, '/') && ($base !== '') && !str_starts_with($base, '/')) {
            return null;
        }
        $dir = trim($base, '/');
        return rtrim($domain, '/') . '/' . (($dir === '') ? '' : "$dir/") . ltrim($page, '/');
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
        $this->last_error = self::responseText($response);
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

    /** Get last communication error - the code and the text */
    public function getLastError()
    {
        return $this->last_error;
    }


    /** name of AA as in local table*/
    public function getName(): string
    {
        $address = $this->getValue('AA_HTTP_DOMAIN') . $this->getValue('AA_BASE_DIR');
        $name    = (string) $this->getValue('ORG_NAME');
        return (trim($name) === '') ? $address : "$name ($address)";
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
        // one call for all the slices - much quicker than a call per slice
        $response = $this->getResponse(new AA_Request('Get_Module_Defs', ['type' => $type, 'ids' => $ids, 'limited' => $limited]));
        return ($response->isError()) ? [] : $response->getResponse();
    }

    /** This command synchronizes the slices base on sync[] array
     *  return the report on the synchronization
     */
    public function synchronize($sync_commands)
    {
        $response = $this->getResponse(new AA_Request('Do_Synchronize', ['sync' => $sync_commands]));
        return ($response->isError()) ? self::responseText($response) : $response->getResponse();
    }

    /** This command calls optimize method
     *  return the report on the optimize
     */
    public function doOptimize($optimize_class, $optimize_method): string
    {
        return self::responseText($this->getResponse(new AA_Request('Do_Optimize', ['class' => $optimize_class, 'method' => $optimize_method])));
    }

    /** Runs all Optimize classes of the type ('sql_update') on the remote AA - the remote picks
     *  the classes of its own version (AA\Optimize\Chain)
     *  @param string $method 'test' | 'repair'
     *  @return string the remote report (HTML, 'OK ' or 'Err ' first) or the error
     */
    public function doOptimizeType(string $type, string $method): string
    {
        $response = $this->getResponse(new AA_Request('Do_Optimize_Type', ['type' => $type, 'method' => $method]));
        $text     = self::responseText($response);
        if ($response->getError() === 3) {   // AA_Request::ask(): timed out or unreadable - the remote run goes on
            $text .= ' - ' . _m('the update may still be running on the remote AA - wait, then run Test before retrying');
        }
        return $text;
    }

    /** The answer of a remote command as text for a report: the response, or the error code and detail */
    public static function responseText(AA_Response $response): string
    {
        $detail = $response->getResponse();
        if (!$response->isError()) {
            // the remote decides the type - concatenating an object would throw
            return is_scalar($detail) ? (string) $detail : _m('Unexpected response from remote AA');
        }
        if ($response->getError() === ResponderPolicy::ERR_UNKNOWN_COMMAND) {
            return _m('This AA does not support this command (update it first)');
        }
        return $response->getError() . (is_scalar($detail) ? ' ' . $detail : '');
    }

    /** Imports slice to the current AA. The id of slice is the same as in
     *  definition
     */
    public function importModuleChunk(AA_Definition_Module_Chunk $definition_chunk)
    {
        $response = $this->getResponse(new AA_Request('Do_Import_Module_Chunk', ['definition_chunk' => $definition_chunk]));
        return ($response->isError()) ? self::responseText($response) : $response->getResponse();
    }

    /** Main communication function - returns AA_Response object; getLastError() tells why this call failed */
    public function getResponse($request)
    {
        $jwt = $this->getAuthToken();
        $response = $jwt
            ? $request->ask($this->getComunicatorUrl(), [], ['Authorization' => 'Bearer ' . $jwt])
            : new AA_Response(_m('No login token for this AA - Authenticate this AA on the ActionApps Central page'), ResponderPolicy::ERR_NOT_AUTHENTICATED);
        $this->last_error = $response->isError() ? self::responseText($response) : null;
        return $response;
    }

    /** the error of the last call as a line for $err of a Central page, '' after a success */
    public function lastErrorHtml(): string
    {
        return ($this->last_error === null) ? '' : safe($this->getName() . ': ' . $this->last_error);
    }

    /// Static methods
    /** create array of all Approved AAs from central database
     * @return Actionapps[]
     */
    public static function getArray(): array
    {
        $ret    = [];
        $set = new AA_Set(null, null, [['ORG_NAME' => 'a']], AA_BIN_APPROVED);  // list of sort specs - a bare map sorts by field "a"
        $zids   = self::queryIds($set);
        $aa_ic  = self::loadContent($zids);

        foreach ($aa_ic as $k => $content4id) {
            $ret[$k] = new Actionapps(new ItemContent($content4id));
        }
        return $ret;
    }

    /** The AA of the row when getArray() lists it (the approved bin), else null - the pages that take
     *  a row id from a request accept only what their pickers offer. */
    public static function listed(int $id): ?Actionapps
    {
        return self::getArray()[$id] ?? null;
    }

    /** Loads the central_conf rows of the ids
     *
     * Stores them in the 'Abstract Data Structure' for use with ItemContent, in the order of $zids
     * @return array - Abstract Data Structure {@link https://actionapps.org/faq/#1337}
     */
    private static function loadContent(zids $zids): array
    {
        $content = [];
        $ret     = [];

        if ($zids->isEmpty()) {
            return $ret;
        }

        $sel_in = $zids->sqlin('');
        $SQL = "SELECT * FROM central_conf WHERE id $sel_in";
        StoreTable2Content($content, $SQL, '', 'id');
        // it is unordered, so we have to sort it:
        for ($i = 0, $ino = $zids->count(); $i < $ino; $i++) {
            $id = $zids->id($i);
            if (isset($content[$id])) {   // a missing row stays missing
                $ret[(string) $id] = $content[$id];
            }
        }
        return $ret;
    }

    /** Finds the central_conf ids for the conditions, sort and bins of the set */
    private static function queryIds(AA_Set $set): zids
    {
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
}
