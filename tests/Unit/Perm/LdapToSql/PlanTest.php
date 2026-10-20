<?php

declare(strict_types=1);

// Hashing a cleartext password loads include/perm_sql.php, whose include/locsess.php
// needs the constants include/config.php normally provides.

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 4) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace AA\Tests\Unit\Perm\LdapToSql {

    use AA\Perm\LdapToSql\Plan;
    use PHPUnit\Framework\TestCase;

    final class PlanTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../Legacy/fixtures/store_fixtures.php';
        }

        private const READER = '0123456789abcdef0123456789abcdef';
        private const CRYPT  = '{CRYPT}$6$salt$hashhashhash';

        public function test_build_idsFromFirstIdPeopleThenGroups(): void
        {
            $plan = $this->plan();

            self::assertSame([
                'uid=eva,ou=people,ou=aa'     => 336,
                'uid=jan,ou=people,ou=aa'     => 337,
                'uid=kalen01,ou=people,ou=aa' => 338,
                'cn=aa admins,ou=aa'          => 339,
                'cn=editors,ou=aa'            => 340,
            ], $plan->ids);
            self::assertSame('uid=kalen01,ou=People,ou=AA', $plan->canonical['uid=kalen01,ou=people,ou=aa']);
            self::assertSame([336, 337, 338], array_column($plan->users, 'id'));
            self::assertSame([[339, 'AA Admins', 'Admins'], [340, 'Editors', '']], array_map(
                static fn(array $g): array => [$g['id'], $g['name'], $g['description']],
                $plan->groupRows,
            ));
        }

        public function test_build_variantsOfOnePersonShareOneId(): void
        {
            $plan = $this->plan();

            self::assertSame([
                'uid=Kalen01,ou=People,ou=AA'  => '338',
                'uid=kalen01 ,ou=People,ou=AA' => '338',
            ], $plan->remap);
            self::assertArrayNotHasKey('uid=gone,ou=People,ou=AA', $plan->remap);
            self::assertTrue($this->hasFinding($plan, false, 'uid=gone,ou=People,ou=AA'));
        }

        public function test_build_passwords(): void
        {
            $plan  = $this->plan();
            $users = array_column($plan->users, null, 'uid');

            self::assertSame(self::CRYPT, $users['kalen01']['password']);
            self::assertStringStartsWith('$2y$', $users['jan']['password']);
            self::assertTrue(password_verify('tajne', $users['jan']['password']));
            self::assertTrue($this->hasFinding($plan, true, 'uid=eva,ou=People,ou=AA'));
            self::assertTrue($plan->blocking());
        }

        public function test_build_unsupportedSchemeBlocks(): void
        {
            $people = $this->people();
            unset($people['uid=eva,ou=People,ou=AA']);
            $people['uid=jan,ou=People,ou=AA']['password']     = '{PBKDF2}xyz';
            $people['uid=kalen01,ou=People,ou=AA']['password'] = '{crypt}$6$salt$hash';

            $plan = Plan::build($people, [], [], [], 1);

            self::assertTrue($this->hasFinding($plan, true, 'uid=jan,ou=People,ou=AA'));
            // the scheme name is case-insensitive
            self::assertFalse($this->hasFinding($plan, true, 'uid=kalen01,ou=People,ou=AA'));
        }

        public function test_build_membersAndPrincipals(): void
        {
            $plan = $this->plan();

            self::assertEqualsCanonicalizing([
                ['groupid' => 339, 'memberid' => '338'],
                ['groupid' => 339, 'memberid' => '340'],
                ['groupid' => 340, 'memberid' => self::READER],
            ], $plan->membership);
            self::assertTrue($this->hasFinding($plan, false, 'cn=aauser,ou=AA'));

            self::assertEqualsCanonicalizing([
                ['object_type' => 'aa', 'objectid' => AA_ID, 'userid' => '339', 'perm' => '4'],
                ['object_type' => 'slice', 'objectid' => 'S1', 'userid' => '338', 'perm' => '3'],
                ['object_type' => 'slice', 'objectid' => 'S1', 'userid' => self::READER, 'perm' => '1'],
            ], $plan->perms);
            self::assertTrue($this->hasFinding($plan, false, 'uid=Kalen01,ou=People,ou=AA'));
        }

        public function test_build_mapsAttributes(): void
        {
            $plan  = $this->plan();
            $users = array_column($plan->users, null, 'uid');

            self::assertSame('Jan Novák', $users['jan']['name']);
            self::assertSame('jan@example.org', $users['jan']['mail']);
            self::assertSame('Kalen', $users['kalen01']['name']);
            self::assertSame('', $users['eva']['mail']);
            self::assertTrue($this->hasFinding($plan, false, 'jan2@example.org'));
        }

        public function test_build_overlongUidBlocks(): void
        {
            $dn     = 'uid=' . str_repeat('a', 41) . ',ou=People,ou=AA';
            $people = [$dn => $this->person(str_repeat('a', 41), '{SSHA}abc')];

            $plan = Plan::build($people, [], [], [], 1);

            self::assertTrue($plan->blocking());
            self::assertTrue($this->hasFinding($plan, true, $dn));

            $ok = Plan::build(['uid=a,ou=AA' => $this->person(str_repeat('a', 40), '{SSHA}abc')], [], [], [], 1);
            self::assertFalse($ok->blocking());
        }

        public function test_build_personWithoutUidBlocks(): void
        {
            $people = ['uid=x,ou=People,ou=AA' => $this->person(null, '{SSHA}abc')];

            $plan = Plan::build($people, [], [], [], 1);

            self::assertTrue($this->hasFinding($plan, true, 'uid=x,ou=People,ou=AA'));
            self::assertSame([], $plan->users);
        }

        public function test_build_twoEntriesWithOneNormalizedDnBlock(): void
        {
            $people = [
                'uid=Petr,ou=People,ou=AA'  => $this->person('Petr', '{SSHA}abc'),
                'uid=petr ,ou=People,ou=AA' => $this->person('petr ', '{SSHA}def'),
            ];

            $plan = Plan::build($people, [], [], [], 1);

            self::assertTrue($this->hasFinding($plan, true, 'uid=petr ,ou=People,ou=AA'));
            self::assertCount(1, $plan->ids);
            self::assertCount(1, $plan->users);
        }

        private function plan(): Plan
        {
            $groups = [
                'cn=AA Admins,ou=AA' => [
                    'cn'          => 'AA Admins',
                    'description' => 'Admins',
                    'members'     => ['uid=kalen01,ou=People,ou=AA', 'cn=aauser,ou=AA', 'cn=Editors,ou=AA'],
                ],
                'cn=Editors,ou=AA' => ['cn' => 'Editors', 'description' => '', 'members' => [self::READER]],
            ];
            $acls = [
                ['type' => 'aa', 'id' => AA_ID, 'perms' => ['cn=AA Admins,ou=AA' => '4']],
                ['type' => 'slice', 'id' => 'S1', 'perms' => [
                    'uid=Kalen01,ou=People,ou=AA' => '2',
                    'uid=kalen01,ou=People,ou=AA' => '3',
                    self::READER                  => '1',
                ]],
            ];
            $referenced = ['uid=Kalen01,ou=People,ou=AA', 'uid=kalen01 ,ou=People,ou=AA', 'uid=gone,ou=People,ou=AA'];

            return Plan::build($this->people(), $groups, $acls, $referenced, 336);
        }

        /** @return array<string, array{uid: ?string, cn: ?string, sn: ?string, givenname: ?string, mail: list<string>, password: ?string}> */
        private function people(): array
        {
            return [
                'uid=kalen01,ou=People,ou=AA' => ['cn' => 'Kalen', 'mail' => ['k@example.org']] + $this->person('kalen01', self::CRYPT),
                'uid=jan,ou=People,ou=AA'     => [
                    'cn'        => null,
                    'givenname' => 'Jan',
                    'sn'        => 'Novák',
                    'mail'      => ['jan@example.org', 'jan2@example.org'],
                ] + $this->person('jan', 'tajne'),
                'uid=eva,ou=People,ou=AA' => $this->person('eva', null),
            ];
        }

        /** @return array{uid: ?string, cn: ?string, sn: ?string, givenname: ?string, mail: list<string>, password: ?string} */
        private function person(?string $uid, ?string $password): array
        {
            return ['uid' => $uid, 'cn' => $uid, 'sn' => null, 'givenname' => null, 'mail' => [], 'password' => $password];
        }

        private function hasFinding(Plan $plan, bool $blocking, string $needle): bool
        {
            foreach ($plan->findings as $finding) {
                if ($finding['blocking'] === $blocking && str_contains($finding['text'], $needle)) {
                    return true;
                }
            }
            return false;
        }
    }
}
