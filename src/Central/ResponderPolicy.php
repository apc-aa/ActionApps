<?php

declare(strict_types=1);

namespace AA\Central;

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
        'Do_Import_Module_Chunk',
    ];

    /** Admin AJAX commands with a side effect, sent only by the JS AA_Response() helper, which POSTs;
     *  a GET of them can only be a forged cross-site link. */
    public const POST_ONLY_COMMANDS = ['Optimize4id', 'Alertitem'];

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

    /** What AA_Sync_Action::printToForm() serializes: the action and its AA_Identifier; params are DB rows (scalars/arrays).
     *  Plain strings: central/ is outside the classmap and PHPStan's view.
     *  @return list<string> */
    public static function syncAllowedClasses(): array
    {
        return ['AA_Sync_Action', 'AA_Identifier'];
    }
}
