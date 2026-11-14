<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use AA\Mail\SmtpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Mail\Fixtures\ScriptedSocket;
use Tests\Unit\Mail\Fixtures\ScriptedSmtpTransport;

require_once __DIR__ . '/Fixtures/ScriptedSmtp.php';

/**
 * Unit tests for the audit-mandated security validators in SmtpTransport and for what its
 * failures mean, over a scripted socket -- no real connection.
 * End-to-end behavior against a live or fake SMTP server lives under
 * tests/Integration/Mail/.
 *
 * Each test runs in its own PHP process so that the AA_SMTP_* / AA_MAIL_*
 * constants we define cannot leak between tests.
 */
#[RunTestsInSeparateProcesses]
final class SmtpTransportTest extends TestCase
{
    public function test_constructor_rejects_missing_host(): void
    {
        if (!defined('AA_SMTP_HOST')) {
            define('AA_SMTP_HOST', '');
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/AA_SMTP_HOST/');
        new SmtpTransport();
    }

    public function test_constructor_rejects_invalid_encryption(): void
    {
        define('AA_SMTP_HOST', 'smtp.example.org');
        define('AA_SMTP_ENCRYPTION', 'rot13');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/AA_SMTP_ENCRYPTION/');
        new SmtpTransport();
    }

    public function test_constructor_rejects_crlf_in_helo(): void
    {
        define('AA_SMTP_HOST', 'smtp.example.org');
        define('AA_SMTP_HELO', "evil.host\r\nRCPT TO:<attacker@x>");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/helo.*control/');
        new SmtpTransport();
    }

    public function test_constructor_rejects_crlf_in_user(): void
    {
        define('AA_SMTP_HOST', 'smtp.example.org');
        define('AA_SMTP_USER', "user\nAUTH PLAIN AAAA");
        define('AA_SMTP_PASS', 'irrelevant');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/user.*control/');
        new SmtpTransport();
    }

    public function test_constructor_rejects_null_byte_in_pass(): void
    {
        define('AA_SMTP_HOST', 'smtp.example.org');
        define('AA_SMTP_USER', 'user');
        define('AA_SMTP_PASS', "secret\x00extra");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/pass.*control/');
        new SmtpTransport();
    }

    public function test_send_raw_returns_permanent_on_empty_recipients(): void
    {
        define('AA_SMTP_HOST', 'smtp.example.org');
        $transport = new SmtpTransport();
        $this->assertSame(
            SmtpTransport::RESULT_PERMANENT,
            $transport->sendRaw('from@example.org', [], "Subject: x\r\n\r\nbody"),
        );
        $this->assertSame(SmtpTransport::FAILURE_MAIL, $transport->lastFailure());
    }

    public function test_send_raw_returns_permanent_on_empty_envelope(): void
    {
        define('AA_SMTP_HOST', 'smtp.example.org');
        $transport = new SmtpTransport();
        $this->assertSame(
            SmtpTransport::RESULT_PERMANENT,
            $transport->sendRaw('', ['to@example.org'], "Subject: x\r\n\r\nbody"),
        );
        $this->assertSame(SmtpTransport::FAILURE_SENDING, $transport->lastFailure());
    }

    /** @return array<string, array{ScriptedSocket, int, int}> server => result, failure */
    public static function dialogues(): array
    {
        $bodyLost        = ScriptedSocket::server(0, '250 ok', '250 ok', '354 go ahead');
        $bodyBroken      = ScriptedSocket::server(0, '250 ok', '250 ok', '354 go ahead', '250 queued');
        $bodyBroken->failWriteOn = 'Hello reader';
        $dataRefusedLost = ScriptedSocket::server(0, '250 ok', '250 ok');

        return [
            'accepted'                    => [ScriptedSocket::server(0, '250 ok', '250 ok', '354 go ahead', '250 2.0.0 queued as X1', '221 bye'), SmtpTransport::RESULT_SENT, SmtpTransport::FAILURE_NONE],
            'no greeting'                 => [new ScriptedSocket(), SmtpTransport::RESULT_TRANSIENT, SmtpTransport::FAILURE_SENDING],
            'sender refused for now'      => [ScriptedSocket::server(0, '451 4.3.0 try later'), SmtpTransport::RESULT_TRANSIENT, SmtpTransport::FAILURE_SENDING],
            'sender refused'              => [ScriptedSocket::server(0, '553 5.7.1 sender not allowed'), SmtpTransport::RESULT_PERMANENT, SmtpTransport::FAILURE_SENDING],
            'reader greylisted'           => [ScriptedSocket::server(0, '250 ok', '450 4.2.0 greylisted'), SmtpTransport::RESULT_TRANSIENT, SmtpTransport::FAILURE_MAIL],
            'reader unknown'              => [ScriptedSocket::server(0, '250 ok', '550 5.1.1 no such user'), SmtpTransport::RESULT_PERMANENT, SmtpTransport::FAILURE_MAIL],
            'server closing at RCPT'      => [ScriptedSocket::server(0, '250 ok', '421 4.3.2 shutting down'), SmtpTransport::RESULT_TRANSIENT, SmtpTransport::FAILURE_SENDING],
            'answer lost at RCPT'         => [ScriptedSocket::server(0, '250 ok'), SmtpTransport::RESULT_TRANSIENT, SmtpTransport::FAILURE_SENDING],
            'DATA refused'                => [ScriptedSocket::server(0, '250 ok', '250 ok', '554 5.5.1 no valid recipients'), SmtpTransport::RESULT_PERMANENT, SmtpTransport::FAILURE_MAIL],
            'message refused'             => [ScriptedSocket::server(0, '250 ok', '250 ok', '354 go ahead', '554 5.7.1 spam'), SmtpTransport::RESULT_PERMANENT, SmtpTransport::FAILURE_MAIL],
            'message refused for now'     => [ScriptedSocket::server(0, '250 ok', '250 ok', '354 go ahead', '451 4.7.1 try later'), SmtpTransport::RESULT_TRANSIENT, SmtpTransport::FAILURE_MAIL],
            'server closing after body'   => [ScriptedSocket::server(0, '250 ok', '250 ok', '354 go ahead', '421 4.3.2 shutting down'), SmtpTransport::RESULT_TRANSIENT, SmtpTransport::FAILURE_SENDING],
            'answer lost after the body'  => [$bodyLost, SmtpTransport::RESULT_TRANSIENT, SmtpTransport::FAILURE_UNCERTAIN],
            'connection broke in body'    => [$bodyBroken, SmtpTransport::RESULT_TRANSIENT, SmtpTransport::FAILURE_UNCERTAIN],
            'answer to DATA lost'         => [$dataRefusedLost, SmtpTransport::RESULT_TRANSIENT, SmtpTransport::FAILURE_UNCERTAIN],
            'message over the size limit' => [ScriptedSocket::server(20, '250 ok', '250 ok', '354 go ahead', '250 queued'), SmtpTransport::RESULT_PERMANENT, SmtpTransport::FAILURE_SENDING],
        ];
    }

    #[DataProvider('dialogues')]
    public function test_sendRaw_dialogue_tellsWhatTheFailureMeans(ScriptedSocket $server, int $result, int $failure): void
    {
        define('AA_SMTP_HOST', 'smtp.example.org');
        define('AA_SMTP_ENCRYPTION', SmtpTransport::ENC_NONE);
        $transport = new ScriptedSmtpTransport($server);

        $this->assertSame($result, $transport->sendRaw('from@example.org', ['to@example.org'], "Subject: x\r\n\r\nHello reader, a long enough body"));
        $this->assertSame($failure, $transport->lastFailure());
    }

    public function test_sendRaw_overTheSizeLimit_sendsNoData(): void
    {
        define('AA_SMTP_HOST', 'smtp.example.org');
        define('AA_SMTP_ENCRYPTION', SmtpTransport::ENC_NONE);
        $transport = new ScriptedSmtpTransport(ScriptedSocket::server(50, '250 ok', '250 ok', '354 go ahead', '250 queued'));

        $transport->sendRaw('from@example.org', ['to@example.org'], str_repeat('x', 50));

        $this->assertStringNotContainsString('DATA', $transport->socket->wire());
        $this->assertStringContainsString('50 bytes', $transport->getLastError());
    }

    public function test_sendRaw_refusedReply_isTheResponse(): void
    {
        define('AA_SMTP_HOST', 'smtp.example.org');
        define('AA_SMTP_ENCRYPTION', SmtpTransport::ENC_NONE);
        $transport = new ScriptedSmtpTransport(ScriptedSocket::server(0, '250 ok', '550 5.1.1 no such user'));

        $transport->sendRaw('from@example.org', ['to@example.org'], 'body');

        $this->assertStringContainsString('550', $transport->getLastResponse());
        $this->assertStringContainsString('no such user', $transport->getLastResponse());
    }

    public function test_timeout_withoutTheSetting_is15(): void
    {
        $this->assertSame(15, SmtpTransport::timeout());
    }

    public function test_timeout_takesTheSetting(): void
    {
        define('AA_SMTP_TIMEOUT', '7');
        $this->assertSame(7, SmtpTransport::timeout());
    }

    public function test_send_raw_rejects_crlf_in_envelope_from(): void
    {
        define('AA_SMTP_HOST', 'smtp.example.org');
        $transport = new SmtpTransport();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/envelopeFrom.*control/');
        $transport->sendRaw("from@example.org\r\nRCPT TO:<attacker@x>", ['to@x'], 'body');
    }

    public function test_send_raw_rejects_crlf_in_rcpt(): void
    {
        define('AA_SMTP_HOST', 'smtp.example.org');
        $transport = new SmtpTransport();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/rcpt.*control/');
        $transport->sendRaw('from@example.org', ["to@x\nDATA"], 'body');
    }

    public function test_resolve_hostname_prefers_smtp_helo_constant(): void
    {
        define('AA_SMTP_HELO', 'configured.example.org');
        $_SERVER['HTTP_HOST'] = 'overridden.example.com';
        $this->assertSame('configured.example.org', SmtpTransport::resolveHostname());
    }

    public function test_resolve_hostname_falls_back_to_mail_hostname(): void
    {
        define('AA_SMTP_HELO', '');
        define('AA_MAIL_HOSTNAME', 'fallback.example.org');
        $_SERVER['HTTP_HOST'] = 'overridden.example.com';
        $this->assertSame('fallback.example.org', SmtpTransport::resolveHostname());
    }

    public function test_resolve_hostname_falls_back_to_aa_http_domain(): void
    {
        define('AA_SMTP_HELO', '');
        define('AA_MAIL_HOSTNAME', '');
        define('AA_HTTP_DOMAIN', 'https://aa.example.org/some/path/');
        unset($_SERVER['HTTP_HOST']);
        $this->assertSame('aa.example.org', SmtpTransport::resolveHostname());
    }

    public function test_resolve_hostname_falls_back_to_http_host(): void
    {
        define('AA_SMTP_HELO', '');
        define('AA_MAIL_HOSTNAME', '');
        define('AA_HTTP_DOMAIN', '');
        $_SERVER['HTTP_HOST'] = 'web.example.org';
        $this->assertSame('web.example.org', SmtpTransport::resolveHostname());
    }

    public function test_resolve_hostname_final_fallback_is_localhost(): void
    {
        define('AA_SMTP_HELO', '');
        define('AA_MAIL_HOSTNAME', '');
        define('AA_HTTP_DOMAIN', '');
        unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME']);
        $this->assertSame('localhost', SmtpTransport::resolveHostname());
    }

    public function test_result_constants_are_distinct(): void
    {
        $this->assertNotSame(SmtpTransport::RESULT_SENT, SmtpTransport::RESULT_TRANSIENT);
        $this->assertNotSame(SmtpTransport::RESULT_SENT, SmtpTransport::RESULT_PERMANENT);
        $this->assertNotSame(SmtpTransport::RESULT_TRANSIENT, SmtpTransport::RESULT_PERMANENT);
    }
}
