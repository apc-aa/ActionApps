<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Credential\Store;
use AA\Http\ClientCertificate;
use AA\Isds\Account;
use AA\Isds\Accounts;
use AA\Isds\Binding;
use AA\Isds\ConfigException;
use AA\Isds\SliceProblem;
use AA\Isds\SliceRules;
use AA_Module;

/**
 * The ISDS accounts include/config.php sets up (AA\Isds\Accounts): what to fix in their constants,
 * the constants AA does not read, whether the site and the slices each account is bound to are ready
 * (SliceRules - the rules the page that sends applies), and the files of a certificate. Constant names
 * and file names only, never a key or a passphrase. No network: CheckIsds logs in.
 */
class CheckIsdsAccounts extends AbstractOptimize
{
    /** fewer days of a certificate warn: ISDS wants the new one 30 days ahead (Provozni_rad_ISDS.pdf p. 22) */
    private const CERTIFICATE_DAYS = 30;

    /** fewer are an error: registering a new certificate takes days */
    private const CERTIFICATE_ERROR_DAYS = 7;

    public static function name(): string
    {
        return _m('ISDS accounts');
    }

    public static function description(): string
    {
        return _m('Checks the ISDS accounts include/config.php sets up for sending to data boxes: their constants, the files of their certificates, and the site and slices each account sends for.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'mail';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    /** @return Finding[] */
    public function findings(): array
    {
        $constants = $this->constants();
        $bindings  = Accounts::bindings($constants);
        $strays    = Accounts::strays($constants);
        if (!$bindings && !$strays) {
            return [new Finding(Finding::OK, _m('No ISDS account is set up in include/config.php'))];
        }
        $rules    = $this->sliceRules();
        $findings = [];
        foreach ($bindings as $binding) {
            if (!$binding->usable()) {
                $findings[] = self::accountFinding($binding);
            }
            array_push($findings, ...$this->certificateFindings($binding, $constants));
            $finding = self::slicesFinding($binding, $rules);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }
        if ($strays) {
            $findings[] = new Finding(
                Finding::WARNING,
                _m('include/config.php has ISDS constants AA does not read: %1', [count($strays)]),
                count: count($strays),
                evidence: array_map(static fn(string $name): array => [_m('Constant') => safe($name)], $strays),
                advice: _m('An ISDS account is set up by the constants %1, the account named by one word of capital letters and digits; production ones start with %2. Correct the names, or remove the constants.', [implode(', ', Accounts::constants(Account::TEST, Accounts::PLACEHOLDER)), Accounts::prefix(Account::PRODUCTION, '')]),
                key: 'strays',
            );
        }

        return $findings ?: [new Finding(Finding::OK, _m('ISDS accounts ready to send: %1', [implode(', ', array_map(static fn(Binding $b): string => Accounts::prefix($b->environment, $b->name), $bindings))]))];
    }

    /** the account's own constants hold the problem */
    private static function accountFinding(Binding $binding): Finding
    {
        return new Finding(
            Finding::ERROR,
            _m('ISDS account %1 cannot be used', [Accounts::prefix($binding->environment, $binding->name)]),
            evidence: array_map(static fn(string $problem): array => [_m('Problem') => safe($problem)], $binding->problems),
            advice: _m('Fix in include/config.php what the evidence names. The account is set up by the constants %1.', [implode(', ', Accounts::constants($binding->environment, $binding->name, $binding->kind))]),
            key: "account:{$binding->environment}:{$binding->name}",
        );
    }

    /**
     * The files of a certificate as PHP sees them, even of an account not usable yet: what keeps them
     * from a TLS handshake (ClientCertificate::problem()), who else may read the key, how long the
     * certificate lasts. Evidence names the files and what the certificate says of itself, never the key
     * or the passphrase.
     * @param array<string, mixed> $constants
     * @return list<Finding>
     */
    private function certificateFindings(Binding $binding, #[\SensitiveParameter] array $constants): array
    {
        try {
            $certificate = Accounts::certificate($binding, $constants);
        } catch (ConfigException) {
            // a damaged constant: accountFinding() names it
            return [];
        }
        if (is_null($certificate)) {
            return [];
        }
        $label    = Accounts::prefix($binding->environment, $binding->name);
        $key      = "{$binding->environment}:{$binding->name}";
        $constant = static fn(string $kind): string => Accounts::constant($binding->environment, $binding->name, $kind);
        $files    = array_filter([Accounts::CERT => $certificate->certificate, Accounts::KEY => $certificate->key]);
        $problem  = $certificate->problem($this->now());
        // first: a key others may read is a leak even while the other file cannot be read
        $findings = array_filter([$this->keyAccess($files, $constant, $label, $key, $constants)]);
        if (in_array($problem, [ClientCertificate::UNREADABLE, ClientCertificate::KEY_UNREADABLE], true)) {
            $kind = ($problem === ClientCertificate::UNREADABLE) ? Accounts::CERT : Accounts::KEY;
            return [...$findings, new Finding(
                Finding::ERROR,
                _m('PHP cannot read the certificate of ISDS account %1', [$label]),
                evidence: [[_m('Constant') => $constant($kind), _m('File') => safe($files[$kind])]],
                advice: _m('Check that the constant names the file by a full path - a relative one starts in %1 of AA -, that the user PHP runs as may read it, and that open_basedir lets PHP there.', [Accounts::CERT_DIR]),
                key: "certificate-file:$key",
            )];
        }
        if ($problem === ClientCertificate::NO_OPENSSL) {
            return [...$findings, new Finding(Finding::ERROR, _m('The certificate of ISDS account %1 cannot be used: PHP has no openssl extension', [$label]), advice: _m('Install the openssl extension of PHP - a login with a certificate needs it.'), key: "certificate-unchecked:$key")];
        }
        $details  = $certificate->details() ?? [];
        $until    = (int) ($details['validTo_time_t'] ?? 0);
        $evidence = $details ? [_m('Subject') => safe((string) ($details['name'] ?? '')), _m('Serial number') => safe((string) ($details['serialNumberHex'] ?? '')), _m('Valid until') => date('Y-m-d H:i', $until)] : [];
        $text     = match ($problem) {
            ClientCertificate::NOT_A_CERTIFICATE => _m('%1 names a file with no PEM certificate', [$constant(Accounts::CERT)]),
            ClientCertificate::KEY_LOCKED        => _m('The private key in %1 does not open: it is missing, or %2 does not fit', [$constant(isset($files[Accounts::KEY]) ? Accounts::KEY : Accounts::CERT), $constant(Accounts::PASSPHRASE)]),
            ClientCertificate::KEY_MISMATCH      => _m('The private key in %1 does not belong to the certificate in %2', [$constant(isset($files[Accounts::KEY]) ? Accounts::KEY : Accounts::CERT), $constant(Accounts::CERT)]),
            ClientCertificate::WEAK_KEY          => _m('The private key is shorter than %1 bits, which the secure connection refuses', [ClientCertificate::MIN_BITS]),
            ClientCertificate::NOT_YET_VALID     => _m('The certificate is valid from %1 only', [date('Y-m-d H:i', (int) ($details['validFrom_time_t'] ?? 0))]),
            default                              => '',
        };
        if ($text !== '') {
            $findings[] = new Finding(Finding::ERROR, _m('The certificate of ISDS account %1 cannot be used', [$label]), evidence: [$evidence + [_m('Problem') => $text]], advice: _m('Name the certificate and its key in include/config.php, and the passphrase of an encrypted key.'), key: "certificate-invalid:$key");
        }
        $left = $until - $this->now();
        if ($details && ($left < self::CERTIFICATE_DAYS * 86400)) {
            $findings[] = new Finding(
                ($left < self::CERTIFICATE_ERROR_DAYS * 86400) ? Finding::ERROR : Finding::WARNING,
                ($left < 0) ? _m('The certificate of ISDS account %1 expired on %2', [$label, date('Y-m-d', $until)]) : _m('The certificate of ISDS account %1 expires on %2', [$label, date('Y-m-d', $until)]),
                evidence: [$evidence],
                advice: _m('Get a new certificate, have ISDS register it next to the old one, and name its file in include/config.php - ISDS wants it %1 days before the old one expires.', [self::CERTIFICATE_DAYS]),
                key: "certificate-expiry:$key",
            );
        }

        return $findings;
    }

    /**
     * Who else may read a file that holds a private key, the certificate file too. Inside the directory
     * of AA the web server may serve it whatever .htaccess says - there the group must not read it
     * either, and with PHP inside the web server no mode helps; outside, others must not. Inside is
     * judged by the path, so a bind mount of the directory of AA elsewhere is not seen.
     * @param array<string, string>   $files     CERT and KEY => the file
     * @param \Closure(string): string $constant the constant of a kind
     * @param array<string, mixed>    $constants AA_BASE_PATH among them
     */
    private function keyAccess(array $files, \Closure $constant, string $label, string $key, #[\SensitiveParameter] array $constants): ?Finding
    {
        clearstatcache(true);
        $base = is_string($constants['AA_BASE_PATH'] ?? null) ? $constants['AA_BASE_PATH'] : '';
        // as written and as resolved: the web server follows links (FollowSymLinks)
        $bases    = ($base === '') ? [] : array_unique(array_filter([self::collapse($base), realpath($base)], is_string(...)));
        $module   = $this->sapi() === 'apache2handler';
        $evidence = [];
        $served   = false;
        foreach (array_unique($files) as $kind => $file) {
            $real = realpath($file);
            // not a FIFO or a device: reading one would wait for ever
            if (($real === false) || !is_file($real) || !is_readable($real) || !str_contains((string) file_get_contents($real, false, null, 0, ClientCertificate::MAX_BYTES), 'PRIVATE KEY-----')) {
                continue;
            }
            // as written, as resolved, and in its resolved directory: a link to a key outside is served too
            $paths = [self::collapse($file), $real, (realpath(dirname($file)) ?: '') . '/' . basename($file)];
            // an unknown base counts as inside: the stricter rule
            $inside = ($bases === []) || self::under($paths, $bases);
            $mode   = (int) fileperms($real);
            if (($inside && $module) || (($mode & ($inside ? 0o077 : 0o007)) !== 0)) {
                $served     = $served || $inside;
                $evidence[] = [_m('Constant') => $constant($kind), _m('File') => safe($file), _m('Permissions') => sprintf('%04o', $mode & 0o7777)];
            }
        }
        if (!$evidence) {
            return null;
        }

        return new Finding(
            Finding::ERROR,
            $served ? _m('The web server may serve the private key of ISDS account %1', [$label]) : _m('Other users of the server may read the private key of ISDS account %1', [$label]),
            evidence: $evidence,
            advice: match (true) {
                $served && $module => _m('PHP runs inside the web server here, so the web server reads whatever PHP may read - no permissions help. Move the file outside the directory of AA and name it by a full path.'),
                $served            => _m('The file lies in the directory of AA, which the web server serves. Move it outside and name it by a full path, or leave it only to its owner (chmod 600) and check its ACL (getfacl).'),
                default            => _m('Take every right of other users from the file (chmod o-rwx) and check its ACL (getfacl).'),
            },
            key: "certificate-access:$key",
        );
    }

    /** an absolute $path with "." and ".." taken away as text: "/aa/../keys" is not in "/aa" */
    private static function collapse(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            return $path;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif (($part !== '') && ($part !== '.')) {
                $parts[] = $part;
            }
        }

        return '/' . implode('/', $parts);
    }

    /**
     * @param list<string> $paths
     * @param array<string> $bases
     */
    private static function under(array $paths, array $bases): bool
    {
        foreach ($paths as $path) {
            foreach ($bases as $base) {
                if (str_starts_with($path, rtrim($base, '/') . '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** the slices of a binding that names them, and its sites - even of an unusable binding, it gets fixed */
    private static function slicesFinding(Binding $binding, SliceRules $rules): ?Finding
    {
        if ($binding->campaignSlice === '') {
            return null;
        }
        $problems = [];
        foreach ($binding->sites as $site) {
            array_push($problems, ...$rules->siteProblems($site, $binding->campaignSlice, $binding->messageSlice));
        }
        array_push($problems, ...$rules->sliceProblems($binding->campaignSlice, $binding->messageSlice));
        if (!$problems) {
            return null;
        }

        return new Finding(
            Finding::ERROR,
            _m('The slices of ISDS account %1 are not ready for sending', [Accounts::prefix($binding->environment, $binding->name)]),
            count: count($problems),
            evidence: array_map(static fn(SliceProblem $problem): array => [_m('Problem') => safe($problem->text)], $problems),
            advice: _m('Fix what the evidence names before sending from these slices.'),
            links: self::links($problems, $rules),
            key: "slices:{$binding->environment}:{$binding->name}",
            place: $rules->name($binding->campaignSlice),
        );
    }

    /**
     * The pages that fix the problems, one link for each page of each module. Two modules of one name
     * are told apart by the start of their ids.
     * @param list<SliceProblem> $problems
     * @return array<string, string> label => url
     */
    private static function links(array $problems, SliceRules $rules): array
    {
        $names = [];
        foreach ($problems as $problem) {
            if (($problem->module !== '') && ($problem->page !== '')) {
                $names[$problem->module] ??= $rules->name($problem->module);
            }
        }
        $counts = array_count_values($names);
        $links  = [];
        foreach ($problems as $problem) {
            if (!isset($names[$problem->module]) || ($problem->page === '')) {
                continue;
            }
            $name = $names[$problem->module];
            if ($counts[$name] > 1) {
                $name .= ' (' . substr($problem->module, 0, 8) . ')';
            }
            $links[self::pageLabel($problem->page) . ': ' . $name] = AA_Module::getModuleUrl($problem->module, $problem->page);
        }

        return $links;
    }

    private static function pageLabel(string $page): string
    {
        return match ($page) {
            SliceRules::SLICE_PAGE   => _m('Slice settings'),
            SliceRules::FIELDS_PAGE  => _m('Fields'),
            SliceRules::FEEDING_PAGE => _m('Inner Node Feeding'),
            SliceRules::NOTIFY_PAGE  => _m('Email Notification'),
            SliceRules::SITE_PAGE    => _m('Site settings'),
            default                  => $page,
        };
    }

    // ------------------------------------------------------------ what tests replace

    /** @return array<string, mixed> as Store::userConstants() */
    protected function constants(): array
    {
        return Store::userConstants();
    }

    protected function sliceRules(): SliceRules
    {
        return new SliceRules();
    }

    protected function now(): int
    {
        return time();
    }

    /** the PHP_SAPI of the requests that read the key: apache2handler is PHP inside the web server */
    protected function sapi(): string
    {
        return PHP_SAPI;
    }
}
