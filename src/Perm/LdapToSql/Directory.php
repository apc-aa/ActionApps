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
 * other character (diacritics, '+'…), until no answer is capped.
 */
final class Directory
{
    /** LDAP matches uid/cn case-insensitively, so lower case covers upper case too. */
    private const PREFIX_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789 .-_@';
    private const MAX_DEPTH    = 6;
    private const SIZELIMIT_EXCEEDED = 4;

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
        $ldap = new \AA_Permsystem_Ldap();   // autoloads perm_ldap.php with InitLDAP()
        $ds   = \InitLDAP();
        if (!$ds) {
            throw new \RuntimeException('Cannot connect or bind to LDAP');
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
        $readAcl = static fn(string $objectId, string $objectType): array => $ldap->getObjectsPerms($objectId, $objectType);
        $config  = \AA_Permsystem_Ldap::getLdap();
        return new self($search, $readAcl, $config['people'], $config['groups']);
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

    /** @return array<string, array{uid: string, cn: ?string, sn: ?string, givenname: ?string, mail: list<string>, password: ?string}> keyed by the entry DN */
    public function people(): array
    {
        $people = [];
        $attrs  = ['uid', 'cn', 'sn', 'givenname', 'mail', 'userpassword'];
        foreach ($this->enumerate($this->peopleBase, 'inetOrgPerson', 'uid', $attrs) as $dn => $e) {
            $people[$dn] = [
                'uid'       => self::first($e, 'uid') ?? '',
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
        foreach ($this->enumerate($this->groupsBase, 'groupOfNames', 'cn', ['cn', 'description', 'member']) as $dn => $e) {
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
    private function enumerate(string $base, string $objectClass, string $attr, array $attrs): array
    {
        $found = [];
        $this->collect($found, $base, $objectClass, $attr, $attrs, '');
        return $found;
    }

    /**
     * @param array<string, array<string, list<string>|string>> $found
     * @param list<string> $attrs
     */
    private function collect(array &$found, string $base, string $objectClass, string $attr, array $attrs, string $prefix): void
    {
        if (strlen($prefix) > self::MAX_DEPTH) {
            throw new \RuntimeException("LDAP size limit hit even for $attr=$prefix* in $base");
        }
        $class  = "(objectClass=$objectClass)";
        $filter = $prefix === '' ? $class : "(&$class(" . $attr . '=' . self::escape($prefix) . '*))';
        if (!$this->search($found, $base, $filter, $attrs)) {
            return;
        }
        $others = '';
        foreach (str_split(self::PREFIX_CHARS) as $c) {
            $this->collect($found, $base, $objectClass, $attr, $attrs, $prefix . $c);
            $others .= "($attr=" . self::escape($prefix . $c) . '*)';
        }
        $complement = "(&$class($attr=" . self::escape($prefix) . "*)(!(|$others)))";
        if ($this->search($found, $base, $complement, $attrs)) {
            throw new \RuntimeException("LDAP size limit hit for $attr=$prefix* starting with an unlisted character in $base");
        }
    }

    /**
     * @param array<string, array<string, list<string>|string>> $found
     * @param list<string> $attrs
     * @return bool the server's size limit was hit
     */
    private function search(array &$found, string $base, string $filter, array $attrs): bool
    {
        [$entries, $hit] = ($this->search)($base, $filter, $attrs);
        foreach ($entries as $entry) {
            if (is_string($entry['dn'] ?? null)) {
                $found[$entry['dn']] = $entry;
            }
        }
        return $hit;
    }

    /** ldap_escape(…, LDAP_ESCAPE_FILTER) without needing the ldap extension. */
    private static function escape(string $value): string
    {
        return strtr($value, ['\\' => '\5c', '*' => '\2a', '(' => '\28', ')' => '\29', "\0" => '\00']);
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
