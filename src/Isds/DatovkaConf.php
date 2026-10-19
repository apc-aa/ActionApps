<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Credential\Secret;

/**
 * Settings of the data-box client behind {datovka} (misc/datovka/datovka.php) and of the accounts of mass
 * sending (Accounts), from misc/datovka/datovka_conf.php, which the administrator makes from
 * datovka_conf.php.default. The file holds only what is the installation's own: the certificate and the
 * atsId of its registration of the authentication service of ISDS, the installations sharing that
 * registration, and the accounts. The addresses of ISDS are the same everywhere, so they live here. The
 * only reader of the file; AA runs without it.
 * Problems and strays name keys of the file, never values - still escape them when shown (safe()).
 */
final class DatovkaConf
{
    /** the file of the installation, in the directory of AA; git ignores it */
    public const FILE = 'misc/datovka/datovka_conf.php';

    /** the key of the accounts of mass sending, which Accounts reads */
    public const ACCOUNTS = 'accounts';

    /** where a relative path of a certificate or a key starts in the directory of AA; git ignores the files there */
    public const CERT_DIR = 'config/certs/';

    /** the authentication service in each environment; a WSDL path starts in misc/datovka/ */
    public const ATS = [
        'test' => [   // the public test environment of ISDS
            'url_login'          => 'https://www.datovka-test.gov.cz/as/login',
            'url_GetCredential'  => 'https://cert.datovka-test.gov.cz/asws/extIs2Endpoint',
            'url_SetConcept'     => 'https://cert.datovka-test.gov.cz/asws/konceptEndpoint',
            'url_ViewConcept'    => 'https://www.datovka-test.gov.cz/as/koncept/view',
            'wsdl_GetCredential' => 'wsdls/GetCredential.wsdl',
            'wsdl_SetConcept'    => 'wsdls/SetConcept.wsdl',
        ],
        'standard' => [   // production - real messages
            'url_login'          => 'https://www.datovka.gov.cz/as/login',
            'url_GetCredential'  => 'https://cert.datovka.gov.cz/asws/extIs2Endpoint',
            'url_SetConcept'     => 'https://cert.datovka.gov.cz/asws/konceptEndpoint',
            'url_ViewConcept'    => 'https://www.datovka.gov.cz/as/koncept/view',
            'wsdl_GetCredential' => 'wsdls/GetCredential.wsdl',
            'wsdl_SetConcept'    => 'wsdls/SetConcept.wsdl',
        ],
    ];

    /** what the file may set for an environment, and its value when unset */
    private const OWN = ['cert' => '', 'atsId' => ''];

    /** the keys of an account that hold secrets; a dump of the settings shows none of them */
    private const SECRETS = [Accounts::PASSWORD, Accounts::PASSPHRASE];

    /** the keys of an account whose text is read as it is; any other value is kept as Secret - a misspelt password too */
    private const PLAIN = [Accounts::ENVIRONMENT, Accounts::AUTH, Accounts::CERT, Accounts::KEY, Accounts::BOX, Accounts::LOGIN];

    /** the keys of an account whose list of texts is read as it is */
    private const LISTS = [Accounts::SITES, Accounts::SLICES];

    /** the file may be there, but PHP of this request could not read it */
    private const UNREAD = 'unread';

    /** the file holds no settings, or no list of accounts */
    private const BROKEN = 'broken';

    /** an instance id is the last four digits of the token ISDS hands back */
    private const MAX_INSTANCE = 9999;

    private const NOT_READ = ' - AA runs as if the file were not there';

    private const UNREADABLE = 'PHP cannot read ' . self::FILE . ' - give it the owner, group and mode of include/config.php, and check that open_basedir lets PHP there';

    private const UNREACHABLE = self::FILE . ' links to a file PHP cannot reach (gone, outside open_basedir, or in a directory PHP may not enter)' . self::NOT_READ;

