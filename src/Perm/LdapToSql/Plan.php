<?php

declare(strict_types=1);

namespace AA\Perm\LdapToSql;

/**
 * What the LDAP → SQL permission conversion will write, computed from a directory
 * snapshot without touching any database.
 *
 * People and groups get numeric ids; every spelling of a DN maps through Dn::normalize()
 * to one id. Anything that would make the SQL install lose a login, a password or a right
 * is a blocking finding; data that is dropped or left alone on purpose is an info finding.
 */
final class Plan
{
    /** Schemes AA_Perm::verifyLdapHash() can check; comparePwds() sends every '{'-prefixed hash there. */
    private const SCHEMES = ['SSHA', 'SHA', 'SMD5', 'MD5', 'CRYPT'];

    /** Byte widths of the `users` columns (include/metabase_schema.php). */
    private const WIDTHS = [
        'uid'         => 40,
        'mail'        => 40,
        'name'        => 80,
        'givenname'   => 40,
        'sn'          => 40,
        'description' => 255,
        'password'    => 255,
    ];

    /** Finding texts list at most this many examples; the count is always complete. */
    private const EXAMPLES = 10;

    /**
     * @param array<string, int> $ids normalized DN => id
     * @param array<string, string> $canonical normalized DN => directory DN
     * @param list<array{id: int, uid: string, mail: string, name: string, givenname: string, sn: string, password: string}> $users
     * @param list<array{id: int, name: string, description: string}> $groupRows
     * @param list<array{groupid: int, memberid: string}> $membership
     * @param list<array{object_type: string, objectid: string, userid: string, perm: string}> $perms
     * @param array<string, string> $remap referenced value (exact) => id
     * @param list<array{blocking: bool, text: string}> $findings
     */
    private function __construct(
        public readonly array $ids,
        public readonly array $canonical,
        public readonly array $users,
        public readonly array $groupRows,
        public readonly array $membership,
        public readonly array $perms,
        public readonly array $remap,
        public readonly array $findings,
    ) {}

    /**
     * @param array<string, array{uid: ?string, cn: ?string, sn: ?string, givenname: ?string, mail: list<string>, password: ?string}> $people keyed by the entry DN
     * @param array<string, array{cn: string, description: string, members: list<string>}> $groups keyed by the entry DN
     * @param list<array{type: string, id: string, perms: array<string, string>}> $acls
     * @param list<string> $referenced DN-shaped values found in the reference columns
     */
    public static function build(array $people, array $groups, array $acls, array $referenced, int $first_id): self
    {
        $findings = [];

        $personKeys = [];
        foreach ($people as $dn => $person) {
            if (($person['uid'] ?? '') === '') {
                $findings[] = self::block("Person $dn has no uid, so it cannot be imported.");
                continue;
            }
            $personKeys[(string) $dn] = Dn::normalize((string) $dn);
        }
        $groupKeys = [];
        foreach (array_keys($groups) as $dn) {
            $groupKeys[(string) $dn] = Dn::normalize((string) $dn);
        }
        asort($personKeys, SORT_STRING);
        asort($groupKeys, SORT_STRING);

        $ids       = [];
        $canonical = [];
        $next      = $first_id;
        foreach ($personKeys + $groupKeys as $dn => $key) {
            if (isset($canonical[$key])) {
                // Merging would silently fuse two accounts; LDAP has to be cleaned first.
                $findings[] = self::block("Directory entries {$canonical[$key]} and $dn are the same DN for AA; remove or rename one of them in LDAP.");
                continue;
            }
            $ids[$key]       = $next++;
            $canonical[$key] = $dn;
        }

        $users     = [];
        $multiMail = [];
        foreach ($personKeys as $dn => $key) {
            if ($canonical[$key] !== $dn) {
                continue;
            }
            $person = $people[$dn];
            if (count($person['mail']) > 1) {
                $multiMail[] = "$dn (" . implode(', ', $person['mail']) . ')';
            }
            $row = [
                'id'        => $ids[$key],
                'uid'       => (string) $person['uid'],
                'mail'      => $person['mail'][0] ?? '',
                'name'      => $person['cn'] ?? trim(($person['givenname'] ?? '') . ' ' . ($person['sn'] ?? '')),
                'givenname' => $person['givenname'] ?? '',
                'sn'        => $person['sn'] ?? '',
                'password'  => self::password($person['password'], $dn, $findings),
            ];
            self::checkWidths($row, $dn, $findings);
            $users[] = $row;
        }
        $findings[] = self::info('People with more than one mail keep only the first', $multiMail);

        $groupRows  = [];
        $membership = [];
        $unknown    = [];
        $duplicates = [];
        foreach ($groupKeys as $dn => $key) {
            if ($canonical[$key] !== $dn) {
                continue;
            }
            $row = ['id' => $ids[$key], 'name' => $groups[$dn]['cn'], 'description' => $groups[$dn]['description']];
            self::checkWidths($row, $dn, $findings);
            $groupRows[] = $row;

            $seen = [];
            foreach ($groups[$dn]['members'] as $member) {
                $memberid = self::principal($member, $ids);
                if ($memberid === null) {
                    $unknown[] = "$member in $dn";
                } elseif (isset($seen[$memberid])) {
                    $duplicates[] = "$member in $dn";
                } else {
                    $seen[$memberid] = true;
                    $membership[]    = ['groupid' => $ids[$key], 'memberid' => $memberid];
                }
            }
        }
        $findings[] = self::info('Group members not in the directory are dropped', $unknown);
        $findings[] = self::info('Group members listed twice under different DN spellings are imported once', $duplicates);

        $perms      = [];
        $unknown    = [];
        $duplicates = [];
        foreach ($acls as $acl) {
            /** @var array<string, array{0: string, 1: string}> $chosen userid => [principal, perm] */
            $chosen = [];
            foreach ($acl['perms'] as $principal => $perm) {
                $principal = (string) $principal;
                $userid    = self::principal($principal, $ids);
                $where     = "{$acl['type']} {$acl['id']}";
                if ($userid === null) {
                    $unknown[] = "$principal on $where";
                } elseif (!isset($chosen[$userid])) {
                    $chosen[$userid] = [$principal, $perm];
                } elseif ($principal === $canonical[Dn::normalize($principal)]) {
                    $duplicates[]    = "{$chosen[$userid][0]} on $where";
                    $chosen[$userid] = [$principal, $perm];
                } else {
                    $duplicates[] = "$principal on $where";
                }
            }
            foreach ($chosen as $userid => [, $perm]) {
                $perms[] = ['object_type' => $acl['type'], 'objectid' => $acl['id'], 'userid' => (string) $userid, 'perm' => $perm];
            }
        }
        $findings[] = self::info('Rights of people or groups not in the directory are dropped', $unknown);
        $findings[] = self::info(
            'Rights granted to one person or group under several DN spellings keep the directory spelling (else the first); dropped',
            $duplicates,
        );

        $remap   = [];
        $unknown = [];
        foreach (array_unique($referenced) as $value) {
            if (!Dn::isDn($value)) {
                continue;
            }
            $key = Dn::normalize($value);
            if (isset($ids[$key])) {
                $remap[$value] = (string) $ids[$key];
            } else {
                $unknown[] = $value;
            }
        }
        $findings[] = self::info('Referenced DNs not in the directory stay as they are', $unknown);

        return new self($ids, $canonical, $users, $groupRows, $membership, $perms, $remap, array_values(array_filter($findings)));
    }

