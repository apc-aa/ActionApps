<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Credential\Secret;
use AA\Credential\Store;
use AA\Http\ClientCertificate;
use AA\Isds\Registry\Box;

/**
 * The ISDS accounts include/config.php sets up. Each sends only for the sites and the one pair of
 * slices - campaigns and their messages - the server's administrator bound it to:
 *     define('AA_APIKEY_ISDS_NSZM_SITE',   '<site module id>');   // more sites: separated by commas
 *     define('AA_APIKEY_ISDS_NSZM_SLICES', '<campaign slice id>,<message slice id>');
 *     define('AA_APIKEY_ISDS_NSZM_CERT',   '/etc/aa-isds/nszm.pem');   // outside the directory of AA
 *     define('AA_APIKEY_ISDS_NSZM_BOX',    '<data box id>');           // only for a hosted filing service
 * The key vendor is the environment (isds = production, isdstest = test), NSZM names the account: one
 * word of capital letters and digits. Slice ids are public, so they alone choose no account: the
 * caller passes the site as well, and an account is used only where the site and both slices match.
 * A slice belongs to one account of an environment.
 * What is set decides the login (Account): BOX - a hosted filing service, with the certificate of the
 * service; CERT - a system certificate of the data box; otherwise LOGIN and PASSWORD, in the test
 * environment only. CERT holds the key too unless KEY names a file of its own; PASSPHRASE opens an
 * encrypted key. A relative path starts in CERT_DIR of AA, which the web server serves - a key belongs
 * outside it.
 *
 * @phpstan-type Found array{name: string, environment: string, sites: list<string>, slices: array{string, string}, problems: list<string>, kind: string}
 */
final class Accounts
{
    public const SITE       = 'SITE';
    public const SLICES     = 'SLICES';
    public const LOGIN      = 'LOGIN';
    public const PASSWORD   = 'PASSWORD';
    public const CERT       = 'CERT';
    public const KEY        = 'KEY';
    public const PASSPHRASE = 'PASSPHRASE';
    public const BOX        = 'BOX';

    /** where a relative path of CERT and KEY starts in the installation; git ignores the files there */
    public const CERT_DIR = 'config/certs/';

    /** the account in advice that shows how to name the constants */
    public const PLACEHOLDER = '<ACCOUNT>';

    /** the key vendor of each environment */
    public const VENDORS = [Account::PRODUCTION => 'isds', Account::TEST => 'isdstest'];

    /** an account of one word: AA_APIKEY_ISDS_TEST_NSZM_SLICES cannot pass for a test account */
    private const NAME = '/^([A-Z0-9]+)_(' . self::SITE . '|' . self::SLICES . '|' . self::LOGIN . '|' . self::PASSWORD . '|'
        . self::CERT . '|' . self::KEY . '|' . self::PASSPHRASE . '|' . self::BOX . ')\z/';

