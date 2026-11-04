<?php

declare(strict_types=1);

namespace Tests\Unit\Passkey;

use AA\Passkey\Ceremony;
use AA\Passkey\Challenges;
use AA\Passkey\Credential;
use AA\Passkey\Refused;
use AA\Util\Txt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Passkey\Fixtures\MemoryCredentials;
use Tests\Unit\Passkey\Fixtures\SoftAuthenticator;

require_once __DIR__ . '/fixtures/SoftAuthenticator.php';

/** Real WebAuthn ceremonies of AA\Passkey\Ceremony with the real library, the browser played by SoftAuthenticator. */
final class CeremonyTest extends TestCase
{
    private const HOST   = 'aa.example.org';
    private const ORIGIN = 'https://aa.example.org';
    private const NOW    = 1790000000;

    private MemoryCredentials $store;
    /** @var array<string, mixed> the session */
    private array $bag = [];

    protected function setUp(): void
    {
        $this->store = new MemoryCredentials();
        $this->bag   = [];
    }

    private function ceremony(string $host = self::HOST): Ceremony
    {
        return Ceremony::forRequest(['HTTP_HOST' => $host, 'HTTPS' => 'on'], $this->store, new Challenges($this->bag), 'Test AA');
    }

    /** a passkey of account 7 made on the host by $key */
    private function registered(SoftAuthenticator $key, string $uid = '7', string $host = self::HOST): Credential
    {
        $ceremony = $this->ceremony($host);
        return $ceremony->register($uid, $key->create($ceremony->registrationOptions($uid, 'honza', 'Honza Malík'), "https://$host"), 'Laptop', self::NOW);
    }

    private function login(SoftAuthenticator $key, string $host = self::HOST): Credential
    {
        $ceremony = $this->ceremony($host);
        return $ceremony->login($key->get($ceremony->loginOptions(), "https://$host"), self::NOW + 60);
    }

