<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Credential\Store;
use AA\Http\TransportException as HttpTransportException;
use AA\Isds\Account;
use AA\Isds\Accounts;
use AA\Isds\AddressBlockedException;
use AA\Isds\AuthException;
use AA\Isds\Binding;
use AA\Isds\Client;
use AA\Isds\ConfigException;
use AA\Isds\IsdsException;
use AA\Isds\LoginLatch;
use AA\Isds\TransportException;
use AA\Isds\UnavailableException;
use AA\Isds\UserInfo;
use AA\Later\Toexecute;

/**
 * Logs in to ISDS with each usable account of include/config.php: the data box it sends from and, for
 * a name and a password, the rights of its user and when its password expires. Read-only operations of
 * the ISDS client only.
 * The accounts that cannot be used, CheckIsdsAccounts names - they are not tried here. A login ISDS
 * refused is tried again only by a person here (Recheck, Run extended checks), never by the daily run;
 * an address ISDS blocked, by a person or once after LoginLatch::BLOCK_HOURS.
 */
class CheckIsds extends AbstractOptimize
{
    /** fewer days left warn: ISDS refuses the login once the password expires */
    public const PASSWORD_DAYS = 21;

    /** operations a sending manages without: CreateMessage itself refuses a box that cannot take a message */
    public const DISPENSABLE = ['CheckDataBox'];

    /**
     * Rights AA never needs: reading received messages (listing them by this login would deliver
     * them), managing the box (it could grant itself those), erasing the vault.
     */
    public const UNWANTED_RIGHTS = [UserInfo::READ_NON_PERSONAL, UserInfo::READ_ALL, UserInfo::OWNER_ADM, UserInfo::ERASE_VAULT];

    public static function name(): string
    {
        return _m('ISDS login');
    }

    public static function description(): string
    {
        return _m('Logs in to ISDS with each usable account of include/config.php and checks the data box it sends from; for a login with a name and a password also the rights of its user and when its password expires.');
    }

    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
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
        $usable    = array_values(array_filter(Accounts::bindings($constants), static fn(Binding $b): bool => $b->usable()));
        if (!$usable) {
            return [new Finding(Finding::OK, _m('No usable ISDS account to log in with'))];
        }
        $latch    = $this->latch();
        $retry    = !$this->unattended();
        $findings = [];
        $boxes    = [];
        $stopped  = [];
        $notTried = [];
        foreach ($usable as $binding) {
            $label   = Accounts::prefix($binding->environment, $binding->name);
            $account = Accounts::account($binding, $constants);
            // a block of the address holds for the environment; no answer, for the host - a name and a
            // password log in on ws1, a certificate on ws1c
            $host = $binding->environment . ':' . parse_url($account->url('DsManage'), PHP_URL_HOST);
            if (isset($stopped[$binding->environment]) || isset($stopped[$host])) {
                $notTried[isset($stopped[$binding->environment]) ? $binding->environment : $host][] = $label;
                continue;
            }
            $client = $this->client($account, $latch);
            try {
                if ($retry) {
                    $client->forgetRefusal();
                }
                [$box, $problems, $stop] = $this->tryAccount($binding, $account, $client);
            } catch (ConfigException $e) {
                // nothing was sent
                $findings[] = self::certificateFinding($binding, $label, $e);
                continue;
            }
            array_push($findings, ...$problems);
            if ($stop) {
                $stopped[($stop instanceof AddressBlockedException) ? $binding->environment : $host] = $stop;
            }
            if ($box !== '') {
                $boxes[] = "$label ($box)";
            }
        }
        foreach ($notTried as $where => $labels) {
            $blocked    = $stopped[$where] instanceof AddressBlockedException;
            $findings[] = new Finding(
                Finding::INFO,
                $blocked
                    ? _m('ISDS accounts not tried, as ISDS blocked the address of this server: %1', [implode(', ', $labels)])
                    : _m('ISDS accounts not tried, as ISDS did not answer: %1', [implode(', ', $labels)]),
                advice: $blocked ? _m('Wait - each attempt from this server extends the block.') : _m('Check again later.'),
                key: "not-tried:$where",
            );
        }