    /**
     * The account bound to the site and the pair of slices.
     *
     * @param string $site        the site whose own page is rendered (AA_Module_Site::renderingPage()), never a value from the template
     * @param string $environment '' = test only when every production constant provably concerns other sites
     *                            or slices, else production; 'test' or 'production' = that one only (a template
     *                            narrows to test, a queued run asks for the environment it was queued in)
     * @param array<string, mixed>|null $constants name => value, as Store::userConstants(); null = those
     * @throws ConfigException no usable account is bound - the message tells how to bind one and nothing
     *                         about the accounts there are: whoever writes the template reads it
     * @throws \InvalidArgumentException an id that is not one, an unknown environment
     */
    public static function forSlices(string $site, string $campaignSlice, string $messageSlice, string $environment = '', #[\SensitiveParameter] ?array $constants = null): Account
    {
        $site          = ModuleId::normalize($site) ?? throw new \InvalidArgumentException('A site id is 32 hexadecimal digits');
        $campaignSlice = ModuleId::normalize($campaignSlice) ?? throw new \InvalidArgumentException('A slice id is 32 hexadecimal digits');
        $messageSlice  = ModuleId::normalize($messageSlice) ?? throw new \InvalidArgumentException('A slice id is 32 hexadecimal digits');
        if (!in_array($environment, ['', Account::TEST, Account::PRODUCTION], true)) {
            throw new \InvalidArgumentException('An ISDS environment is "' . Account::TEST . '", "' . Account::PRODUCTION . '" or none');
        }
        $bindings = self::bindings($constants ??= Store::userConstants());
        if ($environment === '') {
            // never test in place of production: a run sent to the test ISDS reaches nobody
            $environment = self::productionMeant($constants, $site, $campaignSlice, $messageSlice) ? Account::PRODUCTION : Account::TEST;
        }
        $binding = null;
        foreach ($bindings as $b) {
            if (($b->environment === $environment) && $b->binds($site, $campaignSlice, $messageSlice)) {
                $binding = $b;
                break;
            }
        }
        if (is_null($binding) || !$binding->usable()) {
            throw new ConfigException(self::refusal($site, $campaignSlice, $messageSlice));
        }

        return self::login($binding->environment, $binding->name, $binding->kind, $constants);
    }

    /**
     * The account of a binding, for a caller that goes through bindings() - State Check. A template
     * asks forSlices(), which checks the site and the slices first.
     * @param array<string, mixed>|null $constants as forSlices()
     * @throws ConfigException the binding is not usable - its problems say why
     */
    public static function account(Binding $binding, #[\SensitiveParameter] ?array $constants = null): Account
    {
        if (!$binding->usable()) {
            throw new ConfigException('Fix the constants of ' . self::prefix($binding->environment, $binding->name) . ' in config.php first: ' . implode('; ', $binding->problems));
        }

        return self::login($binding->environment, $binding->name, $binding->kind, $constants ?? Store::userConstants());
    }

    /**
     * The certificate CERT, KEY and PASSPHRASE of a binding name, usable or not - for State Check to
     * check the files. Null for a name and a password, or without CERT.
     * @param array<string, mixed>|null $constants as forSlices()
     * @throws ConfigException a line break or NUL in one of them
     */
    public static function certificate(Binding $binding, #[\SensitiveParameter] ?array $constants = null): ?ClientCertificate
    {
        return self::certificateOf($binding->environment, $binding->name, $constants ?? Store::userConstants());
    }

    /**
     * Every account config.php sets up, usable or not, with what is wrong - for State Check, which
     * only a superadmin sees. No key is in them.
     *
     * @param array<string, mixed>|null $constants as forSlices()
     * @return list<Binding> production first, by name
     */
    public static function bindings(#[\SensitiveParameter] ?array $constants = null): array
    {
        $constants ??= Store::userConstants();
        $found       = [];
        foreach (self::scan($constants)[0] as $environment => $accounts) {
            foreach ($accounts as $name => $kinds) {
                $found[] = self::read($environment, (string) $name, $kinds, $constants);
            }
        }
        $shared   = self::shared($found);
        $bindings = [];
        foreach ($found as $i => $f) {
            $bindings[] = new Binding($f['name'], $f['environment'], $f['sites'], $f['slices'][0], $f['slices'][1], [...$f['problems'], ...($shared[$i] ?? [])], $f['kind']);
        }

        return $bindings;
    }

    /**
     * The constants of the ISDS vendors that are no account's key, so AA never reads them: a key
     * without an account (AA_APIKEY_ISDSTEST_LOGIN), a misspelt kind, a name in small letters.
     *
     * @param array<string, mixed>|null $constants as forSlices()
     * @return list<string> constant names
     */
    public static function strays(#[\SensitiveParameter] ?array $constants = null): array
    {
        return self::scan($constants ?? Store::userConstants())[1];
    }

    /**
     * The accounts' constants and the strays.
     *
     * @param array<string, mixed> $constants
     * @return array{array<string, array<array-key, list<string>>>, list<string>} environment => account => kinds; strays
     */
    private static function scan(#[\SensitiveParameter] array $constants): array
    {
        $accounts = [];
        $strays   = [];
        foreach (self::VENDORS as $environment => $vendor) {
            $accounts[$environment] = [];
            foreach (Store::contextsIn($vendor, $constants) as $name => $context) {
                // Store reads a constant only as it spells it - in another case it is a stray
                if ((preg_match(self::NAME, $context, $m) === 1) && ($name === self::constant($environment, $m[1], $m[2]))) {
                    $accounts[$environment][$m[1]][] = $m[2];
                } else {
                    $strays[] = $name;
                }
            }
            ksort($accounts[$environment], SORT_STRING);
        }

        return [$accounts, $strays];
    }

    /**
     * @param list<string>         $kinds the account's constants there are
     * @param array<string, mixed> $constants
     * @return Found
     */
    private static function read(string $environment, string $name, array $kinds, #[\SensitiveParameter] array $constants): array
    {
        $sites    = [];
        $slices   = ['', ''];
        $problems = [];
        try {
            $sites = self::sites($environment, $name, $constants);
        } catch (ConfigException $e) {
            $problems[] = $e->getMessage();
        }
        try {
            $slices = self::slices($environment, $name, $constants);
        } catch (ConfigException $e) {
            $problems[] = $e->getMessage();
        }
        $kind  = self::kind($kinds);
        $mixed = self::mixed($environment, $name, $kind, $kinds);
        array_push($problems, ...$mixed);
        // production with a name and a password has said all there is: set the certificate
        if (($environment !== Account::PRODUCTION) || ($kind !== Account::BASIC)) {
            try {
                self::login($environment, $name, $kind, $constants);
            } catch (ConfigException $e) {
                $problems[] = $e->getMessage();
            }
        }

        return ['name' => $name, 'environment' => $environment, 'sites' => $sites, 'slices' => $slices, 'problems' => $problems, 'kind' => $kind];
    }

    /**
     * The login the constants there are mean, even empty ones: BOX a hosted filing service, CERT a
     * system certificate, otherwise a name and a password.
     * @param list<string> $kinds
     */
    private static function kind(array $kinds): string
    {
        return match (true) {
            in_array(self::BOX, $kinds, true)  => Account::HOSTED,
            in_array(self::CERT, $kinds, true) => Account::CERT,
            default                            => Account::BASIC,
        };
    }

    /**
     * Constants of another login than the one kind() chose - which one is meant is not guessed - and
     * production without a certificate.
     * @param list<string> $kinds
     * @return list<string>
     */
    private static function mixed(string $environment, string $name, string $kind, array $kinds): array
    {
        $names    = fn(string ...$of): string => implode(' and ', array_map(fn(string $k): string => self::constant($environment, $name, $k), $of));
        $named    = array_values(array_intersect([self::LOGIN, self::PASSWORD], $kinds));
        $orphans  = array_values(array_intersect([self::KEY, self::PASSPHRASE], $kinds));
        $problems = [];
        if ($kind !== Account::BASIC) {
            if ($named) {
                $problems[] = 'Remove ' . $names(...$named) . ' from config.php - an account with a certificate logs in without a name and a password';
            }
        } elseif ($environment === Account::PRODUCTION) {
            $problems[] = 'Set ' . $names(self::CERT) . ' in config.php to the file of the certificate - production ISDS lets an application in with a certificate only';
            if ($named) {
                $problems[] = 'Remove ' . $names(...$named) . ' from config.php - AA never logs in to production ISDS with a name and a password';
            }
        } elseif ($orphans) {
            $problems[] = 'Set ' . $names(self::CERT) . ' in config.php, or remove ' . $names(...$orphans) . ' - they belong to a certificate';
        }

        return $problems;
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
            $names   = array_map(static fn(int $i): string => self::constant($found[$i]['environment'], $found[$i]['name'], self::SLICES), $indexes);
            $problem = implode(', ', $names) . ' name the same slice - a slice belongs to one account';
            foreach ($indexes as $i) {
                // a pair named twice shares both slices - one problem
                $problems[$i][$problem] = $problem;
            }
        }

        return array_map(array_values(...), $problems);
    }

    /**
     * @param array<string, mixed> $constants
     * @return list<string>
     * @throws ConfigException
     */
    private static function sites(string $environment, string $name, #[\SensitiveParameter] array $constants): array
    {
        $constant = self::constant($environment, $name, self::SITE);
        $value    = self::key($environment, $name, self::SITE, $constants)
            ?? throw new ConfigException("Set $constant in config.php to the id of the site the account sends for - more sites separated by commas");
        $ids = self::ids($value->reveal()) ?? throw new ConfigException("$constant is not a list of site ids - 32 hexadecimal digits each, separated by commas");

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $constants
     * @return array{string, string} the campaign slice and the message slice
     * @throws ConfigException
     */
    private static function slices(string $environment, string $name, #[\SensitiveParameter] array $constants): array
    {
        $constant = self::constant($environment, $name, self::SLICES);
        $value    = self::key($environment, $name, self::SLICES, $constants)
            ?? throw new ConfigException("Set $constant in config.php to the ids of the campaign slice and the message slice the account sends for, separated by a comma");
        $ids = self::ids($value->reveal());
        if (is_null($ids) || (count($ids) !== 2) || ($ids[0] === $ids[1])) {
            throw new ConfigException("$constant is not a pair of slices - write the id of the campaign slice, a comma and the id of the message slice, 32 hexadecimal digits each");
        }

        return [$ids[0], $ids[1]];
    }

    /**
     * @param string $kind Account::BASIC, CERT or HOSTED, as kind() chose
     * @param array<string, mixed> $constants
     * @throws ConfigException
     */
    private static function login(string $environment, string $name, string $kind, #[\SensitiveParameter] array $constants): Account
    {
        if ($kind !== Account::BASIC) {
            $certificate = self::certificateOf($environment, $name, $constants)
                ?? throw new ConfigException('Set ' . self::constant($environment, $name, self::CERT) . ' in config.php to the PEM file of the certificate '
                    . (($kind === Account::HOSTED) ? 'of the hosting service' : 'ISDS registered for the data box') . ' - a full path outside the directory of AA');

            return Account::withCertificate($environment, $certificate, ($kind === Account::HOSTED) ? self::box($environment, $name, $constants) : '');
        }
        $login    = self::key($environment, $name, self::LOGIN, $constants);
        $password = self::key($environment, $name, self::PASSWORD, $constants);
        if (is_null($login) || is_null($password)) {
            $missing = [];
            if (is_null($login)) {
                $missing[] = self::constant($environment, $name, self::LOGIN);
            }
            if (is_null($password)) {
                $missing[] = self::constant($environment, $name, self::PASSWORD);
            }
            throw new ConfigException('Set ' . implode(' and ', $missing) . ' in config.php - the account logs in with a name and a password');
        }
        try {
            return new Account($environment, $login->reveal(), $password);
        } catch (\InvalidArgumentException $e) {
            // the reason names no value
            throw new ConfigException('Fix ' . self::constant($environment, $name, self::LOGIN) . ' or ' . self::constant($environment, $name, self::PASSWORD) . ' in config.php: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The data box a hosted filing service sends from - no secret, ISDS answers name it.
     * @param array<string, mixed> $constants
     * @throws ConfigException
     */
    private static function box(string $environment, string $name, #[\SensitiveParameter] array $constants): string
    {
        $constant = self::constant($environment, $name, self::BOX);
        $box      = self::key($environment, $name, self::BOX, $constants)
            ?? throw new ConfigException("Set $constant in config.php to the id of the data box the account sends from");
        try {
            return Box::normalizeId($box->reveal());
        } catch (\InvalidArgumentException $e) {
            throw new ConfigException("$constant is not the id of a data box - 7 letters or digits", 0, $e);
        }
    }

    /**
     * CERT, with KEY and PASSPHRASE when set; null without CERT.
     * @param array<string, mixed> $constants
     * @throws ConfigException
     */
    private static function certificateOf(string $environment, string $name, #[\SensitiveParameter] array $constants): ?ClientCertificate
    {
        $certificate = self::key($environment, $name, self::CERT, $constants);
        if (is_null($certificate)) {
            return null;
        }
        $key = self::key($environment, $name, self::KEY, $constants);

        return new ClientCertificate(
            self::path($certificate->reveal(), self::constant($environment, $name, self::CERT), $constants),
            is_null($key) ? '' : self::path($key->reveal(), self::constant($environment, $name, self::KEY), $constants),
            self::key($environment, $name, self::PASSPHRASE, $constants) ?? new Secret(''),
        );
    }

    /**
     * A file config.php names: a relative path starts in CERT_DIR of the installation.
     * @param array<string, mixed> $constants AA_BASE_PATH among them, as locsess.php defines it
     * @throws ConfigException a relative path without AA_BASE_PATH - the directory PHP runs in is no base
     */
    private static function path(string $path, string $constant, #[\SensitiveParameter] array $constants): string
    {
        $path = trim($path);
        if (str_starts_with($path, '/')) {
            return $path;
        }
        $base = $constants['AA_BASE_PATH'] ?? null;
        if (!is_string($base) || ($base === '')) {
            throw new ConfigException("Give $constant in config.php a full path - AA_BASE_PATH, where a relative one starts, is not defined");
        }

        return rtrim($base, '/') . '/' . self::CERT_DIR . $path;
    }

    /**
     * Whether a production account may be meant for the site or the slices. Every production account
     * may be - bound, broken, or constants AA does not read for a typo in the name of the account or of
     * the constant - unless the site and slice ids in it are all of others. Ids are taken wherever they
     * stand in a SITE or SLICE constant: a list broken by one bad part still names its sites and slices,
     * ids glued together are read apart, and a word of 16 or more letters, digits and dashes that is no
     * id may be one of ours with a typo (AA reads no id with dashes).
     * A forgotten SITE and SLICES proves nothing, and the test ISDS reaches nobody.
     * @param array<string, mixed> $constants
     */
    private static function productionMeant(#[\SensitiveParameter] array $constants, string $site, string $campaignSlice, string $messageSlice): bool
    {
        $accounts = [];
        $doubtful = [];
        foreach (Store::contextsIn(self::VENDORS[Account::PRODUCTION], $constants) as $name => $context) {
            // the account without the last word: Nszm_SLICES and NSZM_SITES belong to NSZM
            $cut                = strrpos($context, '_');
            $account            = strtoupper(($cut === false) ? '' : substr($context, 0, $cut));
            $word               = strtoupper(($cut === false) ? $context : substr($context, $cut + 1));
            $accounts[$account] ??= [];
            // ids only, never a key: only the SITE and SLICE constants are read
            if (str_starts_with($word, self::SITE) || str_starts_with($word, 'SLICE')) {
                $value = $constants[$name] ?? '';
                preg_match_all('/[0-9a-z-]+/i', is_scalar($value) ? (string) $value : '', $tokens);
                foreach ($tokens[0] as $token) {
                    if (((strlen($token) % 32) === 0) && ctype_xdigit($token)) {
                        // ids glued together are read apart
                        array_push($accounts[$account], ...str_split(strtolower($token), 32));
                    } elseif (strlen($token) >= 16) {
                        // an id with dashes, a digit too few or many, o for 0: maybe ours
                        $doubtful[$account] = true;
                    }
                }
            }
        }
        foreach ($accounts as $account => $ids) {
            if (($ids === []) || isset($doubtful[$account]) || (array_intersect($ids, [$site, $campaignSlice, $messageSlice]) !== [])) {
                return true;
            }
        }

        return false;
    }

    /** how to bind an account - nothing about the accounts there are, the State Check lists them */
    private static function refusal(string $site, string $campaignSlice, string $messageSlice): string
    {
        $constant = static fn(string $kind): string => self::constant(Account::TEST, self::PLACEHOLDER, $kind);

        return 'No usable ISDS account in include/config.php is bound to this site and these slices. If a test account is bound and you mean it, ask for the test environment. To bind a test account, set '
            . $constant(self::SITE) . " to '$site', " . $constant(self::SLICES) . " to '$campaignSlice,$messageSlice' (the campaign slice first), "
            . $constant(self::LOGIN) . ' and ' . $constant(self::PASSWORD) . '; a production account logs in with a certificate (' . self::constant(Account::PRODUCTION, self::PLACEHOLDER, self::CERT) . ') instead. The State Check lists the accounts and their problems';
    }

    /**
     * An account's constant. SITE and SLICES hold no key, but they live with the keys and are read
     * the same way.
     *
     * @param array<string, mixed> $constants
     * @throws ConfigException a line break or NUL in it
     */
    private static function key(string $environment, string $name, string $kind, #[\SensitiveParameter] array $constants): ?Secret
    {
        try {
            return Store::resolveExact(self::VENDORS[$environment], "{$name}_{$kind}", $constants);
        } catch (\UnexpectedValueException $e) {
            throw new ConfigException($e->getMessage(), 0, $e);
        }
    }

    /** @return list<string>|null the ids of a list separated by commas; null when one is not an id */
    private static function ids(string $list): ?array
    {
        $ids = [];
        foreach (explode(',', $list) as $id) {
            $id = ModuleId::normalize(trim($id));
            if (is_null($id)) {
                return null;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /** the start the constants of an account share: AA_APIKEY_ISDSTEST_NSZM; '' account = of all accounts */
    public static function prefix(string $environment, string $account): string
    {
        return Store::names(self::VENDORS[$environment])[0] . "_$account";
    }

    /**
     * @param string $account an account, or PLACEHOLDER in advice
     * @param string $kind    SITE, SLICES, LOGIN, PASSWORD, CERT, KEY, PASSPHRASE or BOX
     */
    public static function constant(string $environment, string $account, string $kind): string
    {
        return self::prefix($environment, $account) . "_$kind";
    }

    /**
     * @param ?string $login Account::BASIC, CERT or HOSTED; null = of any login. Production never lists
     *                       LOGIN and PASSWORD: it lets an application in with a certificate only
     * @return list<string> the constants that set up an account, in the order config.php shows them
     */
    public static function constants(string $environment, string $account, ?string $login = null): array
    {
        if (($environment === Account::PRODUCTION) && in_array($login, [null, Account::BASIC], true)) {
            $login = Account::HOSTED;
        }
        $kinds = match ($login) {
            Account::BASIC  => [self::LOGIN, self::PASSWORD],
            Account::CERT   => [self::CERT, self::KEY, self::PASSPHRASE],
            Account::HOSTED => [self::BOX, self::CERT, self::KEY, self::PASSPHRASE],
            default         => [self::LOGIN, self::PASSWORD, self::BOX, self::CERT, self::KEY, self::PASSPHRASE],
        };

        return array_map(static fn(string $kind): string => self::constant($environment, $account, $kind), [self::SITE, self::SLICES, ...$kinds]);
    }
}
