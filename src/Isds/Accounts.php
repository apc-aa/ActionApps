<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Credential\Secret;
use AA\Credential\Store;

/**
 * The ISDS accounts include/config.php sets up. Each sends only for the sites and the one pair of
 * slices - campaigns and their messages - the server's administrator bound it to:
 *     define('AA_APIKEY_ISDSTEST_NSZM_SITE',     '<site module id>');   // more sites: separated by commas
 *     define('AA_APIKEY_ISDSTEST_NSZM_SLICES',   '<campaign slice id>,<message slice id>');
 *     define('AA_APIKEY_ISDSTEST_NSZM_LOGIN',    '...');
 *     define('AA_APIKEY_ISDSTEST_NSZM_PASSWORD', '...');
 * The key vendor is the environment (isds = production, isdstest = test), NSZM names the account: one
 * word of capital letters and digits. Slice ids are public, so they alone choose no account: the
 * caller passes the site as well, and an account is used only where the site and both slices match.
 * A slice belongs to one account of an environment. Production is refused until AA can log in with
 * a certificate (Account).
 *
 * @phpstan-type Found array{name: string, environment: string, sites: list<string>, slices: array{string, string}, problems: list<string>, targeted: bool}
 */
final class Accounts
{
    public const SITE     = 'SITE';
    public const SLICES   = 'SLICES';
    public const LOGIN    = 'LOGIN';
    public const PASSWORD = 'PASSWORD';

    /** the key vendor of each environment */
    public const VENDORS = [Account::PRODUCTION => 'isds', Account::TEST => 'isdstest'];

    /** an account of one word: AA_APIKEY_ISDS_TEST_NSZM_SLICES cannot pass for a test account */
    private const KEY = '/^([A-Z0-9]+)_(' . self::SITE . '|' . self::SLICES . '|' . self::LOGIN . '|' . self::PASSWORD . ')\z/';

    /**
     * The account bound to the site and the pair of slices.
     *
     * @param string $site        the site module the page belongs to (AA::$site_id), never a value from the template
     * @param string $environment '' = production when a production account may be meant for the site or the
     *                            slices, else test; 'test' or 'production' = that one only (a template narrows
     *                            to test, a queued run asks for the environment it was queued in)
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
            $environment = Account::TEST;
            foreach ($bindings as $b) {
                if (($b->environment === Account::PRODUCTION) && self::concerns($b, $site, $campaignSlice, $messageSlice)) {
                    $environment = Account::PRODUCTION;
                    break;
                }
            }
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

        return self::login($binding->environment, $binding->name, $constants);
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
            $bindings[] = new Binding($f['name'], $f['environment'], $f['sites'], $f['slices'][0], $f['slices'][1], [...$f['problems'], ...($shared[$i] ?? [])], $f['targeted']);
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
                if ((preg_match(self::KEY, $context, $m) === 1) && ($name === self::constant($environment, $m[1], $m[2]))) {
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
        if ($environment === Account::PRODUCTION) {
            $problems[] = "The production account $name cannot be used: production ISDS lets an application in with a certificate only, and AA cannot log in with one yet";
            if (in_array(self::PASSWORD, $kinds, true)) {
                $problems[] = 'Remove ' . self::constant($environment, $name, self::PASSWORD) . ' from config.php - AA never logs in to production ISDS with a password';
            }
        } else {
            try {
                self::login($environment, $name, $constants);
            } catch (ConfigException $e) {
                $problems[] = $e->getMessage();
            }
        }

        $targeted = array_intersect([self::SITE, self::SLICES], $kinds) !== [];

        return ['name' => $name, 'environment' => $environment, 'sites' => $sites, 'slices' => $slices, 'problems' => $problems, 'targeted' => $targeted];
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
     * @param array<string, mixed> $constants
     * @throws ConfigException
     */
    private static function login(string $environment, string $name, #[\SensitiveParameter] array $constants): Account
    {
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
     * Whether a binding may be meant for the site or the slices. One whose site and slices cannot be
     * read may be - if a SITE or SLICES constant is there, even an empty or unreadable one; without
     * either (a stray AA_APIKEY_ISDS_TEST_LOGIN) it is bound to nothing.
     */
    private static function concerns(Binding $b, string $site, string $campaignSlice, string $messageSlice): bool
    {
        $slices = array_filter([$b->campaignSlice, $b->messageSlice]);

        return in_array($site, $b->sites, true)
            || (array_intersect($slices, [$campaignSlice, $messageSlice]) !== [])
            || ($b->targeted && ($b->sites === []) && ($slices === []));
    }

    /** how to bind an account - nothing about the accounts there are, the State Check lists them */
    private static function refusal(string $site, string $campaignSlice, string $messageSlice): string
    {
        $constant = static fn(string $kind): string => self::constant(Account::TEST, '<ACCOUNT>', $kind);

        return 'No usable ISDS account in include/config.php is bound to this site and these slices. If a test account is bound and you mean it, ask for the test environment. To bind a test account, set '
            . $constant(self::SITE) . " to '$site', " . $constant(self::SLICES) . " to '$campaignSlice,$messageSlice' (the campaign slice first), "
            . $constant(self::LOGIN) . ' and ' . $constant(self::PASSWORD) . '; the State Check lists the accounts and their problems';
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

    /** @param string $account an account, or <ACCOUNT> in advice */
    private static function constant(string $environment, string $account, string $kind): string
    {
        return Store::names(self::VENDORS[$environment])[0] . "_{$account}_{$kind}";
    }
}
