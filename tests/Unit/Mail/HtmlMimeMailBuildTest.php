<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/include/htmlMimeMail/htmlMimeMail.php';

/**
 * Captures the arguments handed to PHP's mail() instead of actually sending,
 * via the nativeMail() seam. Lets us assert the mail() transport's argument
 * assembly with no MTA and no network.
 */
final class CapturingMail extends \htmlMimeMail
{
    /** @var array<string,string> */
    public array $captured = [];

    protected function nativeMail(string $to, string $subject, string $body, string $headers, string $params = ''): bool
    {
        $this->captured = compact('to', 'subject', 'body', 'headers', 'params');
        return true;
    }
}

/**
 * Unit tests for the message-construction logic shared by, and divergent
 * between, the two transports.
 *
 * No sockets: the SMTP payload is built by assembleRfc822() (exercised via
 * reflection) and the mail() arguments by the CapturingMail seam. Separate
 * processes isolate the AA_MAIL_TRANSPORT / CRLF constants between tests.
 */
#[RunTestsInSeparateProcesses]
final class HtmlMimeMailBuildTest extends TestCase
{
    /** Invoke a private/protected method. */
    private function invoke(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    private function readHeaders(object $obj): array
    {
        $ref = new \ReflectionProperty(\htmlMimeMail::class, 'headers');
        $ref->setAccessible(true);
        return $ref->getValue($obj);
    }

    // --- SMTP path: assembleRfc822() -------------------------------------

    public function test_smtp_payload_synthesizes_to_and_subject(): void
    {
        $mail = new \htmlMimeMail();
        $mail->setSubject('Hi there');
        $mail->setText('body text');
        $mail->buildMessage();

        $rfc = $this->invoke($mail, 'assembleRfc822', [['to@example.org']]);

        $this->assertMatchesRegularExpression('/^To: .*to@example\.org/mi', $rfc);
        $this->assertMatchesRegularExpression('/^Subject: Hi there/mi', $rfc);
        $this->assertStringContainsString("\r\n\r\n", $rfc, 'header/body separator present');
        $this->assertStringContainsString('body text', $rfc);
    }

    public function test_smtp_payload_keeps_cc_but_strips_bcc(): void
    {
        $mail = new \htmlMimeMail();
        $mail->setSubject('s');
        $mail->setCc('cc@example.org');
        $mail->setBcc('bcc@example.org');
        $mail->setText('b');
        $mail->buildMessage();

        $rfc = $this->invoke($mail, 'assembleRfc822', [['to@example.org']]);

        $this->assertMatchesRegularExpression('/^Cc: .*cc@example\.org/mi', $rfc);
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $rfc);
        $this->assertStringNotContainsString('bcc@example.org', $rfc);
    }

    public function test_smtp_payload_neutralizes_header_injection(): void
    {
        $mail = new \htmlMimeMail();
        // Attacker-controlled value (e.g. an item field aliased into Subject).
        $mail->setSubject("Real subject\r\nBcc: attacker@evil.com");
        $mail->setText('b');
        $mail->buildMessage();

        $rfc = $this->invoke($mail, 'assembleRfc822', [['to@example.org']]);

        // No forged Bcc header line; the CRLF was folded into the Subject value.
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $rfc);
        $this->assertMatchesRegularExpression('/^Subject: Real subject Bcc: attacker@evil\.com/mi', $rfc);
    }

    public function test_collect_rcpts_merges_to_cc_bcc_deduplicated(): void
    {
        $mail = new \htmlMimeMail();
        $mail->setCc('cc@example.org, shared@example.org');
        $mail->setBcc('bcc@example.org, shared@example.org');

        $rcpts = $this->invoke($mail, 'collectRcpts', [['to@example.org', 'to@example.org']]);

        sort($rcpts);
        $this->assertSame(
            ['bcc@example.org', 'cc@example.org', 'shared@example.org', 'to@example.org'],
            $rcpts
        );
    }

    // --- Shared: Date + Message-ID ---------------------------------------

    public function test_build_message_sets_date_header(): void
    {
        $mail = new \htmlMimeMail();
        $mail->setText('b');
        $mail->buildMessage();

        $headers = $this->readHeaders($mail);
        $this->assertArrayHasKey('Date', $headers);
        $this->assertNotEmpty($headers['Date']);
    }

    public function test_message_id_is_distinct_per_call_and_well_formed(): void
    {
        $mail = new \htmlMimeMail();
        $id1  = $this->invoke($mail, 'generateMessageId');
        $id2  = $this->invoke($mail, 'generateMessageId');

        $this->assertNotSame($id1, $id2, 'a fresh Message-ID per call -- so a sendLater batch does not collapse into one thread');
        $this->assertMatchesRegularExpression('/^<[^@\s]+@[^>\s]+>$/', $id1);
    }

    // --- mail() path: argument assembly via the seam ---------------------

    public function test_mail_path_assembles_args_and_keeps_bcc_in_headers(): void
    {
        // No AA_MAIL_TRANSPORT defined -> send() takes the mail() branch.
        $mail = new CapturingMail();
        $mail->setReturnPath('sender@example.org');
        $mail->setFrom('Sender <sender@example.org>');
        $mail->setSubject('Mail path test');
        $mail->setCc('cc@example.org');
        $mail->setBcc('bcc@example.org');
        $mail->setText('body over mail()');

        $result = $mail->send(['to@example.org']);

        $this->assertSame(1, $result);
        $captured = $mail->captured;
        $this->assertStringContainsString('to@example.org', $captured['to']);
        $this->assertSame('Mail path test', $captured['subject']);
        $this->assertStringContainsString('From: Sender <sender@example.org>', $captured['headers']);
        $this->assertStringContainsString('Cc: cc@example.org', $captured['headers']);
        // mail()-path asymmetry vs SMTP: Bcc rides in the header block (the MTA
        // strips it), it is NOT pulled out into the envelope as on the SMTP path.
        $this->assertStringContainsString('Bcc: bcc@example.org', $captured['headers']);
        // Envelope sender passed via sendmail -f.
        $this->assertSame('-fsender@example.org', $captured['params']);
        $this->assertNotEmpty($captured['body']);
    }
}
