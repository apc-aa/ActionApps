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
     * Fake server: understands the filter shapes Directory sends, matches
     * case-insensitively like LDAP and caps every answer at LIMIT entries.
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
            $include = '';
            $exclude = [];
            if (preg_match('/^\(&\(objectClass=\w+\)\((\w+)=([^()]*)\*\)/', $filter, $m)) {
                [, $attr, $include] = $m;
                $include            = self::unescape($include);
                if (preg_match('/\(!\(\|(.*)\)\)\)$/', $filter, $n)) {
                    preg_match_all('/\(\w+=([^()]*)\*\)/', $n[1], $ex);
                    $exclude = array_map(self::unescape(...), $ex[1]);
                }
            }
            $found = [];
            foreach ($entries as $e) {
                $classes = array_map('strtolower', (array) $e['objectclass']);
                if (!in_array($class, $classes, true)) {
                    continue;
                }
                if ($attr !== null) {
                    $value = mb_strtolower(((array) ($e[strtolower($attr)] ?? ['']))[0]);
                    if (!str_starts_with($value, mb_strtolower($include))) {
                        continue;
                    }
                    foreach ($exclude as $x) {
                        if (str_starts_with($value, mb_strtolower($x))) {
                            continue 2;
                        }
                    }
                }
                $found[] = $e;
            }
            return [array_slice($found, 0, self::LIMIT), count($found) > self::LIMIT];
        };
        return new Directory($search, static fn(string $id, string $type): array => [], self::PEOPLE, self::GROUPS);
    }

    private static function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\([0-9a-f]{2})/i', fn(array $m): string => chr((int) hexdec($m[1])), $value);
    }
}
