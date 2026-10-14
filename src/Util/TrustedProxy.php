<?php

declare(strict_types=1);

namespace AA\Util;

use AA\Optimize\CheckTrustedProxies;
use Throwable;

/**
 * Visitor address, host and scheme behind a reverse proxy.
 *
 * Behind a proxy PHP sees the proxy's address, the backend host name and plain http; the proxy says
 * the truth in X-Forwarded-For/-Host/-Server/-Proto. Any client can send those headers too, so they
 * count only when the request comes from an address in AA_TRUSTED_PROXIES - otherwise anybody could
 * pick the address AA logs and throttles, or the host AA builds links and cache keys from.
 *
 * Apache mod_proxy appends its value to whatever the client sent ("forged, real"), so the last entry
 * is the proxy's own. X-Forwarded-For is walked from the right through the trusted proxies, so a chain
 * of them finds the visitor; the host and scheme are the nearest proxy's, so an outer proxy has to
 * pass them on unchanged.
 *
 * Symfony's IpUtils is not used for the ranges: it throws on a malformed range (here that would kill
 * every request), does not trim and does not unwrap IPv4-mapped addresses.
 */
final class TrustedProxy
{
    /** without AA_TRUSTED_PROXIES in config.php: a proxy on the same machine, the usual setup */
    public const DEFAULT = ['127.0.0.1', '::1'];

    /** set by apply() to the address of the trusted proxy the request came through; a second apply()
     *  (config.php may need the domain before locsess.php) must not take the visitor for the proxy */
    public const PROXY_KEY = 'AA_TRUSTED_PROXY';

    /** the headers AA reads; from anyone but a trusted proxy they are dropped */
    private const HEADERS = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_SERVER', 'HTTP_X_FORWARDED_PROTO'];

    /** what a reverse proxy sends and a forward proxy of the visitor does not */
    private const REVERSE_PROXY_HEADERS = ['HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_SERVER', 'HTTP_X_FORWARDED_PROTO'];

    /** @return list<string> AA_TRUSTED_PROXIES, or DEFAULT when config.php does not set it */
    public static function configured(): array
    {
        return defined('AA_TRUSTED_PROXIES') ? array_values(array_map('strval', (array) constant('AA_TRUSTED_PROXIES'))) : self::DEFAULT;
    }

    /** apply() on $_SERVER with the configured proxies; a proxy missing from the list is logged for State Check */
    public static function applyToRequest(): void
    {
        $proxies = self::configured();
        if (($untrusted = self::untrustedProxy($_SERVER, $proxies)) !== '') {
            // at the end of the request - the log needs the database, which is not set up this early. Not where
            // locsess.php never ran (img.php, a config.php that stopped the request), not after a fatal
            // (FatalLogger must still see it), and never with an error of its own after the response.
            register_shutdown_function(static function () use ($untrusted): void {
                try {
                    if (function_exists('AuthGetUid') && !FatalLogger::isFatal(error_get_last()['type'] ?? 0)) {
                        CheckTrustedProxies::record($untrusted);
                    }
                } catch (Throwable) {
                }
            });
        }
        $_SERVER = self::apply($_SERVER, $proxies);
    }

    /**
     * $server with the client's address, host and scheme taken from a trusted proxy's headers, or with
     * those headers removed when the request did not come from a trusted proxy.
     * @param array<string, mixed> $server  $_SERVER
     * @param list<string>         $proxies addresses and CIDR ranges
     * @return array<string, mixed>
     */
    public static function apply(array $server, array $proxies): array
    {
        if (isset($server[self::PROXY_KEY])) {
            return $server;
        }
        $from = (string) ($server['REMOTE_ADDR'] ?? '');
        if (!self::isTrusted($from, $proxies)) {
            return array_diff_key($server, array_flip(self::HEADERS));
        }
        if (!array_filter(self::HEADERS, static fn(string $h): bool => ($server[$h] ?? '') !== '')) {
            return $server;
        }

        $server[self::PROXY_KEY] = $from;
        $server['REMOTE_ADDR']   = self::clientAddress($from, (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''), $proxies);
        $host = self::lastEntry($server, 'HTTP_X_FORWARDED_HOST');
        if (preg_match('/^([a-z0-9.-]+|\[[0-9a-f:.]+\])(:\d{1,5})?$/iD', $host)) {
            $server['HTTP_HOST'] = $host;
        }
        $name = self::lastEntry($server, 'HTTP_X_FORWARDED_SERVER');
        if (preg_match('/^[a-z0-9.-]+$/iD', $name)) {
            $server['SERVER_NAME'] = $name;
        }
        // self_server() adds any port but the scheme's default, so the port follows the scheme
        switch (strtolower(self::lastEntry($server, 'HTTP_X_FORWARDED_PROTO'))) {
            case 'https':
                $server['HTTPS']       = 'on';
                $server['SERVER_PORT'] = '443';
                break;
            case 'http':
                unset($server['HTTPS']);
                $server['SERVER_PORT'] = '80';
                break;
        }
        return $server;
    }

