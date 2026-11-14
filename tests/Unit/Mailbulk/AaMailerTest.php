<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk;

use AA\Mail\PermanentSmtpException;
use AA\Mail\SmtpTransport;
use AA\Mail\TransientSmtpException;
use AA\Mailbulk\AaMailer;
use AA\Mailbulk\Message;
use AA\Mailbulk\Outcome;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;
use Tests\Unit\Mail\Fixtures\ScriptedSmtpTransport;
use Tests\Unit\Mail\Fixtures\ScriptedSocket;

// the config constants and the DB stand-in come first - include/mail.php boots the legacy code
require_once dirname(__DIR__) . '/Legacy/fixtures/object_form_fixtures.php';
require_once dirname(__DIR__, 3) . '/include/mail.php';
require_once dirname(__DIR__) . '/Mail/Fixtures/ScriptedSmtp.php';

/** AA_Mail that answers a send as the test scripts it, or hands the mail() arguments over; images come from memory. */
final class ScriptedAaMail extends \AA_Mail
{
    /** what sendOnce() answers - a count or an exception; null = the real send over the mail() seam */
    public static int|\Throwable|null $answer = null;

    public static int $fetches = 0;

    /** @var array<string, string> */
    public static array $captured = [];

    /** the SMTP server, when AA_MAIL_TRANSPORT is smtp */
    public static ?ScriptedSocket $server = null;

    public function sendOnce(array $recipients): int
    {
        if (self::$answer instanceof \Throwable) {
            throw self::$answer;
        }
        return self::$answer ?? parent::sendOnce($recipients);
    }

    public function getFile($filename): string
    {
        ++self::$fetches;
        return 'GIF89a';
    }

    protected function nativeMail(string $to, string $subject, string $body, string $headers, string $params = ''): bool
    {
        self::$captured = compact('to', 'subject', 'body', 'headers', 'params');
        return true;
    }

    protected function smtpTransport(): SmtpTransport
    {
        return new ScriptedSmtpTransport(self::$server ?? throw new \LogicException('No server scripted'));
    }
}

