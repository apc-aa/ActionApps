<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * Settings of the data-box client behind {datovka} (misc/datovka/datovka.php), from misc/datovka/datovka_conf.php,
 * which the administrator makes from datovka_conf.php.default. The file holds only what is the
 * installation's own: the certificate and the atsId of its registration of the authentication service of
 * ISDS, and the installations sharing that registration. The addresses of ISDS are the same everywhere, so
 * they live here. The only reader of the file; AA runs without it.
 * Problems and strays name keys of the file, never values - still escape them when shown (safe()).
 */
final class DatovkaConf
{
    /** the file of the installation, in the directory of AA; git ignores it */
    public const FILE = 'misc/datovka/datovka_conf.php';

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

    /** keys of the file read elsewhere - the accounts of mass sending */
    private const ELSEWHERE = ['accounts'];

    /** an instance id is the last four digits of the token ISDS hands back */
    private const MAX_INSTANCE = 9999;

    private const NOT_READ = ' - AA runs as if the file were not there';

    private const UNREADABLE = 'PHP cannot read ' . self::FILE . ' - give it the owner, group and mode of include/config.php, and check that open_basedir lets PHP there';

    private const UNREACHABLE = self::FILE . ' links to a file PHP cannot reach (gone, outside open_basedir, or in a directory PHP may not enter)' . self::NOT_READ;

    private static ?self $loaded = null;

    /**
     * @param array<string, array{cert: string, atsId: string}> $own
     * @param array<int, string> $instances
     * @param list<string> $strays
     * @param list<string> $problems
     */
    private function __construct(
        private readonly array $own = [],
        private readonly array $instances = [],
        private readonly array $strays = [],
        private readonly array $problems = [],
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
            // a link to a file that is gone, or outside open_basedir (then even is_link() is false): the directory still lists it
            return in_array(basename($file), @scandir(dirname($file)) ?: [], true) ? self::broken(self::UNREACHABLE) : new self();
        }
        if (!is_readable($file)) {
            return self::broken(self::UNREADABLE);
        }
        ob_start();   // a hand-edited file may print a BOM or a space before <?php
        try {
            // in a scope of its own: variables of the file cannot overwrite these
            $included = (static fn(string $path): mixed => include_once $path)($file);
        } catch (\ParseError $e) {
            return self::broken(self::FILE . " is not valid PHP (line {$e->getLine()})" . self::NOT_READ);
        } catch (\Throwable $e) {   // its message may quote a value; its file and line may be of the caller
            return self::broken(self::FILE . ' stops with ' . $e::class . self::NOT_READ);
        } finally {
            ob_end_clean();
        }
        if ($included === false) {
            return self::broken(self::UNREADABLE);
        }
        if (!defined('DATOVKA_CONF')) {
            return self::broken(self::FILE . ' does not define DATOVKA_CONF' . self::NOT_READ);
        }
        $conf = constant('DATOVKA_CONF');
        if (!is_array($conf)) {
            return self::broken('DATOVKA_CONF in ' . self::FILE . ' is not an array' . self::NOT_READ);
        }

        return self::fromArray($conf);
    }

    /**
     * Settings from the value of DATOVKA_CONF. A part of the wrong type is left out with a problem; url_*
     * and wsdl_* of an environment are ignored - ATS gives them.
     *
     * @param array<mixed> $conf
     */
    public static function fromArray(array $conf): self
    {
        $own = $strays = $problems = [];
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
                } elseif (!is_string($value)) {
                    $problems[] = "$where is not a string - AA leaves it out";
                } else {
                    $own[$environment][$key] = (($key === 'cert') && ($value !== '') && !str_starts_with($value, '/'))
                        ? self::base() . Accounts::CERT_DIR . $value
                        : $value;
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
            $url = is_array($instance) ? ($instance['url'] ?? null) : null;
            // the answer goes on to <url>misc/datovka/service.php
            if (is_int($id) && ($id >= 1) && ($id <= self::MAX_INSTANCE) && is_string($url) && str_starts_with($url, 'https://') && str_ends_with($url, '/')) {
                $instances[$id] = $url;
            } else {
                $problems[] = self::key('instances', $id) . ' is left out - each installation is an id from 1 to '
                    . self::MAX_INSTANCE . " => ['url' => '<AA_INSTAL_URL of the installation>'], starting with https:// and ending with /";
            }
        }

        foreach (array_keys($conf) as $key) {
            if (!in_array($key, ['instances', ...array_keys(self::ATS), ...self::ELSEWHERE], true)) {
                $strays[] = self::key($key);
            }
        }

        return new self($own, $instances, $strays, $problems);
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

    /** @return list<string> keys of the file AA does not know, as DATOVKA_CONF['key'] */
    public function strays(): array
    {
        return $this->strays;
    }

    /** @return list<string> what in the file AA cannot use, and how to put it right */
    public function problems(): array
    {
        return $this->problems;
    }

    private static function broken(string $problem): self
    {
        return new self(problems: [$problem]);
    }

    /** the directory of AA */
    private static function base(): string
    {
        return dirname(__DIR__, 2) . '/';
    }

    /** where in DATOVKA_CONF, as PHP writes it */
    private static function key(int|string ...$keys): string
    {
        return 'DATOVKA_CONF' . implode('', array_map(static fn(int|string $key): string => is_int($key) ? "[$key]" : "['$key']", $keys));
    }
}
