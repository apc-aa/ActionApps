<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use AA\Credential\Secret;
use AA\Http\ClientCertificate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\Certificates;

final class ClientCertificateTest extends TestCase
{
    private const PASSPHRASE = 'Pass-phrase-never-shown';

    /** the files of this run, made in setUpBeforeClass() */
    private static string $dir = '';

    /** validity of the certificate in cert.pem, unix seconds */
    private static int $from = 0;
    private static int $until = 0;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/Certificates.php';
        self::$dir             = Certificates::directory();
        [$certificate, $key]   = Certificates::make();
        [$another, $otherKey]  = Certificates::make('Another', 43);
        Certificates::file(self::$dir, 'cert.pem', $certificate);
        Certificates::file(self::$dir, 'both.pem', $certificate . $key);
        Certificates::file(self::$dir, 'chain.pem', str_replace("\n", "\r\n", $key . $certificate . $another));
        Certificates::file(self::$dir, 'another.pem', $another . $otherKey);
        Certificates::file(self::$dir, 'key.pem', $key);
        Certificates::file(self::$dir, 'other.key', $otherKey);
        Certificates::file(self::$dir, 'encrypted.key', Certificates::encrypted($key, self::PASSPHRASE));
        Certificates::file(self::$dir, 'garbage.pem', 'not a certificate');
        Certificates::file(self::$dir, 'locked.pem', $certificate . $key, 0o000);
        Certificates::file(self::$dir, 'weak.pem', implode('', Certificates::make('Weak', 44, 400, 1024)));
        Certificates::file(self::$dir, 'rsa.pem', implode('', Certificates::make('RSA', 45, 400, 2048)));
        Certificates::file(self::$dir, 'pss.pem', implode('', Certificates::pss(1024)));
        Certificates::file(self::$dir, 'pss2048.pem', implode('', Certificates::pss(2048)));
        // a valid certificate and key up to 1 MiB exactly, and one byte more
        $limit = 1024 * 1024;
        Certificates::file(self::$dir, 'limit.pem', str_pad($certificate . $key, $limit, "\n"));
        Certificates::file(self::$dir, 'huge.pem', str_pad($certificate . $key, $limit + 1, "\n"));
        if (function_exists('posix_mkfifo')) {
            // nobody writes to it: reading it would wait for ever
            posix_mkfifo(self::$dir . '/fifo.pem', 0o600);
        }
        $details     = (array) openssl_x509_parse($certificate);
        self::$from  = (int) $details['validFrom_time_t'];
        self::$until = (int) $details['validTo_time_t'];
        // making keys leaves notes of OpenSSL (no seed file) that the tests below must not inherit
        while (openssl_error_string() !== false) {
        }
    }

    public static function tearDownAfterClass(): void
    {
        Certificates::remove(self::$dir);
    }

    public function test_keyFile_inTheCertificateUnlessApart(): void
    {
        self::assertSame('/etc/aa/client.pem', (new ClientCertificate('/etc/aa/client.pem'))->keyFile());
        self::assertSame('/etc/aa/client.key', (new ClientCertificate('/etc/aa/client.pem', '/etc/aa/client.key'))->keyFile());
    }

    #[DataProvider('notFiles')]
    public function test_construct_refusesNoFile(string $certificate, string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClientCertificate($certificate, $key);
    }

    public static function notFiles(): array
    {
        return [
            'no certificate'     => ['', '/etc/aa/client.key'],
            'NUL in certificate' => ["/etc/aa/client.pem\0.txt", ''],
            'NUL in key'         => ['/etc/aa/client.pem', "/etc/aa/client.key\0"],
        ];
    }

    // never null: OpenSSL would ask for a missing one on stdin
    public function test_passphrase_emptyWhenNone(): void
    {
        self::assertSame('', (new ClientCertificate('/etc/aa/client.pem'))->passphrase());
        self::assertSame(self::PASSPHRASE, (new ClientCertificate('/etc/aa/client.pem', passphrase: new Secret(self::PASSPHRASE)))->passphrase());
    }

    /**
     * What would otherwise fail inside the TLS handshake, where it looks like the network
     * @param ?string $at 'from' or 'until': a second before the validity starts, or after it ends
     */
    #[DataProvider('problems')]
    public function test_problem_beforeTheHandshake(string $certificate, string $key, string $passphrase, ?string $at, ?string $expected): void
    {
        $file   = static fn(string $name): string => ($name === '') ? '' : self::$dir . "/$name";
        $client = new ClientCertificate($file($certificate), $file($key), new Secret($passphrase));
        $now    = match ($at) {
            'from'  => self::$from - 1,
            'until' => self::$until + 1,
            default => self::$from + 86400,
        };

        self::assertSame($expected, $client->problem($now));
        self::assertFalse(openssl_error_string(), 'no error of OpenSSL left for the next caller');
    }

    public static function problems(): array
    {
        return [
            'one file'             => ['both.pem', '', '', null, null],
            'key apart'            => ['cert.pem', 'key.pem', '', null, null],
            'encrypted key'        => ['cert.pem', 'encrypted.key', self::PASSPHRASE, null, null],
            'a chain, CRLF'        => ['chain.pem', '', '', null, null],
            'no file'              => ['none.pem', '', '', null, ClientCertificate::UNREADABLE],
            'no key file'          => ['cert.pem', 'none.key', '', null, ClientCertificate::KEY_UNREADABLE],
            'not a certificate'    => ['garbage.pem', 'key.pem', '', null, ClientCertificate::NOT_A_CERTIFICATE],
            'no key'               => ['cert.pem', '', '', null, ClientCertificate::KEY_LOCKED],
            'no passphrase'        => ['cert.pem', 'encrypted.key', '', null, ClientCertificate::KEY_LOCKED],
            'another passphrase'   => ['cert.pem', 'encrypted.key', 'Another-phrase', null, ClientCertificate::KEY_LOCKED],
            'another key'          => ['cert.pem', 'other.key', '', null, ClientCertificate::KEY_MISMATCH],
            'RSA of 2048 bits'     => ['rsa.pem', '', '', null, null],
            'RSA of 1024 bits'     => ['weak.pem', '', '', null, ClientCertificate::WEAK_KEY],
            'RSA-PSS of 1024 bits' => ['pss.pem', '', '', null, ClientCertificate::WEAK_KEY],
            'RSA-PSS of 2048 bits' => ['pss2048.pem', '', '', null, null],
            'a file of 1 MiB'      => ['limit.pem', '', '', null, null],
            'a huge file'          => ['huge.pem', '', '', null, ClientCertificate::NOT_A_CERTIFICATE],
            'a huge key file'      => ['cert.pem', 'huge.pem', '', null, ClientCertificate::KEY_LOCKED],
            'a FIFO'               => ['fifo.pem', '', '', null, ClientCertificate::UNREADABLE],
            'a FIFO for the key'   => ['cert.pem', 'fifo.pem', '', null, ClientCertificate::KEY_UNREADABLE],
            'not valid yet'        => ['both.pem', '', '', 'from', ClientCertificate::NOT_YET_VALID],
            'expired'              => ['both.pem', '', '', 'until', ClientCertificate::EXPIRED],
        ];
    }

    // 256 bits of Ed25519 are no weak key; PHP 8.4 makes one
    public function test_problem_edDsaIsNotWeak(): void
    {
        if (!defined('OPENSSL_KEYTYPE_ED25519')) {
            self::markTestSkipped('PHP before 8.4 makes no Ed25519 key');
        }
        $key     = openssl_pkey_new(['private_key_type' => constant('OPENSSL_KEYTYPE_ED25519')]);
        $request = ($key === false) ? false : openssl_csr_new(['commonName' => 'AA test Ed25519'], $key, ['digest_alg' => 'null']);
        $x509    = (($key === false) || ($request === false)) ? false : openssl_csr_sign($request, null, $key, 400, ['digest_alg' => 'null'], 47);
        self::assertNotFalse($x509);
        openssl_x509_export($x509, $certificate);
        openssl_pkey_export($key, $private);

        $file = Certificates::file(self::$dir, 'ed25519.pem', $certificate . $private);

        self::assertNull((new ClientCertificate($file))->problem());
    }

    // what OpenSSL refuses under its default policy; PHP 8.2 calls RSA-PSS and EdDSA EC without a curve
    #[DataProvider('keyKinds')]
    public function test_weak_byKindAndBits(string $kind, bool $weak): void
    {
        $pem = match ($kind) {
            'rsa1024'     => Certificates::make('RSA', 50, 400, 1024)[1],
            'rsa2048'     => Certificates::make('RSA', 51, 400, 2048)[1],
            'rsa-pss1024' => Certificates::pss(1024)[1],
            'ec'          => Certificates::make()[1],
            default       => Certificates::edDsa($kind),
        };
        $key = openssl_pkey_get_private($pem, '');
        self::assertNotFalse($key);

        self::assertSame($weak, ClientCertificate::weak($key));
    }

    public static function keyKinds(): array
    {
        return [
            'RSA of 1024 bits'     => ['rsa1024', true],
            'RSA of 2048 bits'     => ['rsa2048', false],
            'RSA-PSS of 1024 bits' => ['rsa-pss1024', true],
            'EC of 256 bits'       => ['ec', false],
            'Ed25519'              => ['ed25519', false],
            'Ed448'                => ['ed448', false],
        ];
    }

    // a FIFO nobody writes to: opening it would wait for ever
    public function test_details_neverOpensAFifo(): void
    {
        if (!function_exists('pcntl_alarm') || !function_exists('posix_mkfifo')) {
            self::markTestSkipped('needs pcntl and posix');
        }
        $fifo = self::$dir . '/details.fifo';
        posix_mkfifo($fifo, 0o600);
        $woken = 0;
        $async = pcntl_async_signals(true);
        // no restart: the alarm breaks a waiting open() instead of letting it wait again
        pcntl_signal(SIGALRM, static function () use (&$woken): void {
            $woken++;
        }, false);
        $certificate = new ClientCertificate($fifo);
        $read        = [];
        try {
            foreach (['details', 'fingerprint'] as $method) {
                pcntl_alarm(1);
                $read[] = $certificate->$method();
                pcntl_alarm(0);
            }
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_async_signals($async);
            unlink($fifo);
        }

        self::assertSame([null, null], $read);
        self::assertSame(0, $woken, 'the FIFO was opened');
    }

    // a file that is there, but PHP may not read it
    public function test_problem_unreadableFile(): void
    {
        if (function_exists('posix_geteuid') && (posix_geteuid() === 0)) {
            self::markTestSkipped('root reads any file');
        }

        self::assertSame(ClientCertificate::UNREADABLE, (new ClientCertificate(self::$dir . '/locked.pem'))->problem());
        self::assertSame(ClientCertificate::KEY_UNREADABLE, (new ClientCertificate(self::$dir . '/cert.pem', self::$dir . '/locked.pem'))->problem());
    }

    // a refusal of a certificate lasts until a new certificate, whatever else changes in the file
    public function test_fingerprint_ofTheCertificateItself(): void
    {
        $of = static fn(string $name): ?string => (new ClientCertificate(self::$dir . "/$name"))->fingerprint();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', (string) $of('cert.pem'));
        self::assertSame($of('cert.pem'), $of('both.pem'));
        self::assertSame($of('cert.pem'), $of('chain.pem'), 'a chain after it, other line ends');
        self::assertNotSame($of('cert.pem'), $of('another.pem'));
        self::assertNull($of('garbage.pem'));
        self::assertNull($of('huge.pem'), 'not read at all');
        self::assertNull($of('none.pem'));
        self::assertFalse(openssl_error_string());
    }
}
