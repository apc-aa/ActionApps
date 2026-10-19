<?php

declare(strict_types=1);

namespace AA\Credential;

/**
 * Where a key for an outside service comes from: constants in the installation's
 * include/config.php.
 *     define('AA_APIKEY_NZIS_LOGIN', '...');   // vendor + context
 *     define('AA_APIKEY_CSU', '...');          // vendor alone
 * The more specific one wins. Callers ask resolve() and get a Secret, so they will not change
 * when keys get another home.
 *
 * A key is one line: it goes into HTTP headers, where a line break would add a header of its
 * own. A JSON key (a Google service account) fits on one line: paste the line `jq -c . key.json`
 * prints - its output ends with a line break, so file_get_contents() of it would not do.
 *
 * A missing key is a normal answer (null): a website that has not connected a service yet is a
 * normal state.
 */
final class Store
{
    private const PREFIX = 'AA_APIKEY_';

    /**
     * The key for one vendor, or null when the installation has not got it.
     *
     * @param string $vendor  the service: 'csu', 'nzis', 'dataforseo', 'google', ...
     * @param string $context which of its keys: 'login', 'password', 'service_account', ...
     * @throws \UnexpectedValueException when the key holds CR, LF or NUL; the message names the constant only
     */
    public static function resolve(string $vendor, string $context = ''): ?Secret
    {
        return self::lookup(self::names($vendor, $context));
    }

    /** whether the constant is set, damaged or not - resolve() tells the difference */
    public static function has(string $vendor, string $context = ''): bool
    {
        return !is_null(self::first(self::names($vendor, $context)));
    }

    /**
     * For State Check and forms: whether it is there and which one it is - never the whole value.
     * The hint is still part of the key and the text is not escaped: print it into HTML only
     * through safe(), and only to people who may manage the key.
     */
    public static function describe(string $vendor, string $context = ''): string
    {
        $name = self::first(self::names($vendor, $context));
        return match (true) {
            is_null($name)                   => _m('not set'),
            self::breaks(self::value($name)) => _m('damaged (a line break or NUL in %1)', [$name]),
            default                          => _m('set (%1)', [(new Secret(self::value($name)))->hint()]),
        };
    }

    /**
     * The key constants among $constants that hold CR, LF or NUL - names only, for State Check.
     * @param array<string, mixed> $constants name => value, as get_defined_constants(true)['user']
     * @return list<string>
     */
    public static function damagedIn(#[\SensitiveParameter] array $constants): array
    {
        $damaged = [];
        foreach ($constants as $name => $value) {
            if (str_starts_with((string) $name, self::PREFIX) && is_string($value) && self::breaks($value)) {
                $damaged[] = (string) $name;
            }
        }
        return $damaged;
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
            ? [self::PREFIX . $vendor]
            : [self::PREFIX . "{$vendor}_{$context}", self::PREFIX . $vendor];
    }

    /**
     * The key of the first of $names that is set.
     *
     * @param list<string> $names
     * @throws \UnexpectedValueException the key holds CR, LF or NUL
     */
    private static function lookup(array $names): ?Secret
    {
        $name = self::first($names);
        if (is_null($name)) {
            return null;
        }
        $value = self::value($name);
        if (self::breaks($value)) {
            throw new \UnexpectedValueException("$name holds a line break or NUL - fix it in the configuration");
        }

        return new Secret($value);
    }

    /**
     * The first of $names that holds a key; null when none does.
     *
     * @param list<string> $names
     */
    private static function first(array $names): ?string
    {
        foreach ($names as $name) {
            if (self::value($name) !== '') {
                return $name;
            }
        }

        return null;
    }

    /** The constant as text; '' when it is not defined or holds no text. */
    private static function value(string $name): string
    {
        $value = defined($name) ? constant($name) : null;

        return is_scalar($value) ? (string) $value : '';
    }

    private static function breaks(string $value): bool
    {
        return strpbrk($value, "\r\n\0") !== false;
    }

    private static function part(string $name): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', trim($name, " \t\n\r\0\x0B_")));
    }
}
