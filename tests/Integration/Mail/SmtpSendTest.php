<?php

declare(strict_types=1);

namespace Tests\Integration\Mail;

use AA\Mail\SmtpTransport;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for the SMTP send path.
 *
 * Each test forks: the child becomes a minimal fake SMTP server bound to a
 * random localhost port; the parent points AA_SMTP_* at it and drives a real
 * send through the production code (htmlMimeMail::send() or
 * SmtpTransport::sendRaw()). The child records the full wire dialogue --
 * commands + DATA payload -- which the parent then asserts against.
 *
 * No credentials, no network, no TLS: AA_SMTP_ENCRYPTION='none' exercises the
 * cleartext path (connect -> EHLO -> MAIL FROM -> RCPT TO -> DATA -> QUIT).
 * TLS/auth correctness lives in the unit tests; this proves the protocol wiring
 * and the htmlMimeMail -> RFC822 assembly that feeds it.
 *
 * Separate processes isolate the per-test AA_SMTP_PORT constant (the port is
 * only known after binding, and constants cannot be redefined).
 */
#[RunTestsInSeparateProcesses]
final class SmtpSendTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required for the fake-SMTP-server fork');
        }
    }

    public function test_full_send_via_smtp_path_writes_correct_envelope_and_data(): void
    {
        $run = $this->runWithFakeSmtp(
            [], // default 2xx responses everywhere
            static function (string $ip, int $port): int {
                define('AA_SMTP_HOST', $ip);
                define('AA_SMTP_PORT', $port);
                define('AA_SMTP_ENCRYPTION', SmtpTransport::ENC_NONE);
                define('AA_SMTP_TIMEOUT', 3);
                define('AA_MAIL_TRANSPORT', SmtpTransport::TRANSPORT_SMTP);

                require_once dirname(__DIR__, 3) . '/include/htmlMimeMail/htmlMimeMail.php';

                $mail = new \htmlMimeMail();
                $mail->setReturnPath('sender@example.org');
                $mail->setFrom('Sender <sender@example.org>');
                $mail->setSubject('Integration test subject');
                $mail->setCc('cc@example.org');
                $mail->setBcc('bcc@example.org');
                $mail->setText("Hello over SMTP.\nSecond line.");

                return $mail->send(['to@example.org']);
            },
        );

        // htmlMimeMail::send() returns the recipient count on success.
        $this->assertSame(1, $run['result']);

        $transcript = $run['transcript'];

        // Envelope: MAIL FROM uses the return_path; all three RCPTs are present.
        $this->assertStringContainsString('MAIL FROM:<sender@example.org>', $transcript);
        $this->assertStringContainsString('RCPT TO:<to@example.org>', $transcript);
        $this->assertStringContainsString('RCPT TO:<cc@example.org>', $transcript);
        $this->assertStringContainsString('RCPT TO:<bcc@example.org>', $transcript);

        // DATA payload: To/Subject/Date/Message-ID synthesized, body present,
        // and Bcc is NOT on the wire (envelope-only).
        $data = $this->dataPayload($transcript);
        $this->assertMatchesRegularExpression('/^To: .*to@example\.org/mi', $data);
        $this->assertMatchesRegularExpression('/^Subject: Integration test subject/mi', $data);
        $this->assertMatchesRegularExpression('/^Date: /mi', $data);
        $this->assertMatchesRegularExpression('/^Message-ID: <.+@.+>/mi', $data);
        $this->assertStringContainsString('Hello over SMTP.', $data);
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $data);
        $this->assertStringNotContainsString('bcc@example.org', $data);
    }

    public function test_sendraw_happy_path_returns_sent(): void
    {
        $run = $this->runWithFakeSmtp(
            [],
            static function (string $ip, int $port): int {
                define('AA_SMTP_HOST', $ip);
                define('AA_SMTP_PORT', $port);
                define('AA_SMTP_ENCRYPTION', SmtpTransport::ENC_NONE);
                define('AA_SMTP_TIMEOUT', 3);

                return (new SmtpTransport())->sendRaw(
                    'from@example.org',
                    ['to@example.org'],
                    "Subject: x\r\n\r\nbody",
                );
            },
        );

        $this->assertSame(SmtpTransport::RESULT_SENT, $run['result']);
        $this->assertStringContainsString('MAIL FROM:<from@example.org>', $run['transcript']);
    }

    public function test_sendraw_maps_4xx_to_transient(): void
    {
        $run = $this->runWithFakeSmtp(
            ['mail' => '451 4.3.0 Temporary local problem, try later'],
            static function (string $ip, int $port): int {
                define('AA_SMTP_HOST', $ip);
                define('AA_SMTP_PORT', $port);
                define('AA_SMTP_ENCRYPTION', SmtpTransport::ENC_NONE);
                define('AA_SMTP_TIMEOUT', 3);

                return (new SmtpTransport())->sendRaw(
                    'from@example.org',
                    ['to@example.org'],
                    "Subject: x\r\n\r\nbody",
                );
            },
        );

        $this->assertSame(SmtpTransport::RESULT_TRANSIENT, $run['result']);
    }

    public function test_sendraw_maps_5xx_to_permanent(): void
    {
        $run = $this->runWithFakeSmtp(
            ['rcpt' => '550 5.1.1 No such user here'],
            static function (string $ip, int $port): int {
                define('AA_SMTP_HOST', $ip);
                define('AA_SMTP_PORT', $port);
                define('AA_SMTP_ENCRYPTION', SmtpTransport::ENC_NONE);
                define('AA_SMTP_TIMEOUT', 3);

                return (new SmtpTransport())->sendRaw(
                    'from@example.org',
                    ['to@example.org'],
                    "Subject: x\r\n\r\nbody",
                );
            },
        );

        $this->assertSame(SmtpTransport::RESULT_PERMANENT, $run['result']);
    }

    /**
     * Fork a fake SMTP server (child) and run $client (parent), returning
     * ['result' => mixed, 'transcript' => string].
     *
     * @param array<string,string> $responses overrides keyed 'mail'|'rcpt'|'data_end'
     * @param callable(string,int):mixed $client receives (ip, port), runs in the parent
     * @return array{result:mixed,transcript:string}
     */
    private function runWithFakeSmtp(array $responses, callable $client): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "fake SMTP listen failed: $errstr ($errno)");
        $name = stream_socket_get_name($server, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $transcriptFile = tempnam(sys_get_temp_dir(), 'aasmtp');

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork failed');

        if ($pid === 0) {
            // CHILD: serve exactly one connection, then exit hard so PHPUnit
            // teardown never runs in the forked process.
            $conn = @stream_socket_accept($server, 10);
            $transcript = $conn ? self::serve($conn, $responses) : '';
            if ($conn) {
                fclose($conn);
            }
            file_put_contents($transcriptFile, $transcript);
            exit(0);
        }

        // PARENT: drive the client, reap the child, collect the transcript.
        $result = $client('127.0.0.1', $port);
        pcntl_waitpid($pid, $status);
        fclose($server);

        $transcript = (string) file_get_contents($transcriptFile);
        @unlink($transcriptFile);

        return ['result' => $result, 'transcript' => $transcript];
    }

    /**
     * Minimal SMTP server: greets, accepts EHLO (advertising nothing -- no
     * STARTTLS, no AUTH), and answers MAIL/RCPT/DATA/QUIT. Returns everything
     * the client sent (commands + DATA payload) as one string.
     *
     * @param resource             $conn
     * @param array<string,string> $responses
     */
    private static function serve($conn, array $responses): string
    {
        $transcript = '';
        $send = static function (string $line) use ($conn): void {
            fwrite($conn, $line . "\r\n");
        };

        $send('220 fake ESMTP ready');
        $inData = false;

        while (($line = fgets($conn, 8192)) !== false) {
            $transcript .= $line;
            $trimmed     = rtrim($line, "\r\n");

            if ($inData) {
                if ($trimmed === '.') {
                    $inData = false;
                    $send($responses['data_end'] ?? '250 2.0.0 Queued');
                }
                continue;
            }

            switch (strtoupper(substr($trimmed, 0, 4))) {
                case 'EHLO':
                case 'HELO':
                    $send('250-fake.localhost');
                    $send('250 HELP');
                    break;
                case 'MAIL':
                    $send($responses['mail'] ?? '250 2.1.0 Ok');
                    break;
                case 'RCPT':
                    $send($responses['rcpt'] ?? '250 2.1.5 Ok');
                    break;
                case 'DATA':
                    $send('354 End data with <CR><LF>.<CR><LF>');
                    $inData = true;
                    break;
                case 'QUIT':
                    $send('221 2.0.0 Bye');
                    break 2;
                default:
                    $send('250 2.0.0 Ok');
                    break;
            }
        }

        return $transcript;
    }

    /** Extract the bytes the client sent after the DATA command. */
    private function dataPayload(string $transcript): string
    {
        $marker = "DATA\r\n";
        $pos    = stripos($transcript, $marker);
        return $pos === false ? '' : substr($transcript, $pos + strlen($marker));
    }
}
