<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckTrustedProxies;
use AA\Optimize\Finding;
use AA\Util\TrustedProxy;
use PHPUnit\Framework\TestCase;

final class CheckTrustedProxiesTest extends TestCase
{
    private const LOOPBACK = ['127.0.0.1', '::1'];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe()
    }

    public function test_assess_nothingToReportIsOk(): void
    {
        $findings = CheckTrustedProxies::assess(self::LOOPBACK, false, [], ['REMOTE_ADDR' => '84.42.240.38', 'HTTPS' => 'on']);

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('127.0.0.1, ::1 (default)', $findings[0]->evidence[0]['AA_TRUSTED_PROXIES']);
    }

    public function test_assess_aLoggedProxyOutsideTheListIsAWarningWithTheFix(): void
    {
        $findings = CheckTrustedProxies::assess(self::LOOPBACK, false, [self::row('10.0.0.5')], []);

        self::assertSame([Finding::WARNING], self::severities($findings));
        self::assertStringContainsString("define('AA_TRUSTED_PROXIES', ['10.0.0.5']);", $findings[0]->advice);
        self::assertSame(3, $findings[0]->count);
        self::assertSame('10.0.0.5', $findings[0]->key);
    }

    public function test_assess_aProxyAddedToTheListSinceIsNotReported(): void
    {
        $findings = CheckTrustedProxies::assess(['127.0.0.1', '10.0.0.0/8'], true, [self::row('10.0.0.5')], []);

        self::assertSame([Finding::OK], self::severities($findings));
    }

    public function test_assess_aPageThroughATrustedProxyWithEverythingIsOk(): void
    {
        $server = [TrustedProxy::PROXY_KEY => '127.0.0.1', 'REMOTE_ADDR' => '84.42.240.38', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTPS' => 'on'];

        self::assertSame([Finding::OK], self::severities(CheckTrustedProxies::assess(self::LOOPBACK, false, [], $server)));
    }

    public function test_assess_aTrustedProxyWithoutTheSchemeGetsAdvice(): void
    {
        $server = [TrustedProxy::PROXY_KEY => '127.0.0.1', 'REMOTE_ADDR' => '84.42.240.38'];
        self::assertSame([Finding::OK, Finding::INFO], self::severities(CheckTrustedProxies::assess(self::LOOPBACK, false, [], $server)));

        $server['HTTPS'] = 'on';
        self::assertSame([Finding::OK], self::severities(CheckTrustedProxies::assess(self::LOOPBACK, false, [], $server)), 'https known some other way');

        $server = [TrustedProxy::PROXY_KEY => '127.0.0.1', 'REMOTE_ADDR' => '84.42.240.38', 'HTTP_X_FORWARDED_PROTO' => 'http'];
        self::assertSame([Finding::OK], self::severities(CheckTrustedProxies::assess(self::LOOPBACK, false, [], $server)), 'the proxy said plain http');
    }

    public function test_assess_aTrustedProxyWithoutTheVisitorAddressGetsAdvice(): void
    {
        $server   = [TrustedProxy::PROXY_KEY => '127.0.0.1', 'REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'];
        $findings = CheckTrustedProxies::assess(self::LOOPBACK, false, [], $server);

        self::assertSame([Finding::OK, Finding::INFO], self::severities($findings));
        self::assertSame('for', $findings[1]->key);
    }

    public function test_assess_anEntryThatCanNeverMatchIsAnError(): void
    {
        $findings = CheckTrustedProxies::assess(['127.0.0.1', 'proxy.example.org'], true, [], []);

        self::assertSame([Finding::ERROR], self::severities($findings));
        self::assertStringContainsString('proxy.example.org', $findings[0]->title);
    }

    public function test_assess_aRangeOfTheWholeInternetIsAWarning(): void
    {
        self::assertSame([Finding::WARNING], self::severities(CheckTrustedProxies::assess(['0.0.0.0/0'], true, [], [])));
    }

    public function test_assess_anEmptyListSaysNobodyIsTrusted(): void
    {
        $findings = CheckTrustedProxies::assess([], true, [], []);

        self::assertSame('nobody', $findings[0]->evidence[0]['AA_TRUSTED_PROXIES']);
    }

    /** @return array<string, ?string> a LogOnce::recent() row */
    private static function row(string $address): array
    {
        return ['selector' => $address, 'days' => '3', 'first' => '1791900000', 'last' => '1792000000', 'params' => ''];
    }

    /**
     * @param Finding[] $findings
     * @return list<string>
     */
    private static function severities(array $findings): array
    {
        return array_map(static fn(Finding $f): string => $f->severity, $findings);
    }
}
