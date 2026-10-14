<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\TrustedProxy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The header values below are what the ECN proxy (Apache mod_proxy on the same machine) really
 * delivered, measured 2026-10-14: it appends its own value after whatever the client sent.
 */
final class TrustedProxyTest extends TestCase
{
    private const LOOPBACK = ['127.0.0.1', '::1'];

    /** @return array<string, array{string, list<string>, bool}> */
    public static function addresses(): array
    {
        return [
            'exact IPv4'                  => ['127.0.0.1', self::LOOPBACK, true],
            'exact IPv6'                  => ['::1', self::LOOPBACK, true],
            'IPv6 written longer'         => ['0:0:0:0:0:0:0:1', self::LOOPBACK, true],
            'IPv4 on a dual-stack socket' => ['::ffff:127.0.0.1', self::LOOPBACK, true],
            'other address'               => ['127.0.0.2', self::LOOPBACK, false],
            'public client'               => ['84.42.240.38', self::LOOPBACK, false],
            'inside /8'                   => ['10.200.3.4', ['10.0.0.0/8'], true],
            'outside /8'                  => ['11.0.0.1', ['10.0.0.0/8'], false],
            'inside /20'                  => ['172.16.15.255', ['172.16.0.0/20'], true],
            'just outside /20'            => ['172.16.16.0', ['172.16.0.0/20'], false],
            '/0 trusts every IPv4'        => ['203.0.113.9', ['0.0.0.0/0'], true],
            '/32 is one address'          => ['192.0.2.1', ['192.0.2.1/32'], true],
            'inside IPv6 /64'             => ['2001:db8:1:2:aaaa::5', ['2001:db8:1:2::/64'], true],
            'outside IPv6 /64'            => ['2001:db8:1:3::5', ['2001:db8:1:2::/64'], false],
            'IPv4 never matches IPv6'     => ['10.0.0.1', ['::/0'], false],
            'empty list trusts nobody'    => ['127.0.0.1', [], false],
            'not an address'              => ['localhost', ['localhost'], false],
            'empty address'               => ['', self::LOOPBACK, false],
            'broken entry is skipped'     => ['127.0.0.1', ['10.0.0.0/33', '127.0.0.1'], true],
        ];
    }

    /** @param list<string> $proxies */
    #[DataProvider('addresses')]
    public function test_isTrusted(string $address, array $proxies, bool $expected): void
    {
        self::assertSame($expected, TrustedProxy::isTrusted($address, $proxies));
    }

    public function test_invalidEntries_listsWhatCanNeverMatch(): void
    {
        $proxies = ['127.0.0.1', '10.0.0.0/8', '::1', 'fd00::/8', 'proxy.example.org', '10.0.0.0/33', '1.2.3.4/x', '::1/129', ''];
        self::assertSame(['proxy.example.org', '10.0.0.0/33', '1.2.3.4/x', '::1/129', ''], TrustedProxy::invalidEntries($proxies));
    }

    public function test_tooBroad_listsRangesThatTrustTheInternet(): void
    {
        $proxies = ['0.0.0.0/0', '10.0.0.0/8', '8.0.0.0/7', '::/0', '2000::/3', 'fc00::/7', '127.0.0.1', 'garbage'];
        self::assertSame(['0.0.0.0/0', '8.0.0.0/7', '::/0', '2000::/3'], TrustedProxy::tooBroad($proxies));
    }

