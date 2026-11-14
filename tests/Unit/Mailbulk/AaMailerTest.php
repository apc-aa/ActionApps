<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk;

use AA\Mail\PermanentSmtpException;
use AA\Mail\TransientSmtpException;
use AA\Mailbulk\AaMailer;
use AA\Mailbulk\Message;
use AA\Mailbulk\Outcome;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

// the config constants and the DB stand-in come first - include/mail.php boots the legacy code
require_once dirname(__DIR__) . '/Legacy/fixtures/object_form_fixtures.php';
require_once dirname(__DIR__, 3) . '/include/mail.php';

/** AA_Mail that answers a send as the test scripts it, or hands the mail() arguments over; images come from memory. */
final class ScriptedAaMail extends \AA_Mail
{
    /** what sendOnce() answers - a count or an exception; null = the real send over the mail() seam */
    public static int|\Throwable|null $answer = null;

    public static int $fetches = 0;

    /** @var array<string, string> */
    public static array $captured = [];

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

    public function test_send_zeroAccepted_isFailed(): void
    {
        self::assertSame(Outcome::Failed, self::sendAnswered(0)[0]);
    }

    public function test_send_permanent_isFailed(): void
    {
        [$outcome, $detail] = self::sendAnswered(new PermanentSmtpException('SmtpTransport[rcptTo] 550: no such user'));

        self::assertSame(Outcome::Failed, $outcome);
        self::assertStringContainsString('550', $detail);
    }

    public function test_send_transientBeforeData_isRetry(): void
    {
        [$outcome, $detail] = self::sendAnswered(new TransientSmtpException('SmtpTransport[rcptTo] 451: try later'));

        self::assertSame(Outcome::Retry, $outcome);
        self::assertStringContainsString('451', $detail);
    }

    public function test_send_transientAtData_isUncertain(): void
    {
        self::assertSame(Outcome::Uncertain, self::sendAnswered(new TransientSmtpException('SmtpTransport[data] 451: timeout', afterBody: true))[0]);
    }

    public function test_send_queuesNothing(): void
    {
        $mailer = self::mailer();
        $mailer->prepare(self::message());
        ScriptedAaMail::$answer = new TransientSmtpException('SmtpTransport[rcptTo] 451: try later');
        $mailer->send();

        self::assertSame([], RecordingDb::inserts('toexecute'));
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

    public function test_message_headerNameWithALineBreak_isRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::message(headers: ["X-A\r\nBcc" => 'x@y.cz']);
    }

    public function test_message_recipientHeader_isRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::message(headers: ['Bcc' => 'x@y.cz']);
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
