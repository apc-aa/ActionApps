<?php

declare(strict_types=1);

namespace AA\Tests\Unit\Perm\LdapToSql;

use AA\Perm\LdapToSql\Directory;
use PHPUnit\Framework\TestCase;

final class DirectoryTest extends TestCase
{
    private const PEOPLE = 'ou=People,ou=AA';
    private const GROUPS = 'ou=AA';
    private const LIMIT  = 1000;

    /** @var list<string> filters the fake server was asked */
    private array $filters = [];

    public function test_people_allEntriesOnceDespiteSizeLimit(): void
    {
        $uids = $this->syntheticUids();
        $dir  = $this->directory($this->peopleEntries($uids));

        $people = $dir->people();

        self::assertCount(2500, $people);
        $expected = array_map(fn(string $uid): string => "uid=$uid," . self::PEOPLE, $uids);
        self::assertEqualsCanonicalizing($expected, array_keys($people));
        // The cluster forces a second level of splitting, not just the first.
        self::assertContains('(&(objectClass=inetOrgPerson)(uid=ab*))', $this->filters);
    }

    public function test_people_complementFindsNonAsciiStart(): void
    {
        $dir = $this->directory($this->peopleEntries($this->syntheticUids()));

        $people = $dir->people();

        self::assertArrayHasKey('uid=žofie,' . self::PEOPLE, $people);
        self::assertArrayHasKey('uid=+x,' . self::PEOPLE, $people);
        $topComplement = array_filter(
            $this->filters,
            fn(string $f): bool => str_starts_with($f, '(&(objectClass=inetOrgPerson)(uid=*)(!(|(uid=a*)(uid=b*)'),
        );
        self::assertCount(1, $topComplement);
    }

    public function test_people_endlessLimitThrows(): void
    {
        $dir = new Directory(
            static fn(string $base, string $filter, array $attrs): array => [[], true],
            static fn(string $id, string $type): array => [],
            self::PEOPLE,
            self::GROUPS,
        );

        $this->expectException(\RuntimeException::class);
        $dir->people();
    }

    public function test_people_mapsAttributes(): void
    {
        $dir = $this->directory([
            [
                'dn'           => 'uid=Kalen01,' . self::PEOPLE,
                'objectclass'  => ['top', 'inetOrgPerson'],
                'uid'          => ['Kalen01'],
                'sn'           => ['Kalenová'],
                'givenname'    => ['Jana'],
                'mail'         => ['jana@example.org', 'kalen@example.org'],
                'userpassword' => ['{CRYPT}$6$salt$hash'],
            ],
        ]);

        self::assertSame(
            ['uid=Kalen01,' . self::PEOPLE => [
                'uid'       => 'Kalen01',
                'cn'        => null,
                'sn'        => 'Kalenová',
                'givenname' => 'Jana',
                'mail'      => ['jana@example.org', 'kalen@example.org'],
                'password'  => '{CRYPT}$6$salt$hash',
            ]],
            $dir->people(),
        );
    }

    public function test_groups_mapsMembers(): void
    {
        $dir = $this->directory([
            [
                'dn'          => 'cn=Editors,' . self::GROUPS,
                'objectclass' => ['groupOfNames'],
                'cn'          => ['Editors'],
                'member'      => ['uid=a,' . self::PEOPLE, 'uid=b,' . self::PEOPLE],
            ],
            [
                'dn'          => 'cn=Empty,' . self::GROUPS,
                'objectclass' => ['groupOfNames'],
                'cn'          => ['Empty'],
                'description' => ['nobody here'],
            ],
        ]);

        self::assertSame(
            [
                'cn=Editors,' . self::GROUPS => ['cn' => 'Editors', 'description' => '', 'members' => ['uid=a,' . self::PEOPLE, 'uid=b,' . self::PEOPLE]],
                'cn=Empty,' . self::GROUPS   => ['cn' => 'Empty', 'description' => 'nobody here', 'members' => []],
            ],
            $dir->groups(),
        );
    }

