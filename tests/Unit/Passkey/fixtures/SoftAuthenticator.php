<?php

declare(strict_types=1);

namespace Tests\Unit\Passkey\Fixtures;

use AA\Passkey\Credential;
use AA\Passkey\Credentials;
use AA\Util\Txt;

/**
 * An authenticator in PHP: answers the options of AA\Passkey\Ceremony as a browser with a passkey would -
 * an ES256 key of openssl, the authenticator data and CBOR made by hand, so the real library checks real
 * ceremonies without a browser.
 */
final class SoftAuthenticator
{
    private const UP = 0x01;
    private const UV = 0x04;
    private const BE = 0x08;
    private const BS = 0x10;
    private const AT = 0x40;

    public string $credentialId;
    public string $userHandle = '';
    /** a security key counts its signatures; a synced passkey says 0 */
    public int $counter = 0;
    public bool $counts = false;
    public bool $verifiesUser = true;
    public bool $synced = true;
    /** none, or packed: the self attestation a browser passes through for attestation "none" */
    public string $format = 'none';

    private \OpenSSLAsymmetricKey $key;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new \RuntimeException('openssl cannot make an EC key');
        }
        $this->key          = $key;
        $this->credentialId = random_bytes(20);
    }

    /** the answer of navigator.credentials.create() to these options, as PublicKeyCredential.toJSON() gives it */
    public function create(string $options, string $origin): string
    {
        $o                = json_decode($options, true, 512, JSON_THROW_ON_ERROR);
        $this->userHandle = self::bytes($o['user']['id']);
        $client = self::client('webauthn.create', $o['challenge'], $origin);
        $ec     = openssl_pkey_get_details($this->key)['ec'];
        // openssl drops leading zero bytes of a coordinate, COSE wants all 32
        $cose   = self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => new Bytes(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)), -3 => new Bytes(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT))]);
        $auth   = hash('sha256', $o['rp']['id'], true) . chr($this->flags() | self::AT) . pack('N', $this->counter)
            . str_repeat("\x11", 16) . pack('n', strlen($this->credentialId)) . $this->credentialId . $cose;
        $statement = [];
        if ($this->format === 'packed') {
            openssl_sign($auth . hash('sha256', $client, true), $signature, $this->key, OPENSSL_ALGO_SHA256);
            $statement = ['alg' => -7, 'sig' => new Bytes($signature)];
        }
        $object = self::cbor(['fmt' => $this->format, 'attStmt' => $statement, 'authData' => new Bytes($auth)]);
        return self::answer($this->credentialId, ['clientDataJSON' => $client, 'attestationObject' => $object]);
    }

    /** the answer of navigator.credentials.get() to these options */
    public function get(string $options, string $origin): string
    {
        $o = json_decode($options, true, 512, JSON_THROW_ON_ERROR);
        if ($this->counts) {
            ++$this->counter;
        }
        $client = self::client('webauthn.get', $o['challenge'], $origin);
        $auth   = hash('sha256', $o['rpId'], true) . chr($this->flags()) . pack('N', $this->counter);
        openssl_sign($auth . hash('sha256', $client, true), $signature, $this->key, OPENSSL_ALGO_SHA256);
        return self::answer($this->credentialId, ['clientDataJSON' => $client, 'authenticatorData' => $auth, 'signature' => $signature, 'userHandle' => $this->userHandle]);
    }

    /** an answer of other bytes than the browser signed */
    public static function tamper(string $answer, string $field): string
    {
        $a = json_decode($answer, true);
        $bytes = self::bytes($a['response'][$field]);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        $a['response'][$field] = Txt::bin2url($bytes);
        return json_encode($a);
    }

    private static function bytes(string $url): string
    {
        return (string) Txt::url2bin($url);
    }

    private function flags(): int
    {
        return self::UP | ($this->verifiesUser ? self::UV : 0) | ($this->synced ? self::BE | self::BS : 0);
    }

    private static function client(string $type, string $challenge, string $origin): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, string> $response raw bytes */
    private static function answer(string $id, array $response): string
    {
        return json_encode(['id' => Txt::bin2url($id), 'rawId' => Txt::bin2url($id), 'type' => 'public-key', 'response' => array_map(Txt::bin2url(...), $response)]);
    }

    /** CBOR (RFC 8949) of ints, text, Bytes and maps - all the authenticator data needs */
    private static function cbor(mixed $value): string
    {
        return match (true) {
            is_int($value)           => ($value >= 0) ? self::head(0, $value) : self::head(1, -1 - $value),
            $value instanceof Bytes  => self::head(2, strlen($value->bytes)) . $value->bytes,
            is_string($value)        => self::head(3, strlen($value)) . $value,
            is_array($value)         => self::head(5, count($value)) . implode('', array_map(static fn($k, $v): string => self::cbor($k) . self::cbor($v), array_keys($value), $value)),
            default                  => throw new \LogicException('no CBOR for ' . get_debug_type($value)),
        };
    }

    private static function head(int $major, int $n): string
    {
        return match (true) {
            $n < 24    => chr(($major << 5) | $n),
            $n < 256   => chr(($major << 5) | 24) . chr($n),
            $n < 65536 => chr(($major << 5) | 25) . pack('n', $n),
            default    => chr(($major << 5) | 26) . pack('N', $n),
        };
    }
}

/** a CBOR byte string (a PHP string is encoded as text) */
final class Bytes
{
    public function __construct(public readonly string $bytes) {}
}

/** the passkeys in an array, ids from 1 */
final class MemoryCredentials implements Credentials
{
    /** @var array<int, Credential> */
    public array $rows = [];

    public function find(string $credential_id): ?Credential
    {
        foreach ($this->rows as $row) {
            if ($row->credentialId === $credential_id) {
                return $row;
            }
        }
        return null;
    }

    public function forUid(string $uid): array
    {
        return array_values(array_filter($this->rows, static fn(Credential $c): bool => $c->uid === $uid));
    }

    public function add(Credential $credential): int
    {
        $id              = count($this->rows) + 1;
        $this->rows[$id] = $credential->withId($id);
        return $id;
    }

    public function used(int $id, int $sign_count, int $time): void
    {
        $c               = $this->rows[$id];
        $this->rows[$id] = new Credential(
            id: $c->id,
            uid: $c->uid,
            rpId: $c->rpId,
            credentialId: $c->credentialId,
            publicKey: $c->publicKey,
            signCount: $sign_count,
            userHandle: $c->userHandle,
            aaguid: $c->aaguid,
            backupEligible: $c->backupEligible,
            backedUp: $c->backedUp,
            name: $c->name,
            created: $c->created,
            lastUsed: $time,
        );
    }
}
