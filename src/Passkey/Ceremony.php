<?php

declare(strict_types=1);

namespace AA\Passkey;

use AA\Central\Package\DbImportTarget;
use AA\Util\Txt;
use AA\Util\Url;
use ReportUri\Passkeys\CBOR\CborDecoder;
use ReportUri\Passkeys\Binary\ByteBuffer;
use ReportUri\Passkeys\WebAuthn;
use ReportUri\Passkeys\WebAuthnException;

/**
 * Passkey login and registration on this host (WebAuthn): the options for the browser and the check of its answer.
 *
 * The relying party is the exact host, never its domain: under one domain (ecn.cz) run several AAs, and sites
 * whose templates anyone with template rights writes - a passkey of the domain could be asked for from any of
 * them. The answer must also come from exactly this origin. A passkey always verifies the user (PIN, fingerprint),
 * so it is two factors by itself.
 */
final class Ceremony
{
    /** seconds the browser waits for the user */
    public const TIMEOUT = 300;
    /** the field of the login form the browser's answer comes in */
    public const LOGIN_FIELD = 'passkey_login';
    /** Refused::COUNTER of a login that passed */
    private bool $counterWentBack = false;
    private const LOGIN    = 'login';
    private const REGISTER = 'register';
    /** a browser's answer is a few kB; anything far bigger is not one */
    private const MAX_ANSWER = 65536;

    private function __construct(private string $rp_id, private string $origin, private string $rp_name, private Credentials $store, private Challenges $challenges) {}

    /** null where passkeys cannot work: no table yet, not https, the host an IP address or one this AA does
     *  not serve - a Host header of the visitor's choice would put a passkey (and the login form's link to its
     *  host) on any domain */
    public static function here(): ?self
    {
        $store = new Store();
        if (!$store->available()) {
            return null;
        }
        $ceremony = self::forRequest($_SERVER, $store, Challenges::session(), defined('ORG_NAME') ? (string) constant('ORG_NAME') : 'ActionApps');
        return ($ceremony && self::servedHere($ceremony->rp_id)) ? $ceremony : null;
    }

    /** @param array<string, mixed> $server $_SERVER after TrustedProxy - HTTP_HOST is the visitor's host */
    public static function forRequest(array $server, Credentials $store, Challenges $challenges, string $rp_name): ?self
    {
        $host = strtolower((string) ($server['HTTP_HOST'] ?? ''));
        if (!preg_match('/^([a-z0-9.-]+)(?::(\d{1,5}))?$/D', $host, $m) || !self::isDomain($m[1])) {
            return null;
        }
        $https = Url::isHttps($server);
        if (!$https && ($m[1] !== 'localhost')) {   // WebAuthn runs only on https - and on localhost for development
            return null;
        }
        // the browser leaves the default port out of the origin; some proxies send it in Host
        $port = (($m[2] ?? '') === '') || ((int) $m[2] === ($https ? 443 : 80)) ? '' : ':' . (int) $m[2];
        return new self($m[1], ($https ? 'https://' : 'http://') . $m[1] . $port, $rp_name, $store, $challenges);
    }

    /** the host of this AA's own address, or of one of its sites */
    private static function servedHere(string $host): bool
    {
        $own = strtolower((string) parse_url((string) constant('AA_INSTAL_URL'), PHP_URL_HOST));
        return ($host === $own) || (new DbImportTarget())->domainTaken($host);
    }

