<?php

declare(strict_types=1);

namespace AA\Central;

use AA;
use AA\Central\Package\CollectResult;
use AA\Central\Package\DbSource;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\ModuleCharset;
use AA\Central\Package\Source;
use AA\IO\DB\DB_AA;
use AA_Log;
use Closure;

/** The app store: the apps (sites) an AA publishes to anyone, and the public account other AAs read them
 *  with. A superadmin publishes a site by its settings (app_publish, app_description, app_version) with
 *  the modules it uses then (app_modules); the public account then gets its definitions for an install -
 *  nothing else, checked on every request. A site that uses a module added since is not served until a
 *  superadmin publishes it again: "uses" is edited by any admin of the site.
 *
 *  The public account is an ordinary AA user without any rights. Its password is in every AA's code, so
 *  the account opens only what is public anyway: it cannot log in to the administration (AA_Auth), and
 *  on central/responder.php it gets an apps token and runs Get_Apps and Get_App with it
 *  (ResponderPolicy::publicAllows()), at most LIMITS requests an hour from one address. */
final class AppStore
{
    public const PUBLIC_USER = 'aa-app-store';
    /** public on purpose - see the class doc; changing it locks every AA out of every store */
    public const PUBLIC_PASSWORD = 'foC521ovhk1jmSKrO96fN6_fKKgCZwbxvRoTt2eq';

    /** the store an AA asks, unless the config names another (AA_APP_STORE_URL) - the AA's install URL */
    public const DEFAULT_URL = 'https://actionapps.org/apc-aa/';

    /** E: a published app uses a constant group of a slice no published site carries - its values would
     *  leak; ref = the owning slice */
    public const GROUP_UNPUBLISHED = 'group-unpublished';

    /** log rows of the public account's requests - the limit counts them */
    public const LOG = 'APPSTORE';
    /** requests of the public account per command and client (address, IPv6 /64) in an hour; an install
     *  logs in and asks for the definitions at the preview and at the write */
    private const LIMITS = ['Get_Jwt' => 30, 'Get_App' => 120, 'Get_Apps' => 120];
    /** ... from one IPv6 /48 - a site commonly has one, so its /64s must not add up without a limit */
    private const NETWORK_LIMITS = ['Get_Jwt' => 120, 'Get_App' => 480, 'Get_Apps' => 480];
    /** ... and from all clients together - many addresses must not add up to more than the store can give */
    private const GLOBAL_LIMITS = ['Get_Jwt' => 500, 'Get_App' => 2000, 'Get_Apps' => 2000];
    private const HOUR = 3600;
    /** seconds a kept token must still live to be used */
    private const TOKEN_MARGIN = 600;

    private const SETTINGS_CLASS = 'AA_Modulesettings_Site';
    /** the site settings of the store - AA_Modulesettings_Site::APP_PROPERTIES */
    private const SETTINGS = ['app_publish', 'app_description', 'app_version', 'app_modules'];

    /** @var ?list<string> */
    private ?array $published = null;

    public function __construct(private readonly Source $source) {}

    public static function url(): string
    {
        return defined('AA_APP_STORE_URL') ? (string) constant('AA_APP_STORE_URL') : self::DEFAULT_URL;
    }

    public static function responderUrl(): string
    {
        return rtrim(self::url(), '/') . '/central/responder.php';
    }

    /** the public account of this AA - a user (never a reader) of its login name, of the SQL backend */
    public static function isPublic(string $uid): bool
    {
        return ($uid !== '') && self::sqlBackend() && AA::$perm->isUserEditable($uid) && (AA::$perm->getUserLoginName($uid) === self::PUBLIC_USER);
    }

    /** Only AA's own user table has the account: in LDAP the name may belong to anyone, and its password
     *  is set there, not by this AA.
     *  @param ?string $permLib PERM_LIB; null = this AA's */
    public static function sqlBackend(?string $permLib = null): bool
    {
        return ($permLib ?? (defined('PERM_LIB') ? (string) constant('PERM_LIB') : '')) === 'sql';
    }

    /** $recent requests of the command from the client in the last hour, $network from its IPv6 /48 or
     *  $all from anyone are the limit (or more); a command without a limit is never the public account's */
    public static function overLimit(string $command, int $recent, int $all = 0, int $network = 0): bool
    {
        return ($recent >= (self::LIMITS[$command] ?? 0)) || ($network >= (self::NETWORK_LIMITS[$command] ?? 0)) || ($all >= (self::GLOBAL_LIMITS[$command] ?? 0));
    }

