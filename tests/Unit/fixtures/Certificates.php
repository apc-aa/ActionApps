<?php

declare(strict_types=1);

namespace Tests\Unit\Fixtures;

/**
 * Throwaway certificates made at runtime, so no private key lives in the repository. EC keys: an RSA
 * one takes a noticeable part of a second to make.
 */
final class Certificates
{
    private const OPTIONS = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'digest_alg' => 'sha256'];

    /**
     * @param int $rsa bits of an RSA key instead of the EC one
     * @return array{string, string} PEM of a new self-signed certificate valid from now, and of its key
     */
    public static function make(string $name = 'AA test', int $serial = 42, int $days = 400, int $rsa = 0): array
    {
        $options = ($rsa > 0) ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $rsa, 'digest_alg' => 'sha256'] : self::OPTIONS;
        $key     = openssl_pkey_new($options);
        $request = ($key === false) ? false : openssl_csr_new(['commonName' => $name, 'organizationName' => 'ActionApps'], $key, $options);
        $x509    = (($key === false) || ($request === false)) ? false : openssl_csr_sign($request, null, $key, $days, $options, $serial);
        if (($key === false) || ($x509 === false) || !openssl_x509_export($x509, $certificate) || !openssl_pkey_export($key, $private)) {
            throw new \RuntimeException('openssl made no certificate: ' . openssl_error_string());
        }

        return [$certificate, $private];
    }

    /**
     * A self-signed certificate of an RSA-PSS key, which PHP cannot make: an RSA key relabelled in its
     * PKCS#8 form (id-RSASSA-PSS, no parameters), then signed with itself
     * @return array{string, string} PEM of the certificate and of the key
     */
    public static function pss(int $bits): array
    {
        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
        if (($rsa === false) || !openssl_pkey_export($rsa, $pem)) {
            throw new \RuntimeException('openssl made no RSA key');
        }
        $der  = (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem));
        $body = str_replace("\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00", "\x30\x0b\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x0a", substr($der, 4), $relabelled);
        if ($relabelled !== 1) {
            throw new \RuntimeException('no rsaEncryption to relabel');
        }
        $key  = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(base64_encode("\x30\x82" . pack('n', strlen($body)) . $body), 64, "\n") . "-----END PRIVATE KEY-----\n";
        $pss     = openssl_pkey_get_private($key, '');
        $request = ($pss === false) ? false : openssl_csr_new(['commonName' => 'AA test PSS'], $pss, ['digest_alg' => 'sha256']);
        $x509    = (($pss === false) || ($request === false)) ? false : openssl_csr_sign($request, null, $pss, 400, ['digest_alg' => 'sha256'], 46);
        if (($x509 === false) || !openssl_x509_export($x509, $certificate)) {
            throw new \RuntimeException('openssl made no RSA-PSS certificate');
        }

        return [$certificate, $key];
    }

    /**
     * A new Ed25519 or Ed448 private key in PEM, from random bytes: PHP 8.2 cannot make one, but reads it
     * @param string $curve 'ed25519' or 'ed448'
     */
    public static function edDsa(string $curve): string
    {
        // PKCS#8 of EdDSA is a fixed head and the seed
        [$head, $bytes] = ($curve === 'ed448') ? ['3047020100300506032b6571043b0439', 57] : ['302e020100300506032b657004220420', 32];

        return "-----BEGIN PRIVATE KEY-----\n" . chunk_split(base64_encode((string) hex2bin($head) . random_bytes($bytes)), 64, "\n") . "-----END PRIVATE KEY-----\n";
    }

    /** $private encrypted by $passphrase the way openssl does it by default (PBES2, PBKDF2) */
    public static function encrypted(string $private, string $passphrase): string
    {
        $key = openssl_pkey_get_private($private, '');
        if (($key === false) || !openssl_pkey_export($key, $encrypted, $passphrase)) {
            throw new \RuntimeException('openssl did not encrypt the key');
        }

        return $encrypted;
    }

    /** a new, empty directory among the temporary ones */
    public static function directory(): string
    {
        $dir = sys_get_temp_dir() . '/aa-certificates-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700);

        return $dir;
    }

    /** @return string the path of $content written to $dir/$name with $mode */
    public static function file(string $dir, string $name, string $content, int $mode = 0o600): string
    {
        $file = "$dir/$name";
        file_put_contents($file, $content);
        chmod($file, $mode);
        clearstatcache();

        return $file;
    }

    /** $dir and all in it, after chmod back: a file PHP could not read is still removed; links, not their targets */
    public static function remove(string $dir): void
    {
        foreach ((array) glob("$dir/*") as $path) {
            $path = (string) $path;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                self::remove($path);
            } else {
                chmod($path, 0o600);
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