    /** @return array<string, array{string, string, list<string>, string}> */
    public static function chains(): array
    {
        return [
            'one proxy'                         => ['127.0.0.1', '84.42.240.38', self::LOOPBACK, '84.42.240.38'],
            'client entry before the real one'  => ['127.0.0.1', '192.0.2.1, 84.42.240.38', self::LOOPBACK, '84.42.240.38'],
            'two proxies in a row'              => ['127.0.0.1', '192.0.2.1, 84.42.240.38, 10.0.0.5', ['127.0.0.1', '10.0.0.0/8'], '84.42.240.38'],
            'no spaces'                         => ['127.0.0.1', '192.0.2.1,84.42.240.38', self::LOOPBACK, '84.42.240.38'],
            'IPv6 client'                       => ['127.0.0.1', '2001:db8::7', self::LOOPBACK, '2001:db8::7'],
            'garbage stops at the trusted hop'  => ['127.0.0.1', '84.42.240.38, <script>, 10.0.0.5', ['127.0.0.1', '10.0.0.0/8'], '10.0.0.5'],
            'garbage as the only entry'         => ['127.0.0.1', 'unknown', self::LOOPBACK, '127.0.0.1'],
            'every hop trusted'                 => ['127.0.0.1', '10.0.0.6, 10.0.0.5', ['127.0.0.1', '10.0.0.0/8'], '10.0.0.6'],
            'IPv4 with a port'                  => ['127.0.0.1', '84.42.240.38:5678', self::LOOPBACK, '84.42.240.38'],
            'IPv6 in brackets with a port'      => ['127.0.0.1', '[2001:db8::7]:5678', self::LOOPBACK, '2001:db8::7'],
            'IPv6 in brackets'                  => ['127.0.0.1', '[2001:db8::7]', self::LOOPBACK, '2001:db8::7'],
            'no X-Forwarded-For'                => ['127.0.0.1', '', self::LOOPBACK, '127.0.0.1'],
        ];
    }

    /** @param list<string> $proxies */
    #[DataProvider('chains')]
    public function test_clientAddress(string $remote_addr, string $forwarded_for, array $proxies, string $expected): void
    {
        self::assertSame($expected, TrustedProxy::clientAddress($remote_addr, $forwarded_for, $proxies));
    }

    public function test_apply_behindTheProxy_takesTheProxysOwnValues(): void
    {
        $server = TrustedProxy::apply([
            'REMOTE_ADDR'              => '127.0.0.1',
            'HTTP_HOST'                => 'proxyaa.ecn.cz',
            'SERVER_NAME'              => 'proxyaa.ecn.cz',
            'SERVER_PORT'              => '80',
            'HTTP_X_FORWARDED_FOR'     => '192.0.2.1, 84.42.240.38',
            'HTTP_X_FORWARDED_HOST'    => 'test.invalid, hanka-honza.ecn.cz',
            'HTTP_X_FORWARDED_SERVER'  => 'test.invalid, hanka-honza.ecn.cz',
        ], self::LOOPBACK);

        self::assertSame('84.42.240.38', $server['REMOTE_ADDR']);
        self::assertSame('hanka-honza.ecn.cz', $server['HTTP_HOST']);
        self::assertSame('hanka-honza.ecn.cz', $server['SERVER_NAME']);
        self::assertArrayNotHasKey('HTTPS', $server, 'without X-Forwarded-Proto the scheme stays as the backend saw it');
        self::assertSame('80', $server['SERVER_PORT']);
        self::assertSame('test.invalid, hanka-honza.ecn.cz', $server['HTTP_X_FORWARDED_SERVER'], 'a trusted proxy keeps its headers');
        self::assertSame('127.0.0.1', $server[TrustedProxy::PROXY_KEY]);
    }

    public function test_apply_aSecondTimeChangesNothing(): void
    {
        $once = TrustedProxy::apply(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'backend', 'HTTP_X_FORWARDED_FOR' => '84.42.240.38', 'HTTP_X_FORWARDED_HOST' => 'www.example.org'], self::LOOPBACK);

