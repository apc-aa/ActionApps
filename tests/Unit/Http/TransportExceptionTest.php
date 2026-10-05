<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use AA\Http\Client;
use AA\Http\TransportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TransportExceptionTest extends TestCase
{
    #[DataProvider('chains')]
    public function test_malformedAddress_byCauseInChain(?\Throwable $cause, bool $expected): void
    {
        self::assertSame($expected, (new TransportException('No answer', 0, $cause))->malformedAddress());
    }

    public static function chains(): array
    {
        return [
            'no cause'               => [null, false],
            'timeout'                => [new \RuntimeException('Timeout reached'), false],
            'invalid argument'       => [new \InvalidArgumentException('Malformed URL'), true],
            'type error'             => [new \TypeError('file:// is no http'), true],
            'deeper in the chain'    => [new \RuntimeException('wrapped', 0, new \InvalidArgumentException('Malformed URL')), true],
            'logic error is no sign' => [new \LogicException('bug'), false],
        ];
    }

    // the library rejects the scheme before any request and wraps the reason
    public function test_malformedAddress_unsupportedSchemeFromClient(): void
    {
        try {
            (new Client(http: new MockHttpClient([new MockResponse('a')])))->request('GET', 'ftp://example.invalid/a');
            self::fail('TransportException expected');
        } catch (TransportException $e) {
            self::assertTrue($e->malformedAddress());
        }
    }

    #[DataProvider('blockChains')]
    public function test_blockedHost_byCauseInChain(?\Throwable $cause, ?string $expected): void
    {
        self::assertSame($expected, (new TransportException('No answer', 0, $cause))->blockedHost());
    }

    public static function blockChains(): array
    {
        return [
            'no cause'            => [null, null],
            'timeout'             => [new \RuntimeException('Timeout reached'), null],
            'ip'                  => [new \RuntimeException('IP "127.0.0.1" is blocked for "http://127.0.0.1/".'), '127.0.0.1'],
            'host'                => [new \RuntimeException('Host "intranet" is blocked for "http://intranet/".'), 'intranet'],
            'deeper in the chain' => [new \RuntimeException('wrapped', 0, new \RuntimeException('IP "::1" is blocked for "http://[::1]/".')), '::1'],
            'only at the start'   => [new \RuntimeException('Why IP "10.0.0.1" is blocked for "x"'), null],
        ];
    }

    public function test_malformedAddress_noAnswerIsNot(): void
    {
        try {
            (new Client(http: new MockHttpClient([new MockResponse('', ['error' => 'Connection refused'])])))->request('GET', 'https://example.invalid/a');
            self::fail('TransportException expected');
        } catch (TransportException $e) {
            self::assertFalse($e->malformedAddress());
        }
    }
}
