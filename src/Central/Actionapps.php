<?php

declare(strict_types=1);

namespace AA\Central;

use AA;
use AA\IO\DB\DB_AA;
use AA\Util\Crypt;
use AA\Util\LoginToken;
use AA\Util\Secret;
use AA_Definition_Module;
use AA_Definition_Module_Alerts;
use AA_Definition_Module_Site;
use AA_Definition_Module_Slice;
use AA_Request;
use AA_Response;
use AA_Set;
use Files;
use ItemContent;
use LogicException;
use RuntimeException;
use Throwable;
use zids;

/**
 * One AA installation as Central sees it - its central_conf row and the commands Central sends
 * to its responder (central/responder.php).
 *
 * AA_Actionapps stays an alias: old callers use it and older toexecute rows hold it (see __unserialize()).
 */
class Actionapps
{
    /** what AA_ADMIN_PWD holds (credentialState()) */
    public const CREDENTIAL_NONE       = 'none';
    public const CREDENTIAL_TOKEN      = 'token';
    /** anything but v1: - a password or a token in plain text (older Central); never sent */
    public const CREDENTIAL_PLAIN      = 'plain';
    /** encrypted by another key (AA_SECRET changed) or for another address (the row was edited) */
    public const CREDENTIAL_UNREADABLE = 'unreadable';

    private const SEALED = 'v1:';

    /** no usable answer */
    private const ERR_TRANSPORT = 3;

    /** a report comes after the remote AA did the work - a long one must not be lost */
    private const REPORT_BYTES = 4 * 1024 * 1024;

    /** the only objects an answer may hold - those of Get_Module_Defs */
    private const DEFINITION_CLASSES = [AA_Definition_Module::class, AA_Definition_Module_Slice::class, AA_Definition_Module_Site::class, AA_Definition_Module_Alerts::class];

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

    /** The login token for this AA; null when there is none or it cannot be used here (credentialState()) */
    public function getAuthToken(): ?string
    {
        return $this->credential()[1];
    }

    /** what AA_ADMIN_PWD holds - one of the CREDENTIAL_* constants */
    public function credentialState(): string
    {
        return $this->credential()[0];
    }

    /** @return array{string, ?string} the state and the token usable for getComunicatorUrl() */
    private function credential(): array
    {
        $stored = (string) $this->getValue('AA_ADMIN_PWD');
        if ($stored === '') {
            return [self::CREDENTIAL_NONE, null];
        }
        if (!str_starts_with($stored, self::SEALED)) {
            return [self::CREDENTIAL_PLAIN, null];
        }
        try {
            // the address as additional data: a token of one AA never goes to the server an edited row names
            $token = Crypt::decryptAesGcm(substr($stored, strlen(self::SEALED)), Secret::key('central-credential'), $this->getComunicatorUrl());
        } catch (Throwable) {                       // no AES-GCM in this PHP, a malformed value
            $token = false;
        }
        return is_string($token) ? [self::CREDENTIAL_TOKEN, $token] : [self::CREDENTIAL_UNREADABLE, null];
    }

