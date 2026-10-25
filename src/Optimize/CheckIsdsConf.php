<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Credential\Secret;
use AA\Isds\Accounts;
use AA\Isds\Binding;
use AA\Isds\DatovkaConf;
use AA\Util\LogOnce;
use AA_Log;

/**
 * misc/datovka/datovka_conf.php itself (AA\Isds\DatovkaConf): one file of the tree for all its sites and
 * databases, so shown in each - whether PHP reads it and AA can use all of it, keys AA does not read or
 * that are written twice, its permissions next to those of include/config.php, copies of it in the
 * directory of AA, and of the last week the problems other requests met reading it and the answers of
 * ISDS service.php refused for an installation it does not list (both logged once a day), test_boxes and
 * paid_tests AA cannot read. Names of keys
 * and files only, never a value; an account written twice by its name only in the database of its sites.
 * Its accounts CheckIsdsAccounts checks.
 */
class CheckIsdsConf extends AbstractOptimize
{
    /** a problem logged that long ago is likely fixed - it is logged once a day while it is not */
    private const LOG_WINDOW_SECONDS = 7 * 86400;
    private const LOG_ROWS           = 50;

    public static function name(): string
    {
        return _m('ISDS settings file');
    }

    public static function description(): string
    {
        return _m('Checks misc/datovka/datovka_conf.php, the data-box settings of this installation for {datovka} and mass sending: whether PHP reads it and AA can use all of it, keys written twice, its permissions, copies of it in the directory of AA, and in the last week the problems other requests met reading it and the answers of ISDS refused for an installation it does not list.');
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
        $conf     = $this->conf();
        $unread   = $this->unreadableHere($conf);
        $findings = [
            ...$this->fileFindings($conf),
            ...array_filter([$this->repeatedFinding($conf), $this->testBoxesFinding($unread[Accounts::TEST_BOXES]), $this->paidTestsFinding($unread[Accounts::PAID_TESTS]), $this->accessFinding($conf), $this->copiesFinding()]),
            ...$this->loggedFindings($conf),
            ...$this->instanceFindings(),
        ];
        if ($findings) {
            return $findings;
        }

        return [new Finding(Finding::OK, @file_exists($this->file()) ? _m('AA reads all of misc/datovka/datovka_conf.php') : _m('There is no misc/datovka/datovka_conf.php - AA runs without the data-box settings of this installation'))];
    }

    /**
     * In place of the OK of a check of the accounts while AA cannot use the file, or its accounts: it has
     * no account then - none read, not none bound.
     * @return list<Finding>
     */
    public static function notChecked(DatovkaConf $conf): array
    {
        if (!$conf->unreadable() && !$conf->broken()) {
            return [];
        }

        return [new Finding(Finding::INFO, _m('ISDS accounts not checked: %1', [self::accountsLost()]), advice: _m('The check "%1" tells what to fix.', [self::name()]), key: 'file')];
    }

    /**
     * What this request finds in the file: why AA cannot use it or its accounts, what it leaves out, keys
     * it does not read, url_* and wsdl_* of an older file.
     * @return list<Finding>
     */
    private function fileFindings(DatovkaConf $conf): array
    {
        $findings   = [];
        $problems   = $conf->problems();
        $unreadable = $conf->unreadable();
        $evidence   = static fn(array $texts, string $label): array => array_map(static fn(string $text): array => [$label => safe($text)], $texts);
        if ($unreadable || $conf->broken()) {
            [$title, $advice, $key] = match (true) {
                $unreadable   => [_m('PHP cannot read misc/datovka/datovka_conf.php'), self::readAdvice(), 'unreadable'],
                $conf->lost() => [_m('AA cannot use misc/datovka/datovka_conf.php'), _m('Correct the file following datovka_conf.php.default - the problem tells what is wrong. Until then AA runs as if there were no file.'), 'broken'],
                default       => [self::accountsLost(), _m('Correct %1 following datovka_conf.php.default. Until then AA has no ISDS account; AA reads the rest of the file.', [DatovkaConf::key(DatovkaConf::ACCOUNTS)]), 'broken-accounts'],
            };
            $findings[] = new Finding(Finding::ERROR, $title, evidence: $evidence([(string) array_shift($problems)], _m('Problem')), advice: $advice, key: $key);
        }
        if ($problems) {
            $findings[] = new Finding(Finding::WARNING, _m('AA leaves out settings of misc/datovka/datovka_conf.php: %1', [count($problems)]), count: count($problems), evidence: $evidence($problems, _m('Problem')), advice: _m('Correct each key as its problem says.'), key: 'problems');
        }
        $strays = $conf->strays();
        if ($strays) {
            $findings[] = new Finding(
                Finding::WARNING,
                _m('misc/datovka/datovka_conf.php has keys AA does not read: %1', [count($strays)]),
                count: count($strays),
                evidence: $evidence($strays, _m('Setting')),
                advice: _m('Move an ISDS account under %1, or delete what AA does not read: at the top AA reads instances, test, standard and accounts, in test and standard cert and atsId, in an installation only url.', [DatovkaConf::key(DatovkaConf::ACCOUNTS)]),
                key: 'strays',
            );
        }
        $ignored = $conf->ignored();
        if ($ignored) {
            $findings[] = new Finding(Finding::INFO, _m('misc/datovka/datovka_conf.php still has addresses of ISDS: %1', [count($ignored)]), count: count($ignored), evidence: $evidence($ignored, _m('Setting')), advice: _m('AA does not read url_* and wsdl_* from the file - the addresses are part of AA. Delete them.'), key: 'addresses');
        }

        return $findings;
    }

