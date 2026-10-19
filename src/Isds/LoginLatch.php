<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\IO\DB\DB_AA;
use AA\Util\Secret;

/**
 * Remembers what ISDS refused, so that nothing tries it again by itself: a login (ISDS blocks it
 * after failed attempts) and the address of the server (blocked on suspicion of an attack - for
 * every account and every installation on the server). A new login or password lets the client try
 * again; a blocked address after BLOCK_HOURS, once. Before that only a person who asks once more
 * (forget()). The AA log keeps a keyed fingerprint of the account, or the environment, and the
 * time - never the login or the password.
 */
class LoginLatch
{
    /** log.type, at most 10 bytes; LogCleanup keeps these rows, an expired refusal would be retried */
    public const LOG_EVENT = 'ISDS_LOGIN';

    public const REFUSED   = 'refused';
    public const BLOCKED   = 'blocked';
    public const FORGOTTEN = 'forgotten';

    /** ISDS lifts an address block within hours (WS_souvisejici_s_pristupem_do_ISDS.pdf 1.3); then one try tells */
    public const BLOCK_HOURS = 24;

    /** @var array<string, ?int> selector => the refusal that stands, read once per latch */
    private array $known = [];

    /** @var array<string, true> refusals recorded by this latch - a run never forgets its own */
    private array $recorded = [];

    /** @var array<string, true> forgotten for this run; written only once ISDS takes the login (accepted()) */
    private array $forgotten = [];

    /** unix time of the refusal of the login that stands; null = it may log in */
    public function refusedAt(Account $account): ?int
    {
        return $this->standing($this->fingerprint($account));
    }

    /** unix time of the block of the server's address in $environment that stands; null = none */
    public function blockedAt(string $environment): ?int
    {
        $blocked = $this->standing(self::environment($environment));

        return (!is_null($blocked) && ($this->now() - $blocked < self::BLOCK_HOURS * 3600)) ? $blocked : null;
    }

    public function refuse(Account $account): void
    {
        $this->latch($this->fingerprint($account), self::REFUSED);
    }

    public function block(string $environment): void
    {
        $this->latch(self::environment($environment), self::BLOCKED);
    }

    /**
     * Lets the account try once more, as a person asked: its login and the address block of its
     * environment. Only for this run - the log learns of it once ISDS takes the login, so a try
     * that gets no answer leaves the next daily run latched. A refusal this latch recorded stays:
     * another account with the same login must not try it in the same run.
     * @return bool a refusal or a block was forgotten
     */
    public function forget(Account $account): bool
    {
        $forgot   = false;
        $standing = [$this->fingerprint($account) => $this->refusedAt($account), self::environment($account->environment) => $this->blockedAt($account->environment)];
        foreach ($standing as $selector => $since) {
            if (!is_null($since) && !isset($this->recorded[$selector])) {
                $this->known[$selector]     = null;
                $this->forgotten[$selector] = true;
                $forgot                     = true;
            }
        }

        return $forgot;
    }

    /** ISDS answered past the login: what forget() let go is gone for good */
    public function accepted(Account $account): void
    {
        if (!$this->forgotten) {
            return;
        }
        foreach ([$this->fingerprint($account), self::environment($account->environment)] as $selector) {
            if (isset($this->forgotten[$selector])) {
                unset($this->forgotten[$selector]);
                $this->record($selector, self::FORGOTTEN);
            }
        }
    }

    private function standing(string $selector): ?int
    {
        if (!array_key_exists($selector, $this->known)) {
            $last = $this->last($selector);
            $this->known[$selector] = (($last !== null) && in_array($last['what'], [self::REFUSED, self::BLOCKED], true)) ? $last['time'] : null;
        }

        return $this->known[$selector];
    }

    private function latch(string $selector, string $what): void
    {
        $this->record($selector, $what);
        $this->known[$selector]    = $this->now();
        $this->recorded[$selector] = true;
        unset($this->forgotten[$selector]);
    }

    private function fingerprint(Account $account): string
    {
        return $account->fingerprint(Secret::key('isds-login'));
    }

    private static function environment(string $environment): string
    {
        return "blocked:$environment";
    }

    // ------------------------------------------------------------ what tests replace

    /** @return ?array{what: string, time: int} the newest row of the selector */
    protected function last(string $selector): ?array
    {
        $row = DB_AA::select1(['params', 'time'], 'SELECT `params`, `time` FROM `log`', [['type', self::LOG_EVENT], ['selector', $selector]], ['time-', 'id-']);

        return is_array($row) ? ['what' => (string) $row['params'], 'time' => (int) $row['time']] : null;
    }

    protected function record(string $selector, string $what): void
    {
        \AA_Log::write(self::LOG_EVENT, $selector, $what);
    }

    protected function now(): int
    {
        return time();
    }
}