    public function test_acls_skipsObjectsWithoutPerms(): void
    {
        $acl = [
            'slice:s1' => ['uid=a,' . self::PEOPLE => 'E'],
            'aa:aa'    => ['cn=G,' . self::GROUPS => 'S', 'uid=b,' . self::PEOPLE => 'A'],
        ];
        $dir = new Directory(
            static fn(string $base, string $filter, array $attrs): array => [[], false],
            static fn(string $id, string $type): array => $acl["$type:$id"] ?? [],
            self::PEOPLE,
            self::GROUPS,
        );

        self::assertSame(
            [
                ['type' => 'slice', 'id' => 's1', 'perms' => $acl['slice:s1']],
                ['type' => 'aa', 'id' => 'aa', 'perms' => $acl['aa:aa']],
            ],
            $dir->acls([
                ['type' => 'slice', 'id' => 's1'],
                ['type' => 'slice', 'id' => 's2'],
                ['type' => 'aa', 'id' => 'aa'],
            ]),
        );
    }

    public function test_plainEntries_dropsCounts(): void
    {
        $raw = [
            'count' => 1,
            0       => [
                'count'        => 2,
                0              => 'uid',
                'uid'          => ['count' => 1, 0 => 'kalen01'],
                1              => 'userpassword',
                'userpassword' => ['count' => 1, 0 => '{CRYPT}$6$salt$hash'],
                'dn'           => 'uid=kalen01,ou=People,ou=AA',
            ],
        ];

        self::assertSame(
            [['dn' => 'uid=kalen01,ou=People,ou=AA', 'uid' => ['kalen01'], 'userpassword' => ['{CRYPT}$6$salt$hash']]],
            Directory::plainEntries($raw),
        );
    }

    public function test_people_withoutUidSurviveSplit(): void
    {
        $entries   = $this->peopleEntries($this->syntheticUids());
        $entries[] = ['dn' => 'cn=No Uid,' . self::PEOPLE, 'objectclass' => ['inetOrgPerson'], 'cn' => ['No Uid']];
        $dir       = $this->directory($entries);

        $people = $dir->people();

        self::assertCount(2501, $people);
        self::assertNull($people['cn=No Uid,' . self::PEOPLE]['uid']);
        self::assertContains('(&(objectClass=inetOrgPerson)(!(uid=*)))', $this->filters);
    }

    public function test_people_spacesInUidNeverSplitOnSpace(): void
    {
        $entries = $this->peopleEntries([...$this->syntheticUids(), ' heidi01', 'jan  novak', 'ab0001 x']);
        $dir     = $this->directory($entries);

        $people = $dir->people();

        self::assertCount(2503, $people);
        self::assertSame(' heidi01', $people['uid= heidi01,' . self::PEOPLE]['uid']);
        self::assertSame('jan  novak', $people['uid=jan  novak,' . self::PEOPLE]['uid']);
        foreach ($this->filters as $filter) {
            self::assertDoesNotMatchRegularExpression('/\(uid=[^()]* \*\)/', $filter);
        }
    }

    public function test_groups_multiValuedCnFoundOnceAfterSplit(): void
    {
        $entries = [];
        for ($i = 0; $i < 1001; ++$i) {
            $entries[] = ['dn' => sprintf('cn=g%04d,', $i) . self::GROUPS, 'objectclass' => ['groupOfNames'], 'cn' => [sprintf('g%04d', $i)]];
        }
        $entries[] = ['dn' => 'cn=Ánketa,' . self::GROUPS, 'objectclass' => ['groupOfNames'], 'cn' => ['Ánketa', 'zeta']];
        $dir       = $this->directory($entries);

        $groups = $dir->groups();

        self::assertCount(1002, $groups);
        self::assertSame('Ánketa', $groups['cn=Ánketa,' . self::GROUPS]['cn']);
    }