/** One prepared message, one send, one outcome - and a mail the server may have taken is not offered again. */
final class AaMailerTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingDb::reset();
        $GLOBALS['spareDBs']     = [new RecordingDb(), new RecordingDb()];
        ScriptedAaMail::$answer  = null;
        ScriptedAaMail::$fetches = 0;
        ScriptedAaMail::$captured = [];
        ScriptedAaMail::$server   = null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
    }

    private static function mailer(): AaMailer
    {
        return new AaMailer(static fn(): \AA_Mail => new ScriptedAaMail());
    }

    /** @param array<string, string> $headers */
    private static function message(string $html = '<p>Hello</p>', array $headers = [], string $subject = 'News', string $from = 'News <news@example.org>'): Message
    {
        return new Message('reader@example.org', $subject, $html, $from, $headers);
    }

    private static function sendAnswered(int|\Throwable $answer): array
    {
        $mailer = self::mailer();
        $mailer->prepare(self::message());
        ScriptedAaMail::$answer = $answer;
        return [$mailer->send(), $mailer->detail()];
    }

    public function test_send_ok_isSent(): void
    {
        self::assertSame(Outcome::Sent, self::sendAnswered(1)[0]);
    }

    /** mail() returning false: the local MTA did not take it, nothing went out */
    public function test_send_zeroAccepted_isRetry(): void
    {
        self::assertSame(Outcome::Retry, self::sendAnswered(0)[0]);
    }

    public function test_send_permanentForThisMail_isFailed(): void
    {
        [$outcome, $detail] = self::sendAnswered(new PermanentSmtpException('SmtpTransport[rcptTo] 550: no such user'));

        self::assertSame(Outcome::Failed, $outcome);
        self::assertStringContainsString('550', $detail);
    }

    public function test_send_permanentSendingWide_isHalt(): void
    {
        self::assertSame(Outcome::Halt, self::sendAnswered(new PermanentSmtpException('SmtpTransport[auth] 535: bad login', sendingWide: true))[0]);
    }

    public function test_send_transientForThisMail_isDeferred(): void
    {
        [$outcome, $detail] = self::sendAnswered(new TransientSmtpException('SmtpTransport[rcptTo] 451: try later'));

        self::assertSame(Outcome::Deferred, $outcome);
        self::assertStringContainsString('451', $detail);
    }

    public function test_send_transientSendingWide_isRetry(): void
    {
        self::assertSame(Outcome::Retry, self::sendAnswered(new TransientSmtpException('SmtpTransport[connect] -1: no answer', sendingWide: true))[0]);
    }

    public function test_send_transientAfterBody_isUncertain(): void
    {
        self::assertSame(Outcome::Uncertain, self::sendAnswered(new TransientSmtpException('SmtpTransport[data] -1: no answer', afterBody: true))[0]);
    }

    public function test_send_otherThrowable_isHalt(): void
    {
        [$outcome, $detail] = self::sendAnswered(new \RuntimeException('SmtpTransport: AA_SMTP_HOST is not configured'));

        self::assertSame(Outcome::Halt, $outcome);
        self::assertStringContainsString('AA_SMTP_HOST', $detail);
    }

    /** @return array{Outcome, list<array<string, mixed>>} the outcome and what was queued, over the real AA_Mail::sendOnce() */
    private static function sendOverSmtp(ScriptedSocket $server): array
    {
        define('AA_MAIL_TRANSPORT', SmtpTransport::TRANSPORT_SMTP);
        define('AA_SMTP_HOST', 'smtp.example.org');
        define('AA_SMTP_ENCRYPTION', SmtpTransport::ENC_NONE);
        ScriptedAaMail::$server = $server;
        $mailer                 = self::mailer();
        $mailer->prepare(self::message());
        return [$mailer->send(), RecordingDb::inserts('toexecute')];
    }

    #[RunInSeparateProcess]
    public function test_send_smtpRefusedForNow_isDeferredAndQueuesNothing(): void
    {
        [$outcome, $queued] = self::sendOverSmtp(ScriptedSocket::server(0, '250 ok', '450 4.2.0 greylisted'));

        self::assertSame(Outcome::Deferred, $outcome);
        self::assertSame([], $queued);
    }

    #[RunInSeparateProcess]
    public function test_send_smtpAnswerLostAfterTheBody_isUncertainAndQueuesNothing(): void
    {
        [$outcome, $queued] = self::sendOverSmtp(ScriptedSocket::server(0, '250 ok', '250 ok', '354 go ahead'));

        self::assertSame(Outcome::Uncertain, $outcome);
        self::assertSame([], $queued);
    }

    #[RunInSeparateProcess]
    public function test_send_smtpSenderRefused_isHalt(): void
    {
        self::assertSame(Outcome::Halt, self::sendOverSmtp(ScriptedSocket::server(0, '553 5.7.1 sender not allowed'))[0]);
    }

    #[RunInSeparateProcess]
    public function test_maxSeconds_smtp_coversEveryWaitWithItsTimeout(): void
    {
        define('AA_MAIL_TRANSPORT', SmtpTransport::TRANSPORT_SMTP);
        define('AA_SMTP_TIMEOUT', 10);

        self::assertSame(15 * 11, self::mailer()->maxSeconds());
    }

    public function test_maxSeconds_mail_isAMinute(): void
    {
        self::assertSame(60, self::mailer()->maxSeconds());
    }

    public function test_prepare_buildsWithImages_sendFetchesNothing(): void
    {
        $mailer = self::mailer();
        $mailer->prepare(self::message('<p>Hello <img src="https://img.example.org/' . uniqid('mailer', true) . '.gif"></p>'));
        self::assertSame(1, ScriptedAaMail::$fetches);

        self::assertSame(Outcome::Sent, $mailer->send());
        self::assertSame(1, ScriptedAaMail::$fetches);
        self::assertStringContainsString('Content-ID', ScriptedAaMail::$captured['body']);
        self::assertSame('accepted for delivery', $mailer->detail());
    }

    public function test_prepare_setsTheGivenHeaders(): void
    {
        $mailer = self::mailer();
        $mailer->prepare(self::message(headers: ['List-Unsubscribe' => '<https://example.org/u?c=1>', 'X-Mailbulk' => 'r1']));
        $mailer->send();

        $headers = ScriptedAaMail::$captured['headers'];
        self::assertStringContainsString('List-Unsubscribe: <https://example.org/u?c=1>', $headers);
        self::assertStringContainsString('X-Mailbulk: r1', $headers);
        self::assertStringContainsString('From: News <news@example.org>', $headers);
        self::assertStringContainsString('Reply-To: News <news@example.org>', $headers);
        self::assertStringContainsString('Errors-To: News <news@example.org>', $headers);
        self::assertSame('-fnews@example.org', ScriptedAaMail::$captured['params']);
        self::assertSame('reader@example.org', ScriptedAaMail::$captured['to']);
    }

    public function test_prepare_subjectAndFromWithALineBreak_addNoHeader(): void
    {
        $mailer = self::mailer();
        $mailer->prepare(self::message(subject: "News\r\nBcc: x@y.cz", from: "news@example.org\r\nBcc: x@y.cz"));
        $mailer->send();

        self::assertDoesNotMatchRegularExpression('/^Bcc:/mi', ScriptedAaMail::$captured['headers']);
        self::assertStringNotContainsString("\n", ScriptedAaMail::$captured['subject']);
    }

    public function test_prepare_emptyBody_isSkipped(): void
    {
        $mailer = self::mailer();
        $mailer->prepare(self::message(' '));

        self::assertSame(Outcome::Skipped, $mailer->send());
        self::assertSame([], ScriptedAaMail::$captured);
    }

    public function test_send_twice_isRefused(): void
    {
        $mailer = self::mailer();
        $mailer->prepare(self::message());
        $mailer->send();

        $this->expectException(\LogicException::class);
        $mailer->send();
    }
}