    private const UNLISTED = 'PHP cannot list the directory of ' . self::FILE . ', so it cannot tell whether the file is there - check that open_basedir lets PHP there and that PHP may read the directory';

    private static ?self $loaded = null;

    /**
     * @param array<string, array{cert: string, atsId: string}> $own
     * @param array<int, string> $instances
     * @param list<string> $strays
     * @param list<string> $problems
     * @param array<array-key, mixed> $accounts
     * @param list<array<array-key, mixed>> $loose
     * @param string $failure '', UNREAD or BROKEN
     */
    private function __construct(
        private readonly array $own = [],
        private readonly array $instances = [],
        private readonly array $strays = [],
        private readonly array $problems = [],
        #[\SensitiveParameter]
        private readonly array $accounts = [],
        #[\SensitiveParameter]
        private readonly array $loose = [],
        private readonly string $failure = '',
    ) {}

    /** The settings of this installation, read once a request: PHP defines the constant of the file only once. */
    public static function load(): self
    {
        return self::$loaded ??= self::fromFile(self::base() . self::FILE);
    }

    /**
     * Settings from a file in the form of datovka_conf.php: no file gives the defaults, a broken one the
     * defaults and a problem. Includes the file once a process, and the first file to define the constant
     * wins - a test reads each file in a process of its own.
     */
    public static function fromFile(string $file): self
    {
        // @: under open_basedir the warning would show the path and the allowed directories
        if (!@file_exists($file)) {
            $listed = @scandir(dirname($file));
            if ($listed === false) {
                // a network file system refuses PHP now and then: the file may be there
                return self::failed(self::UNLISTED, self::UNREAD);
            }
            // a link to a file that is gone, or outside open_basedir (then even is_link() is false): the directory still lists it
            return in_array(basename($file), $listed, true) ? self::failed(self::UNREACHABLE, self::UNREAD) : new self();
        }
        if (!is_readable($file)) {
            return self::failed(self::UNREADABLE, self::UNREAD);
        }
        ob_start();   // a hand-edited file may print a BOM or a space before <?php
        try {
            // in a scope of its own: variables of the file cannot overwrite these
            $included = (static fn(string $path): mixed => include_once $path)($file);
        } catch (\ParseError $e) {
            return self::failed(self::FILE . " is not valid PHP (line {$e->getLine()})" . self::NOT_READ, self::BROKEN);
        } catch (\Throwable $e) {   // its message may quote a value; its file and line may be of the caller
            return self::failed(self::FILE . ' stops with ' . $e::class . self::NOT_READ, self::BROKEN);
        } finally {
            ob_end_clean();
        }
        if ($included === false) {
            return self::failed(self::UNREADABLE, self::UNREAD);
        }
        if (!defined('DATOVKA_CONF')) {
            return self::failed(self::FILE . ' does not define DATOVKA_CONF' . self::NOT_READ, self::BROKEN);
        }
        $conf = constant('DATOVKA_CONF');
        if (!is_array($conf)) {
            return self::failed('DATOVKA_CONF in ' . self::FILE . ' is not an array' . self::NOT_READ, self::BROKEN);
        }

        return self::fromArray($conf);
    }

