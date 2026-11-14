<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk;

use AA\Mailbulk\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A header from item fields may neither break into the header block nor replace what the mail sets itself. */
final class MessageTest extends TestCase
{
    /** @param array<string, string> $headers */
    private static function message(array $headers = [], string $to = 'reader@example.org'): Message
    {
        return new Message($to, 'News', '<p>Hello</p>', 'news@example.org', $headers);
    }

    /** @return array<string, array{string}> */
    public static function refusedNames(): array
    {
        $names = [
            'to', 'Cc', 'BCC', 'From', 'Subject', 'Sender', 'Reply-To', 'Errors-To', 'Return-Path', 'MIME-Version', 'Message-ID', 'Date',
            'Resent-To', 'resent-bcc', 'Content-Type', 'content-transfer-encoding',
            "X-A\r\nBcc", "X-Mailbulk\n", "X-A\x00", 'X A', 'X:A', '-X', '',
        ];
        return array_combine(array_map('json_encode', $names), array_map(static fn(string $n): array => [$n], $names));
    }

    #[DataProvider('refusedNames')]
    public function test_construct_header_isRefused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::message([$name => 'x@y.cz']);
    }

    public function test_construct_ownHeaders_areKept(): void
    {
        $headers = ['List-Unsubscribe' => '<https://example.org/u?c=1>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click', 'X-Mailbulk' => 'r1'];

        self::assertSame($headers, self::message($headers)->headers);
    }

    public function test_construct_twoRecipients_areRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::message(to: 'a@example.org, b@example.org');
    }
}
