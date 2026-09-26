<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Util\LoginToken;

/** What central/responder.php may run, and from which transport. Pure rules, no state. */
class ResponderPolicy
{
    /** Central remote-management commands. Only the Central client sends them, always as the
     *  serialized POST `request`; a `?command=` URL would let a cross-site link (SameSite=Lax
     *  cookies ride top-level GETs) run them as the logged-in superadmin. */
    public const REMOTE_COMMANDS = [
        'Get_Sessionid',
        'Get_Jwt',
        'Logout',
        'Get_Aaname',
        'Get_Modules',
        'Get_Module_Defs',
        'Do_Synchronize',
        'Do_Optimize',
        'Do_Optimize_Type',
        'Do_Import_Module_Chunk',
        'Get_App',
    ];

    /** Admin AJAX commands with a side effect, sent only by the JS AA_Response() helper, which POSTs;
     *  a GET of them can only be a forged cross-site link. */
    public const POST_ONLY_COMMANDS = ['Optimize4id', 'Alertitem'];

    /** responder.php has no such command - also what every older AA answers to a newer one */
    public const ERR_UNKNOWN_COMMAND = 103;

    /** Do_Optimize_Type errors - AA_Response codes 1-8 and 101-106 are taken by the other responders */
    public const ERR_UNKNOWN_OPTIMIZE_TYPE   = 9;
    public const ERR_UNKNOWN_OPTIMIZE_METHOD = 10;
    public const ERR_OPTIMIZE_TYPE_RUNNING   = 11;

    /** Get_Widget: the item (or its slice) does not exist */
    public const ERR_UNKNOWN_ITEM = 12;

    /** Get_Jwt: no username and password of an AA user in the request */
    public const ERR_TOKEN_NEEDS_PASSWORD = 13;

    /** a Bearer token was sent and refused - Central has to authenticate again */
    public const ERR_TOKEN_REFUSED = 14;

    /** Central side, never sent by a responder: no token stored for the AA, so nothing was asked */
    public const ERR_NOT_AUTHENTICATED = 15;

    /** Get_App: no such app, or the user may not export it - one answer, so it tells no module ids */
    public const ERR_APP_DENIED = 16;
    /** Get_App: the export found errors (E); the answer lists them as "code path ref" */
    public const ERR_APP_EXPORT = 17;
    /** Get_App: a parameter is missing or of a wrong form */
    public const ERR_APP_REQUEST = 18;
    /** Get_App: the source failed - the detail is in its PHP error log */
    public const ERR_APP_FAILED = 19;
    /** the token of the request is of a scope that does not allow the command */
    public const ERR_TOKEN_SCOPE = 20;
    /** Get_Jwt: ttl or scope of a form this AA cannot read */
    public const ERR_TOKEN_REQUEST = 21;

    /** what a token of the scope apps may run: what another AA needs to pull an app */
    private const APPS_SCOPE_COMMANDS = ['Get_App', 'Get_Modules'];

    /** Optimize types Do_Optimize_Type runs as a chain (AA\Optimize\Chain). The State Check tags
     *  report through findings(), private_fix and opt_feature are one-off, site-chosen repairs. */
    private const OPTIMIZE_TYPES = ['sql_update'];

    /** Chain methods: sql_update.php "Test" and "Install / Update" */
    private const OPTIMIZE_TYPE_METHODS = ['test', 'repair'];

    /** Module types that have an AA_Definition_Module_<Type> class (central/include/actionapps.class.php). */
    private const MODULE_DEFINITIONS = [
        'Slice'  => 'AA_Definition_Module_Slice',
        'Site'   => 'AA_Definition_Module_Site',
        'Alerts' => 'AA_Definition_Module_Alerts',
    ];

    /** $name = AA_Responder::name() - the declared class name, whatever case the request used. */
    public static function isRemoteCommand(string $name): bool
    {
        return in_array($name, self::REMOTE_COMMANDS, true);
    }

    /** $name = AA_Responder::name(); an admin token runs anything (isPerm() still decides) */
    public static function scopeAllows(string $scope, string $name): bool
    {
        return match ($scope) {
            LoginToken::SCOPE_ADMIN => true,
            LoginToken::SCOPE_APPS  => in_array($name, self::APPS_SCOPE_COMMANDS, true),
            default                 => false,
        };
    }

    /** The parameters of Get_Jwt; null = one of a wrong form - an error, never a silent year-long admin token.
     *  @return array{ttl: ?int, scope: string}|null ttl null = the long token */
    public static function jwtParams(mixed $param): ?array
    {
        $param = is_array($param) ? $param : [];
        $ttl   = $param['ttl'] ?? null;
        $scope = $param['scope'] ?? LoginToken::SCOPE_ADMIN;
        if (is_string($ttl) && preg_match('/^-?\d{1,10}\z/', $ttl)) {
            $ttl = (int) $ttl;
        }
        if ((($ttl !== null) && !is_int($ttl)) || !in_array($scope, [LoginToken::SCOPE_ADMIN, LoginToken::SCOPE_APPS], true)) {
            return null;
        }
        return ['ttl' => $ttl, 'scope' => $scope];
    }

    public static function isPostOnlyCommand(string $name): bool
    {
        return in_array($name, self::POST_ONLY_COMMANDS, true);
    }

    /** A browser request sent by a page of another origin. SameSite=Lax cookies still ride POSTs
     *  from sibling subdomains (*.ecn.cz share a registrable domain), so Lax alone is no CSRF guard.
     *  Server-to-server calls (Central's AA_Http) send neither header, so they pass. */
    public static function isForeignBrowserRequest(array $server): bool
    {
        if (isset($server['HTTP_SEC_FETCH_SITE'])) {
            return !in_array($server['HTTP_SEC_FETCH_SITE'], ['same-origin', 'none'], true);
        }
        if (!isset($server['HTTP_ORIGIN'])) {
            return false;
        }
        $origin = parse_url((string) $server['HTTP_ORIGIN']);  // 'null' (sandboxed/opaque origin) has no host
        $host   = isset($origin['host']) ? $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : '') : '';
        return $host === '' || strcasecmp($host, (string) ($server['HTTP_HOST'] ?? '')) !== 0;
    }

    /** Class to instantiate for a Get_Module_Defs type; null for anything else, so input never names a class. */
    public static function moduleDefinitionClass(string $type): ?string
    {
        return self::MODULE_DEFINITIONS[$type] ?? null;
    }

    /** Why Do_Optimize_Type refuses the type/method, null when it may run
     *  @return array{0: string, 1: int}|null  [message, AA_Response error code] */
    public static function optimizeTypeRefusal(string $type, string $method): ?array
    {
        if (!in_array($type, self::OPTIMIZE_TYPES, true)) {
            return ["Unknown optimize type: $type", self::ERR_UNKNOWN_OPTIMIZE_TYPE];
        }
        if (!in_array($method, self::OPTIMIZE_TYPE_METHODS, true)) {
            return ["Unsupported method '$method' for optimize type $type", self::ERR_UNKNOWN_OPTIMIZE_METHOD];
        }
        return null;
    }

    /** What AA_Sync_Action::printToForm() serializes: the action and its AA_Identifier; params are DB rows (scalars/arrays).
     *  Plain strings: central/ is outside the classmap and PHPStan's view.
     *  @return list<string> */
    public static function syncAllowedClasses(): array
    {
        return ['AA_Sync_Action', 'AA_Identifier'];
    }
}
