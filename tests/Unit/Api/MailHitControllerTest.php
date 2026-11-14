<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use AA\Api\Controller\MailHitController;
use AA\Mailbulk\Tracker;
use AA\Mailbulk\TrackToken;
use PHPUnit\Framework\TestCase;

/** A Tracker that only lists the tokens it got - or fails when the test says so. */
final class ListingTracker extends Tracker
{
    /** @var list<TrackToken> */
    public array $recorded = [];
    public bool $throws = false;

    public function record(TrackToken $token): bool
    {
        $this->recorded[] = $token;
        if ($this->throws) {
            throw new \RuntimeException('database gone');
        }
        return true;
    }
}

/** A tracked address answers the mail client or the reader whatever the recording does. */
final class MailHitControllerTest extends TestCase
{
    private const LIST   = 'aB3x9';
    private const ITEM   = '0123456789abcdef0123456789abcdef';
    private const READER = 'fedcba9876543210fedcba9876543210';
    private const URL    = 'https://example.org/news/?a=1&b=2';

    private string $log = '';
    private string|false $log_before = false;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // DB_PASSWORD, DB_NAME for Secret::key()
    }

    protected function setUp(): void
    {
        $this->log        = (string) tempnam(sys_get_temp_dir(), 'aa-mailhit');
        $this->log_before = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->log_before);
        @unlink($this->log);
    }

    public function test_answer_badToken_is404_andStoresNothing(): void
    {
        $tracker = new ListingTracker();
        $token   = TrackToken::make(self::LIST, self::ITEM, self::READER, TrackToken::CLICK, self::URL);

        foreach (['', 'garbage', substr($token, 0, -2) . (str_ends_with($token, 'AA') ? 'BB' : 'AA')] as $bad) {
            $answer = (new MailHitController($tracker))->answer($bad);
            $this->assertSame(404, $answer['status'], $bad);
            $this->assertArrayNotHasKey('Location', $answer['headers']);
        }
        $this->assertSame([], $tracker->recorded);
    }

    public function test_answer_open_isGifNoStore(): void
    {
        $tracker = new ListingTracker();

        $answer = (new MailHitController($tracker))->answer(TrackToken::make(self::LIST, self::ITEM, self::READER, TrackToken::OPEN));

        $this->assertSame(200, $answer['status']);
        $this->assertSame('image/gif', $answer['headers']['Content-Type']);
        $this->assertSame('no-store', $answer['headers']['Cache-Control']);
        $this->assertStringStartsWith('GIF89a', $answer['body']);
        $this->assertSame([1, 1], array_slice((array) getimagesizefromstring($answer['body']), 0, 2));
        $this->assertCount(1, $tracker->recorded);
        $this->assertSame([self::LIST, self::ITEM, self::READER, 'o'], [$tracker->recorded[0]->list, $tracker->recorded[0]->itemId, $tracker->recorded[0]->readerId, $tracker->recorded[0]->kind]);
    }

    public function test_answer_click_redirectsToTheUrlOfTheToken(): void
    {
        $tracker = new ListingTracker();

        $answer = (new MailHitController($tracker))->answer(TrackToken::make(self::LIST, self::ITEM, self::READER, TrackToken::CLICK, self::URL));

        $this->assertSame(302, $answer['status']);
        $this->assertSame(self::URL, $answer['headers']['Location']);
        $this->assertSame('no-store', $answer['headers']['Cache-Control']);
        $this->assertCount(1, $tracker->recorded);
        $this->assertSame('c', $tracker->recorded[0]->kind);
    }

    public function test_answer_click_redirectsEvenWhenRecordThrows(): void
    {
        $tracker         = new ListingTracker();
        $tracker->throws = true;
        $controller      = new MailHitController($tracker);

        $click = $controller->answer(TrackToken::make(self::LIST, self::ITEM, self::READER, TrackToken::CLICK, self::URL));
        $open  = $controller->answer(TrackToken::make(self::LIST, self::ITEM, self::READER, TrackToken::OPEN));

        $this->assertSame([302, self::URL], [$click['status'], $click['headers']['Location']]);
        $this->assertSame([200, 'image/gif'], [$open['status'], $open['headers']['Content-Type']]);
        $this->assertStringContainsString('database gone', (string) file_get_contents($this->log), 'the admin finds it in the log');
    }
}