    /**
     * What other requests met reading the file in the last week, and on which site: each site may run PHP
     * as a user of its own, and a network file system may refuse PHP for a while. What this request meets
     * too is above.
     * @return list<Finding>
     */
    private function loggedFindings(DatovkaConf $conf): array
    {
        $findings = [];
        foreach ($this->recent(DatovkaConf::LOG_EVENT) as $row) {
            $problem = (string) $row['selector'];
            // the log cuts a long problem
            if (array_filter($conf->problems(), static fn(string $now): bool => str_starts_with($now, $problem))) {
                continue;
            }
            [$failure, $site] = array_pad(ParamExplode((string) $row['params']), 2, '');
            $last             = (int) $row['last'];
            $findings[]       = new Finding(
                // LogOnce keeps the first failure of a day: on a quiet site one still going on may show yesterday
                (($failure !== '') && ($last >= strtotime('yesterday', $this->now()))) ? Finding::ERROR : Finding::WARNING,
                match ($failure) {
                    ''                           => _m('Other requests met a problem in misc/datovka/datovka_conf.php'),
                    DatovkaConf::BROKEN_ACCOUNTS => _m('Other requests could not use the accounts of misc/datovka/datovka_conf.php'),
                    default                      => _m('Other requests could not use misc/datovka/datovka_conf.php'),
                },
                count: (int) $row['days'],
                since: (int) $row['first'],
                // the site of the first request to log it on one of the days; other sites may meet it too
                evidence: [[_m('Problem') => safe($problem)] + (($site === '') ? [] : [_m('Site, one of them') => safe($site)]) + [_m('Days seen') => (string) (int) $row['days'], _m('Last') => date('Y-m-d', $last)]],
                advice: ($failure === DatovkaConf::UNREAD)
                    ? _m('%1 Seen on one day only, it is usually a network file system refusing PHP for a while. The finding disappears 7 days after %2.', [self::readAdvice(), _m('Last')])
                    : _m('The file has changed since, or another tree of AA over this database reads a file of its own. Correct what the problem names in the file each tree reads.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(DatovkaConf::LOG_EVENT, $problem)],
                key: 'logged:' . md5($problem),
                last: $last,
            );
        }

        return $findings;
    }

    /**
     * Answers of ISDS service.php refused in the last week, as the token named an installation the file
     * does not list: of a message of this database, or of none.
     * @return list<Finding>
     */
    private function instanceFindings(): array
    {
        $instances = DatovkaConf::key(DatovkaConf::INSTANCES);
        $findings  = [];
        foreach ($this->recent(DatovkaConf::INSTANCE_EVENT) as $row) {
            $selector = (string) $row['selector'];
            $known    = str_starts_with($selector, DatovkaConf::KNOWN_TOKEN);
            // a known token is logged by its id; an unknown one under one selector, the id of one of them
            $id         = $known ? substr($selector, strlen(DatovkaConf::KNOWN_TOKEN)) : (string) $row['params'];
            $findings[] = new Finding(
                // anyone can send a made-up token: usually nothing to change
                $known ? Finding::ERROR : Finding::INFO,
                $known ? _m('Answers of ISDS to messages of this database were refused: %1 does not list installation %2', [$instances, $id]) : _m('Answers of ISDS refused: the installation in their token is not in %1', [$instances]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[($known ? _m('Installation id') : _m('Installation id, one of them')) => safe($id), _m('Days seen') => (string) (int) $row['days'], _m('Last') => date('Y-m-d', (int) $row['last'])]],
                advice: $known
                    ? _m('Add the installation of that id to %1, with its AA_INSTAL_URL - every installation that shares the registration of the authentication service lists the same ids.', [$instances])
                    : _m('No message of this database has the token. If an installation that shares the registration of the authentication service has the id, add it to %1; otherwise the answer does not belong to this installation and nothing needs to change.', [$instances]),
                links: [_m('Log rows') => AA_Log::getAdminUrl(DatovkaConf::INSTANCE_EVENT, $selector)],
                key: 'instance:' . $selector,
                last: (int) $row['last'],
            );
        }

        return $findings;
    }

    /**
     * test_boxes AA cannot read: the account sends, only no test message goes from it. Of the accounts of
     * the sites of this database only, as repeatedFinding() names them; the key, never its value.
     * @param list<string> $settings as unreadableHere()
     */
    private function testBoxesFinding(array $settings): ?Finding
    {
        if (!$settings) {
            return null;
        }

        return new Finding(
            Finding::WARNING,
            _m('Test boxes of ISDS accounts AA cannot read: %1', [count($settings)]),
            count: count($settings),
            evidence: array_map(static fn(string $setting): array => [_m('Setting') => safe($setting)], $settings),
            advice: _m("Write test_boxes as a list of data box ids: ['<data box id>', ...], 7 letters or digits each. Until then the account sends, but no test message of a draft goes from it."),
            key: 'test-boxes',
        );
    }

    /**
     * paid_tests of production accounts AA cannot read: no paid test goes from them. As testBoxesFinding().
     * @param list<string> $settings as unreadableHere()
     */
    private function paidTestsFinding(array $settings): ?Finding
    {
        if (!$settings) {
            return null;
        }

        return new Finding(
            Finding::WARNING,
            _m('Paid tests of ISDS accounts AA cannot read: %1', [count($settings)]),
            count: count($settings),
            evidence: array_map(static fn(string $setting): array => [_m('Setting') => safe($setting)], $settings),
            advice: _m('Write paid_tests as true - without quotes - to let a test message of a draft cost money, or remove it. Until then a test message goes only free of charge.'),
            key: 'paid-tests',
        );
    }

    /**
     * test_boxes and paid_tests AA cannot read, of the accounts that send for a site of this database - one
     * query of the sites for both
     * @return array<string, list<string>> key => settings, as DATOVKA_CONF['accounts']['NSZM']['test_boxes']
     */
    private function unreadableHere(DatovkaConf $conf): array
    {
        $broken = [Accounts::TEST_BOXES => Accounts::brokenTestBoxes($conf), Accounts::PAID_TESTS => Accounts::brokenPaidTests($conf)];
        $names  = array_merge(...array_values($broken));
        $here   = [];
        if ($names) {
            $bindings = array_values(array_filter(Accounts::bindings($conf), static fn(Binding $b): bool => in_array($b->name, $names, true)));
            $here     = array_map(static fn(Binding $b): string => $b->name, Accounts::here($bindings, $this->modulesHere(Accounts::moduleIds($bindings))));
        }
        $settings = [];
        foreach ($broken as $key => $accounts) {
            $settings[$key] = array_values(array_map(static fn(string $name): string => Accounts::setting($name, $key), array_intersect($here, $accounts)));
        }

        return $settings;
    }

    /**
     * Keys of the file and accounts written twice. An account only by a name Accounts reads, and by its
     * name only when it sends for a site of this database (Accounts::here(), as CheckIsdsAccounts) -
     * those of other databases by their count in one row. Nothing else of the text.
     */
    private function repeatedFinding(DatovkaConf $conf): ?Finding
    {
        $repeated = DatovkaConf::repeated($this->file());
        if (is_null($repeated)) {
            return new Finding(Finding::INFO, _m('misc/datovka/datovka_conf.php is not checked for keys written twice - the file is over %1 KB', [intdiv(DatovkaConf::MAX_BYTES, 1024)]), advice: _m('The settings of one installation take a few kilobytes - check what else the file holds.'), key: 'twice-unchecked');
        }
        $settings = $names = [];
        foreach ($repeated as $path) {
            if (count($path) === 1) {
                $settings[] = DatovkaConf::key(...$path);
            } else {
                $names[] = $path[1];
            }
        }
        $foreign = 0;
        if ($names) {
            $bindings = array_values(array_filter(Accounts::bindings($conf), static fn(Binding $b): bool => in_array($b->name, $names, true)));
            $here     = Accounts::here($bindings, $this->modulesHere(Accounts::moduleIds($bindings)));
            foreach ($bindings as $binding) {
                if (in_array($binding, $here, true)) {
                    $settings[] = Accounts::setting($binding->name);
                } else {
                    ++$foreign;
                }
            }
        }
        $count = count($settings) + $foreign;
        if ($count === 0) {
            return null;
        }

        return new Finding(
            Finding::ERROR,
            _m('misc/datovka/datovka_conf.php writes keys twice: %1', [$count]),
            count: $count,
            evidence: [
                ...array_map(static fn(string $setting): array => [_m('Setting') => safe($setting)], $settings),
                ...($foreign ? [[_m('Setting') => safe(_m('ISDS accounts of the sites of other databases: %1', [$foreign]))]] : []),
            ],
            advice: _m('PHP keeps only the last of a key written twice and leaves out the first without a word. Join the two into one, or rename one of the accounts.')
                . ($foreign ? ' ' . _m('An account of the sites of another database: the State Check of the database of its sites names it.') : ''),
            key: 'twice',
        );
    }

    /**
     * The file holds a password or a passphrase, and others may read it while they may not read
     * include/config.php. The group is not judged: the PHP of each site of a shared tree has to read the
     * file, and with PHP inside the web server every site reads both anyway.
     */
    private function accessFinding(DatovkaConf $conf): ?Finding
    {
        if (!self::holdsSecret($conf->accounts()) && !self::holdsSecret($conf->loose())) {
            return null;
        }
        $modes = [];
        foreach ([DatovkaConf::FILE, 'include/config.php'] as $file) {
            $path = $this->base() . $file;
            clearstatcache(true, $path);
            $mode = @fileperms($path);
            if ($mode === false) {
                return null;
            }
            $modes[$file] = $mode & 0o7777;
        }
        if ((($modes[DatovkaConf::FILE] & 0o004) === 0) || (($modes['include/config.php'] & 0o004) !== 0)) {
            return null;
        }

        return new Finding(
            Finding::ERROR,
            _m('misc/datovka/datovka_conf.php is readable to all users of the server, include/config.php is not'),
            evidence: array_map(static fn(string $file, int $mode): array => [_m('File') => safe($file), _m('Permissions') => sprintf('%04o', $mode)], array_keys($modes), $modes),
            advice: _m('The file holds a password or a passphrase. Give it the owner, group and mode of include/config.php (chown, chmod), and check its ACL (getfacl).'),
            key: 'access',
        );
    }

    /** files of the name of the settings next to them or in the directory of AA: a backup, an older version, what an editor or svn leaves */
    private function copiesFinding(): ?Finding
    {
        $own    = basename(DatovkaConf::FILE);
        $stem   = basename($own, '.php');
        $copies = [];
        foreach ([dirname(DatovkaConf::FILE) . '/', ''] as $dir) {
            // @: under open_basedir the warning would show the path
            foreach (@scandir($this->base() . $dir) ?: [] as $name) {
                if ((stripos($name, $stem) !== false) && !in_array($name, [$own, "$own.default"], true)) {
                    $copies[] = $dir . $name;
                }
            }
        }
        if (!$copies) {
            return null;
        }
        // scandir() sorts by the locale
        sort($copies, SORT_STRING);

        return new Finding(
            Finding::ERROR,
            _m('Copies of misc/datovka/datovka_conf.php in the directory of AA: %1', [count($copies)]),
            count: count($copies),
            evidence: array_map(static fn(string $copy): array => [_m('File') => safe($copy)], $copies),
            advice: _m('Keep copies of the file - backups, older versions, what an editor or svn leaves next to it - only outside the web tree. Move or delete these.'),
            key: 'copies',
        );
    }

    /** a password or a passphrase anywhere in $value - in an account, or in a block out of its place */
    private static function holdsSecret(mixed $value): bool
    {
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $secret = in_array($key, [Accounts::PASSWORD, Accounts::PASSPHRASE], true) && !in_array($item, [null, []], true) && !(($item instanceof Secret) && $item->isEmpty());
            if ($secret || self::holdsSecret($item)) {
                return true;
            }
        }

        return false;
    }

    private static function accountsLost(): string
    {
        return _m('AA cannot use the accounts of misc/datovka/datovka_conf.php');
    }

    private static function readAdvice(): string
    {
        return _m('Give the file the owner, group and mode of include/config.php, as each site may run PHP as a user of its own, and check that open_basedir of each site lets PHP reach the file (and the target of a link). Until then AA runs as if there were no file.');
    }

    private function file(): string
    {
        return $this->base() . DatovkaConf::FILE;
    }

    // ------------------------------------------------------------ what tests replace

    protected function conf(): DatovkaConf
    {
        return DatovkaConf::load();
    }

    /** the directory of AA, ending with a slash */
    protected function base(): string
    {
        return DatovkaConf::base();
    }

    /**
     * @param list<string> $ids of sites or slices
     * @return list<string> those that are modules of this database
     */
    protected function modulesHere(array $ids): array
    {
        return Accounts::modulesHere($ids);
    }

    protected function now(): int
    {
        return time();
    }

    /** @return list<array<string, ?string>> LogOnce::recent() rows of $event in the last week */
    protected function recent(string $event): array
    {
        return LogOnce::recent($event, $this->now() - self::LOG_WINDOW_SECONDS, self::LOG_ROWS);
    }
}
