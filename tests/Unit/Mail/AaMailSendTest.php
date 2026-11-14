<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use AA\Mail\TransientSmtpException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

// the config constants and the DB stand-in come first - include/mail.php boots the legacy code
require_once dirname(__DIR__) . '/Legacy/fixtures/object_form_fixtures.php';
require_once dirname(__DIR__, 3) . '/include/mail.php';

/** AA_Mail whose send stops on a transient SMTP failure, or hands the mail() arguments over instead of sending. */
final class TransientAaMail extends \AA_Mail
{
    public bool $transient = false;

    /** @var array<string, string> */
    public array $captured = [];

    // the first thing send() does after the build - a failure here is one of the whole send
    protected function generateMessageId(): string
    {
        if ($this->transient) {
            throw new TransientSmtpException('SmtpTransport[rcptTo] 451: try later');
        }
        return parent::generateMessageId();
    }

    protected function nativeMail(string $to, string $subject, string $body, string $headers, string $params = ''): bool
    {
        $this->captured = compact('to', 'subject', 'body', 'headers', 'params');
        return true;
    }
}

/** AA_Mail::send() as all its callers use it: a transient failure goes to the queue, a subject adds no header. */
final class AaMailSendTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
    }

    private static function mail(string $subject): TransientAaMail
    {
        $mail = new TransientAaMail();
        $mail->setFromArray([
            'subject' => $subject, 'body' => '<p>Hello</p>', 'header_from' => 'news@example.org', 'reply_to' => '',
            'errors_to' => '', 'sender' => '', 'lang' => 'utf-8', 'html' => 1, 'cc' => '', 'bcc' => '', 'attachments' => '',
        ]);
        return $mail;
    }

    public function test_send_transient_returnsZeroAndQueuesARetry(): void
    {
        $mail            = self::mail('News');
        $mail->transient = true;

        self::assertSame(0, $mail->send(['reader@example.org']));

        $queued = RecordingDb::inserts('toexecute');
        self::assertCount(1, $queued);
        self::assertStringStartsWith('AA_Mail_retry:', (string) $queued[0]['selector']);
        self::assertStringEndsWith(':1', (string) $queued[0]['selector']);
        self::assertSame([['reader@example.org']], unserialize((string) $queued[0]['params']));
    }

    public function test_send_subjectWithALineBreak_addsNoHeader(): void
    {
        $mail = self::mail("A\r\nBcc: x@y.cz");

        self::assertSame(1, $mail->send(['reader@example.org']));

        self::assertDoesNotMatchRegularExpression('/^Bcc:/mi', $mail->captured['headers']);
        self::assertStringNotContainsString("\n", $mail->captured['subject']);
        self::assertSame([], RecordingDb::inserts('toexecute'));
    }

    public function test_send_notAList_sendsNothing(): void
    {
        $mail = self::mail('News');

        self::assertSame(0, $mail->send('reader@example.org'));
        self::assertSame([], $mail->captured);
    }
}