    /** Keeps the token encrypted, bound to the responder URL it was given for
     *  @throws RuntimeException no AES-GCM in this PHP */
    public function setAuthToken(#[\SensitiveParameter] string $jwt): void
    {
        if (!static::aesGcmAvailable()) {
            throw new RuntimeException(_m('This PHP has no AES-GCM (sodium) - the login token cannot be stored encrypted'));
        }
        $this->local_data->setValue('AA_ADMIN_PWD', self::SEALED . Crypt::encryptAesGcm($jwt, Secret::key('central-credential'), $this->getComunicatorUrl()));
    }

    /** setAuthToken() and the central_conf row of this AA
     *  @throws RuntimeException no AES-GCM in this PHP
     *  @throws LogicException an AA of no central_conf row */
    public function saveAuthToken(#[\SensitiveParameter] string $jwt): void
    {
        $id = $this->getId() ?? throw new LogicException('The AA has no central_conf row - the token cannot be saved');
        $this->setAuthToken($jwt);
        DB_AA::update('central_conf', [['AA_ADMIN_PWD', (string) $this->getValue('AA_ADMIN_PWD'), 's']], [['id', $id, 'i']]);
    }

    /** AES-256-GCM of sodium needs the CPU's AES instructions */
    protected static function aesGcmAvailable(): bool
    {
        return function_exists('sodium_crypto_aead_aes256gcm_is_available') && sodium_crypto_aead_aes256gcm_is_available();
    }

    /** What the Central list says of the login. The claims are read unverified - a display only. A token
     *  dies by its exp or by its age (LoginToken::verify()), whichever comes first. */
    public function credentialBadge(?int $now = null): string
    {
        [$state, $jwt] = $this->credential();
        $claims        = ($jwt === null) ? null : RemoteSource::claims($jwt);
        if (!is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null)) {
            return ($state === self::CREDENTIAL_NONE) ? _m('Not configured') : _m('Not authenticated - get a token');
        }
        $until = min($claims['exp'], LoginToken::ageLimit($claims['iat']));
        return ($until > ($now ?? time())) ? _m('Authenticated until %1', [date('Y-m-d H:i', $until)]) : _m('Expired - get a token');
    }

    /** The central_conf rows for the list of the Central page: AA_ADMIN_PWD holds its credentialBadge(),
     *  never the stored value
     *  @param array{table: string} $settings @return array<string, \AA_Content> */
    public static function listContent(zids $zids, array $settings): array
    {
        $content = \AA_Metabase::getContent($zids, $settings);
        foreach ($content as $row) {
            $row->setValue('AA_ADMIN_PWD', (new self(new ItemContent($row->getContent())))->credentialBadge());
        }
        return $content;
    }

    /** Authenticate to remote AA and get JWT token
     *  @return string JWT token on success, empty string on failure (getLastError() says why)
     */
    public function requestJwt(string $username, string $password): string
    {
        $response = $this->send(new AA_Request('Get_Jwt'), ['username' => $username, 'password' => $password], '');
        $jwt      = $response->getResponse();
        $this->last_error = match (true) {
            $response->isError()                                 => self::responseText($response),
            !is_string($jwt) || !RemoteSource::isToken($jwt)     => _m('The remote AA gave no login token - update it'),
            !RemoteSource::livesADay(RemoteSource::claims($jwt)) => _m('The remote AA gave a token for longer than a day - update the remote AA'),
            default                                              => null,
        };
        return ($this->last_error === null) ? $jwt : '';
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
        if ($response->getError() === self::ERR_TRANSPORT) {   // timed out or unreadable - the remote run goes on
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

    /** Main communication function - returns AA_Response object; getLastError() tells why this call failed */
    public function getResponse($request)
    {
        [$state, $jwt] = $this->credential();
        $response = match (true) {
            $jwt !== null => $this->send($request, [], $jwt),
            $state === self::CREDENTIAL_UNREADABLE => new AA_Response(_m('The stored login token cannot be used (AA_SECRET, the database password or the address of this AA changed) - authenticate this AA again on the ActionApps Central page'), ResponderPolicy::ERR_NOT_AUTHENTICATED),
            default => new AA_Response(_m('No login token for this AA - Authenticate this AA on the ActionApps Central page'), ResponderPolicy::ERR_NOT_AUTHENTICATED),
        };
        $this->last_error = $response->isError() ? self::responseText($response) : null;
        return $response;
    }

    /** The one way a login or a token leaves Central: RemoteSource - https only, no redirect, the peer
     *  verified, a capped answer of plain data. A failure is an error answer. */
    private function send(AA_Request $request, array $post, string $jwt): AA_Response
    {
        $command = (string) $request->getCommand();
        try {
            return $this->remote()->ask($request, $post, $jwt, self::answerBytes($command), ($command === 'Get_Module_Defs') ? self::DEFINITION_CLASSES : []);
        } catch (SourceFailed $e) {
            return new AA_Response($e->getMessage(), self::ERR_TRANSPORT);
        }
    }

    /** The module definitions of all the slices Synchronize compares are as big as an app package; the
     *  reports a few MB at most; names, lists and tokens are small. */
    private static function answerBytes(string $command): int
    {
        return match ($command) {
            'Get_Module_Defs'                               => RemoteSource::MAX_BYTES,
            'Do_Synchronize', 'Do_Optimize', 'Do_Optimize_Type' => self::REPORT_BYTES,
            default                                         => RemoteSource::SMALL_BYTES,
        };
    }

    /** the client for the responder of this AA */
    protected function remote(): RemoteSource
    {
        return new RemoteSource($this->getComunicatorUrl());
    }

    /** the error of the last call as a line for $err of a Central page, '' after a success */
    public function lastErrorHtml(): string
    {
        return ($this->last_error === null) ? '' : safe($this->getName() . ': ' . $this->last_error);
    }

    /// Static methods
    /** every central_conf row, whatever its bin
     * @return Actionapps[]
     */
    public static function all(): array
    {
        $ret = [];
        foreach (self::loadContent(GetZidsFromSQL('SELECT id FROM central_conf ORDER BY id', 's')) as $k => $content4id) {
            $ret[$k] = new Actionapps(new ItemContent($content4id));
        }
        return $ret;
    }

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
