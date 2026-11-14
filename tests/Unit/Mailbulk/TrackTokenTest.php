<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk;

use AA\Mailbulk\TrackToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A tracked address may only lead where the mail pointed and count what the mail sent. */
final class TrackTokenTest extends TestCase
{
    private const LIST   = 'aB3x9';
    private const ITEM   = '0123456789abcdef0123456789abcdef';
    private const READER = 'fedcba9876543210fedcba9876543210';
    private const URL    = 'https://example.org/news/?a=1&b=%C3%A1#part';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // DB_PASSWORD, DB_NAME for Secret::key()
    }

    public function test_makeParse_roundTrip(): void
    {
        $click = TrackToken::parse(TrackToken::make(self::LIST, strtoupper(self::ITEM), self::READER, TrackToken::CLICK, self::URL));
        $this->assertNotNull($click);
        $this->assertSame([self::LIST, self::ITEM, self::READER, 'c', self::URL], [$click->list, $click->itemId, $click->readerId, $click->kind, $click->url]);

        $open = TrackToken::parse(TrackToken::make(self::LIST, self::ITEM, self::READER, TrackToken::OPEN));
        $this->assertNotNull($open);
        $this->assertSame([self::LIST, self::ITEM, self::READER, 'o', ''], [$open->list, $open->itemId, $open->readerId, $open->kind, $open->url]);
    }

    public function test_parse_tampered_isNull(): void
    {
        $token = TrackToken::make(self::LIST, self::ITEM, self::READER, TrackToken::CLICK, self::URL);
        $raw   = base64_decode(strtr($token, '-_', '+/'));
        for ($i = 0; $i < strlen($raw); $i++) {
            $bad = $raw;
            $bad[$i] = chr(ord($bad[$i]) ^ 1);
            $this->assertNull(TrackToken::parse(rtrim(strtr(base64_encode($bad), '+/', '-_'), '=')), "byte $i flipped");
        }
        $this->assertNull(TrackToken::parse(substr($token, 0, -1)), 'cut short');
        $this->assertNull(TrackToken::parse($token . 'A'), 'extended');
    }

    /** @return array<string, array{string}> */
    public static function garbage(): array
    {
        return [
            'empty'     => [''],
            'not b64'   => ['!!!***'],
            'short'     => ['AAAA'],
            'zeros'     => [str_repeat('A', 80)],
            'padded'    => [base64_encode(str_repeat("\0", 60))],
        ];
    }

    #[DataProvider('garbage')]
    public function test_parse_garbage_isNull(string $token): void
    {
        $this->assertNull(TrackToken::parse($token));
    }

    /** @return array<string, array{string, string}> */
    public static function refused(): array
    {
        return [
            'javascript'   => [TrackToken::CLICK, 'javascript:alert(1)'],
            'data'         => [TrackToken::CLICK, 'data:text/html,x'],
            'relative'     => [TrackToken::CLICK, '//evil.example/'],
            'mailto'       => [TrackToken::CLICK, 'mailto:a@example.org'],
            'scheme only'  => [TrackToken::CLICK, 'https://'],
            'header break' => [TrackToken::CLICK, "https://example.org/\r\nSet-Cookie: x=1"],
            'click no url' => [TrackToken::CLICK, ''],
            'open with url' => [TrackToken::OPEN, self::URL],
            'unknown kind' => ['x', ''],
        ];
    }

    #[DataProvider('refused')]
    public function test_make_click_rejectsNonHttpUrl(string $kind, string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TrackToken::make(self::LIST, self::ITEM, self::READER, $kind, $url);
    }

    public function test_make_badIds_rejected(): void
    {
        foreach ([['', self::ITEM, self::READER], ['toolong7', self::ITEM, self::READER], [self::LIST, 'abc', self::READER], [self::LIST, self::ITEM, str_repeat('g', 32)]] as [$list, $item, $reader]) {
            try {
                TrackToken::make($list, $item, $reader, TrackToken::OPEN);
                $this->fail("accepted $list/$item/$reader");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_token_isUrlSafe(): void
    {
        foreach (['https://example.org/' . str_repeat("\xff\xfe?", 20), 'http://a.example/' . str_repeat('~', 33)] as $url) {
            $token = TrackToken::make(self::LIST, self::ITEM, self::READER, TrackToken::CLICK, $url);
            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $token);
            $this->assertSame($token, rawurlencode($token));
        }
        $this->assertLessThanOrEqual(72, strlen(TrackToken::make(self::LIST, self::ITEM, self::READER, TrackToken::OPEN)), 'goes into every mail');
    }

    /** mails already sent carry tokens in this form: base64url of MAC and data, no padding */
    public function test_token_formOfSentMails_stillParses(): void
    {
        $token = TrackToken::make(self::LIST, self::ITEM, self::READER, TrackToken::CLICK, 'https://example.org/' . str_repeat("\xff\xfe?", 7));
        $raw   = base64_decode(strtr($token, '-_', '+/'), true);

        $this->assertIsString($raw);
        $this->assertSame(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='), $token);
        $this->assertNotNull(TrackToken::parse(base64_encode($raw)), 'the standard alphabet with padding parsed before as well');
    }
}
