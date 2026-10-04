<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Http\Client;
use AA\Import\Context;
use AA\IO\SourceFailure;
use AA\IO\SourceRefused;
use AA\IO\SourceUnavailable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Addresses are public IP literals: the guard resolves host names, so a name would hit real DNS.
 * The key value has characters that URL encoding changes, so every form of it is checked.
 */
final class ContextTest extends TestCase
{
    private const KEY = 'k3y a b~c/d+e&=99';

    private const CREDENTIALS = [['vendor' => 'testvendor', 'context' => 'ctx']];

    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public static function setUpBeforeClass(): void
    {
        if (!defined('AA_APIKEY_TESTVENDOR_CTX')) {
            define('AA_APIKEY_TESTVENDOR_CTX', self::KEY);
        }
        if (!defined('AA_APIKEY_CTXBROKEN')) {
            define('AA_APIKEY_CTXBROKEN', "broken-ctx-value\nX: 1");
        }
    }

    /** @param list<MockResponse> $responses */
    private function context(array $responses, float $seconds = 30.0, int $maxBytes = 20 * 1024 * 1024): Context
    {
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['url' => $url, 'options' => $options];
            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        // IP literals never reach the resolver; a call would mean the test asks DNS
        return new Context(self::CREDENTIALS, microtime(true) + $seconds, $maxBytes, $mock, fn(string $host): array => throw new \LogicException("DNS asked for $host"));
    }

    private static function redirect(string $to): MockResponse
    {
        return new MockResponse('', ['http_code' => 302, 'redirect_url' => $to, 'response_headers' => ['Location: ' . $to]]);
    }

    private function expectRefused(callable $call, string $reason = 'SOURCE'): SourceRefused
    {
        try {
            $call();
        } catch (SourceRefused $e) {
            self::assertSame($reason, $e->reason);
            return $e;
        }
        self::fail('SourceRefused expected');
    }

    public function test_http_returnsAnswer(): void
    {
        $answer = $this->context([new MockResponse('data')])->http()->request('GET', 'https://1.1.1.1/data');

        self::assertSame([200, 'data'], [$answer->status, $answer->body]);
    }

    // a client error is an answer: the grabber decides what it means
    public function test_http_errorStatusIsAnswer(): void
    {
        self::assertSame(404, $this->context([new MockResponse('', ['http_code' => 404])])->http()->request('GET', 'https://1.1.1.1/x')->status);
    }

    #[DataProvider('privateAddresses')]
    public function test_http_refusesPrivateAddress(string $url): void
    {
        $ctx = $this->context([new MockResponse('internal')]);

        $this->expectRefused(fn() => $ctx->http()->request('GET', $url));
        self::assertSame([], $this->requests);
    }

    public static function privateAddresses(): array
    {
        return [
            'loopback'   => ['http://127.0.0.1/'],
            'private'    => ['http://10.0.0.1/'],
            'link-local' => ['http://169.254.169.254/latest/meta-data/'],
            'ipv6'       => ['http://[::1]/'],
        ];
    }

    public function test_http_refusesRedirectToPrivate(): void
    {
        $ctx = $this->context([self::redirect('http://10.0.0.1/'), new MockResponse('internal')]);

        $this->expectRefused(fn() => $ctx->http()->request('GET', 'https://1.1.1.1/a'));
        self::assertCount(1, $this->requests);
    }

    #[DataProvider('otherSchemes')]
    public function test_http_refusesNonHttpScheme(string $url): void
    {
        $ctx = $this->context([new MockResponse('x')]);

        $this->expectRefused(fn() => $ctx->http()->request('GET', $url));
        self::assertSame([], $this->requests);
    }

    public static function otherSchemes(): array
    {
        return [
            'file'       => ['file:///etc/passwd'],
            'php stream' => ['php://filter/resource=/etc/passwd'],
            'ftp'        => ['ftp://1.1.1.1/data.csv'],
            'local path' => ['/etc/passwd'],
            'no host'    => ['http://'],
        ];
    }

    // data over the limit are a permanent refusal, not a retry
    public function test_http_tooLargeIsSourceRefused(): void
    {
        $ctx = $this->context([new MockResponse(str_repeat('x', 11))], maxBytes: 10);

        $this->expectRefused(fn() => $ctx->http()->request('GET', 'https://1.1.1.1/big'));
    }

