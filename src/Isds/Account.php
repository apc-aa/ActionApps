<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Credential\Secret;

/**
 * Who logs in to ISDS and where: the environment decides the host, so a test account can never
 * reach production by a typo in a URL. The password is revealed only to check it in the
 * constructor and to build headers().
 * Production lets an application in with a certificate only, never with a password given to a
 * server - so this name-and-password account is refused there.
 */
final class Account
{
    public const TEST       = 'test';
    public const PRODUCTION = 'production';

    /** basic-auth hosts (WS_manipulace_s_datovymi_zpravami.pdf 1.2.1.1 and 1.2.1.3) */
    private const HOSTS = [
        self::TEST       => 'https://ws1.datovka-test.gov.cz',
        self::PRODUCTION => 'https://ws1.datovka.gov.cz',
    ];

    /**
     * @throws \InvalidArgumentException an unknown environment, production, an empty login or one with
     *                                   a colon, a password ISDS never accepts
     */
    public function __construct(public readonly string $environment, #[\SensitiveParameter] public readonly string $login, private readonly Secret $password)
    {
        if (!isset(self::HOSTS[$environment])) {
            throw new \InvalidArgumentException('An ISDS environment is "' . self::TEST . '" or "' . self::PRODUCTION . '"');
        }
        if ($environment === self::PRODUCTION) {
            throw new \InvalidArgumentException('Production ISDS lets an application in with a certificate only, and AA cannot log in with one yet');
        }
        // basic auth splits the pair at the first colon
        if (($login === '') || str_contains($login, ':')) {
            throw new \InvalidArgumentException('The ISDS login is empty or contains a colon');
        }
        if (!self::possible($password->reveal())) {
            throw new \InvalidArgumentException('An ISDS password is 8 to 64 letters, digits and ASCII signs');
        }
    }

    /** @param string $service the last part of the endpoint: dz, dx, df, DsManage */
    public function url(string $service): string
    {
        return self::HOSTS[$this->environment] . '/DS/' . $service;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return ['Authorization' => 'Basic ' . base64_encode($this->login . ':' . $this->password->reveal())];
    }

    /**
     * $text without the login, the password and the pair the Authorization header carries - for the
     * text of an error, before it is cut: a cut through a login would leave its start.
     */
    public function redact(string $text): string
    {
        return str_ireplace($this->secrets(), '***', $text);
    }

    /** bytes of the longest text redact() takes out */
    public function longestSecret(): int
    {
        return strlen($this->secrets()[0]);
    }

    /** @return list<string> what redact() takes out, the longest first: the password may hold the login */
    private function secrets(): array
    {
        $password = $this->password->reveal();
        $secrets  = [base64_encode($this->login . ':' . $password), $password, $this->login];
        usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $secrets;
    }

    /**
     * Tells this login and password apart from others without keeping them: keyed, as an 8-character
     * password could be guessed from a plain hash of it.
     */
    public function fingerprint(#[\SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', $this->environment . "\0" . $this->login . "\0" . $this->password->reveal(), $key);
    }

    /**
     * 8 to 64 characters: letters a-z A-Z, digits, a space and ASCII signs (WS_souvisejici_s_pristupem_
     * do_ISDS.pdf 1.9). Any printable ASCII passes - a password set under older rules may hold a sign
     * the list leaves out.
     */
    private static function possible(#[\SensitiveParameter] string $password): bool
    {
        return preg_match('/^[\x20-\x7E]{8,64}\z/', $password) === 1;
    }
}