    public function test_acisToPerms_parsesPrincipalPerm(): void
    {
        $entries = [['dn' => 'apcobjectid=s1,ou=ACLs,ou=AA', 'apcaci' => ['uid=a,ou=People,ou=AA:E', 'cn=G,ou=AA:S', 'garbage']]];

        self::assertSame(['uid=a,ou=People,ou=AA' => 'E', 'cn=G,ou=AA' => 'S'], Directory::acisToPerms($entries));
        self::assertSame([], Directory::acisToPerms([]));
    }

    /**
     * 2 500 uids: a 1 300-strong cluster under "ab" (splits twice), the rest
     * spread over letters and digits, plus two that no listed prefix covers.
     *
     * @return list<string>
     */
    private function syntheticUids(): array
    {
        $uids = [];
        for ($i = 0; $i < 1300; ++$i) {
            $uids[] = sprintf('ab%04d', $i);
        }
        $starts = 'bcdefghijklmnopqrstuvwxyz0123456789';
        for ($i = 0; $i < 1198; ++$i) {
            $uids[] = $starts[$i % strlen($starts)] . sprintf('user%04d', $i);
        }
        $uids[] = 'žofie';
        $uids[] = '+x';
        return $uids;
    }

    /**
     * @param list<string> $uids
     * @return list<array<string, list<string>|string>>
     */
    private function peopleEntries(array $uids): array
    {
        return array_map(
            fn(string $uid): array => ['dn' => "uid=$uid," . self::PEOPLE, 'objectclass' => ['inetOrgPerson'], 'uid' => [$uid]],
            $uids,
        );
    }

    /**
     * Fake server: understands the filter shapes Directory sends, matches any
     * value of an attribute case-insensitively with insignificant spaces like
     * LDAP, and caps every answer at LIMIT entries.
     *
     * @param list<array<string, list<string>|string>> $entries
     */
    private function directory(array $entries): Directory
    {
        $search = function (string $base, string $filter, array $attrs) use ($entries): array {
            $this->filters[] = $filter;
            if (!preg_match('/objectClass=(\w+)\)/', $filter, $m)) {
                self::fail("unexpected filter $filter");
            }
            $class   = strtolower($m[1]);
            $attr    = null;
            $absent  = null;
            $include = '';
            $exclude = [];
            if (preg_match('/^\(&\(objectClass=\w+\)\(!\((\w+)=\*\)\)\)$/', $filter, $m)) {
                $absent = $m[1];
            } elseif (preg_match('/^\(&\(objectClass=\w+\)\((\w+)=([^()]*)\*\)/', $filter, $m)) {
                [, $attr, $include] = $m;
                $include            = self::fold(self::unescape($include));
                if (preg_match('/\(!\(\|(.*)\)\)\)$/', $filter, $n)) {
                    preg_match_all('/\(\w+=([^()]*)\*\)/', $n[1], $ex);
                    $exclude = array_map(fn(string $x): string => self::fold(self::unescape($x)), $ex[1]);
                }
            }
            $found = [];
            foreach ($entries as $e) {
                if (!in_array($class, array_map('strtolower', (array) $e['objectclass']), true)) {
                    continue;
                }
                if ($absent !== null && isset($e[$absent])) {
                    continue;
                }
                if ($attr !== null) {
                    $values = array_map(self::fold(...), (array) ($e[$attr] ?? []));
                    $starts = fn(string $p): bool => array_filter($values, fn(string $v): bool => str_starts_with($v, $p)) !== [];
                    if (!$starts($include) || array_filter($exclude, $starts) !== []) {
                        continue;
                    }
                }
                $found[] = $e;
            }
            return [array_slice($found, 0, self::LIMIT), count($found) > self::LIMIT];
        };
        return new Directory($search, static fn(string $id, string $type): array => [], self::PEOPLE, self::GROUPS);
    }

    /** caseIgnoreMatch with insignificant space handling */
    private static function fold(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/ +/', ' ', $value)));
    }

    private static function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\([0-9a-f]{2})/i', fn(array $m): string => chr((int) hexdec($m[1])), $value);
    }
}