    // a body over 100 MB would not fit the test's memory, so the limit is read directly
    public function test_context_maxBytesCappedAt100MB(): void
    {
        $ctx = new Context([], microtime(true) + 30, 500 * 1024 * 1024);

        self::assertSame(Context::MAX_BYTES_CAP, (new \ReflectionProperty(Context::class, 'maxBytes'))->getValue($ctx));
    }

    public function test_context_rejectsZeroMaxBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Context([], microtime(true) + 30, 0);
    }

    public function test_http_timeoutFollowsDeadline(): void
    {
        $this->context([new MockResponse('a')], 12.5)->http()->request('GET', 'https://1.1.1.1/a');

        self::assertEqualsWithDelta(12.0, $this->requests[0]['options']['max_duration'], 1.0);
    }

    public function test_http_lastSecondGetsOneSecond(): void
    {
        $this->context([new MockResponse('a')], 0.5)->http()->request('GET', 'https://1.1.1.1/a');

        self::assertSame(1.0, (float) $this->requests[0]['options']['max_duration']);
    }

    public function test_http_pastDeadlineSendsNothing(): void
    {
        $ctx = $this->context([new MockResponse('a')], -5.0);

        try {
            $ctx->http()->request('GET', 'https://1.1.1.1/a');
            self::fail('SourceUnavailable expected');
        } catch (SourceUnavailable $e) {
            self::assertSame('TRANSPORT', $e->reason);
        }
        self::assertSame([], $this->requests);
    }

    /**
     * The guard reports a host it failed to look up as blocked too, so the host is looked up again.
     * The guard's text comes from the mock: the URL is an IP literal and no lookup happens.
     * @param list<string> $ips what the fake resolver answers
     */
    #[DataProvider('lookups')]
    public function test_http_blockedHostByItsAddresses(array $ips, string $expected): void
    {
        $asked = [];
        $error = new MockResponse('', ['error' => 'Host "data.example" is blocked for "https://data.example/x".']);
        $ctx   = new Context([], microtime(true) + 30, transport: new MockHttpClient($error), resolver: function (string $host) use ($ips, &$asked): array {
            $asked[] = $host;
            return $ips;
        });

        try {
            $ctx->http()->request('GET', 'https://1.1.1.1/x');
            self::fail('SourceFailure expected');
        } catch (SourceFailure $e) {
            self::assertSame($expected, $e::class);
        }
        self::assertSame(['data.example'], $asked);
    }

    public static function lookups(): array
    {
        return [
            'not found'           => [[], SourceUnavailable::class],
            'public only'         => [['93.184.216.34', '2606:2800:220:1::1'], SourceUnavailable::class],
            'one private'         => [['93.184.216.34', '10.0.0.5'], SourceRefused::class],
            'private ipv6'        => [['fd00::1'], SourceRefused::class],
        ];
    }

    public function test_http_unresolvableHostIsUnavailable(): void
    {
        $ctx = new Context([], microtime(true) + 30, transport: new MockHttpClient(new MockResponse('', ['error' => 'Host "no-such.example" is blocked for "https://no-such.example/".'])), resolver: fn(string $host): array => []);

        try {
            $ctx->http()->request('GET', 'https://1.1.1.1/data');
            self::fail('SourceUnavailable expected');
        } catch (SourceUnavailable $e) {
            self::assertSame('TRANSPORT', $e->reason);
        }
    }

    // the real guard: localhost comes from the hosts file, so it asks no DNS server
    public function test_http_resolvableBlockedHostIsRefused(): void
    {
        $ctx = new Context([], microtime(true) + 30, transport: new MockHttpClient(new MockResponse('a')), resolver: fn(string $host): array => ['127.0.0.1']);

        $this->expectRefused(fn() => $ctx->http()->request('GET', 'http://localhost/data'));
    }

    // a quote ends the host in the guard's text; the rest is not taken for a host name
    public function test_http_quoteInBlockedHostIsNotLookedUp(): void
    {
        $ctx = $this->context([new MockResponse('', ['error' => 'Host "a"b.example" is blocked for "https://1.1.1.1/".'])]);

        $this->expectException(SourceUnavailable::class);
        $ctx->http()->request('GET', 'https://1.1.1.1/x');
    }

    public function test_http_controlCharactersEncodedInMessage(): void
    {
        $ctx = $this->context([]);
        $e   = $this->expectRefused(fn() => $ctx->http()->request('GET', "ftp://1.1.1.1/a\r\nX-Injected: 1\u{0085}"));

        self::assertSame('Only http(s) addresses are imported: ftp://1.1.1.1/a%0D%0AX-Injected: 1%C2%85', $e->getMessage());
    }

    #[DataProvider('unprintableUrls')]
    public function test_http_unprintableBytesEncodedInMessage(string $url, string $shown): void
    {
        $ctx = $this->context([]);
        $e   = $this->expectRefused(fn() => $ctx->http()->request('GET', $url));

        self::assertSame('Only http(s) addresses are imported: ' . $shown, $e->getMessage());
        self::assertNotFalse(json_encode($e->getMessage()));
    }

    public static function unprintableUrls(): array
    {
        return [
            'DEL'                => ["ftp://x/a\x7Fb", 'ftp://x/a%7Fb'],
            'tab'                => ["ftp://x/a\tb", 'ftp://x/a%09b'],
            'escape'             => ["ftp://x/a\eb", 'ftp://x/a%1Bb'],
            'NUL'                => ["ftp://x/a\0b", 'ftp://x/a%00b'],
            'truncated sequence' => ["ftp://x/\xC2", 'ftp://x/%C2'],
            'stray continuation' => ["ftp://x/\x80a", 'ftp://x/%80a'],
            'overlong slash'     => ["ftp://x/\xC0\xAF", 'ftp://x/%C0%AF'],
            'surrogate'          => ["ftp://x/\xED\xA0\x80", 'ftp://x/%ED%A0%80'],
            'valid UTF-8 stays'  => ['ftp://x/čaj-€-😀', 'ftp://x/čaj-€-😀'],
        ];
    }

    /** AA's error handler stores call arguments from the trace; these carry URLs, keys or the library's exception */
    public function test_sensitiveParameters_markedWhereDataFlow(): void
    {
        $marked = [
            [Context::class, 'send', ['url', 'body', 'headers']],
            [Context::class, 'transportFailure', ['e', 'url']],
            [Context::class, 'failure', ['url']],
            [Context::class, 'redact', ['text']],
            [Client::class, 'request', ['url', 'body', 'headers']],
            [Client::class, 'limitedContent', ['url']],
            [(new Context([], microtime(true) + 30))->http(), 'request', ['url', 'body', 'headers']],
        ];
        foreach ($marked as [$class, $method, $names]) {
            $params = [];
            foreach ((new \ReflectionMethod($class, $method))->getParameters() as $param) {
                $params[$param->getName()] = $param;
            }
            foreach ($names as $name) {
                $where = (is_string($class) ? $class : 'http()') . "::$method \$$name";
                self::assertArrayHasKey($name, $params, $where);
                self::assertNotEmpty($params[$name]->getAttributes(\SensitiveParameter::class), $where);
            }
        }
    }

    public function test_secret_damagedKeyIsNoCredential(): void
    {
        $ctx = new Context([['vendor' => 'ctxbroken', 'context' => '']], microtime(true) + 30);
        self::assertSame('nothing to hide', $ctx->redact('nothing to hide'), 'a damaged key does not break the step');

        try {
            $ctx->secret('ctxbroken');
            self::fail('SourceRefused expected');
        } catch (SourceRefused $e) {
            self::assertSame('NO_CREDENTIAL', $e->reason);
            self::assertStringNotContainsString('broken-ctx', $e->getMessage());
        }
    }

    #[DataProvider('otherSchemeTargets')]
    public function test_http_refusesRedirectToOtherScheme(string $to): void
    {
        $ctx = $this->context([self::redirect($to), new MockResponse('local')]);

        $this->expectRefused(fn() => $ctx->http()->request('GET', 'https://1.1.1.1/a'));
        self::assertCount(1, $this->requests);
    }

    public static function otherSchemeTargets(): array
    {
        return [
            'ftp'  => ['ftp://1.1.1.1/x'],
            'file' => ['file:///etc/passwd'],
        ];
    }

    public function test_http_timeoutIsUnavailable(): void
    {
        $ctx = $this->context([new MockResponse('', ['error' => 'Timeout reached'])]);

        try {
            $ctx->http()->request('GET', 'https://1.1.1.1/slow');
            self::fail('SourceUnavailable expected');
        } catch (SourceUnavailable $e) {
            self::assertSame('TRANSPORT', $e->reason);
        }
    }

    public function test_http_followsThreeRedirects(): void
    {
        $ctx    = $this->context([self::redirect('https://1.1.1.1/b'), self::redirect('https://1.1.1.1/c'), self::redirect('https://1.1.1.1/d'), new MockResponse('ok')]);
        $answer = $ctx->http()->request('GET', 'https://1.1.1.1/a');

        self::assertSame('ok', $answer->body);
    }

    public function test_http_moreThanThreeRedirectsRefused(): void
    {
        $ctx = $this->context([self::redirect('https://1.1.1.1/b'), self::redirect('https://1.1.1.1/c'), self::redirect('https://1.1.1.1/d'), self::redirect('https://1.1.1.1/e'), new MockResponse('ok')]);

        $this->expectRefused(fn() => $ctx->http()->request('GET', 'https://1.1.1.1/a'));
        self::assertCount(4, $this->requests);
    }

    // a conditional request answered "not modified" is not a redirect
    public function test_http_notModifiedIsAnswer(): void
    {
        self::assertSame(304, $this->context([new MockResponse('', ['http_code' => 304])])->http()->request('GET', 'https://1.1.1.1/a')->status);
    }

    public function test_http_sendsImportUserAgent(): void
    {
        $this->context([new MockResponse('a')])->http()->request('GET', 'https://1.1.1.1/a');

        self::assertSame(['User-Agent: ' . Client::agent('import')], $this->requests[0]['options']['normalized_headers']['user-agent']);
    }

    public function test_secret_undeclaredThrows(): void
    {
        $ctx = new Context(self::CREDENTIALS, microtime(true) + 30);

        $this->expectException(LogicException::class);
        $ctx->secret('testvendor');
    }

    public function test_secret_declaredResolves(): void
    {
        $ctx = new Context(self::CREDENTIALS, microtime(true) + 30);

        self::assertSame(self::KEY, $ctx->secret('testvendor', 'ctx')?->reveal());
    }

    public function test_secret_declaredButMissingIsNull(): void
    {
        $ctx = new Context([['vendor' => 'importctxmissing', 'context' => '']], microtime(true) + 30);

        self::assertNull($ctx->secret('importctxmissing'));
        self::assertSame('nothing to hide', $ctx->redact('nothing to hide'));
    }

    public function test_redact_masksValueInUrlQuery(): void
    {
        $ctx = new Context(self::CREDENTIALS, microtime(true) + 30);

        self::assertSame('https://1.1.1.1/a?key=***&x=1', $ctx->redact('https://1.1.1.1/a?key=' . self::KEY . '&x=1'));
    }

    public function test_redact_masksUrlencodedValue(): void
    {
        $ctx = new Context(self::CREDENTIALS, microtime(true) + 30);

        self::assertSame('a=*** b=***', $ctx->redact('a=' . urlencode(self::KEY) . ' b=' . rawurlencode(self::KEY)));
    }

    // the library's text would carry the URL re-encoded (~ for %7E), past redact()
    #[DataProvider('queriesWithKey')]
    public function test_redact_masksInExceptionMessage(string $query): void
    {
        $ctx = $this->context([new MockResponse('', ['error' => 'Timeout reached for "https://1.1.1.1/data?key=k3y+a+b~c/d+e"'])]);

        try {
            $ctx->http()->request('GET', 'https://1.1.1.1/data?' . $query);
            self::fail('SourceUnavailable expected');
        } catch (SourceUnavailable $e) {
            self::assertSame('No answer in time: https://1.1.1.1/data?key=***', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public static function queriesWithKey(): array
    {
        return [
            'urlencode'               => ['key=' . urlencode(self::KEY)],
            'http_build_query'        => [http_build_query(['key' => self::KEY])],
            'http_build_query RFC3986' => [http_build_query(['key' => self::KEY], '', '&', PHP_QUERY_RFC3986)],
        ];
    }

    public function test_redact_masksInRefusalMessage(): void
    {
        $ctx = $this->context([]);
        $e   = $this->expectRefused(fn() => $ctx->http()->request('GET', 'http://127.0.0.1/?key=' . rawurlencode(self::KEY)));

        self::assertStringNotContainsString(rawurlencode(self::KEY), $e->getMessage());
        self::assertNull($e->getPrevious());
    }

    public function test_sourceFailure_isCommonParent(): void
    {
        self::assertInstanceOf(SourceFailure::class, new SourceRefused('SOURCE'));
        self::assertInstanceOf(SourceFailure::class, new SourceUnavailable('TRANSPORT'));
    }

    public function test_sourceRefused_rejectsBadReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SourceRefused('not a code');
    }
}
