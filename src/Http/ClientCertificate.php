<?php

declare(strict_types=1);

namespace AA\Http;

use AA\Credential\Secret;

/**
 * The certificate a client proves itself with in the TLS handshake (Client): files on this server, as
 * symfony/http-client takes them. The passphrase stays a Secret, so no dump of this object, nor of a
 * Client holding it, shows it.
 */
final class ClientCertificate
{
    /** problem(): PHP cannot read the certificate file */
    public const UNREADABLE = 'unreadable';
    /** problem(): PHP cannot read the key file */
    public const KEY_UNREADABLE = 'key-unreadable';
    /** problem(): PHP has no openssl extension to check the files with */
    public const NO_OPENSSL = 'no-openssl';
    /** problem(): the certificate file holds no PEM certificate */
    public const NOT_A_CERTIFICATE = 'not-a-certificate';
    /** problem(): no private key, or the passphrase does not open it */
    public const KEY_LOCKED = 'key-locked';
    /** problem(): the private key is not the one of the certificate */
    public const KEY_MISMATCH = 'key-mismatch';
    /** problem(): a key shorter than MIN_BITS (not EC nor EdDSA), which OpenSSL refuses in the handshake */
    public const WEAK_KEY = 'weak-key';
    public const NOT_YET_VALID = 'not-yet-valid';
    public const EXPIRED       = 'expired';

    public const MIN_BITS = 2048;

    /** larger files are not read: a constant naming a huge file must not fill the memory */
    public const MAX_BYTES = 1024 * 1024;

    /**
     * @param string $certificate PEM file of the certificate; it holds the private key too when $key is ''
     * @param string $key         PEM file of the private key; '' = in $certificate
     * @param Secret $passphrase  of an encrypted private key; empty = not encrypted
     * @throws \InvalidArgumentException no certificate file, or a NUL in a path
     */
    public function __construct(public readonly string $certificate, public readonly string $key = '', private readonly Secret $passphrase = new Secret(''))
    {
        if (($certificate === '') || str_contains($certificate . $key, "\0")) {
            throw new \InvalidArgumentException('A client certificate needs the name of its file, without NUL');
        }
    }

    /** the file with the private key */
    public function keyFile(): string
    {
        return ($this->key === '') ? $this->certificate : $this->key;
    }

    /**
     * The passphrase of the key, '' for none - never null: without one OpenSSL asks for it on stdin,
     * which under FastCGI is the socket of the request.
     */
    public function passphrase(): string
    {
        return $this->passphrase->reveal();
    }

    /**
     * What keeps the certificate from a TLS handshake, as this server sees it: the first of the
     * constants above that applies, null for none. Inside the handshake the same failure would look
     * like a network one.
     * @param ?int $now unix time; null = now
     */
    public function problem(?int $now = null): ?string
    {
        if (!self::readable($this->certificate)) {
            return self::UNREADABLE;
        }
        if (($this->key !== '') && !self::readable($this->key)) {
            return self::KEY_UNREADABLE;
        }
        if (!function_exists('openssl_x509_parse')) {
            return self::NO_OPENSSL;
        }
        $details = $this->details();
        if (is_null($details)) {
            return self::NOT_A_CERTIFICATE;
        }
        if (self::tooLarge($this->keyFile())) {
            return self::KEY_LOCKED;
        }
        $now ??= time();
        try {
            $key = openssl_pkey_get_private('file://' . $this->keyFile(), $this->passphrase());

            return match (true) {
                $key === false                                                        => self::KEY_LOCKED,
                !openssl_x509_check_private_key('file://' . $this->certificate, $key) => self::KEY_MISMATCH,
                self::weak($key)                                                      => self::WEAK_KEY,
                (int) ($details['validFrom_time_t'] ?? 0) > $now                      => self::NOT_YET_VALID,
                (int) ($details['validTo_time_t'] ?? 0) < $now                        => self::EXPIRED,
                default                                                               => null,
            };
        } finally {
            self::forgetErrors();
        }
    }

    /**
     * What the certificate says of itself, as openssl_x509_parse() gives it (name, serialNumberHex,
     * validFrom_time_t, validTo_time_t, ...); null when the file holds none, cannot be read or is
     * larger than MAX_BYTES.
     * @return ?array<string, mixed>
     */
    public function details(): ?array
    {
        if (!self::readable($this->certificate) || self::tooLarge($this->certificate) || !function_exists('openssl_x509_parse')) {
            return null;
        }
        try {
            $details = openssl_x509_parse('file://' . $this->certificate);
        } finally {
            self::forgetErrors();
        }

        return is_array($details) ? $details : null;
    }

    /**
     * SHA-256 of the certificate itself, not of its file: a chain added after it or other line ends
     * keep it, a new certificate changes it. Null when there is none.
     */
    public function fingerprint(): ?string
    {
        // openssl_x509_fingerprint() warns about a file that holds no certificate
        if (is_null($this->details())) {
            return null;
        }
        try {
            $fingerprint = openssl_x509_fingerprint('file://' . $this->certificate, 'sha256');
        } finally {
            self::forgetErrors();
        }

        return is_string($fingerprint) ? $fingerprint : null;
    }

    private static function readable(string $file): bool
    {
        return is_file($file) && is_readable($file);
    }

    private static function tooLarge(string $file): bool
    {
        return (int) filesize($file) > self::MAX_BYTES;
    }

    /**
     * Fewer bits than MIN_BITS, unless a named EC curve or EdDSA: an EC key of 256 bits is as strong
     * as RSA of 3072. RSA-PSS has no type in PHP; PHP 8.2 calls it, and EdDSA, EC without a curve.
     */
    public static function weak(\OpenSSLAsymmetricKey $key): bool
    {
        $details = openssl_pkey_get_details($key);
        if (!is_array($details)) {
            return false;
        }
        $curve = (($details['type'] ?? null) === OPENSSL_KEYTYPE_EC) && isset($details['ec']['curve_name']);

        return !$curve && !self::edDsa((string) ($details['key'] ?? '')) && ((int) ($details['bits'] ?? 0) < self::MIN_BITS);
    }

    /** the public key in PEM is Ed25519 or Ed448: its algorithm is OID 1.3.101.112 or .113 */
    private static function edDsa(string $publicKey): bool
    {
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $publicKey), true);

        return is_string($der) && (preg_match('/^\x30[\x00-\x7f]\x30\x05\x06\x03\x2b\x65[\x70\x71]/', $der) === 1);
    }

    /** OpenSSL keeps its errors for whoever asks next - none of them are that caller's */
    private static function forgetErrors(): void
    {
        while (function_exists('openssl_error_string') && (openssl_error_string() !== false)) {
        }
    }
}
