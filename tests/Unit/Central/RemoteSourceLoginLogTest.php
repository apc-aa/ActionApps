<?php

declare(strict_types=1);

// RecordingDb (store_fixtures) loads the real locsess.php, which needs the constants include/config.php normally provides.

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Central {

    use AA\Central\RemoteSource;
    use AA\Central\ResponderPolicy;
    use AA\Central\SourceFailed;
    use AA_Response;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\HttpClient\MockHttpClient;
    use Symfony\Component\HttpClient\Response\MockResponse;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * RemoteSource::login(): the login of an admin of this AA to the source - a failure leaves a row in the
     * AA log (the source, the reason, the error number of the source), never the login or a token.
     */
    final class RemoteSourceLoginLogTest extends TestCase
    {
        private const URL = 'https://source.example/aa/central/responder.php';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        private static function remote(string $body, string $url = self::URL): RemoteSource
        {
            return new RemoteSource($url, timeout: 5, transport: new MockHttpClient(new MockResponse($body)), source: '3');
        }

        /** @return list<array<string, mixed>> */
        private static function logRows(): array
        {
            return array_values(array_filter(RecordingDb::inserts('log'), static fn(array $row): bool => $row['type'] === RemoteSource::LOG_DENY));
        }

        public function test_a_refused_login_is_logged_without_the_login(): void
        {
            try {
                self::remote(serialize(new AA_Response('A token needs a login', ResponderPolicy::ERR_TOKEN_NEEDS_PASSWORD)))->login(['source-admin', 'hunter2', '123456']);
                self::fail('no token');
            } catch (SourceFailed $e) {
                self::assertSame(SourceFailed::LOGIN, $e->reason);
            }
            $rows = self::logRows();
            self::assertCount(1, $rows);
            self::assertSame('3', $rows[0]['selector']);
            self::assertSame(SourceFailed::LOGIN . ':' . ResponderPolicy::ERR_TOKEN_NEEDS_PASSWORD, $rows[0]['params']);
            self::assertStringNotContainsString('hunter2', implode("\n", RecordingDb::$log));
            self::assertStringNotContainsString('source-admin', implode("\n", RecordingDb::$log));
        }

        public function test_an_unreachable_source_is_logged_with_its_reason(): void
        {
            try {
                self::remote('', 'http://source.example/aa/central/responder.php')->login(['source-admin', 'pw', '']);
                self::fail('no token');
            } catch (SourceFailed $e) {
                self::assertSame(SourceFailed::UNREACHABLE, $e->reason);
            }
            self::assertSame([['3', SourceFailed::UNREACHABLE . ':']], array_map(static fn(array $row): array => [$row['selector'], $row['params']], self::logRows()));
        }

        public function test_a_login_given_logs_nothing(): void
        {
            $b64   = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $token = $b64('{"typ":"JWT","alg":"HS256"}') . '.' . $b64((string) json_encode(['sub' => '7', 'iat' => time(), 'exp' => time() + 3600, 'scp' => 'apps'])) . '.' . $b64('sig');

            self::assertSame($token, self::remote(serialize(new AA_Response($token)))->login(['source-admin', 'pw', '']));
            self::assertSame([], self::logRows());
        }
    }
}