    /**
     * The sender, when it looks like a reverse proxy AA does not trust: it sends the headers only a
     * reverse proxy sends, from a private or reserved address. A client cannot fake such an address,
     * so scanners forging the headers from the internet are not reported; a proxy on a public address
     * is not either, which is the price of that.
     * @param array<string, mixed> $server  $_SERVER before apply()
     * @param list<string>         $proxies
     * @return string the address, or ''
     */
    public static function untrustedProxy(array $server, array $proxies): string
    {
        $from   = (string) ($server['REMOTE_ADDR'] ?? '');
        $packed = self::pack($from);
        // classified unwrapped: PHP counts every ::ffff:a.b.c.d as reserved, the internet included
        if (isset($server[self::PROXY_KEY]) || ($packed === null) || self::isTrusted($from, $proxies)
            || filter_var((string) inet_ntop($packed), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return '';
        }
        return array_filter(self::REVERSE_PROXY_HEADERS, static fn(string $h): bool => ($server[$h] ?? '') !== '') ? $from : '';
    }

    /**
     * The rightmost address in X-Forwarded-For that is not a trusted proxy. Entries left of it come from
     * the client and mean nothing. An unparsable entry stops the walk at the last trusted hop.
     * @param list<string> $proxies
     */
    public static function clientAddress(string $remote_addr, string $forwarded_for, array $proxies): string
    {
        $address = $remote_addr;
        foreach (array_reverse(explode(',', $forwarded_for)) as $hop) {
            $hop = trim($hop);
            // some balancers add the client's port: 192.0.2.1:5678, [2001:db8::1]:5678
            if (preg_match('/^\[([0-9a-f:.]+)\](?::\d+)?$/iD', $hop, $m) || preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/D', $hop, $m)) {
                $hop = $m[1];
            }
            if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                break;
            }
            $address = $hop;
            if (!self::isTrusted($hop, $proxies)) {
                break;
            }
        }
        return $address;
    }

    /** @param list<string> $proxies addresses and CIDR ranges, IPv4 and IPv6 */
    public static function isTrusted(string $address, array $proxies): bool
    {
        $packed = self::pack($address);
        if ($packed === null) {
            return false;
        }
        foreach ($proxies as $proxy) {
            $range = self::range($proxy);
            if (($range !== null) && (strlen($range[0]) === strlen($packed)) && self::samePrefix($packed, $range[0], $range[1])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $proxies
     * @return list<string> entries that are neither an address nor a CIDR range - they never match
     */
    public static function invalidEntries(array $proxies): array
    {
        return array_values(array_filter($proxies, static fn(string $proxy): bool => self::range($proxy) === null));
    }

    /**
     * @param list<string> $proxies
     * @return list<string> ranges wider than any private network (IPv4 /8, IPv6 /7) - they trust the internet
     */
    public static function tooBroad(array $proxies): array
    {
        return array_values(array_filter($proxies, static function (string $proxy): bool {
            $range = self::range($proxy);
            return ($range !== null) && ($range[1] < ((strlen($range[0]) === 4) ? 8 : 7));
        }));
    }

    /** @return ?array{0: string, 1: int} packed network address and prefix length */
    private static function range(string $proxy): ?array
    {
        [$net, $bits] = array_pad(explode('/', trim($proxy), 2), 2, null);
        $packed = self::pack((string) $net);
        if ($packed === null) {
            return null;
        }
        $max = 8 * strlen($packed);
        if ($bits === null) {
            return [$packed, $max];
        }
        return (ctype_digit($bits) && ((int) $bits <= $max)) ? [$packed, (int) $bits] : null;
    }

    /** binary form; an IPv4 client on a dual-stack socket comes as ::ffff:a.b.c.d and is unwrapped to IPv4 */
    private static function pack(string $address): ?string
    {
        if (!filter_var($address, FILTER_VALIDATE_IP)) {
            return null;
        }
        $packed = (string) inet_pton($address);
        return str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") ? substr($packed, 12) : $packed;
    }

    private static function samePrefix(string $a, string $b, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /** @param array<string, mixed> $server */
    private static function lastEntry(array $server, string $header): string
    {
        $entries = explode(',', (string) ($server[$header] ?? ''));
        return trim((string) end($entries));
    }
}
