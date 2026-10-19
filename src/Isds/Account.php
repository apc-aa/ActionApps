<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Credential\Secret;
use AA\Http\ClientCertificate;
use AA\Isds\Registry\Box;

/**
 * Who logs in to ISDS and where: the environment and the kind of login decide the host, so a test
 * account can never reach production by a typo in a URL. The password is revealed only to check it
 * in the constructor and to build headers().
 * Production lets an application in with a certificate only, never with a password given to a
 * server - so a name-and-password account is refused there.
 */
final class Account
{
    public const TEST       = 'test';
    public const PRODUCTION = 'production';

    /** a name and a password */
    public const BASIC = 'basic';
    /** a system certificate ISDS registered for one data box */
    public const CERT = 'cert';
    /** the certificate of a hosted filing service the data box allowed; the login is the box id, the password empty */
    public const HOSTED = 'hosted';

    /**
     * baseURL by the kind of login (WS_manipulace_s_datovymi_zpravami.pdf 1.2.1.1, Provozni_rad_ISDS.pdf
     * p. 15); the test environment swaps datovka.gov for datovka-test.gov (1.2.1.3)
     */
    private const BASES = [
        self::BASIC  => [self::TEST => 'https://ws1.datovka-test.gov.cz', self::PRODUCTION => 'https://ws1.datovka.gov.cz'],
        self::CERT   => [self::TEST => 'https://ws1c.datovka-test.gov.cz/cert', self::PRODUCTION => 'https://ws1c.datovka.gov.cz/cert'],
        self::HOSTED => [self::TEST => 'https://ws1c.datovka-test.gov.cz/hspis', self::PRODUCTION => 'https://ws1c.datovka.gov.cz/hspis'],
    ];

    /** BASIC, CERT or HOSTED: what the certificate and the login make it */
    public readonly string $kind;

    /** fingerprint() of the certificate, read once */
    private ?string $certificateId = null;

    /**
     * Without a certificate a name and a password (BASIC); with one, a system certificate (CERT) when
     * the login is empty, else a hosted filing service sending from the box the login names (HOSTED).
     * @param string $login    BASIC: the name; HOSTED: the id of the data box; CERT: ''
     * @param Secret $password BASIC only, empty for a certificate
     * @throws \InvalidArgumentException an unknown environment, a name and a password for production, an
     *                                   empty login or one with a colon, a password ISDS never accepts, a
     *                                   password with a certificate, a box id that is not one
     */
    public function __construct(
        public readonly string $environment,
        #[\SensitiveParameter]
        public readonly string $login,
        private readonly Secret $password,
        public readonly ?ClientCertificate $certificate = null,
    ) {
        if (!isset(self::BASES[self::BASIC][$environment])) {
            throw new \InvalidArgumentException('An ISDS environment is "' . self::TEST . '" or "' . self::PRODUCTION . '"');
        }
        if (!is_null($certificate)) {
            $this->kind = ($login === '') ? self::CERT : self::HOSTED;
            if (!$password->isEmpty() || (($login !== '') && (preg_match(Box::ID, $login) !== 1))) {
                throw new \InvalidArgumentException('A login with a certificate has no password; a hosted one has the id of the data box as its login, 7 small letters or digits');
            }
            return;
        }
        $this->kind = self::BASIC;
        if ($environment === self::PRODUCTION) {
            throw new \InvalidArgumentException('Production ISDS lets an application in with a certificate only, never with a name and a password');
        }
        // basic auth splits the pair at the first colon
        if (($login === '') || str_contains($login, ':')) {
            throw new \InvalidArgumentException('The ISDS login is empty or contains a colon');
        }
        if (!self::possible($password->reveal())) {
            throw new \InvalidArgumentException('An ISDS password is 8 to 64 letters, digits and ASCII signs');
        }
    }

    /** a system certificate registered for one data box; with $box, a hosted filing service sending from that box */
    public static function withCertificate(string $environment, ClientCertificate $certificate, string $box = ''): self
    {
        return new self($environment, $box, new Secret(''), $certificate);
    }

    /** @param string $service the last part of the endpoint: dz, dx, df, DsManage */
    public function url(string $service): string
    {
        return self::BASES[$this->kind][$this->environment] . '/DS/' . $service;
    }

    /**
     * Basic auth with the name and the password; a hosted filing service names the box the same way,
     * with an empty password (Provozni_rad_ISDS.pdf p. 15); a system certificate needs nothing more.
     * @return array<string, string>
     */
    public function headers(): array
    {
        return ($this->kind === self::CERT) ? [] : ['Authorization' => 'Basic ' . base64_encode($this->login . ':' . $this->password->reveal())];
    }

    /**
     * $text without the secrets of the login - for the text of an error, before it is cut: a cut through
     * a login would leave its start. The id of a box is none, ISDS answers name it.
     */
    public function redact(string $text): string
    {
        return str_ireplace($this->secrets(), '***', $text);
    }

    /** bytes of the longest text redact() takes out */
    public function longestSecret(): int
    {
        return strlen($this->secrets()[0] ?? '');
    }

    /**
     * @return list<string> what redact() takes out, the longest first: the password may hold the login.
     *                      BASIC: the login, the password, the pair the Authorization header carries;
     *                      a certificate: the passphrase of its key from 8 bytes - a shorter one would
     *                      cut pieces out of host names and dates
     */
    private function secrets(): array
    {
        if (is_null($this->certificate)) {
            $password = $this->password->reveal();
            $secrets  = [base64_encode($this->login . ':' . $password), $password, $this->login];
        } else {
            $passphrase = $this->certificate->passphrase();
            $secrets    = (strlen($passphrase) >= 8) ? [$passphrase] : [];
        }
        usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $secrets;
    }

    /**
     * Tells this login apart from others without keeping its secrets: keyed, as an 8-character password
     * could be guessed from a plain hash of it. A certificate counts by itself: a new one is a new login
     * even in the old file, while a new passphrase or a chain added to the file is not.
     * @throws ConfigException the certificate cannot be read - never a fingerprint that would fit any
     */
    public function fingerprint(#[\SensitiveParameter] string $key): string
    {
        if (is_null($this->certificate)) {
            return hash_hmac('sha256', $this->environment . "\0" . $this->login . "\0" . $this->password->reveal(), $key);
        }
        $this->certificateId ??= $this->certificate->fingerprint() ?? throw new ConfigException('The certificate of the ISDS account cannot be read - nothing was sent; the State Check tells more');

        return hash_hmac('sha256', implode("\0", [$this->environment, $this->kind, $this->login, $this->certificateId]), $key);
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
