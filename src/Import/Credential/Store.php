<?php

declare(strict_types=1);

namespace AA\Import\Credential;

/**
 * Same contract as AA\Credential\Store; replaced by it once that class is in main.
 *
 * Keys come from constants in the installation's include/config.php:
 *     define('AA_APIKEY_NZIS_LOGIN', '...');   // vendor + context
 *     define('AA_APIKEY_CSU', '...');          // vendor alone
 * The more specific one wins. A missing key is a normal answer (null): a website that has not
 * connected a source yet is a normal state.
 */
final class Store
{
    /**
     * The key for one vendor, or null when the installation has not got it.
     *
     * @param string $vendor  the source: 'csu', 'nzis', ...
     * @param string $context which of its keys: 'login', 'password', ...
     */
    public static function resolve(string $vendor, string $context = ''): ?Secret
    {
        foreach (self::names($vendor, $context) as $name) {
            if (defined($name) && (string) constant($name) !== '') {
                return new Secret((string) constant($name));
            }
        }

        return null;
    }

    public static function has(string $vendor, string $context = ''): bool
    {
        return !is_null(self::resolve($vendor, $context));
    }

    /**
     * For State Check and forms: whether it is there and which one it is - never the whole value.
     * The hint is still part of the key and the text is not escaped: print it through safe().
     */
    public static function describe(string $vendor, string $context = ''): string
    {
        $secret = self::resolve($vendor, $context);

        return is_null($secret) ? _m('not set') : _m('set (%1)', [$secret->hint()]);
    }

    /**
     * Constant names for one key, most specific first.
     *
     * @return list<string>
     */
    public static function names(string $vendor, string $context = ''): array
    {
        $vendor  = self::part($vendor);
        $context = self::part($context);
        if ($vendor === '') {
            return [];
        }

        return $context === ''
            ? ["AA_APIKEY_$vendor"]
            : ["AA_APIKEY_{$vendor}_{$context}", "AA_APIKEY_$vendor"];
    }

    private static function part(string $name): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', trim($name, " \t\n\r\0\x0B_")));
    }
}
