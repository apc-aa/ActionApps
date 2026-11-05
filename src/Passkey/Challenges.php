<?php

declare(strict_types=1);

namespace AA\Passkey;

/**
 * The challenges this session handed to the browser, each usable once and for a few minutes. A few are
 * kept per purpose, as two open tabs each show a login form with its own challenge.
 */
final class Challenges
{
    /** the browser's wait for the user, and a minute for the request to arrive */
    public const LIFETIME = Ceremony::TIMEOUT + 60;
    private const KEEP = 5;

    /** @var array<string, mixed> purpose => challenge => ['exp' => time, 'ctx' => context] - it comes from the session, so trusted in nothing */
    private array $bag;

    /** @param array<string, mixed> $bag where to keep them - $_SESSION['aa_passkey'] */
    public function __construct(array &$bag)
    {
        $this->bag = &$bag;
    }

    /** the challenges of the PHP session; without a session they last only for this request */
    public static function session(): self
    {
        if (!isset($_SESSION)) {
            $none = [];
            return new self($none);
        }
        if (!is_array($_SESSION['aa_passkey'] ?? null)) {
            $_SESSION['aa_passkey'] = [];
        }
        return new self($_SESSION['aa_passkey']);
    }

    /** @param array<string, string> $context what the answer to it must be about (the account of a registration) */
    public function issue(string $purpose, string $challenge, array $context = [], ?int $now = null): void
    {
        $now  ??= time();
        $list = $this->live($purpose, $now);
        $list[bin2hex($challenge)] = ['exp' => $now + self::LIFETIME, 'ctx' => $context];
        $this->bag[$purpose] = array_slice($list, -self::KEEP, null, true);
    }

    /** the context of the challenge, which is spent by it; null for one this session did not issue for the purpose
     *  @return array<string, string>|null */
    public function take(string $purpose, string $challenge, ?int $now = null): ?array
    {
        $list = $this->live($purpose, $now ?? time());
        $key  = bin2hex($challenge);
        $entry = $list[$key] ?? null;
        unset($list[$key]);
        $this->bag[$purpose] = $list;
        return $entry['ctx'] ?? null;
    }

    /** @return array<string, array{exp: int, ctx: array<string, string>}> */
    private function live(string $purpose, int $now): array
    {
        $list = is_array($this->bag[$purpose] ?? null) ? $this->bag[$purpose] : [];
        return array_filter($list, static fn($e): bool => is_array($e) && is_int($e['exp'] ?? null) && ($e['exp'] >= $now) && is_array($e['ctx'] ?? null));
    }
}
