<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Credential\Secret;
use AA\Http\ClientCertificate;
use AA\IO\DB\DB_AA;
use AA\Isds\Registry\Box;

/**
 * The ISDS accounts of misc/datovka/datovka_conf.php (DatovkaConf::accounts()). Each sends only for the
 * sites and the one pair of slices - campaigns and their messages - the server's administrator bound it to:
 *     'accounts' => [
 *         'NSZM' => [
 *             'environment' => 'production',                 // or 'test'
 *             'auth'        => 'hosted',
 *             'sites'       => ['<site module id>'],
 *             'slices'      => ['<campaign slice id>', '<message slice id>'],
 *             'box'         => '<data box id>',
 *             'cert'        => '/etc/aa-isds/nszm.pem',      // outside the directory of AA
 *             'test_boxes'  => ['<data box id>'],
 *             'paid_tests'  => true,                         // optional: each test may cost money
 *         ],
 *     ],
 * NSZM names the account: one word of capital letters and digits, the first a letter - so one name has
 * one environment. Slice ids are public, so they alone choose no account: the caller passes the site as
 * well, and an account is used only where the site and both slices match. A slice belongs to one account
 * of an environment.
 * auth says how it logs in (Account): basic - login and password, in the test environment only; cert - a
 * system certificate of the data box; hosted - the certificate of a hosted filing service, sending from
 * box. cert holds the key too unless key names a file of its own; passphrase opens an encrypted key. A
 * relative path starts in DatovkaConf::CERT_DIR, which the web server serves - a key belongs outside it.
 * test_boxes lists the only boxes a test message of a production account may go to: a template of the site
 * may narrow them, never add one (Setup). A list AA cannot read leaves the account sending, without tests.
 * A test message of a production account goes only free of charge, unless paid_tests is true: then also a
 * paid one, and one of which ISDS does not say. Anything but true - false, or a value AA cannot read, which
 * State Check tells - allows no paid test. A test account has no paid_tests: State Check names it there.
 *
 * @phpstan-type Found array{name: string, environment: string, sites: list<string>, slices: array{string, string}, problems: list<string>, kind: string, testBoxes: list<string>, paidTests: bool}
 */
final class Accounts
{
    /** the keys of an account */
    public const ENVIRONMENT = 'environment';
    public const AUTH        = 'auth';
    public const SITES       = 'sites';
    public const SLICES      = 'slices';
    public const LOGIN       = 'login';
    public const PASSWORD    = 'password';
    public const BOX         = 'box';
    public const CERT        = 'cert';
    public const KEY         = 'key';
    public const PASSPHRASE  = 'passphrase';
    public const TEST_BOXES  = 'test_boxes';
    public const PAID_TESTS  = 'paid_tests';

    /** the keys of every account */
    private const COMMON = [self::ENVIRONMENT, self::AUTH, self::SITES, self::SLICES, self::TEST_BOXES];

    /** the keys of a production account beyond COMMON and its login */
    private const PRODUCTION_ONLY = [self::PAID_TESTS];

    /** the keys of each login - the values of auth -, in the order the file shows them */
    private const LOGINS = [
        Account::BASIC  => [self::LOGIN, self::PASSWORD],
        Account::CERT   => [self::CERT, self::KEY, self::PASSPHRASE],
        Account::HOSTED => [self::BOX, self::CERT, self::KEY, self::PASSPHRASE],
    ];

    /** the keys AA reads */
    private const KEYS = [...self::COMMON, ...self::PRODUCTION_ONLY, self::LOGIN, self::PASSWORD, self::BOX, self::CERT, self::KEY, self::PASSPHRASE];

    /** one word: the name labels the account in State Check */
    private const NAME = '/^[A-Z][A-Z0-9]*\z/';