    /** a host name WebAuthn takes as relying party: no IP address, no empty labels */
    private static function isDomain(string $host): bool
    {
        return ($host === 'localhost')
            || (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/D', $host) && !preg_match('/^[0-9.]+$/D', $host));
    }

    public function rpId(): string
    {
        return $this->rp_id;
    }

    /** options for navigator.credentials.get() - any passkey of this host, the user picks it (no name typed)
     *  @return string JSON, binary values in base64url */
    public function loginOptions(): string
    {
        $server = $this->server();
        $args   = $server->getGetArgs([], self::TIMEOUT, true, true, true, true, true, true);
        $this->challenges->issue(self::LOGIN, $server->getChallenge()->getBinaryString());
        return self::json($args->publicKey);
    }

    /** the passkey the browser's answer to loginOptions() proves; its use is recorded
     *  @param string $answer JSON of the PublicKeyCredential (as its toJSON() gives)
     *  @throws Refused */
    public function login(string $answer, ?int $now = null): Credential
    {
        $data      = self::decode($answer);
        $client    = self::field($data, 'clientDataJSON');
        $auth_data = self::field($data, 'authenticatorData');
        $signature = self::field($data, 'signature');
        [$challenge] = $this->challenge(self::LOGIN, $client, $now);

        $credential = $this->store->find(self::bytes($data['id']));
        if (!$credential || ($credential->rpId !== $this->rp_id)) {
            throw new Refused(Refused::UNKNOWN);
        }
        $handle = $data['response']['userHandle'] ?? null;
        if ((($handle ?? '') !== '') && !hash_equals($credential->userHandle, self::bytes($handle))) {
            throw new Refused(Refused::USER);
        }
        $server = $this->server();
        $this->verify(fn() => $server->processGet($client, $auth_data, $signature, $credential->publicKey, $challenge, null, true, true));
        // a counter that does not grow hints at a copied security key - but refusing it would lock the account
        // out (its password no longer logs in), so it only warns
        $count = (int) $server->getSignatureCounter();
        $this->counterWentBack = ($credential->signCount > 0) && ($count <= $credential->signCount);
        $this->store->used($credential->id, max($count, $credential->signCount), $now ?? time());
        return $credential;
    }

    /** the last login() passed with a signature counter that did not grow */
    public function counterWentBack(): bool
    {
        return $this->counterWentBack;
    }

    /** options for navigator.credentials.create() - a passkey of the account kept by the authenticator, so it
     *  logs in without the name; none twice on one authenticator
     *  @return string JSON, binary values in base64url */
    public function registrationOptions(string $uid, string $login, string $display_name): string
    {
        $mine    = $this->store->forUid($uid);
        $handle  = $mine ? $mine[0]->userHandle : random_bytes(32);
        $exclude = [];
        foreach ($mine as $credential) {
            if ($credential->rpId === $this->rp_id) {
                $exclude[] = $credential->credentialId;
            }
        }
        $server = $this->server();
        $args   = $server->getCreateArgs($handle, $login, ($display_name === '') ? $login : $display_name, self::TIMEOUT, true, true, null, $exclude);
        unset($args->publicKey->extensions);   // the library asks for a made-up extension
        $this->challenges->issue(self::REGISTER, $server->getChallenge()->getBinaryString(), ['uid' => $uid, 'handle' => bin2hex($handle)]);
        return self::json($args->publicKey);
    }

    /** stores the passkey of the browser's answer to registrationOptions() of the same account
     *  @throws Refused */
    public function register(string $uid, string $answer, string $name, ?int $now = null): Credential
    {
        $data   = self::decode($answer);
        $client = self::field($data, 'clientDataJSON');
        $object = self::field($data, 'attestationObject');
        [$challenge, $context] = $this->challenge(self::REGISTER, $client, $now);
        if (($context['uid'] ?? null) !== $uid) {
            throw new Refused(Refused::CHALLENGE, 'another account');
        }
        $made = $this->verify(fn() => $this->server()->processCreate($client, self::withoutAttestation($object), $challenge, true, true));
        $credential_id = (string) $made->credentialId;
        if ($credential_id !== self::bytes($data['id'])) {
            throw new Refused(Refused::FORMAT, 'id');
        }
        if ($this->store->find($credential_id)) {
            throw new Refused(Refused::DUPLICATE);
        }
        $now ??= time();
        $credential = new Credential(
            id: 0,
            uid: $uid,
            rpId: $this->rp_id,
            credentialId: $credential_id,
            publicKey: (string) $made->credentialPublicKey,
            signCount: (int) ($made->signatureCounter ?? 0),
            userHandle: (string) hex2bin($context['handle'] ?? ''),
            aaguid: bin2hex((string) $made->AAGUID),
            backupEligible: (bool) $made->isBackupEligible,
            backedUp: (bool) $made->isBackedUp,
            name: Credential::cleanName($name, $now),
            created: $now,
            lastUsed: 0,
        );
        return $credential->withId($this->store->add($credential));
    }

    /** the challenge the answer signed, spent - and the context it was issued with
     *  @return array{0: string, 1: array<string, string>} */
    private function challenge(string $purpose, string $client_data_json, ?int $now): array
    {
        $client = json_decode($client_data_json, true);
        if (!is_array($client) || !is_string($client['challenge'] ?? null)) {
            throw new Refused(Refused::FORMAT, 'client data');
        }
        // exact, not the library's "host or its subdomain"
        if (($client['origin'] ?? null) !== $this->origin) {
            throw new Refused(Refused::ORIGIN, self::originDiffers(is_string($client['origin'] ?? null) ? $client['origin'] : ''));
        }
        $challenge = self::bytes($client['challenge']);
        $context   = $this->challenges->take($purpose, $challenge, $now);
        if ($context === null) {
            throw new Refused(Refused::CHALLENGE);
        }
        return [$challenge, $context];
    }

    /** the library keeps one challenge per instance - a second set of options from one instance would repeat it */
    private function server(): WebAuthn
    {
        return new WebAuthn($this->rp_name, $this->rp_id, true);
    }

    /** what differs - one of a few words, so the log does not keep whatever an answer claims */
    private function originDiffers(string $origin): string
    {
        $theirs = parse_url($origin);
        $ours   = parse_url($this->origin);
        return match (true) {
            !is_array($theirs)                                                    => 'malformed',
            (($theirs['scheme'] ?? '') !== $ours['scheme'])                       => 'scheme',
            (strtolower($theirs['host'] ?? '') !== $ours['host'])                 => 'host',
            (($theirs['port'] ?? null) !== ($ours['port'] ?? null))               => 'port',
            default                                                               => 'other',
        };
    }

    /**
     * The attestation object as the format "none". AA asks for no attestation and checks none, but a browser
     * passes the self attestation ("packed" without a certificate) through unchanged (WebAuthn 5.1.3), and the
     * library takes only "none". The key and flags are in authData, which stays as it is.
     */
    private static function withoutAttestation(string $object): string
    {
        try {
            $data = CborDecoder::decode($object);
        } catch (WebAuthnException) {
            throw new Refused(Refused::FORMAT, 'attestation');
        }
        if (!is_array($data) || !(($data['authData'] ?? null) instanceof ByteBuffer)) {
            throw new Refused(Refused::FORMAT, 'attestation');
        }
        $auth = $data['authData']->getBinaryString();
        $n    = strlen($auth);
        // CBOR map {fmt: "none", attStmt: {}, authData: <bytes>}
        $head = match (true) {
            $n < 24    => chr(0x40 | $n),
            $n < 256   => "\x58" . chr($n),
            $n < 65536 => "\x59" . pack('n', $n),
            default    => "\x5a" . pack('N', $n),
        };
        return "\xa3\x63fmt\x64none\x67attStmt\xa0\x68authData" . $head . $auth;
    }

    /**
     * @template T
     * @param callable(): T $check
     * @return T
     */
    private function verify(callable $check): mixed
    {
        try {
            return $check();
        } catch (WebAuthnException $e) {
            throw new Refused(match ($e->getCode()) {
                WebAuthnException::SIGNATURE_COUNTER => Refused::COUNTER,
                WebAuthnException::INVALID_ORIGIN    => Refused::ORIGIN,
                WebAuthnException::INVALID_CHALLENGE => Refused::CHALLENGE,
                default                              => Refused::INVALID,
            }, $e->getMessage());
        }
    }

    /** @return array{id: string, response: array<string, mixed>} */
    private static function decode(string $answer): array
    {
        $data = (strlen($answer) <= self::MAX_ANSWER) ? json_decode($answer, true) : null;
        if (!is_array($data) || !is_string($data['id'] ?? null) || !is_array($data['response'] ?? null)) {
            throw new Refused(Refused::FORMAT);
        }
        return $data;
    }

    /** @param array{id: string, response: array<string, mixed>} $data */
    private static function field(array $data, string $name): string
    {
        return self::bytes($data['response'][$name] ?? null);
    }

    private static function bytes(mixed $base64url): string
    {
        $bytes = is_string($base64url) ? Txt::url2bin($base64url, true) : false;
        if (($bytes === false) || ($bytes === '')) {
            throw new Refused(Refused::FORMAT);
        }
        return $bytes;
    }

    private static function json(object $options): string
    {
        // a name from the cp-1250 era may still be invalid UTF-8
        return json_encode($options, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