    public function blocking(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding['blocking']) {
                return true;
            }
        }
        return false;
    }

    /**
     * A non-DN principal (a Reader id, a Reader set's group id) is already what SQL stores.
     *
     * @param array<string, int> $ids
     */
    private static function principal(string $value, array $ids): ?string
    {
        if (!Dn::isDn($value)) {
            return $value;
        }
        $id = $ids[Dn::normalize($value)] ?? null;
        return $id === null ? null : (string) $id;
    }

    /** @param list<array{blocking: bool, text: string}|null> $findings */
    private static function password(?string $password, string $dn, array &$findings): string
    {
        if ($password === null || $password === '') {
            $findings[] = self::block("Person $dn has no password.");
            return '';
        }
        if (!str_starts_with($password, '{')) {
            return \AA_Permsystem_Sql::generatePasswordHash($password);
        }
        if (preg_match('/^\{([A-Za-z0-9]+)\}/', $password, $m) && in_array(strtoupper($m[1]), self::SCHEMES, true)) {
            return $password;
        }
        $scheme     = preg_match('/^\{[^}]*\}/', $password, $m) ? $m[0] : '{';
        $findings[] = self::block("Person $dn has a password in the $scheme scheme, which AA cannot verify.");
        return '';
    }

    /**
     * @param array<string, int|string> $row
     * @param list<array{blocking: bool, text: string}|null> $findings
     */
    private static function checkWidths(array $row, string $dn, array &$findings): void
    {
        foreach (array_intersect_key(self::WIDTHS, $row) as $column => $width) {
            $length = strlen((string) $row[$column]);
            if ($length > $width) {
                $findings[] = self::block("$dn: $column is $length bytes, the users column holds $width.");
            }
        }
    }

    /** @return array{blocking: bool, text: string} */
    private static function block(string $text): array
    {
        return ['blocking' => true, 'text' => $text];
    }

    /**
     * @param list<string> $items
     * @return array{blocking: bool, text: string}|null
     */
    private static function info(string $text, array $items): ?array
    {
        if (!$items) {
            return null;
        }
        $more = count($items) > self::EXAMPLES ? '; ...' : '';
        return [
            'blocking' => false,
            'text'     => "$text (" . count($items) . '): ' . implode('; ', array_slice($items, 0, self::EXAMPLES)) . $more,
        ];
    }
}
