<?php

declare(strict_types=1);

namespace AA\Perm\LdapToSql;

/**
 * Snapshot of the LDAP people, groups and ACLs.
 *
 * Production OpenLDAP caps every search of a non-root bind (paged searches
 * included), so one `(objectClass=X)` search may return only part of the
 * directory. On a hit the search is split by the first characters of the
 * naming attribute, plus a complement filter for values starting with any
 * other character (diacritics, '+', a space…), until no answer is capped.
 * A split search also asks for entries without the naming attribute, which
 * no prefix filter can match.
 */
final class Directory
{
    /**
     * LDAP matches uid/cn case-insensitively, so lower case covers upper case too.
     * No space: insignificant-space matching makes `(uid=jan *)` equal `(uid=jan*)`,
     * so splitting on it would never shrink the answer.
     */
    private const PREFIX_CHARS       = 'abcdefghijklmnopqrstuvwxyz0123456789.-_@';
    private const MAX_DEPTH          = 6;
    private const SIZELIMIT_EXCEEDED = 4;
    private const NO_SUCH_OBJECT     = 32;

    /**
     * @param \Closure(string $base, string $filter, list<string> $attrs): array{0: list<array<string, list<string>|string>>, 1: bool} $search
     *        entries with lower-case attribute names (no 'count'), 'dn' as string; [1] = the server's size limit was hit
     * @param \Closure(string $objectId, string $objectType): array<string, string> $readAcl principal => perm
     */
    public function __construct(
        private readonly \Closure $search,
        private readonly \Closure $readAcl,
        private readonly string $peopleBase = '',
        private readonly string $groupsBase = '',
    ) {}

    public static function fromConfig(): self
    {
        if (!\extension_loaded('ldap')) {
            throw new \RuntimeException('PHP ldap extension is not loaded');
        }
        $config = \AA_Permsystem_Ldap::getLdap();
        $ds     = @\ldap_connect('ldap://' . $config['host'] . ':' . $config['port']);
        if ($ds === false) {
            throw new \RuntimeException('Cannot connect to LDAP');
        }
        @\ldap_set_option($ds, \LDAP_OPT_PROTOCOL_VERSION, 3);
        if (!@\ldap_bind($ds, $config['binddn'], $config['bindpw'])) {
            throw new \RuntimeException('Cannot bind to LDAP: ' . \ldap_error($ds));
        }
        $search = static function (string $base, string $filter, array $attrs) use ($ds): array {
            // A capped search warns and still returns the partial entries.
            $result = @\ldap_search($ds, $base, $filter, $attrs);
            $errno  = \ldap_errno($ds);
            if ($result === false || ($errno !== 0 && $errno !== self::SIZELIMIT_EXCEEDED)) {
                throw new \RuntimeException("LDAP search $filter in $base failed: " . \ldap_error($ds));
            }
            return [self::plainEntries(\ldap_get_entries($ds, $result) ?: []), $errno === self::SIZELIMIT_EXCEEDED];
        };
        // Not AA_Permsystem_Ldap::getObjectsPerms(): it answers [] on any error,
        // which here would silently drop rights.
        $readAcl = static function (string $objectId, string $objectType) use ($ds, $config): array {
            $dn     = 'apcobjectid=' . self::escapeDnValue($objectId) . ',' . $config['acls'];
            $filter = '(&(objectclass=apcacl)(apcobjecttype=' . self::escape($objectType) . '))';
            $result = @\ldap_read($ds, $dn, $filter, ['apcaci']);
            $errno  = \ldap_errno($ds);
            if ($errno === self::NO_SUCH_OBJECT) {
                return [];
            }
            if ($result === false || $errno !== 0) {
                throw new \RuntimeException("LDAP ACL read of $dn failed: " . \ldap_error($ds));
            }
            return self::acisToPerms(self::plainEntries(\ldap_get_entries($ds, $result) ?: []));
        };
        return new self($search, $readAcl, $config['people'], $config['groups']);
    }

    /**
     * apcaci values `principal:perm` of the first entry, as ParseApcAci() reads them.
     *
     * @param list<array<string, list<string>|string>> $entries
     * @return array<string, string> principal => perm
     */
    public static function acisToPerms(array $entries): array
    {
        $perms = [];
        foreach (self::all($entries[0] ?? [], 'apcaci') as $aci) {
            $parts = explode(':', $aci);
            if (count($parts) >= 2) {
                $perms[$parts[0]] = $parts[1];
            }
        }
        return $perms;
    }

    /**
     * ldap_get_entries() shape → entries without the 'count' keys.
     *
     * @param array<int|string, mixed> $raw
     * @return list<array<string, list<string>|string>>
     */
    public static function plainEntries(array $raw): array
    {
        $entries = [];
        foreach ($raw as $i => $entry) {
            if (!is_int($i) || !is_array($entry)) {
                continue;
            }
            $plain = ['dn' => (string) ($entry['dn'] ?? '')];
            foreach ($entry as $attr => $values) {
                if (is_string($attr) && is_array($values)) {
                    unset($values['count']);
                    $plain[$attr] = array_map('strval', array_values($values));
                }
            }
            $entries[] = $plain;
        }
        return $entries;
    }

