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
 * Malformed input never throws: an RDN without '=' is kept as a bare value and a
 * trailing lone backslash is taken literally, so every string still has a stable key.
 */
final class Dn
{
    public static function normalize(string $dn): string
    {
        $rdns = [];
        foreach (self::splitUnescaped($dn, ',') as $rdn) {
            $parts   = self::splitUnescaped($rdn, '=', 2);
            $rdns[]  = count($parts) === 2
                ? strtolower(trim($parts[0])) . '=' . self::normalizeValue($parts[1])
                : self::normalizeValue($rdn);
        }
        return implode(',', $rdns);
    }

    public static function isDn(string $value): bool
    {
        return preg_match('/^\s*(uid|cn)\s*=/i', $value) === 1
            && count(self::splitUnescaped($value, ',')) > 1;
    }

    private static function normalizeValue(string $value): string
    {
        $value = preg_replace_callback(
            '/\\\\([0-9a-fA-F]{2}|.)/s',
            static fn(array $m): string => strlen($m[1]) === 2 ? chr((int) hexdec($m[1])) : $m[1],
            $value,
        ) ?? $value;
        // Only ',' and '\' would break re-parsing; '=' is safe because RDNs split at the first one.
        return strtr(mb_strtolower(trim($value), 'UTF-8'), ['\\' => '\5c', ',' => '\2c']);
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