    private function assertRefused(string $reason, callable $ceremony): void
    {
        try {
            $ceremony();
            self::fail("Refused($reason) expected");
        } catch (Refused $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    public static function requests(): array
    {
        return [
            'https'               => [['HTTP_HOST' => 'AA.Example.org', 'HTTPS' => 'on'], 'aa.example.org'],
            'https with a port'   => [['HTTP_HOST' => 'aa.example.org:8443', 'HTTPS' => 'on'], 'aa.example.org'],
            'https by the port'   => [['HTTP_HOST' => 'aa.example.org', 'SERVER_PORT' => '443'], 'aa.example.org'],
            'plain http'          => [['HTTP_HOST' => 'aa.example.org'], null],
            'https off'           => [['HTTP_HOST' => 'aa.example.org', 'HTTPS' => 'off'], null],
            'localhost over http' => [['HTTP_HOST' => 'localhost:8080'], 'localhost'],
            'an IPv4 address'     => [['HTTP_HOST' => '192.0.2.1', 'HTTPS' => 'on'], null],
            'an IPv6 address'     => [['HTTP_HOST' => '[2001:db8::1]', 'HTTPS' => 'on'], null],
            'no dot'              => [['HTTP_HOST' => 'intranet', 'HTTPS' => 'on'], null],
            'an empty label'      => [['HTTP_HOST' => 'aa..example.org', 'HTTPS' => 'on'], null],
            'no host'             => [['HTTPS' => 'on'], null],
        ];
    }

    #[DataProvider('requests')]
    public function test_forRequest_hostAndScheme(array $server, ?string $rp_id): void
    {
        $bag      = [];
        $ceremony = Ceremony::forRequest($server, $this->store, new Challenges($bag), 'Test AA');
        self::assertSame($rp_id, $ceremony?->rpId());
    }

    public function test_register_thenLogin_returnsThePasskey(): void
    {
        $key        = new SoftAuthenticator();
        $registered = $this->registered($key);

        self::assertSame(1, $registered->id);
        self::assertSame(['7', self::HOST, 'Laptop', self::NOW, 0], [$registered->uid, $registered->rpId, $registered->name, $registered->created, $registered->lastUsed]);
        self::assertSame($key->credentialId, $registered->credentialId);
        self::assertSame(32, strlen($registered->userHandle));
        self::assertSame(str_repeat('11', 16), $registered->aaguid);
        self::assertTrue($registered->backupEligible && $registered->backedUp);

        $logged = $this->login($key);
        self::assertSame(['7', 1], [$logged->uid, $logged->id]);
        self::assertSame(self::NOW + 60, $this->store->rows[1]->lastUsed);
    }

    public function test_registrationOptions_secondPasskey_sameHandle_excludesTheFirst(): void
    {
        $first    = $this->registered(new SoftAuthenticator());
        $options  = json_decode($this->ceremony()->registrationOptions('7', 'honza', ''), true);

        self::assertSame(Txt::bin2url($first->userHandle), $options['user']['id']);
        self::assertSame([Txt::bin2url($first->credentialId)], array_column($options['excludeCredentials'], 'id'));
        self::assertSame('honza', $options['user']['displayName'], 'no name - the login');
        self::assertSame(['required', true, 'none', self::HOST], [$options['authenticatorSelection']['userVerification'], $options['authenticatorSelection']['requireResidentKey'], $options['attestation'], $options['rp']['id']]);
        self::assertArrayNotHasKey('extensions', $options);
    }

    public function test_loginOptions_anyPasskeyOfTheHost_userVerified(): void
    {
        $options = json_decode($this->ceremony()->loginOptions(), true);

        self::assertSame([self::HOST, 'required'], [$options['rpId'], $options['userVerification']]);
        self::assertArrayNotHasKey('allowCredentials', $options, 'the browser offers the passkeys itself - no name is typed');
        self::assertSame(32, strlen(Txt::url2bin($options['challenge'])));
    }

    public function test_login_sameAnswerTwice_refused(): void
    {
        $key = new SoftAuthenticator();
        $this->registered($key);
        $ceremony = $this->ceremony();
        $answer   = $key->get($ceremony->loginOptions(), self::ORIGIN);

        $ceremony->login($answer, self::NOW);
        $this->assertRefused(Refused::CHALLENGE, fn() => $ceremony->login($answer, self::NOW));
    }

    public function test_login_challengeOfAnotherSession_refused(): void
    {
        $key = new SoftAuthenticator();
        $this->registered($key);
        $options = $this->ceremony()->loginOptions();
        $this->bag = [];   // the answer arrives in another session

        $this->assertRefused(Refused::CHALLENGE, fn() => $this->ceremony()->login($key->get($options, self::ORIGIN), self::NOW));
    }

    public function test_login_afterTheChallengeExpired_refused(): void
    {
        $key = new SoftAuthenticator();
        $this->registered($key);
        $ceremony = $this->ceremony();
        $answer   = $key->get($ceremony->loginOptions(), self::ORIGIN);

        $this->assertRefused(Refused::CHALLENGE, fn() => $ceremony->login($answer, time() + Challenges::LIFETIME + 1));
    }

    public function test_login_twoTabs_bothChallengesWork(): void
    {
        $key = new SoftAuthenticator();
        $this->registered($key);
        $ceremony = $this->ceremony();
        $first    = $ceremony->loginOptions();
        $second   = $ceremony->loginOptions();

        self::assertSame('7', $ceremony->login($key->get($second, self::ORIGIN), self::NOW)->uid);
        self::assertSame('7', $ceremony->login($key->get($first, self::ORIGIN), self::NOW)->uid);
    }

    public function test_login_fromAnotherOrigin_refused(): void
    {
        $key = new SoftAuthenticator();
        $this->registered($key);
        $ceremony = $this->ceremony();

        // a subdomain passes the library's own check, not this one
        $this->assertRefused(Refused::ORIGIN, fn() => $ceremony->login($key->get($ceremony->loginOptions(), 'https://evil.aa.example.org'), self::NOW));
        $this->assertRefused(Refused::ORIGIN, fn() => $ceremony->login($key->get($ceremony->loginOptions(), 'http://aa.example.org'), self::NOW));
    }

    public function test_login_passkeyOfAnotherHost_unknown(): void
    {
        $key = new SoftAuthenticator();
        $this->registered($key, '7', 'other.example.org');

        $this->assertRefused(Refused::UNKNOWN, fn() => $this->login($key));
    }

    public function test_login_deletedPasskey_unknown(): void
    {
        $key = new SoftAuthenticator();
        $this->registered($key);
        $this->store->rows = [];

        $this->assertRefused(Refused::UNKNOWN, fn() => $this->login($key));
    }

    public function test_login_handleOfAnotherAccount_refused(): void
    {
        $key = new SoftAuthenticator();
        $this->registered($key);
        $key->userHandle = random_bytes(32);

        $this->assertRefused(Refused::USER, fn() => $this->login($key));
    }

    public function test_login_signatureOverOtherData_refused(): void
    {
        $key = new SoftAuthenticator();
        $this->registered($key);
        $ceremony = $this->ceremony();
        $answer   = SoftAuthenticator::tamper($key->get($ceremony->loginOptions(), self::ORIGIN), 'signature');

        $this->assertRefused(Refused::INVALID, fn() => $ceremony->login($answer, self::NOW));
    }

    public function test_login_withoutUserVerification_refused(): void
    {
        $key = new SoftAuthenticator();
        $this->registered($key);
        $key->verifiesUser = false;

        $this->assertRefused(Refused::INVALID, fn() => $this->login($key));
    }

    public function test_login_counterOfASecurityKey_wentBack_letInWithAWarning(): void
    {
        $key         = new SoftAuthenticator();
        $key->counts = true;
        $this->registered($key);

        $ceremony = $this->ceremony();
        $ceremony->login($key->get($ceremony->loginOptions(), self::ORIGIN), self::NOW);
        $ceremony->login($key->get($ceremony->loginOptions(), self::ORIGIN), self::NOW);
        self::assertSame(2, $this->store->rows[1]->signCount);
        self::assertFalse($ceremony->counterWentBack());

        // a clone of the key, made before those logins - refusing it would lock the account out
        $key->counter = 0;
        self::assertSame('7', $ceremony->login($key->get($ceremony->loginOptions(), self::ORIGIN), self::NOW)->uid);
        self::assertTrue($ceremony->counterWentBack());
        self::assertSame(2, $this->store->rows[1]->signCount, 'the counter stays where it was');
    }

    /** a browser passes the self attestation of a security key through, though AA asks for none */
    public function test_register_packedSelfAttestation_stored(): void
    {
        $key         = new SoftAuthenticator();
        $key->format = 'packed';

        self::assertSame(1, $this->registered($key)->id);
        self::assertSame('7', $this->login($key)->uid);
    }

    public function test_login_hostWithTheDefaultPort_sameOrigin(): void
    {
        $key = new SoftAuthenticator();
        $this->registered($key);

        $ceremony = $this->ceremony(self::HOST . ':443');
        self::assertSame('7', $ceremony->login($key->get($ceremony->loginOptions(), self::ORIGIN), self::NOW)->uid);
    }

    public static function malformed(): array
    {
        return [
            'not JSON'          => ['{'],
            'no response'       => ['{"id":"AAAA"}'],
            'id not a string'   => ['{"id":5,"response":{}}'],
            'missing signature' => ['{"id":"AAAA","response":{"clientDataJSON":"e30","authenticatorData":"AAAA"}}'],
            'not base64url'     => ['{"id":"AAAA","response":{"clientDataJSON":"*","authenticatorData":"AAAA","signature":"AAAA"}}'],
            'too big'           => [str_repeat(' ', 70000)],
        ];
    }

    #[DataProvider('malformed')]
    public function test_login_notABrowserAnswer_format(string $answer): void
    {
        $this->assertRefused(Refused::FORMAT, fn() => $this->ceremony()->login($answer, self::NOW));
    }

    public function test_register_optionsOfAnotherAccount_refused(): void
    {
        $ceremony = $this->ceremony();
        $answer   = (new SoftAuthenticator())->create($ceremony->registrationOptions('7', 'honza', ''), self::ORIGIN);

        $this->assertRefused(Refused::CHALLENGE, fn() => $ceremony->register('8', $answer, '', self::NOW));
        self::assertSame([], $this->store->rows);
    }

    public function test_register_theSamePasskeyTwice_refused(): void
    {
        $key = new SoftAuthenticator();
        $this->registered($key);

        $this->assertRefused(Refused::DUPLICATE, fn() => $this->registered($key));
        self::assertCount(1, $this->store->rows);
    }

    public function test_register_withoutUserVerification_refused(): void
    {
        $key               = new SoftAuthenticator();
        $key->verifiesUser = false;

        $this->assertRefused(Refused::INVALID, fn() => $this->registered($key));
    }

    public function test_register_deviceBoundPasskey_notBackedUp(): void
    {
        $key         = new SoftAuthenticator();
        $key->synced = false;

        $registered = $this->registered($key);
        self::assertFalse($registered->backupEligible || $registered->backedUp);
    }

    public static function names(): array
    {
        return [
            'kept'          => ['iPhone', 'iPhone'],
            'one line'      => ["Pracovní\n\t notebook ", 'Pracovní notebook'],
            'empty: a date' => [' ', date('Y-m-d H:i', self::NOW)],
            'cut'           => [str_repeat('ž', 120), str_repeat('ž', 100)],
        ];
    }

    #[DataProvider('names')]
    public function test_cleanName(string $name, string $clean): void
    {
        self::assertSame($clean, Credential::cleanName($name, self::NOW));
    }
}