    /**
     * The usable binding of the site and the pair of slices; account() gives its login.
     *
     * @param string       $site        the site whose own page is rendered (AA_Module_Site::renderingPage()), never a value from the template
     * @param string       $environment '' = test only when every production account provably concerns other sites
     *                                  or slices, else production; 'test' or 'production' = that one only (a template
     *                                  narrows to test, a queued run asks for the environment it was queued in)
     * @param ?DatovkaConf $conf        the settings; null = those of this installation
     * @throws ConfigException no usable account is bound - the message tells how to bind one and nothing
     *                         about the accounts there are: whoever writes the template reads it
     * @throws \InvalidArgumentException an id that is not one, an unknown environment
     */
    public static function binding(string $site, string $campaignSlice, string $messageSlice, string $environment, #[\SensitiveParameter] ?DatovkaConf $conf = null): Binding
    {
        $site          = ModuleId::normalize($site) ?? throw new \InvalidArgumentException('A site id is 32 hexadecimal digits');
        $campaignSlice = ModuleId::normalize($campaignSlice) ?? throw new \InvalidArgumentException('A slice id is 32 hexadecimal digits');
        $messageSlice  = ModuleId::normalize($messageSlice) ?? throw new \InvalidArgumentException('A slice id is 32 hexadecimal digits');
        if (!in_array($environment, ['', Account::TEST, Account::PRODUCTION], true)) {
            throw new \InvalidArgumentException('An ISDS environment is "' . Account::TEST . '", "' . Account::PRODUCTION . '" or none');
        }
        $bindings = self::bindings($conf ??= DatovkaConf::load());
        if ($environment === '') {
            // never test in place of production: a run sent to the test ISDS reaches nobody
            $environment = self::productionMeant($conf, $site, $campaignSlice, $messageSlice) ? Account::PRODUCTION : Account::TEST;
        }
        foreach ($bindings as $binding) {
            if (($binding->environment === $environment) && $binding->binds($site, $campaignSlice, $messageSlice)) {
                if ($binding->usable()) {
                    return $binding;
                }
                break;
            }
        }

        throw new ConfigException(self::refusal($site, $campaignSlice, $messageSlice));
    }

    /**
     * The account of a binding binding() or bindings() returned in this request - the file may change
     * between requests. A queued run asks binding() again with the environment it stored, never ''.
     * @throws ConfigException the binding is not usable - its problems say why
     */
    public static function account(Binding $binding, #[\SensitiveParameter] ?DatovkaConf $conf = null): Account
    {
        if (!$binding->usable()) {
            throw new ConfigException('Fix ' . self::setting($binding->name) . ' in ' . DatovkaConf::FILE . ' first: ' . implode('; ', $binding->problems));
        }

        return self::login($binding->environment, $binding->name, $binding->kind, self::entry($binding->name, $conf));
    }

    /**
     * The cert, key and passphrase of a binding, usable or not - for State Check to check the files. Null
     * without cert.
     * @throws ConfigException one of them is no text, or holds a line break or NUL
     */
    public static function certificate(Binding $binding, #[\SensitiveParameter] ?DatovkaConf $conf = null): ?ClientCertificate
    {
        return self::certificateOf($binding->name, self::entry($binding->name, $conf));
    }

    /**
     * Every account the file sets up, usable or not, with what is wrong - for State Check, which only a
     * superadmin sees. No secret is in them. An account of a name AA does not read is a stray.
     *
     * @return list<Binding> production first, by name
     */
    public static function bindings(#[\SensitiveParameter] ?DatovkaConf $conf = null): array
    {
        $found = [];
        foreach (($conf ?? DatovkaConf::load())->accounts() as $name => $fields) {
            if (preg_match(self::NAME, (string) $name) === 1) {
                $found[] = self::read((string) $name, $fields);
            }
        }
        usort($found, static fn(array $a, array $b): int => [$a['environment'] === Account::TEST, $a['name']] <=> [$b['environment'] === Account::TEST, $b['name']]);
        $shared   = self::shared($found);
        $bindings = [];
        foreach ($found as $i => $f) {
            $bindings[] = new Binding($f['name'], $f['environment'], $f['sites'], $f['slices'][0], $f['slices'][1], [...$f['problems'], ...($shared[$i] ?? [])], $f['kind'], $f['testBoxes'], $f['paidTests']);
        }

        return $bindings;
    }

    /**
     * What of the accounts AA never reads: an account whose name is not one word of capital letters and
     * digits, a misspelt key of an account, a key of production in a test account.
     *
     * @param ?list<string> $names the accounts whose keys to look at; null = all. Each account of a name AA
     *                             does not read is named.
     * @return list<string> as DATOVKA_CONF['accounts']['NSZM']['pasword']
     */
    public static function strays(#[\SensitiveParameter] ?DatovkaConf $conf = null, ?array $names = null): array
    {
        $strays = [];
        foreach (($conf ?? DatovkaConf::load())->accounts() as $name => $fields) {
            if (preg_match(self::NAME, (string) $name) !== 1) {
                $strays[] = DatovkaConf::key(DatovkaConf::ACCOUNTS, $name);
                continue;
            }
            if (!is_null($names) && !in_array($name, $names, true)) {
                continue;
            }
            $unread = (self::environment($fields) === Account::TEST) ? self::PRODUCTION_ONLY : [];
            foreach (is_array($fields) ? array_keys($fields) : [] as $key) {
                if (!in_array($key, self::KEYS, true) || in_array($key, $unread, true)) {
                    $strays[] = DatovkaConf::key(DatovkaConf::ACCOUNTS, $name, $key);
                }
            }
        }

        return $strays;
    }

    /**
     * The ids to ask modulesHere() about, in one query: the sites of the bindings, and the slices of
     * those of no site read.
     *
     * @param list<Binding> $bindings
     * @return list<string>
     */
    public static function moduleIds(array $bindings): array
    {
        $ids = [];
        foreach ($bindings as $b) {
            array_push($ids, ...(($b->sites === []) ? array_filter([$b->campaignSlice, $b->messageSlice]) : $b->sites));
        }

        return array_values(array_unique($ids));
    }

    /**
     * The bindings State Check of a database deals with: one tree serves several databases, and each
     * keeps its own log of refused logins. Those of no site read are a fault of the file - shown in each.
     * A module id may be in two databases: an account of such a site is dealt with in both.
     *
     * @param list<Binding> $bindings
     * @param list<string>  $here     the modules of this database among moduleIds()
     * @return list<Binding>
     */
    public static function here(array $bindings, array $here): array
    {
        return array_values(array_filter($bindings, static fn(Binding $b): bool => ($b->sites === []) || (array_intersect($b->sites, $here) !== [])));
    }

    /**
     * @param list<string> $ids of sites or slices
     * @return list<string> those that are modules of this database
     */
    public static function modulesHere(array $ids): array
    {
        return $ids ? array_values(DB_AA::select('id', 'SELECT LOWER(HEX(`id`)) AS id FROM `module`', [['id', $ids, 'l']])) : [];
    }

    /** @return Found */
    private static function read(string $name, #[\SensitiveParameter] mixed $fields): array
    {
        $found = ['name' => $name, 'environment' => self::environment($fields), 'sites' => [], 'slices' => ['', ''], 'problems' => [], 'kind' => '', 'testBoxes' => [], 'paidTests' => false];
        if (!is_array($fields)) {
            $found['problems'][] = self::setting($name) . ' is not an array - give the account its keys in one';

            return $found;
        }
        $written = in_array($fields[self::ENVIRONMENT] ?? null, [Account::TEST, Account::PRODUCTION], true);
        if (!$written) {
            $found['problems'][] = 'Set ' . self::setting($name, self::ENVIRONMENT) . " to '" . Account::TEST . "' or '" . Account::PRODUCTION . "'";
        }
        $kind = $fields[self::AUTH] ?? null;
        if (is_string($kind) && isset(self::LOGINS[$kind])) {
            $found['kind'] = $kind;
        } else {
            $found['problems'][] = 'Set ' . self::setting($name, self::AUTH) . " to '" . Account::BASIC . "', '" . Account::CERT . "' or '" . Account::HOSTED . "'";
        }
        try {
            $found['sites'] = self::sites($name, $fields);
        } catch (ConfigException $e) {
            $found['problems'][] = $e->getMessage();
        }
        try {
            $found['slices'] = self::slices($name, $fields);
        } catch (ConfigException $e) {
            $found['problems'][] = $e->getMessage();
        }
        // only for tests of a draft: a mistake there must not stop a sending (brokenTestBoxes())
        $found['testBoxes'] = self::testBoxes($fields) ?? [];
        $found['paidTests'] = self::paidTests($fields) ?? false;
        // an environment misspelt is to fix first: which keys belong is not known before
        if ($written) {
            array_push($found['problems'], ...self::mixed($found['environment'] === Account::PRODUCTION, $name, $found['kind'], $fields));
        }
        // production with a name and a password has said all there is: auth is to change
        if (($found['kind'] !== '') && (($found['environment'] !== Account::PRODUCTION) || ($found['kind'] !== Account::BASIC))) {
            try {
                self::login($found['environment'], $name, $found['kind'], $fields);
            } catch (ConfigException $e) {
                $found['problems'][] = $e->getMessage();
            }
        }

        return $found;
    }

    /**
     * production unless written exactly as test: a typo must never send to the test ISDS in place of
     * production. A block out of its place holds Secret (DatovkaConf::loose()).
     */
    private static function environment(#[\SensitiveParameter] mixed $fields): string
    {
        $environment = is_array($fields) ? ($fields[self::ENVIRONMENT] ?? null) : null;
        $environment = ($environment instanceof Secret) ? $environment->reveal() : $environment;

        return ($environment === Account::TEST) ? Account::TEST : Account::PRODUCTION;
    }

    /**
     * Keys of another login than auth names - which one is meant is not guessed - and production with a
     * name and a password, where auth is to change and the other keys may be the right ones.
     * @param bool                    $production environment says production
     * @param array<array-key, mixed> $fields
     * @return list<string>
     */
    private static function mixed(bool $production, string $name, string $kind, #[\SensitiveParameter] array $fields): array
    {
        if ($kind === '') {
            return [];
        }
        $set      = array_values(array_filter(array_diff(self::KEYS, self::COMMON, self::PRODUCTION_ONLY), static fn(string $key): bool => self::isSet($fields[$key] ?? null)));
        $settings = static fn(array $keys): string => implode(' and ', array_map(static fn(string $key): string => self::setting($name, $key), $keys));
        if ($production && ($kind === Account::BASIC)) {
            $named = array_values(array_intersect($set, self::LOGINS[Account::BASIC]));

            return ['Set ' . self::setting($name, self::AUTH) . " to '" . Account::CERT . "' or '" . Account::HOSTED . "'"
                . ($named ? ' and remove ' . $settings($named) : '') . ' - AA never logs in to production ISDS with a name and a password'];
        }
        $others = array_values(array_diff($set, self::LOGINS[$kind]));

        return $others ? ['Remove ' . $settings($others) . " - an account with auth '$kind' does not use " . ((count($others) === 1) ? 'it' : 'them')] : [];
    }

    /**
     * A slice two accounts of one environment name: which of them sends is not clear, and a pair
     * reversed or mistyped in a template would send with the other one.
     *
     * @param list<Found> $found
     * @return array<int, list<string>> index in $found => problems
     */
    private static function shared(array $found): array
    {
        $accounts = [];
        foreach ($found as $i => $f) {
            foreach (array_filter($f['slices']) as $slice) {
                $accounts["{$f['environment']} $slice"][] = $i;
            }
        }
        $problems = [];
        foreach ($accounts as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            $names   = array_map(static fn(int $i): string => self::setting($found[$i]['name'], self::SLICES), $indexes);
            $problem = implode(', ', $names) . ' name the same slice - a slice belongs to one account';
            foreach ($indexes as $i) {
                // a pair named twice shares both slices - one problem
                $problems[$i][$problem] = $problem;
            }
        }

        return array_map(array_values(...), $problems);
    }

    /**
     * @param array<array-key, mixed> $fields
     * @return list<string>
     * @throws ConfigException
     */
    private static function sites(string $name, #[\SensitiveParameter] array $fields): array
    {
        $setting = self::setting($name, self::SITES);
        if (!self::isSet($fields[self::SITES] ?? null)) {
            throw new ConfigException("Set $setting to the ids of the sites the account sends for: ['<site id>']");
        }
        $ids = self::ids($fields[self::SITES]) ?? throw new ConfigException("$setting is not a list of site ids - write ['<site id>', ...], 32 hexadecimal digits each");

        return array_values(array_unique($ids));
    }

    /**
     * @param array<array-key, mixed> $fields
     * @return array{string, string} the campaign slice and the message slice
     * @throws ConfigException
     */
    private static function slices(string $name, #[\SensitiveParameter] array $fields): array
    {
        $setting = self::setting($name, self::SLICES);
        if (!self::isSet($fields[self::SLICES] ?? null)) {
            throw new ConfigException("Set $setting to the ids of the campaign slice and the message slice the account sends for: ['<campaign slice id>', '<message slice id>']");
        }
        $ids = self::ids($fields[self::SLICES]);
        if (is_null($ids) || (count($ids) !== 2) || ($ids[0] === $ids[1])) {
            throw new ConfigException("$setting is not a pair of slices - write ['<campaign slice id>', '<message slice id>'], 32 hexadecimal digits each");
        }

        return [$ids[0], $ids[1]];
    }

    /**
     * @param array<array-key, mixed> $fields
     * @return ?list<string> box ids as ISDS knows them, [] when not set; null = no list of box ids
     */
    private static function testBoxes(#[\SensitiveParameter] array $fields): ?array
    {
        $list = $fields[self::TEST_BOXES] ?? [];
        if (!is_array($list)) {
            return null;
        }
        try {
            // DatovkaConf keeps what is no text of the list as Secret
            return array_values(array_unique(array_map(static fn(mixed $box): string => Box::normalizeId(is_string($box) ? $box : ''), $list)));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $fields
     * @return ?bool paid_tests, false when not set; null = neither true nor false
     */
    private static function paidTests(#[\SensitiveParameter] array $fields): ?bool
    {
        $flag = $fields[self::PAID_TESTS] ?? false;

        return is_bool($flag) ? $flag : null;
    }

    /**
     * The production accounts whose paid_tests AA cannot read - no paid test message goes from them
     * @return list<string> names of the accounts
     */
    public static function brokenPaidTests(#[\SensitiveParameter] ?DatovkaConf $conf = null): array
    {
        return self::broken($conf, static fn(array $fields): bool => (self::environment($fields) === Account::PRODUCTION) && is_null(self::paidTests($fields)));
    }

    /**
     * The accounts whose test_boxes AA cannot read - they send, but no test message goes from them
     * @return list<string> names of the accounts
     */
    public static function brokenTestBoxes(#[\SensitiveParameter] ?DatovkaConf $conf = null): array
    {
        return self::broken($conf, static fn(array $fields): bool => is_null(self::testBoxes($fields)));
    }

    /**
     * @param \Closure(array<array-key, mixed>): bool $unreadable whether the keys of an account hold a value AA cannot read
     * @return list<string> names of the accounts AA reads whose keys $unreadable
     */
    private static function broken(#[\SensitiveParameter] ?DatovkaConf $conf, \Closure $unreadable): array
    {
        $broken = [];
        foreach (($conf ?? DatovkaConf::load())->accounts() as $name => $fields) {
            if ((preg_match(self::NAME, (string) $name) === 1) && is_array($fields) && $unreadable($fields)) {
                $broken[] = (string) $name;
            }
        }

        return $broken;
    }

    /**
     * @param string                  $kind Account::BASIC, CERT or HOSTED, as auth says
     * @param array<array-key, mixed> $fields
     * @throws ConfigException
     */
    private static function login(string $environment, string $name, string $kind, #[\SensitiveParameter] array $fields): Account
    {
        if ($kind !== Account::BASIC) {
            $certificate = self::certificateOf($name, $fields)
                ?? throw new ConfigException('Set ' . self::setting($name, self::CERT) . ' to the PEM file of the certificate '
                    . (($kind === Account::HOSTED) ? 'of the hosting service' : 'ISDS registered for the data box') . ' - a full path outside the directory of AA');

            return Account::withCertificate($environment, $certificate, ($kind === Account::HOSTED) ? self::box($name, $fields) : '');
        }
        $login    = self::text($name, $fields, self::LOGIN);
        $password = self::secret($name, $fields, self::PASSWORD);
        if (is_null($login) || is_null($password)) {
            $missing = array_keys(array_filter([self::LOGIN => is_null($login), self::PASSWORD => is_null($password)]));
            throw new ConfigException('Set ' . implode(' and ', array_map(static fn(string $key): string => self::setting($name, $key), $missing)) . ' - the account logs in with a name and a password');
        }
        try {
            return new Account($environment, $login, $password);
        } catch (\InvalidArgumentException $e) {
            // the reason names no value
            throw new ConfigException('Fix ' . self::setting($name, self::LOGIN) . ' or ' . self::setting($name, self::PASSWORD) . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The data box a hosted filing service sends from - no secret, ISDS answers name it.
     * @param array<array-key, mixed> $fields
     * @throws ConfigException
     */
    private static function box(string $name, #[\SensitiveParameter] array $fields): string
    {
        $setting = self::setting($name, self::BOX);
        $box     = self::text($name, $fields, self::BOX) ?? throw new ConfigException("Set $setting to the id of the data box the account sends from");
        try {
            return Box::normalizeId($box);
        } catch (\InvalidArgumentException $e) {
            throw new ConfigException("$setting is not the id of a data box - 7 letters or digits", 0, $e);
        }
    }

    /**
     * cert, with key and passphrase when set; null without cert. A path of spaces names no file.
     * @param array<array-key, mixed> $fields
     * @throws ConfigException
     */
    private static function certificateOf(string $name, #[\SensitiveParameter] array $fields): ?ClientCertificate
    {
        $certificate = DatovkaConf::path(self::text($name, $fields, self::CERT) ?? '');
        if ($certificate === '') {
            return null;
        }

        return new ClientCertificate(
            $certificate,
            DatovkaConf::path(self::text($name, $fields, self::KEY) ?? ''),
            self::secret($name, $fields, self::PASSPHRASE) ?? new Secret(''),
        );
    }

    /**
     * Whether a production account may be meant for the site or the slices. Every account not written
     * for test may be - bound, broken, of a name AA does not read, or a bracket out of its place
     * (DatovkaConf::loose(), and any array in an account or in those, at any depth - inner()) - unless the
     * site and slice ids in it are all of others. Ids are taken
     * wherever they stand in sites, slices and the keys AA does not read, keys of lists too: a list
     * broken by one bad part still names its sites and slices, ids glued together are read apart, and a
     * word of 16 or more letters, digits and dashes that is no id may be one of ours with a typo (AA reads
     * no id with dashes). Never from the other keys of an account: a path, a login or a passphrase would
     * make each account doubtful.
     * No sites and slices prove nothing, and the test ISDS reaches nobody.
     */
    private static function productionMeant(#[\SensitiveParameter] DatovkaConf $conf, string $site, string $campaignSlice, string $messageSlice): bool
    {
        $blocks = [...array_values($conf->accounts()), ...$conf->loose()];
        for ($i = 0; $i < count($blocks); ++$i) {
            $fields = $blocks[$i];
            $texts  = $fields;
            if (is_array($fields)) {
                array_push($blocks, ...self::inner($fields));
                $texts = array_diff_key($fields, array_flip(array_diff(self::KEYS, [self::ENVIRONMENT, self::SITES, self::SLICES])));
            }
            if (self::environment($fields) !== Account::PRODUCTION) {
                continue;
            }
            $ids      = [];
            $doubtful = false;
            foreach (self::texts($texts) as $text) {
                preg_match_all('/[0-9a-z-]+/i', $text, $tokens);
                foreach ($tokens[0] as $token) {
                    if (((strlen($token) % 32) === 0) && ctype_xdigit($token)) {
                        // ids glued together are read apart
                        array_push($ids, ...str_split(strtolower($token), 32));
                    } elseif (strlen($token) >= 16) {
                        // an id with dashes, a digit too few or many, o for 0: maybe ours
                        $doubtful = true;
                    }
                }
            }
            if (($ids === []) || $doubtful || (array_intersect($ids, [$site, $campaignSlice, $messageSlice]) !== [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The arrays in an account where it holds none - the value of a key AA does not read, an item of a key
     * it reads, of a test account too -: each may be an account a bracket out of its place. A real list of
     * sites or slices holds texts only.
     *
     * @param array<array-key, mixed> $fields
     * @return list<array<array-key, mixed>> not empty
     */
    private static function inner(#[\SensitiveParameter] array $fields): array
    {
        $inner = [];
        foreach ($fields as $key => $value) {
            array_push($inner, ...((in_array($key, self::KEYS, true) && is_array($value)) ? array_values($value) : [$value]));
        }

        return array_values(array_filter($inner, static fn(mixed $value): bool => is_array($value) && ($value !== [])));
    }

    /** @return list<string> the keys and the values in $value, at any depth, Secret revealed */
    private static function texts(#[\SensitiveParameter] mixed $value): array
    {
        if (!is_array($value)) {
            return ($value instanceof Secret) ? [$value->reveal()] : (is_scalar($value) ? [(string) $value] : []);
        }
        $texts = [];
        foreach ($value as $key => $item) {
            array_push($texts, (string) $key, ...self::texts($item));
        }

        return $texts;
    }

    /** how to bind an account - nothing about the accounts there are, the State Check lists them */
    private static function refusal(string $site, string $campaignSlice, string $messageSlice): string
    {
        return 'No usable ISDS account in ' . DatovkaConf::FILE . ' is bound to this site and these slices. If a test account is bound and you mean it, ask for the test environment. To bind a test account, add to '
            . DatovkaConf::key(DatovkaConf::ACCOUNTS) . " an account with 'environment' => 'test', 'auth' => 'basic', 'sites' => ['$site'], 'slices' => ['$campaignSlice', '$messageSlice'] (the campaign slice first), 'login' and 'password'; a production account logs in with a certificate ('auth' => 'cert' or 'hosted') instead. The State Check lists the accounts and their problems";
    }

    /**
     * @return array<array-key, mixed> the keys of the account $name; [] when the file has none
     */
    private static function entry(string $name, #[\SensitiveParameter] ?DatovkaConf $conf): array
    {
        $fields = ($conf ?? DatovkaConf::load())->accounts()[$name] ?? [];

        return is_array($fields) ? $fields : [];
    }

    /**
     * A key of an account; null when not set, or of spaces only. DatovkaConf keeps only a text as it is.
     * @param array<array-key, mixed> $fields
     * @throws ConfigException no text, or a line break or NUL in it - in a login it would end a header of HTTP
     */
    private static function text(string $name, #[\SensitiveParameter] array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? '';
        if (!is_string($value)) {
            throw new ConfigException(self::setting($name, $key) . ' is not a text - write it in quotes');
        }

        return self::filled($name, $key, $value);
    }

    /**
     * password or passphrase, which DatovkaConf keeps as Secret - as false when no text
     * @param array<array-key, mixed> $fields
     * @throws ConfigException as text()
     */
    private static function secret(string $name, #[\SensitiveParameter] array $fields, string $key): ?Secret
    {
        $value = $fields[$key] ?? null;
        if (is_null($value)) {
            return null;
        }
        if (!($value instanceof Secret)) {
            throw new ConfigException(self::setting($name, $key) . ' is not a text - write it in quotes');
        }

        return is_null(self::filled($name, $key, $value->reveal())) ? null : $value;
    }

    /**
     * @return ?string $value; null when of spaces only
     * @throws ConfigException a line break or NUL in it
     */
    private static function filled(string $name, string $key, #[\SensitiveParameter] string $value): ?string
    {
        if (strpbrk($value, "\r\n\0") !== false) {
            throw new ConfigException(self::setting($name, $key) . ' holds a line break or NUL - remove it');
        }

        return (trim($value) === '') ? null : $value;
    }

    /** a value that says something: no text of spaces, no empty list or secret */
    private static function isSet(#[\SensitiveParameter] mixed $value): bool
    {
        return match (true) {
            $value instanceof Secret => !$value->isEmpty(),
            is_string($value)        => trim($value) !== '',
            default                  => !in_array($value, [null, []], true),
        };
    }

    /** @return list<string>|null the ids of a list; null when it is no list of ids */
    private static function ids(mixed $list): ?array
    {
        if (!is_array($list)) {
            return null;
        }
        $ids = [];
        foreach ($list as $id) {
            // an item under a name DatovkaConf keeps as Secret
            $id = ($id instanceof Secret) ? $id->reveal() : $id;
            $id = is_string($id) ? ModuleId::normalize(trim($id)) : null;
            if (is_null($id)) {
                return null;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /** the account in the texts of State Check: NSZM (test) */
    public static function label(string $environment, string $name): string
    {
        return "$name ($environment)";
    }

    /** where the file sets the account, or one of its keys: DATOVKA_CONF['accounts']['NSZM']['password'] */
    public static function setting(string $name, string $key = ''): string
    {
        return DatovkaConf::key(DatovkaConf::ACCOUNTS, $name, ...(($key === '') ? [] : [$key]));
    }

    /**
     * @param string $environment Account::TEST or PRODUCTION; '' = of either
     * @param string $login       Account::BASIC, CERT or HOSTED; '' = of any login. Production never lists
     *                            login and password: it lets an application in with a certificate only
     * @return list<string> the keys of an account, in the order the file shows them
     */
    public static function keys(string $environment, string $login = ''): array
    {
        if (($environment === Account::PRODUCTION) && in_array($login, ['', Account::BASIC], true)) {
            $login = Account::HOSTED;
        }
        $keys = self::LOGINS[$login] ?? array_values(array_diff(self::KEYS, self::COMMON, self::PRODUCTION_ONLY));

        return [...self::COMMON, ...(($environment === Account::TEST) ? [] : self::PRODUCTION_ONLY), ...$keys];
    }
}