        return $findings ?: [new Finding(Finding::OK, _m('ISDS accounts log in to their data boxes: %1', [implode(', ', $boxes)]))];
    }

    /**
     * A certificate that cannot work. What ClientCertificate::problem() finds, CheckIsdsAccounts names
     * with its files - a note here. What only the TLS library of this server refuses (a key under its
     * policy, a weak certificate in the chain, an old signature) nobody else reports: an error, with
     * the reason of OpenSSL. Its text holds no path; the page is for superadmins only.
     */
    private static function certificateFinding(Binding $binding, string $label, ConfigException $e): Finding
    {
        $where = "{$binding->environment}:{$binding->name}";
        $tls   = $e->getPrevious();
        if (!($tls instanceof HttpTransportException) || ($tls->kind() !== HttpTransportException::CLIENT_CERTIFICATE)) {
            return new Finding(Finding::INFO, _m('ISDS account %1 not tried, as its certificate cannot be used', [$label]), evidence: [[_m('Problem') => safe($e->getMessage())]], advice: _m('The check of ISDS accounts tells more.'), key: "certificate-unusable:$where");
        }
        // the last error code of OpenSSL: a path before it may hold "routines::" too; curl cuts its text
        // at 256 bytes, then Symfony adds ' for "URL"'
        $reason = '';
        if (preg_match_all('/error:[0-9A-F]{8}:[^:]*routines::(.*?)(?=,|\sfor\s+"|$)/m', $tls->getMessage(), $m) > 0) {
            $reason = trim((string) end($m[1]));
        }

        return new Finding(
            Finding::ERROR,
            _m('The TLS library of this server refuses the certificate of ISDS account %1', [$label]),
            evidence: [array_filter([_m('Reason of OpenSSL') => safe($reason), _m('Problem') => safe($e->getMessage())])],
            advice: _m('OpenSSL on this server does not take the certificate or its key - e.g. a key shorter than its policy wants, a weak certificate in the chain or an old signature. Get a certificate that meets the policy (an RSA key of 3072 bits, a SHA-256 signature), have ISDS register it and name it in include/config.php.'),
            key: "certificate-refused:$where",
        );
    }

    /**
     * The box first - every kind of login may ask for it. The rights and the expiry concern a login
     * with a name and a password only: a certificate has the rights 159 and no password, ISDS answers
     * 2102 to both questions - the list of operations of the client keeps it from the received
     * messages. A refused login or no answer ends the account; an answer AA cannot read, or a refused
     * question, is that question's finding.
     * @return array{string, list<Finding>, ?IsdsException} the data box, '' when not read; the
     *                                                     findings; what stops the other accounts of the host
     * @throws ConfigException the certificate cannot be used - nothing was sent
     */
    private function tryAccount(Binding $binding, Account $account, Client $client): array
    {
        $box      = '';
        $findings = [];
        $label    = Accounts::prefix($binding->environment, $binding->name);
        $key      = "{$binding->environment}:{$binding->name}";
        try {
            $box = $client->ownerBox();
        } catch (IsdsException $e) {
            $findings[] = $this->failure($binding, $account, $e);
            // a refused account would refuse the questions too; an answer AA cannot read tells nothing of them
            if (!($e instanceof TransportException) || !$e->answered()) {
                return ['', $findings, self::hostStop($e)];
            }
        }
        if ($account->kind !== Account::BASIC) {
            return [$box, $findings, null];
        }
        $evidence = static fn(IsdsException $e): array => [[_m('ISDS') => safe($e->getMessage())]];
        try {
            array_push($findings, ...$this->rightsFindings($label, $key, $client->userInfo()));
        } catch (IsdsException $e) {
            if (self::stops($e)) {
                return [$box, [...$findings, $this->failure($binding, $account, $e)], self::hostStop($e)];
            }
            $findings[] = new Finding(Finding::WARNING, _m('ISDS did not tell the rights of the user of account %1', [$label]), evidence: $evidence($e), advice: self::rightsAdvice(), key: "rights-unknown:$key");
        }
        try {
            $expiry = $client->passwordExpiry();
            if (!is_null($expiry) && ($expiry < $this->now() + self::PASSWORD_DAYS * 86400)) {
                $findings[] = new Finding(
                    Finding::WARNING,
                    _m('The password of ISDS account %1 expires on %2', [$label, date('Y-m-d', $expiry)]),
                    advice: _m('Change the password in the ISDS portal and write the new one to %1 in include/config.php.', [Accounts::constant($binding->environment, $binding->name, Accounts::PASSWORD)]),
                    key: "expiry:$key",
                );
            }
        } catch (IsdsException $e) {
            if (self::stops($e)) {
                return [$box, [...$findings, $this->failure($binding, $account, $e)], self::hostStop($e)];
            }
            $findings[] = new Finding(Finding::WARNING, _m('ISDS did not tell when the password of account %1 expires', [$label]), evidence: $evidence($e), advice: _m('Check the password of the user in the ISDS portal.'), key: "expiry-unknown:$key");
        }

        return [$box, $findings, null];
    }

    /**
     * The rights each operation of the client needs (Client::RIGHTS): one missing for sending or for
     * following what was sent is an error, one for an operation the sending manages without a warning.
     * @return list<Finding>
     */
    private function rightsFindings(string $label, string $key, UserInfo $user): array
    {
        $evidence = [[_m('Rights') => is_null($user->privileges) ? _m('unknown') : (string) $user->privileges, _m('User type') => safe($user->type)]];
        if (is_null($user->privileges)) {
            return [new Finding(Finding::WARNING, _m('ISDS did not tell the rights of the user of account %1', [$label]), evidence: $evidence, advice: self::rightsAdvice(), key: "rights-unknown:$key")];
        }
        $missing = [Finding::ERROR => [], Finding::WARNING => []];
        foreach (self::neededRights() as $right => $operations) {
            if (!$user->has($right)) {
                $missing[array_diff($operations, self::DISPENSABLE) ? Finding::ERROR : Finding::WARNING][$right] = $operations;
            }
        }
        $findings = [];
        foreach ($missing as $severity => $rights) {
            if ($rights) {
                $findings[] = new Finding(
                    $severity,
                    _m('The user of ISDS account %1 lacks the rights %2', [$label, self::rightList(array_keys($rights))]),
                    evidence: $evidence,
                    advice: _m('Give the user the rights %1 in the ISDS portal - without them ISDS refuses %2.', [self::rightList(array_keys($rights)), implode(', ', array_merge(...array_values($rights)))]),
                    key: (($severity === Finding::ERROR) ? 'rights' : 'rights-optional') . ":$key",
                );
            }
        }
        $unwanted = array_values(array_filter(self::UNWANTED_RIGHTS, $user->has(...)));
        if ($unwanted) {
            $findings[] = new Finding(
                Finding::WARNING,
                _m('The user of ISDS account %1 has rights AA must not have: %2', [$label, self::rightList($unwanted)]),
                evidence: $evidence,
                advice: _m('In the ISDS portal take these rights from the user, or let AA log in as a user of its own with only the rights %1.', [self::rightList(array_keys(self::neededRights()))]),
                key: "unwanted:$key",
            );
        }

        return $findings;
    }

    private function failure(Binding $binding, Account $account, IsdsException $e): Finding
    {
        $label = Accounts::prefix($binding->environment, $binding->name);
        [$severity, $kind, $title, $advice] = match (true) {
            // the server, not the account: never advice to change the login
            $e instanceof AddressBlockedException => [
                Finding::ERROR,
                'blocked',
                _m('ISDS blocked logins from the address of this server'),
                _m('ISDS blocks the address of a server for a while when logins from it look like an attack - every account and every AA on this server, whatever their login. Wait: each attempt extends the block. AA tries once more by itself %1 hours later, or when you check again here. If the block lasts, call the ISDS helpline 954 200 200.', [LoginLatch::BLOCK_HOURS]),
            ],
            $e instanceof AuthException => [
                Finding::ERROR,
                'auth',
                _m('ISDS refused the login of account %1', [$label]),
                self::refusedAdvice($binding, $account),
            ],
            $e instanceof UnavailableException => [Finding::WARNING, 'busy', _m('ISDS asked account %1 to come back later', [$label]), _m('ISDS is in an outage or busy. Check again later.')],
            ($e instanceof TransportException) && !$e->answered() => [
                Finding::WARNING,
                'transport',
                _m('ISDS gave account %1 no answer', [$label]),
                _m('Check again later. If it repeats, check that the server reaches %1 over HTTPS.', [(string) parse_url($account->url('DsManage'), PHP_URL_HOST)]),
            ],
            $e instanceof TransportException => [Finding::WARNING, 'unreadable', _m('ISDS gave account %1 an answer AA cannot read', [$label]), _m('Check again later. If it repeats, the evidence gives the answer of ISDS.')],
            default => [Finding::ERROR, 'refused', _m('ISDS refused account %1', [$label]), _m('The evidence gives the answer of ISDS. Check the account and its data box in the ISDS portal.')],
        };
        $key = ($kind === 'blocked') ? "$kind:{$binding->environment}" : "$kind:{$binding->environment}:{$binding->name}";

        // the client took the login and the password out of the text
        return new Finding($severity, $title, evidence: [[_m('ISDS') => safe($e->getMessage())]], advice: $advice, key: $key);
    }

    private static function refusedAdvice(Binding $binding, Account $account): string
    {
        $constant = static fn(string $kind): string => Accounts::constant($binding->environment, $binding->name, $kind);

        return match ($account->kind) {
            Account::CERT   => _m('Check that the certificate %1 names is the system certificate ISDS registered for the data box, and that it has not expired. AA tries a new certificate by itself, the same one never. Run extended checks or Recheck here tries it once more.', [$constant(Accounts::CERT)]),
            Account::HOSTED => _m('Check that the data box %1 allows the hosting service whose certificate %2 names (Settings, Access of external applications in the ISDS portal), and that the certificate has not expired. AA tries a new certificate by itself, the same one never. Run extended checks or Recheck here tries it once more.', [$account->login, $constant(Accounts::CERT)]),
            default         => _m('Check %1 and %2 in include/config.php, and in the ISDS portal that the password has not expired. AA tries a changed login or password by itself, the same one never - every refused attempt extends the block of the login. Run extended checks or Recheck here tries it once more.', [$constant(Accounts::LOGIN), $constant(Accounts::PASSWORD)]),
        };
    }

    /** a refused login, or no answer: asking more would only extend the block, or wait out the limit again */
    private static function stops(IsdsException $e): bool
    {
        return ($e instanceof AuthException) || self::unanswered($e);
    }

    /**
     * The other accounts would extend the block of the address - of the environment -, or wait out the
     * limit of the host again
     */
    private static function hostStop(IsdsException $e): ?IsdsException
    {
        return (($e instanceof AddressBlockedException) || self::unanswered($e)) ? $e : null;
    }

    /** an unreadable answer is one question's problem, not ISDS down */
    private static function unanswered(IsdsException $e): bool
    {
        return ($e instanceof UnavailableException) || (($e instanceof TransportException) && !$e->answered());
    }

    private static function rightsAdvice(): string
    {
        return _m('Check in the ISDS portal that the user has the rights %1 and none of %2.', [self::rightList(array_keys(self::neededRights())), self::rightList(self::UNWANTED_RIGHTS)]);
    }

    /** @return array<int, list<string>> right => the operations that need it */
    private static function neededRights(): array
    {
        $needed = [];
        foreach (Client::RIGHTS as $operation => $right) {
            if ($right) {
                $needed[$right][] = $operation;
            }
        }
        ksort($needed);

        return $needed;
    }

    /**
     * @param list<int> $rights
     * @return string "4 (send messages), 8 (...)"
     */
    private static function rightList(array $rights): string
    {
        return implode(', ', array_map(static fn(int $right): string => "$right (" . self::rightLabel($right) . ')', $rights));
    }

    private static function rightLabel(int $right): string
    {
        return match ($right) {
            UserInfo::READ_NON_PERSONAL => _m('read received messages'),
            UserInfo::READ_ALL          => _m('read received messages to own hands'),
            UserInfo::CREATE_DM         => _m('send messages'),
            UserInfo::VIEW_INFO         => _m('lists of messages and delivery info'),
            UserInfo::SEARCH_DB         => _m('search for data boxes'),
            UserInfo::OWNER_ADM         => _m('manage the data box'),
            UserInfo::ERASE_VAULT       => _m('erase the vault'),
            default                     => (string) $right,
        };
    }

    // ------------------------------------------------------------ what tests replace

    /** @return array<string, mixed> as Store::userConstants() */
    protected function constants(): array
    {
        return Store::userConstants();
    }

    /** one for the run: a login refused for one account is not tried for another */
    protected function latch(): LoginLatch
    {
        return new LoginLatch();
    }

    protected function client(Account $account, LoginLatch $latch): Client
    {
        return new Client($account, latch: $latch);
    }

    /** the task queue runs the daily snapshot; a person runs the page */
    protected function unattended(): bool
    {
        return Toexecute::timeLeft() !== null;
    }

    protected function now(): int
    {
        return time();
    }
}
