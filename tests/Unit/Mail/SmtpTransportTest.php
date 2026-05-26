<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use AA\Mail\SmtpTransport;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the audit-mandated security validators in SmtpTransport.
 *
 * The class is exercised here in isolation -- no real socket connection.
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
            $transport->sendRaw('from@example.org', [], "Subject: x\r\n\r\nbody")
        );
    }

    public function test_send_raw_returns_permanent_on_empty_envelope(): void
    {
        define('AA_SMTP_HOST', 'smtp.example.org');
        $transport = new SmtpTransport();
        $this->assertSame(
            SmtpTransport::RESULT_PERMANENT,
            $transport->sendRaw('', ['to@example.org'], "Subject: x\r\n\r\nbody")
        );
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