    /** @return array<string, array{uid: ?string, cn: ?string, sn: ?string, givenname: ?string, mail: list<string>, password: ?string}> keyed by the entry DN */
    public function people(): array
    {
        $people = [];
        $attrs  = ['uid', 'cn', 'sn', 'givenname', 'mail', 'userpassword'];
        foreach ($this->collect($this->peopleBase, 'inetOrgPerson', 'uid', $attrs) as $dn => $e) {
            $people[$dn] = [
                'uid'       => self::first($e, 'uid'),
                'cn'        => self::first($e, 'cn'),
                'sn'        => self::first($e, 'sn'),
                'givenname' => self::first($e, 'givenname'),
                'mail'      => self::all($e, 'mail'),
                'password'  => self::first($e, 'userpassword'),
            ];
        }
        return $people;
    }

    /** @return array<string, array{cn: string, description: string, members: list<string>}> keyed by the entry DN */
    public function groups(): array
    {
        $groups = [];
        foreach ($this->collect($this->groupsBase, 'groupOfNames', 'cn', ['cn', 'description', 'member']) as $dn => $e) {
            $groups[$dn] = [
                'cn'          => self::first($e, 'cn') ?? '',
                'description' => self::first($e, 'description') ?? '',
                'members'     => self::all($e, 'member'),
            ];
        }
        return $groups;
    }

    /**
     * @param list<array{type: string, id: string}> $objects
     * @return list<array{type: string, id: string, perms: array<string, string>}> only objects with at least one perm
     */
    public function acls(array $objects): array
    {
        $acls = [];
        foreach ($objects as $object) {
            $perms = ($this->readAcl)($object['id'], $object['type']);
            if ($perms) {
                $acls[] = ['type' => $object['type'], 'id' => $object['id'], 'perms' => $perms];
            }
        }
        return $acls;
    }

    /**
     * @param list<string> $attrs
     * @return array<string, array<string, list<string>|string>> keyed by DN
     */
    private function collect(string $base, string $objectClass, string $attr, array $attrs, string $prefix = ''): array
    {
        if (strlen($prefix) > self::MAX_DEPTH) {
            throw new \RuntimeException("LDAP size limit hit even for $attr=$prefix* in $base");
        }
        $class          = "(objectClass=$objectClass)";
        $filter         = $prefix === '' ? $class : "(&$class($attr=" . self::escape($prefix) . '*))';
        [$found, $hit]  = $this->search($base, $filter, $attrs);
        if (!$hit) {
            return $found;
        }
        $others = '';
        foreach (str_split(self::PREFIX_CHARS) as $c) {
            $found  += $this->collect($base, $objectClass, $attr, $attrs, $prefix . $c);
            $others .= "($attr=" . self::escape($prefix . $c) . '*)';
        }
        $rest = ["(&$class($attr=" . self::escape($prefix) . "*)(!(|$others)))"];
        if ($prefix === '') {
            $rest[] = "(&$class(!($attr=*)))";
        }
        foreach ($rest as $filter) {
            [$entries, $hit] = $this->search($base, $filter, $attrs);
            if ($hit) {
                throw new \RuntimeException("LDAP size limit hit for $filter in $base, which cannot be split further");
            }
            $found += $entries;
        }
        return $found;
    }

    /**
     * @param list<string> $attrs
     * @return array{0: array<string, array<string, list<string>|string>>, 1: bool} entries keyed by DN, the server's size limit was hit
     */
    private function search(string $base, string $filter, array $attrs): array
    {
        [$entries, $hit] = ($this->search)($base, $filter, $attrs);
        $found           = [];
        foreach ($entries as $entry) {
            if (is_string($entry['dn'] ?? null)) {
                $found[$entry['dn']] = $entry;
            }
        }
        return [$found, $hit];
    }

    /** ldap_escape(…, LDAP_ESCAPE_FILTER) without needing the ldap extension. */
    private static function escape(string $value): string
    {
        return strtr($value, ['\\' => '\5c', '*' => '\2a', '(' => '\28', ')' => '\29', "\0" => '\00']);
    }

    /** ldap_escape(…, LDAP_ESCAPE_DN) without needing the ldap extension. */
    private static function escapeDnValue(string $value): string
    {
        $escaped = strtr($value, ['\\' => '\5c', ',' => '\2c', '+' => '\2b', '"' => '\22', '<' => '\3c', '>' => '\3e', ';' => '\3b', '=' => '\3d', "\0" => '\00']);
        return (string) preg_replace(['/^[ #]/', '/ $/'], ['\\\\$0', '\\\\20'], $escaped);
    }

    /** @param array<string, list<string>|string> $entry */
    private static function first(array $entry, string $attr): ?string
    {
        return self::all($entry, $attr)[0] ?? null;
    }

    /**
     * @param array<string, list<string>|string> $entry
     * @return list<string>
     */
    private static function all(array $entry, string $attr): array
    {
        return (array) ($entry[$attr] ?? []);
    }
}