    /** the /48 of an IPv6 client; null for IPv4 (and IPv4 mapped to IPv6) or no address */
    public static function networkKey(string $ip): ?string
    {
        $bin = ($ip === '') ? false : @inet_pton($ip);
        if (($bin === false) || (strlen($bin) !== 16) || str_starts_with($bin, str_repeat("\0", 10) . "\xFF\xFF")) {
            return null;
        }
        return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48';
    }

    /** the client the limit counts: an IPv4 address, or the /64 of an IPv6 one - a single host commonly
     *  has a whole /64 */
    public static function clientKey(string $ip): string
    {
        $bin = ($ip === '') ? false : @inet_pton($ip);
        if (($bin === false) || !in_array(strlen($bin), [4, 16], true)) {
            return 'unknown';
        }
        if ((strlen($bin) === 16) && str_starts_with($bin, str_repeat("\0", 10) . "\xFF\xFF")) {
            $bin = substr($bin, 12);            // IPv4 mapped to IPv6
        }
        return (strlen($bin) === 4) ? (string) inet_ntop($bin) : inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** Counts a request of the public account from $ip; false when the client or the store used up its hour.
     *  Without its log rows nothing counts, so then it is always false: an admin who stops logging LOG
     *  ($DO_NOT_LOG) closes the store rather than leaving it without a limit. */
    public static function admit(string $command, string $ip, string $uid, ?int $now = null): bool
    {
        if (!AA_Log::isLogable(self::LOG)) {
            return false;
        }
        $now      = $now ?? time();
        $network  = self::networkKey($ip);
        // "command:/48 /64" for IPv6, so that both count from one row
        $selector = mb_strcut($command . ':' . (($network === null) ? '' : "$network ") . self::clientKey($ip), 0, 255);
        $since    = ['time', $now - self::HOUR, '>='];
        $recent   = DB_AA::count('log', [['type', self::LOG], $since, ['selector', $selector]]);
        $in48     = ($network === null) ? 0 : DB_AA::count('log', [['type', self::LOG], $since, ['selector', "$command:$network ", 'BEGIN']]);
        $all      = DB_AA::count('log', [['type', self::LOG], $since, ['params', $command]]);
        if (self::overLimit($command, $recent, $all, $in48)) {
            return false;
        }
        $row = ['time' => (string) $now, 'user' => $uid, 'type' => self::LOG, 'selector' => $selector, 'params' => $command];
        return AA::Metabase()->doInsert('log', $row, 'nohalt') !== false;
    }

    /** a token of the store that lives long enough for a preview and its write */
    public static function fresh(string $token, int $now): bool
    {
        $exp = RemoteSource::claims($token)['exp'] ?? null;
        return is_int($exp) && ($exp > $now + self::TOKEN_MARGIN);
    }

    /** The store asked with the token of a run while it lives, else by a new login - the password is
     *  public, so there is no one to ask for it.
     *  @throws SourceFailed */
    public static function source(RemoteSource $remote, string $token, ?int $now = null): RemoteAppSource
    {
        return new RemoteAppSource($remote, self::fresh($token, $now ?? time()) ? $token : $remote->jwt(self::PUBLIC_USER, self::PUBLIC_PASSWORD));
    }

    /** Why the store would not give the site (its export as the store's, whether published yet or not):
     *  the E findings of the export; [] = it would give it. For the superadmin who publishes the site.
     *  @return list<array{string, LintFinding}> */
    public static function exportErrors(string $site): array
    {
        $export = new AppExport(new DbSource(), ['url' => (string) constant('AA_INSTAL_URL'), 'aa-version' => aa_version('aa')], static fn(): bool => true, true, true);
        try {
            $export->definitions([$site], false, false, time());
            return [];
        } catch (ExportRefused $e) {
            return $e->errors() ?: [['site-module', new LintFinding(LintFinding::ERROR, 'export-denied', $site, 0)]];
        } catch (\Throwable $e) {
            error_log('AA app store: the export of a published site failed: ' . preg_replace('/[\x00-\x1F\x7F]+/', ' ', get_class($e) . ': ' . $e->getMessage()));
            return [['site-module', new LintFinding(LintFinding::ERROR, 'export-failed', $site, 0)]];
        }
    }

    /** the store settings of a site; null for no site (or a deleted one). changed: published, but it uses
     *  the added modules it did not use when it was published - not served until a superadmin approves them.
     *  @return ?array{published: bool, description: string, version: string, changed: bool, added: list<string>} */
    public function site(string $site): ?array
    {
        if (!$this->isSite($site)) {
            return null;
        }
        $settings = $this->settings([$site])[$site];
        $added    = $settings['publish'] ? array_values(array_diff([$site, ...$this->uses([$site])[$site]], $settings['modules'])) : [];
        return ['published' => $settings['publish'] && !$added, 'description' => $settings['description'], 'version' => $settings['version'], 'changed' => (bool) $added, 'added' => $added];
    }

    /** What a superadmin's save of the site approves. Publishing first approves what the site uses; later
     *  a module added since is approved only when asked explicitly - saving the form for another reason
     *  must not put it in the store. A module taken away is never approved again by itself.
     *  @param ?list<string> $stored approved so far; null = not published yet
     *  @param list<string> $current the site and the modules it uses now @return list<string> */
    public static function approvedModules(?array $stored, array $current, bool $approveAdded): array
    {
        return (($stored === null) || $approveAdded) ? $current : array_values(array_intersect($current, $stored));
    }

    /** what publishing the site now approves: the site and the modules it uses; [] for no site
     *  @return list<string> */
    public function modulesOf(string $site): array
    {
        return $this->isSite($site) ? [$site, ...$this->uses([$site])[$site]] : [];
    }

    public function published(string $site): bool
    {
        return $this->site($site)['published'] ?? false;
    }

    /** the published sites and the slices and Alerts modules they use - what the public account may read
     *  @return list<string> */
    public function publishedModules(): array
    {
        if ($this->published === null) {
            $sites           = array_keys($this->sites());
            $uses            = $this->uses($sites);
            $settings        = $this->settings($sites);
            $served          = array_values(array_filter($sites, fn(string $site): bool => $this->approved($settings[$site], $uses[$site], $site)));
            $this->published = array_values(array_unique(array_merge($served, ...array_map(static fn(string $site): array => $uses[$site], $served))));
        }
        return $this->published;
    }

    /** What Get_Apps lists: the published sites, and with $mayEdit also every other site whose modules the
     *  user may all edit; by name.
     *  @param ?Closure(string): bool $mayEdit PS_EDIT on a module; null = the public account
     *  @return list<array{id: string, name: string, description: string, version: string, published: bool, slices: int, alerts: int}> */
    public function apps(?Closure $mayEdit): array
    {
        $sites    = $this->sites();
        $settings = $this->settings(array_keys($sites));
        $uses     = $this->uses(array_keys($sites));
        $types    = array_column($uses ? $this->source->select('module', [['id', array_values(array_unique(array_merge(...array_values($uses)))), 'l']]) : [], 'type', 'id');
        $apps     = [];
        foreach ($sites as $id => [$name, $charset]) {
            $modules   = [$id, ...$uses[$id]];
            $published = $this->approved($settings[$id], $uses[$id], $id);
            if ($published || (($mayEdit !== null) && !array_filter($modules, static fn(string $m): bool => !$mayEdit($m)))) {
                $counts = array_count_values(array_map(static fn(string $m): string => (string) ($types[$m] ?? ''), $uses[$id]));
                $apps[] = ['id' => $id, 'name' => self::utf8($name, $charset), 'description' => self::utf8($settings[$id]['description'], $charset), 'version' => $settings[$id]['version'],
                    'published' => $published, 'slices' => $counts['S'] ?? 0, 'alerts' => $counts['Alerts'] ?? 0];
            }
        }
        usort($apps, static fn(array $a, array $b): int => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);
        return $apps;
    }

    /** served: published, and every module it uses now was approved when it was published
     *  @param array{publish: bool, modules: list<string>} $settings @param list<string> $uses */
    private function approved(array $settings, array $uses, string $site): bool
    {
        return $settings['publish'] && !array_diff([$site, ...$uses], $settings['modules']);
    }

    private function isSite(string $site): bool
    {
        if (!preg_match('/^[0-9a-f]{32}\z/', $site)) {
            return false;
        }
        $row = $this->source->select('module', [['id', $site, 'l']])[0] ?? [];
        return (($row['type'] ?? '') === 'W') && !(int) $row['deleted'];
    }

    /** a text of the site's charset in UTF-8, as the list is; what does not convert is dropped */
    private static function utf8(string $text, string $charset): string
    {
        $utf = ModuleCharset::toUtf8($text, $charset)[0];
        return mb_check_encoding($utf, 'UTF-8') ? $utf : mb_scrub($utf, 'UTF-8');
    }

    /** The findings a published app gets for the constant groups owned by a slice outside every published
     *  site: the public account may not read them (the export refuses the owner), so the superadmin who
     *  publishes the app is told why.
     *  @return list<array{string, LintFinding}> */
    public function unpublishedGroups(CollectResult $collected): array
    {
        $app     = array_map(static fn(Module $m): string => $m->id, $collected->package->modules);
        $owners  = array_values(array_diff($collected->checkPermissions, $app, $this->publishedModules()));
        $names   = array_map(static fn($g): string => $g->name, $collected->package->constantGroups);
        $rows    = ($owners && $names) ? $this->source->select('constant_slice', [['group_id', $names, 's'], ['slice_id', $owners, 'l']]) : [];
        $found   = [];
        foreach ($rows as $row) {
            $found[] = ["constant-group[{$row['group_id']}]", new LintFinding(LintFinding::ERROR, self::GROUP_UNPUBLISHED, (string) $row['slice_id'], 0)];
        }
        foreach (array_diff($owners, array_column($rows, 'slice_id')) as $owner) {
            $found[] = ['constant-group', new LintFinding(LintFinding::ERROR, self::GROUP_UNPUBLISHED, $owner, 0)];
        }
        return $found;
    }

    /** the sites that are not deleted @return array<string, array{string, string}> id => [name, charset] */
    private function sites(): array
    {
        $sites = [];
        foreach ($this->source->select('module', [['type', 'W', 's'], ['deleted', 0, 'i']]) as $row) {
            $sites[(string) $row['id']] = [(string) $row['name'], ModuleCharset::ofModule($row)[1] ?? 'utf-8'];
        }
        return $sites;
    }

    /** the slices and Alerts modules the sites use, not deleted - what Collector::fromSite() takes
     *  @param list<string> $sites @return array<string, list<string>> site => modules */
    private function uses(array $sites): array
    {
        $uses = array_fill_keys($sites, []);
        $rows = $sites ? $this->source->select('relation', [['source_id', $sites, 'l'], ['flag', REL_FLAG_MODULE_DEPEND, 'i']]) : [];
        $ids  = array_values(array_unique(array_column($rows, 'destination_id')));
        $ok   = [];
        foreach ($ids ? $this->source->select('module', [['id', $ids, 'l']]) : [] as $m) {
            if (in_array($m['type'], ['S', 'Alerts'], true) && !(int) $m['deleted']) {
                $ok[$m['id']] = true;
            }
        }
        foreach ($rows as $row) {
            if (isset($ok[$row['destination_id']]) && !in_array($row['destination_id'], $uses[$row['source_id']], true)) {
                $uses[$row['source_id']][] = (string) $row['destination_id'];
            }
        }
        return $uses;
    }

    /** The store settings of the sites, from their settings objects - only an object of the site class
     *  owned by the site counts (a form can write any object id).
     *  @param list<string> $sites @return array<string, array{publish: bool, description: string, version: string, modules: list<string>}> */
    private function settings(array $sites): array
    {
        $ids = [];
        foreach ($sites as $site) {
            $ids[string2id(self::SETTINGS_CLASS . $site)] = $site;
        }
        $props = [];
        foreach ($ids ? $this->source->objectRows(array_map('strval', array_keys($ids)), ['aa_type', 'aa_owner', ...self::SETTINGS]) : [] as $row) {
            $props[$row['object_id']][$row['property']] ??= (string) $row['value'];
        }
        $out = [];
        foreach ($ids as $id => $site) {
            $p          = $props[$id] ?? [];
            $own        = (($p['aa_type'] ?? '') === self::SETTINGS_CLASS) && (($p['aa_owner'] ?? '') === $site);
            $out[$site] = [
                'publish'     => $own && (($p['app_publish'] ?? '') === '1'),
                'description' => $own ? ($p['app_description'] ?? '') : '',
                'version'     => $own ? ($p['app_version'] ?? '') : '',
                'modules'     => $own ? array_values(array_filter(explode(',', $p['app_modules'] ?? ''), static fn(string $id): bool => (bool) preg_match('/^[0-9a-f]{32}\z/', $id))) : [],
            ];
        }
        return $out;
    }
}