        self::assertSame($once, TrustedProxy::apply($once, self::LOOPBACK), 'the visitor is not taken for the proxy');
    }

    public function test_apply_fromAnUntrustedAddress_dropsTheHeadersAndChangesNothingElse(): void
    {
        $direct = [
            'REMOTE_ADDR'             => '84.42.240.38',
            'HTTP_HOST'               => 'www.zdravamesta.cz',
            'SERVER_NAME'             => 'www.zdravamesta.cz',
            'HTTPS'                   => 'on',
            'SERVER_PORT'             => '443',
            'HTTP_X_FORWARDED_FOR'    => '192.0.2.1',
            'HTTP_X_FORWARDED_HOST'   => 'test.invalid',
            'HTTP_X_FORWARDED_SERVER' => 'test.invalid',
            'HTTP_X_FORWARDED_PROTO'  => 'http',
        ];
        $server = TrustedProxy::apply($direct, self::LOOPBACK);

        $expected = array_diff_key($direct, array_flip(['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_SERVER', 'HTTP_X_FORWARDED_PROTO']));
        self::assertSame($expected, $server);
    }

    public function test_apply_withoutForwardedHeaders_leavesTheRequestAlone(): void
    {
        $direct = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'];
        self::assertSame($direct, TrustedProxy::apply($direct, self::LOOPBACK));
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function senders(): array
    {
        return [
            'proxy on the LAN'            => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_HOST' => 'www.example.org'], '10.0.0.5'],
            'proxy sending only the scheme' => [['REMOTE_ADDR' => '192.168.1.2', 'HTTP_X_FORWARDED_PROTO' => 'https'], '192.168.1.2'],
            'IPv6 unique local'           => [['REMOTE_ADDR' => 'fd00::5', 'HTTP_X_FORWARDED_SERVER' => 'www.example.org'], 'fd00::5'],
            'trusted proxy'               => [['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_HOST' => 'www.example.org'], ''],
            'public address is a client'  => [['REMOTE_ADDR' => '84.42.240.38', 'HTTP_X_FORWARDED_HOST' => 'www.example.org'], ''],
            'public IPv4 written mapped'  => [['REMOTE_ADDR' => '::ffff:8.8.8.8', 'HTTP_X_FORWARDED_HOST' => 'www.example.org'], ''],
            'private IPv4 written mapped' => [['REMOTE_ADDR' => '::ffff:10.0.0.5', 'HTTP_X_FORWARDED_HOST' => 'www.example.org'], '::ffff:10.0.0.5'],
            'forward proxy of a visitor'  => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '10.1.1.1'], ''],
            'no headers'                  => [['REMOTE_ADDR' => '10.0.0.5'], ''],
            'already applied'             => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_HOST' => 'www.example.org', TrustedProxy::PROXY_KEY => '127.0.0.1'], ''],
        ];
    }

    /** @param array<string, string> $server */
    #[DataProvider('senders')]
    public function test_untrustedProxy(array $server, string $expected): void
    {
        self::assertSame($expected, TrustedProxy::untrustedProxy($server, self::LOOPBACK));
    }

    /** @return array<string, array{string, ?string, string}> */
    public static function schemes(): array
    {
        return [
            'https'                 => ['https', 'on', '443'],
            'any letter case'       => ['HTTPS', 'on', '443'],
            'http'                  => ['http', null, '80'],
            'last entry counts'     => ['http, https', 'on', '443'],
            'unknown value ignored' => ['gopher', 'on', '8443'],
        ];
    }

    #[DataProvider('schemes')]
    public function test_apply_schemeFromTheProxy(string $proto, ?string $https, string $port): void
    {
        $server = TrustedProxy::apply(['REMOTE_ADDR' => '::1', 'HTTPS' => 'on', 'SERVER_PORT' => '8443', 'HTTP_X_FORWARDED_PROTO' => $proto], self::LOOPBACK);

        self::assertSame($https, $server['HTTPS'] ?? null);
        self::assertSame($port, $server['SERVER_PORT']);
    }

    /** @return array<string, array{string, string}> */
    public static function hosts(): array
    {
        return [
            'domain'               => ['www.example.org', 'www.example.org'],
            'domain with port'     => ['www.example.org:8080', 'www.example.org:8080'],
            'IPv6 literal'         => ['[2001:db8::1]:8080', '[2001:db8::1]:8080'],
            'path is refused'      => ['example.org/x', 'backend'],
            'markup is refused'    => ['<b>x</b>', 'backend'],
            'space is refused'     => ['a b', 'backend'],
            'empty keeps the host' => ['example.org, ', 'backend'],
        ];
    }

    #[DataProvider('hosts')]
    public function test_apply_hostMustLookLikeAHost(string $forwarded_host, string $expected): void
    {
        $server = TrustedProxy::apply(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'backend', 'HTTP_X_FORWARDED_HOST' => $forwarded_host], self::LOOPBACK);

        self::assertSame($expected, $server['HTTP_HOST']);
    }

    public function test_configured_defaultsToLoopback(): void
    {
        if (defined('AA_TRUSTED_PROXIES')) {
            self::markTestSkipped('AA_TRUSTED_PROXIES is defined in this process');
        }
        self::assertSame(['127.0.0.1', '::1'], TrustedProxy::configured());
    }
}