    /**
     * Settings from the value of DATOVKA_CONF. A part of the wrong type is left out with a problem; url_*
     * and wsdl_* of an environment are ignored - ATS gives them.
     *
     * @param array<mixed> $conf
     */
    public static function fromArray(#[\SensitiveParameter] array $conf): self
    {
        $own = $strays = $problems = $loose = [];
        foreach (array_keys(self::ATS) as $environment) {
            $own[$environment] = self::OWN;
            $section = $conf[$environment] ?? [];
            if (!is_array($section)) {
                $problems[] = self::key($environment) . ' is not an array - AA uses no certificate and no atsId for it';
                continue;
            }
            foreach ($section as $key => $value) {
                $where = self::key($environment, $key);
                if (!array_key_exists($key, self::OWN)) {
                    if (!array_key_exists($key, self::ATS[$environment])) {
                        $strays[] = $where;
                    }
                    // url_* and wsdl_* AA ignores hold texts: an array there is out of its place too
                    $loose[] = $value;
                } elseif (!is_string($value)) {
                    $problems[] = "$where is not a string - AA leaves it out";
                    $loose[]    = $value;
                } else {
                    $own[$environment][$key] = ($key === 'cert') ? self::path($value) : $value;
                }
            }
        }

        $instances = [];
        $listed = $conf['instances'] ?? [];
        if (!is_array($listed)) {
            $problems[] = self::key('instances') . ' is not an array - AA lists no installations';
            $listed = [];
        }
        foreach ($listed as $id => $instance) {
            // an installation is numbered and holds the text url only: a named one is no installation at all
            array_push($loose, ...(is_array($instance) ? (is_string($id) ? [$instance] : array_values($instance)) : []));
            $url = is_array($instance) ? ($instance['url'] ?? null) : null;
            // the answer goes on to <url>misc/datovka/service.php
            if (is_int($id) && ($id >= 1) && ($id <= self::MAX_INSTANCE) && is_string($url) && str_starts_with($url, 'https://') && str_ends_with($url, '/')) {
                $instances[$id] = $url;
            } else {
                $problems[] = self::key('instances', $id) . ' is left out - each installation is an id from 1 to '
                    . self::MAX_INSTANCE . " => ['url' => '<AA_INSTAL_URL of the installation>'], starting with https:// and ending with /";
            }
        }

        $accounts = [];
        $listed   = $conf[self::ACCOUNTS] ?? [];
        $failure  = '';
        if (!is_array($listed)) {
            $problems[] = self::key(self::ACCOUNTS) . ' is not an array - AA has no ISDS account';
            $listed     = [];
            // a broken file, not one without accounts: a run fails on its settings, not on a sender gone
            $failure = self::BROKEN;
        }
        foreach ($listed as $name => $fields) {
            $accounts[$name] = is_array($fields) ? self::hidden($fields) : self::secret($fields);
        }

        foreach ($conf as $key => $value) {
            if (!in_array($key, ['instances', ...array_keys(self::ATS), self::ACCOUNTS], true)) {
                $strays[] = self::key($key);
                $loose[]  = $value;
            }
        }
        /** @var list<array<array-key, mixed>> $loose */
        $loose = array_map(self::secret(...), array_values(array_filter($loose, static fn(mixed $value): bool => is_array($value) && ($value !== []))));

        return new self($own, $instances, $strays, $problems, $accounts, $loose, $failure);
    }

    /**
     * An account with all AA does not read as it is - a misspelt key of a password, a list where a text
     * belongs - as Secret; a password or a passphrase that is neither a text nor a list as false: a number
     * without quotes may be a password, 0123 even an octal one.
     *
     * @param array<array-key, mixed> $fields
     * @return array<array-key, mixed>
     */
    private static function hidden(#[\SensitiveParameter] array $fields): array
    {
        foreach ($fields as $key => $value) {
            if (in_array($key, self::SECRETS, true)) {
                $fields[$key] = match (true) {
                    is_string($value) => new Secret($value),
                    is_array($value)  => self::secret($value),
                    is_null($value)   => null,
                    default           => false,
                };
            } elseif (in_array($key, self::LISTS, true) && is_array($value)) {
                foreach ($value as $i => $item) {
                    $fields[$key][$i] = (is_int($i) && is_string($item)) ? $item : self::secret($item);
                }
            } elseif (!in_array($key, self::PLAIN, true) || !is_string($value)) {
                $fields[$key] = self::secret($value);
            }
        }

        return $fields;
    }

    /** every value in $value, at any depth, as Secret - an object as an empty one */
    private static function secret(#[\SensitiveParameter] mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::secret(...), $value);
        }

        return is_null($value) ? null : new Secret(is_scalar($value) ? (string) $value : '');
    }

    /**
     * The authentication service of the environment: the addresses and WSDL files of ATS, and the
     * certificate (a full path, a relative one taken from config/certs/) and the atsId of this installation.
     *
     * @return array{cert: string, atsId: string, url_login: string, url_GetCredential: string, url_SetConcept: string, url_ViewConcept: string, wsdl_GetCredential: string, wsdl_SetConcept: string}
     * @throws \InvalidArgumentException an environment other than test and standard
     */
    public function ats(string $environment): array
    {
        $ats = self::ATS[$environment] ?? throw new \InvalidArgumentException("Unknown ISDS environment: $environment");
        $dir = self::base() . 'misc/datovka/';

        return [
            'cert'               => $this->own[$environment]['cert'] ?? '',
            'atsId'              => $this->own[$environment]['atsId'] ?? '',
            'wsdl_GetCredential' => $dir . $ats['wsdl_GetCredential'],
            'wsdl_SetConcept'    => $dir . $ats['wsdl_SetConcept'],
        ] + $ats;
    }

    /**
     * The installations sharing the registration of the authentication service: ISDS hands every answer
     * to one of them, which passes it on to the installation in the token.
     *
     * @return array<int, string> id => AA_INSTAL_URL of the installation
     */
    public function instances(): array
    {
        return $this->instances;
    }

    /**
     * The accounts of mass sending as the file writes them, name => their keys. Password and passphrase
     * are Secret, or false when they are neither a text nor a list. Only the texts of environment, auth, cert, key, box and
     * login and the texts listed in sites and slices by number are as written; the rest is Secret - an
     * account that is no array too. Accounts reads them.
     *
     * @return array<array-key, mixed>
     */
    public function accounts(): array
    {
        return $this->accounts;
    }

    /**
     * The arrays outside the accounts where the file holds no array - under a key AA does not know, at the
     * top or in test and standard, in place of a text, in an installation -, every scalar in them as
     * Secret: perhaps an account a bracket out of its place. Accounts looks for ids in them, and in the
     * arrays in them. Objects are not looked into: the administrator writes the file, and no typo makes one.
     *
     * @return list<array<array-key, mixed>>
     */
    public function loose(): array
    {
        return $this->loose;
    }

    /** @return list<string> keys of the file AA does not know, as DATOVKA_CONF['key']; Accounts::strays() those of an account */
    public function strays(): array
    {
        return $this->strays;
    }

    /** @return list<string> what in the file AA cannot use, and how to put it right */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The file is there, or PHP cannot list its directory, but PHP of this request could not read it, so
     * AA runs on the defaults. A network file system may refuse PHP for a while: the next request may
     * read it.
     */
    public function unreadable(): bool
    {
        return $this->failure === self::UNREAD;
    }

    /**
     * The file is there, but holds no settings - it is no valid PHP, it stops, or it defines no array -,
     * or its accounts are no array.
     */
    public function broken(): bool
    {
        return $this->failure === self::BROKEN;
    }

    /** a file the settings name, by a full path: a relative one starts in CERT_DIR of AA; '' = none */
    public static function path(string $path): string
    {
        $path = trim($path);

        return (($path === '') || str_starts_with($path, '/')) ? $path : self::base() . self::CERT_DIR . $path;
    }

    /** the directory of AA, ending with a slash */
    public static function base(): string
    {
        return dirname(__DIR__, 2) . '/';
    }

    /** where in DATOVKA_CONF, as PHP writes it: DATOVKA_CONF['accounts']['NSZM'] */
    public static function key(int|string ...$keys): string
    {
        return 'DATOVKA_CONF' . implode('', array_map(static fn(int|string $key): string => is_int($key) ? "[$key]" : "['$key']", $keys));
    }

    /** @param string $failure UNREAD or BROKEN */
    private static function failed(string $problem, string $failure): self
    {
        return new self(problems: [$problem], failure: $failure);
    }
}
