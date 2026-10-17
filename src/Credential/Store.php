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
 * Name a new vendor in one word: AA_APIKEY_ISDS_REGISTRY_LOGIN of a vendor isds-registry is also
 * the context REGISTRY_LOGIN of the vendor isds, whose contexts name accounts (contextsIn()).
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

    /**
     * The key of exactly this vendor and context, never the vendor's own: for a key of one named
     * thing (an account), where the vendor's key would be somebody else's. No context, no key.
     *
     * @param array<string, mixed>|null $constants name => value, as userConstants(); null = the constants defined now
     * @throws \UnexpectedValueException as resolve()
     */
    public static function resolveExact(string $vendor, string $context, #[\SensitiveParameter] ?array $constants = null): ?Secret
    {
        return (self::part($context) === '') ? null : self::lookup(array_slice(self::names($vendor, $context), 0, 1), $constants);
    }

    /**
     * A vendor's constants among $constants with their contexts as written after the vendor; '' for
     * the vendor's own key. The vendor is matched in any case, so that a name Store never reads can
     * be reported - names() spells the one it reads. For a vendor whose contexts are not fixed (a
     * set per account).
     *
     * @param string               $vendor    one word of letters and digits: the contexts of a vendor
     *                                        of two words would be read wrongly
     * @param array<string, mixed> $constants as damagedIn()
     * @return array<string, string> constant name => context
     * @throws \InvalidArgumentException a vendor of more words
     */
    public static function contextsIn(string $vendor, #[\SensitiveParameter] array $constants): array
    {
        $vendor = self::part($vendor);
        if (preg_match('/^[A-Z0-9]+\z/', $vendor) !== 1) {
            throw new \InvalidArgumentException('Contexts are listed only for a vendor of one word of letters and digits');
        }
        $own      = self::PREFIX . $vendor;
        $contexts = [];
        foreach (array_keys($constants) as $name) {
            $name = (string) $name;
            if (strcasecmp($name, $own) === 0) {
                $contexts[$name] = '';
            } elseif (strncasecmp($name, $own . '_', strlen($own) + 1) === 0) {
                $contexts[$name] = substr($name, strlen($own) + 1);
            }
        }

        return $contexts;
    }

    /**
     * The constants include/config.php and AA define, keys among them - pass them on only to
     * #[\SensitiveParameter] parameters.
     *
     * @return array<string, mixed>
     */
    public static function userConstants(): array
    {
        return get_defined_constants(true)['user'] ?? [];
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
     * @param array<string, mixed> $constants name => value, as userConstants()
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
     * @param list<string>              $names
     * @param array<string, mixed>|null $constants null = the constants defined now
     * @throws \UnexpectedValueException the key holds CR, LF or NUL
     */
    private static function lookup(array $names, #[\SensitiveParameter] ?array $constants = null): ?Secret
    {
        $name = self::first($names, $constants);
        if (is_null($name)) {
            return null;
        }
        $value = self::value($name, $constants);
        if (self::breaks($value)) {
            throw new \UnexpectedValueException("$name holds a line break or NUL - fix it in the configuration");
        }

        return new Secret($value);
    }

    /**
     * The first of $names that holds a key; null when none does.
     *
     * @param list<string>              $names
     * @param array<string, mixed>|null $constants
     */
    private static function first(array $names, #[\SensitiveParameter] ?array $constants = null): ?string
    {
        foreach ($names as $name) {
            if (self::value($name, $constants) !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * The constant as text; '' when it is not defined or holds no text.
     *
     * @param array<string, mixed>|null $constants
     */
    private static function value(string $name, #[\SensitiveParameter] ?array $constants = null): string
    {
        $value = is_null($constants) ? (defined($name) ? constant($name) : null) : ($constants[$name] ?? null);

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
