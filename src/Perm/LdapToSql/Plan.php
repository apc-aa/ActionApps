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
    /** varbinary(N) columns of `users` (include/metabase_schema.php): N bytes */
    private const BYTE_WIDTHS = ['uid' => 40, 'password' => 255];

    /** char(N) columns of `users`: N characters, which is N bytes unless the DB is UTF-8 */
    private const CHAR_WIDTHS = ['mail' => 40, 'name' => 80, 'givenname' => 40, 'sn' => 40, 'description' => 255];

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
     * @param string $db_character_set DB_CHARACTER_SET of the install; LDAP values are stored byte for byte,
     *                                 so only a utf8* DB counts char(N) in characters
     */
    public static function build(array $people, array $groups, array $acls, array $referenced, int $first_id, string $db_character_set = ''): self
    {
        $findings = [];
        $utf8     = str_starts_with(strtolower($db_character_set), 'utf8');

        $personKeys = [];
        $uids       = [];
        $uidDiffers = [];
        foreach ($people as $dn => $person) {
            $dn = (string) $dn;
            // LDAP login binds as the DN's uid RDN, so that value is the login, not the attribute
            $rdnUid = Dn::firstValue($dn, 'uid');
            $uid    = $rdnUid ?? $person['uid'] ?? '';
            if ($uid === '') {
                $findings[] = self::block("Person $dn has no uid, so it cannot be imported.");
                continue;
            }
            if ($rdnUid !== null && $person['uid'] !== null && strcasecmp($person['uid'], $rdnUid) !== 0) {
                $uidDiffers[] = "$dn (uid attribute {$person['uid']})";
            }
            $uids[$dn]       = $uid;
            $personKeys[$dn] = Dn::normalize($dn);
        }
        $findings[] = self::info('People whose uid attribute differs from the uid in their DN log in with the DN one', $uidDiffers);

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
        if ($next - 1 > References::MAX_ID) {
            $findings[] = self::block('The ids would reach ' . ($next - 1) . ', above ' . References::MAX_ID . ', the highest users.id can hold.');
        }

        $users     = [];
        $multiMail = [];
        $logins    = ['uid' => [], 'mail' => []];
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
                'uid'       => $uids[$dn],
                'mail'      => $person['mail'][0] ?? '',
                'name'      => $person['cn'] ?? trim(($person['givenname'] ?? '') . ' ' . ($person['sn'] ?? '')),
                'givenname' => $person['givenname'] ?? '',
                'sn'        => $person['sn'] ?? '',
                'password'  => self::password($person['password'], $dn, $findings),
            ];
            self::checkWidths($row, $dn, $utf8, $findings);
            foreach ($logins as $column => $_) {
                $login = mb_strtolower(trim($row[$column]));
                if ($login !== '') {
                    $logins[$column][$login][] = $dn;
                }
            }
            $users[] = $row;
        }
        $findings[] = self::info('People with more than one mail keep only the first', $multiMail);
        $shared = [];
        foreach ($logins as $column => $byLogin) {
            foreach ($byLogin as $login => $dns) {
                if (count($dns) > 1) {
                    $shared[] = "$column $login: " . implode(', ', $dns);
                }
            }
        }
        $findings[] = self::info('Logins shared by several people; login picks the account by password', $shared);

        $groupRows  = [];
        $membership = [];
        $unknown    = [];
        $duplicates = [];
        foreach ($groupKeys as $dn => $key) {
            if ($canonical[$key] !== $dn) {
                continue;
            }
            $row = ['id' => $ids[$key], 'name' => $groups[$dn]['cn'], 'description' => $groups[$dn]['description']];
            self::checkWidths($row, $dn, $utf8, $findings);
            $groupRows[] = $row;

            $seen = [];
            foreach ($groups[$dn]['members'] as $member) {
                $memberid = self::principal($member, $ids)[0] ?? null;
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
        $findings[] = self::info('Group members that are neither in the directory nor a reader id are dropped', $unknown);
        $findings[] = self::info('Group members listed twice under different DN spellings are imported once', $duplicates);

        $perms      = [];
        $unknown    = [];
        $duplicates = [];
        $permKeys   = [];
        $repeated   = [];
        foreach ($acls as $acl) {
            $where = "{$acl['type']} {$acl['id']}";
            /** @var array<string, array{0: string, 1: string, 2: bool}> $chosen userid => [principal, perm, spelled as in the directory] */
            $chosen = [];
            foreach ($acl['perms'] as $principal => $perm) {
                $principal = (string) $principal;
                $found     = self::principal($principal, $ids);
                if ($found === null) {
                    $unknown[] = "$principal on $where";
                    continue;
                }
                [$userid, $key] = $found;
                // LDAP's getJoinedPerms() used the first ACI naming the DN case-insensitively
                $asDirectory = $key !== null && strcasecmp($principal, $canonical[$key] ?? '') === 0;
                if (!isset($chosen[$userid])) {
                    $chosen[$userid] = [$principal, $perm, $asDirectory];
                } elseif ($asDirectory && !$chosen[$userid][2]) {
                    $duplicates[]    = "{$chosen[$userid][0]} on $where";
                    $chosen[$userid] = [$principal, $perm, true];
                } else {
                    $duplicates[] = "$principal on $where";
                }
            }
            foreach ($chosen as $userid => [$principal, $perm]) {
                // one row per perms primary key, else the insert of the second one fails
                $permKey = "{$acl['type']}\0{$acl['id']}\0$userid";
                if (isset($permKeys[$permKey])) {
                    $repeated[] = "$principal on $where";
                    continue;
                }
                $permKeys[$permKey] = true;
                $perms[] = ['object_type' => $acl['type'], 'objectid' => $acl['id'], 'userid' => (string) $userid, 'perm' => $perm];
            }
        }
        $findings[] = self::info('Objects listed more than once keep the first right', $repeated);
        $findings[] = self::info('Rights of principals that are neither in the directory nor a reader id are dropped', $unknown);
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
     * SQL id and normalized DN of a member or ACL principal; a reader id is already what
     * SQL stores. Anything else (placeholders like cn=aauser, stray numbers) has no SQL owner.
     *
     * @param array<string, int> $ids
     * @return array{0: string, 1: ?string}|null
     */
    private static function principal(string $value, array $ids): ?array
    {
        if (Dn::isDn($value)) {
            $key = Dn::normalize($value);
            return isset($ids[$key]) ? [(string) $ids[$key], $key] : null;
        }
        return \is_long_id($value) ? [$value, null] : null;
    }

    /** @param list<array{blocking: bool, text: string}|null> $findings */
    private static function password(?string $password, string $dn, array &$findings): string
    {
        if ($password === null || $password === '') {
            $findings[] = self::block("Person $dn has no password.");
            return '';
        }
        // AA_Perm::comparePwds() treats every '{'-prefixed value as an LDAP hash
        if (!str_starts_with($password, '{')) {
            return \AA_Perm::cryptPwd($password);
        }
        if (\AA_Perm::isVerifiableLdapHash($password)) {
            return $password;
        }
        $scheme     = preg_match('/^\{[^}]*\}/', $password, $m) ? $m[0] : '{';
        $findings[] = self::block("Person $dn has a $scheme password hash that AA cannot verify.");
        return '';
    }

    /**
     * @param array<string, int|string> $row
     * @param list<array{blocking: bool, text: string}|null> $findings
     */
    private static function checkWidths(array $row, string $dn, bool $utf8, array &$findings): void
    {
        foreach ($row as $column => $value) {
            if (isset(self::BYTE_WIDTHS[$column])) {
                [$width, $length, $unit] = [self::BYTE_WIDTHS[$column], strlen((string) $value), 'bytes'];
            } elseif (isset(self::CHAR_WIDTHS[$column])) {
                [$width, $length, $unit] = $utf8
                    ? [self::CHAR_WIDTHS[$column], mb_strlen((string) $value, 'UTF-8'), 'characters']
                    : [self::CHAR_WIDTHS[$column], strlen((string) $value), 'bytes'];
            } else {
                continue;
            }
            if ($length > $width) {
                $findings[] = self::block("$dn: $column is $length $unit, the users column holds $width.");
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
