<?php

declare(strict_types=1);

namespace AA\Perm\LdapToSql;

/**
 * One canonical key for an LDAP DN.
 *
 * AA data holds the same DN in many spellings (case, spaces around '=' and ',',
 * RFC 4514 escapes like `\20`); LDAP's caseIgnore/DN matching treats them as one
 * entry, so the conversion has to as well.
 *
 * Spaces follow LDAP insignificant-space handling: leading and trailing ones are
 * dropped even when escaped (`\20x` = `x`), inner runs collapse to one.
 * A value that is not valid UTF-8 (legacy cp1250 bytes) is lowercased in ASCII only,
 * because mb_strtolower would turn distinct bytes into '?' and merge distinct people.
 *
 * Malformed input never throws and still gets a stable key: an RDN without '=' is kept
 * as a bare value, a trailing lone backslash is literal, empty RDNs (`uid=x,ou=AA,`)
 * are dropped.
 *
 * Not handled, as AA data does not use them: multi-valued RDNs ('+'), attribute
 * aliases and OIDs (`2.5.4.3=` vs `cn=`), `#`-prefixed BER hex values.
 */
final class Dn
{
    public static function normalize(string $dn): string
    {
        $rdns = [];
        foreach (self::splitUnescaped($dn, ',') as $rdn) {
            $parts = self::splitUnescaped($rdn, '=', 2);
            $rdn   = count($parts) === 2
                ? strtolower(trim($parts[0])) . '=' . self::normalizeValue($parts[1])
                : self::normalizeValue($rdn);
            if ($rdn !== '') {
                $rdns[] = $rdn;
            }
        }
        return implode(',', $rdns);
    }

    public static function isDn(string $value): bool
    {
        return preg_match('/^\s*(uid|cn)\s*=/i', $value) === 1
            && count(self::splitUnescaped($value, ',')) > 1;
    }

    /**
     * The value of the first RDN when its attribute is $attribute, unescaped and trimmed
     * but otherwise as written - e.g. the uid an LDAP login binds as.
     */
    public static function firstValue(string $dn, string $attribute): ?string
    {
        $parts = self::splitUnescaped(self::splitUnescaped($dn, ',', 2)[0], '=', 2);
        return count($parts) === 2 && strcasecmp(trim($parts[0]), $attribute) === 0
            ? trim(self::unescape($parts[1]))
            : null;
    }

    private static function unescape(string $value): string
    {
        return preg_replace_callback(
            '/\\\\([0-9a-fA-F]{2}|.)/s',
            static fn(array $m): string => strlen($m[1]) === 2 ? chr((int) hexdec($m[1])) : $m[1],
            $value,
        ) ?? $value;
    }

    private static function normalizeValue(string $value): string
    {
        $value = preg_replace('/ {2,}/', ' ', trim(self::unescape($value))) ?? $value;
        $value = mb_check_encoding($value, 'UTF-8') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        // Only ',' and '\' would break re-parsing; '=' is safe because RDNs split at the first one.
        return strtr($value, ['\\' => '\5c', ',' => '\2c']);
    }

    /**
     * Separator and backslash are ASCII, so a byte scan is safe on UTF-8.
     *
     * @return list<string>
     */
    private static function splitUnescaped(string $s, string $separator, int $limit = PHP_INT_MAX): array
    {
        $parts   = [];
        $current = '';
        $len     = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $current .= $c . $s[++$i];
            } elseif ($c === $separator && count($parts) < $limit - 1) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $c;
            }
        }
        $parts[] = $current;
        return $parts;
    }
}
