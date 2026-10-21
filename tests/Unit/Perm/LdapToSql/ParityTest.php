<?php

declare(strict_types=1);

// AA_Perm::resolveRoles() lives in include/perm_core.php, whose include/locsess.php needs the
// constants include/config.php normally provides.

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

    use AA\Perm\LdapToSql\Parity;
    use AA\Perm\LdapToSql\Plan;
    use PHPUnit\Framework\TestCase;

    /** A permission facade answering from arrays and counting what it was asked. */
    final class FakePerm
    {
        /** @var array<string, int> "method arg" => calls */
        public array $calls = [];

        /**
         * @param array<string, array<string, array<string, string>>> $userPerms uid => type => object => perm
         * @param array<string, array{0: list<string>, 1: list<string>}> $membership uid => [all levels, direct]
         * @param array<string, array<string, string>> $info uid => getIDsInfo()
         * @param array<string, array<string, mixed>> $members group => member => info
         * @param array<string, array<string, string>> $objectPerms "type id" => principal => perm
         */
        public function __construct(
            public array $userPerms,
            public array $membership,
            public array $info,
            public array $members,
            public array $objectPerms,
        ) {}

        /** @return array<string, string> */
        public function getUserPerms(string $uid, string $objectType, int $flags = 0): array
        {
            $this->count("getUserPerms $uid $objectType");
            return $this->userPerms[$uid][$objectType] ?? [];
        }

        /** @return list<string> */
        public function getMembership(string $uid, int $flags = 0): array
        {
            $this->count("getMembership $uid $flags");
            return $this->membership[$uid][$flags ? 1 : 0] ?? [];
        }

        /** @return false|array<string, string> */
        public function getIDsInfo(string $uid): false|array
        {
            $this->count("getIDsInfo $uid");
            return $this->info[$uid] ?? false;
        }

        /** @return array<string, mixed> */
        public function getGroupMembers(string $group_id): array
        {
            $this->count("getGroupMembers $group_id");
            return $this->members[$group_id] ?? [];
        }

        /** @return array<string, string> */
        public function getObjectsPerms(string $objectID, string $objectType): array
        {
            $this->count("getObjectsPerms $objectType $objectID");
            return $this->objectPerms["$objectType $objectID"] ?? [];
        }

        private function count(string $call): void
        {
            $this->calls[$call] = ($this->calls[$call] ?? 0) + 1;
        }
    }

    final class ParityTest extends TestCase
    {
        private const AA      = '7e5700000000000000000000000000aa';
        private const S1      = 'b5e9a2c4d6f8b0a1c3e5f7a9b1d3e5f7';
        private const S2      = 'c5e9a2c4d6f8b0a1c3e5f7a9b1d3e5f7';
        private const S9      = 'd5e9a2c4d6f8b0a1c3e5f7a9b1d3e5f7';
        private const READER  = '0123456789abcdef0123456789abcdef';
        private const KALEN   = 'uid=kalen01,ou=People,ou=AA';
        private const VARIANT = 'uid=Kalen01,ou=People,ou=AA';
        private const SPACED  = 'uid=kalen01 ,ou=People,ou=AA';
        private const JAN     = 'uid=jan,ou=People,ou=AA';
        private const ADMINS  = 'cn=AA Admins,ou=AA';
        private const EDITORS = 'cn=Editors,ou=AA';
        private const SSHA    = '{SSHA}YWFhYWFhYWFhYWFhYWFhYWFhYWE=';

        // ids from the plan: jan 336, kalen01 337, AA Admins 338, Editors 339

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../Legacy/fixtures/store_fixtures.php';
        }

        public function test_compare_identicalGivesNothing(): void
        {
            self::assertSame([], $this->compare($this->ldap(), $this->sql()));
        }

        public function test_compare_missingLetterBlocks(): void
        {
            $sql = $this->sql();
            $sql->userPerms['336']['slice'][self::S1] = '1';

            $findings = $this->compare($this->ldap(), $sql);

            self::assertCount(1, $this->blocking($findings));
            self::assertStringContainsString(self::JAN . ' on slice ' . self::S1, $this->blocking($findings)[0]);
        }

        public function test_compare_extraLetterIsInfo(): void
        {
            $sql = $this->sql();
            $sql->userPerms['336']['slice'][self::S1] = '3';

            $findings = $this->compare($this->ldap(), $sql);

            self::assertSame([], $this->blocking($findings));
            self::assertTrue($this->has($findings, false, self::JAN . ' on slice ' . self::S1));
        }

        public function test_compare_variantChecked(): void
        {
            $ldap = $this->ldap();
            $ldap->userPerms[self::VARIANT]['slice'][self::S2] = '1';

            $findings = $this->compare($ldap, $this->sql());

            self::assertCount(1, $this->blocking($findings));
            self::assertStringContainsString(self::VARIANT . ' on slice ' . self::S2, $this->blocking($findings)[0]);
            self::assertSame(1, $ldap->calls['getMembership ' . self::VARIANT . ' 1']);
        }

        public function test_compare_emptySuperGroupBlocks(): void
        {
            // the directory holds the group only through a placeholder member, which the plan drops
            $ldap = $this->ldap();
            $sql  = $this->sql();
            $ldap->members[self::ADMINS] = ['cn=aauser,ou=AA' => ['type' => 'User']];
            $sql->members['338']         = [];
            foreach ([self::KALEN, self::VARIANT] as $dn) {
                $ldap->userPerms[$dn]['aa'] = [];
                $ldap->membership[$dn]      = [[], []];
            }
            $sql->userPerms['337']['aa'] = [];
            $sql->membership['337']      = [[], []];

            $findings = $this->compare($ldap, $sql);

            self::assertCount(1, $this->blocking($findings));
            self::assertStringContainsString('admin/setup.php', $this->blocking($findings)[0]);
        }

        public function test_compare_superUserNeedsNoGroup(): void
        {
            $ldap = $this->ldap();
            $sql  = $this->sql();
            $ldap->objectPerms['aa ' . self::AA] = [self::KALEN => '4'];
            $sql->objectPerms['aa ' . self::AA]  = ['337' => '4'];

            self::assertSame([], $this->blocking($this->compare($ldap, $sql)));
        }

        public function test_compare_missingMembershipBlocks(): void
        {
            $sql = $this->sql();
            $sql->membership['336'] = [['339'], []];

            $findings = $this->compare($this->ldap(), $sql);

            self::assertCount(1, $this->blocking($findings));
            self::assertStringContainsString(self::JAN . ' in ' . self::EDITORS . ' (direct)', $this->blocking($findings)[0]);
        }

        public function test_compare_groupMemberAndObjectRightMissingBlock(): void
        {
            $sql = $this->sql();
            unset($sql->members['339']['336'], $sql->objectPerms['slice ' . self::S1][self::READER]);

            $findings = $this->compare($this->ldap(), $sql);

            self::assertCount(2, $this->blocking($findings));
            self::assertTrue($this->has($findings, true, self::JAN . ' in ' . self::EDITORS));
            self::assertTrue($this->has($findings, true, self::READER . ' on slice ' . self::S1));
        }

        public function test_compare_typeBlocksDetailsInform(): void
        {
            $sql = $this->sql();
            $sql->info['336']['type'] = 'Group';
            $sql->info['337']['mail'] = 'other@example.org';

            $findings = $this->compare($this->ldap(), $sql);

            self::assertCount(1, $this->blocking($findings));
            self::assertStringContainsString(self::JAN, $this->blocking($findings)[0]);
            self::assertTrue($this->has($findings, false, 'other@example.org'));
        }

        public function test_compare_spellingThePlanDroppedOnlyInforms(): void
        {
            // the plan keeps the directory spelling; LDAP still grants the other one to whoever logs in with it
            $acls = $this->acls();
            $acls[1]['perms'] = [self::SPACED => '3', self::KALEN => '2'] + $acls[1]['perms'];
            $plan = $this->plan($acls, [self::VARIANT, self::SPACED]);
            self::assertFalse($plan->blocking());

            $ldap = $this->ldap();
            $sql  = $this->sql();
            $ldap->objectPerms['slice ' . self::S1]  = $acls[1]['perms'];
            $ldap->userPerms[self::KALEN]['slice']   = [self::S1 => '2'];
            $ldap->userPerms[self::VARIANT]['slice'] = [self::S1 => '2'];
            $ldap->userPerms[self::SPACED]           = $ldap->userPerms[self::KALEN];
            $ldap->userPerms[self::SPACED]['slice']  = [self::S1 => '3'];
            $sql->userPerms['337']['slice']          = [self::S1 => '2'];
            $sql->objectPerms['slice ' . self::S1]['337'] = '2';

            $findings = Parity::compare($ldap, $sql, $plan, array_keys($plan->remap), $this->objects(), self::AA);

            self::assertSame([], $this->blocking($findings));
            self::assertTrue($this->has($findings, false, self::SPACED . ' on slice ' . self::S1));
        }

        public function test_compare_ignoresObjectsOfOtherDbs(): void
        {
            $ldap = $this->ldap();
            $ldap->userPerms[self::JAN]['slice'][self::S9] = '3';

            self::assertSame([], $this->compare($ldap, $this->sql()));
        }

        public function test_compare_asksSqlOncePerId(): void
        {
            $sql = $this->sql();

            $this->compare($this->ldap(), $sql);

            // kalen01 is checked under two spellings, SQL knows one id
            self::assertSame(1, $sql->calls['getUserPerms 337 slice']);
            self::assertSame(1, $sql->calls['getMembership 337 1']);
            self::assertSame(1, $sql->calls['getIDsInfo 337']);
            self::assertSame(1, $sql->calls['getObjectsPerms aa ' . self::AA]);
        }

        /** @return list<array{blocking: bool, text: string}> */
        private function compare(FakePerm $ldap, FakePerm $sql): array
        {
            $plan = $this->plan($this->acls());
            return Parity::compare($ldap, $sql, $plan, array_keys($plan->remap), $this->objects(), self::AA);
        }

        /**
         * @param list<array{type: string, id: string, perms: array<string, string>}> $acls
         * @param list<string> $referenced
         */
        private function plan(array $acls, array $referenced = [self::VARIANT]): Plan
        {
            $person = static fn(string $uid, string $mail): array => [
                'uid' => $uid, 'cn' => $uid, 'sn' => null, 'givenname' => null, 'mail' => [$mail], 'password' => self::SSHA,
            ];
            $people = [self::KALEN => $person('kalen01', 'k@example.org'), self::JAN => $person('jan', 'j@example.org')];
            $groups = [
                self::ADMINS  => ['cn' => 'AA Admins', 'description' => '', 'members' => [self::KALEN, 'cn=aauser,ou=AA']],
                self::EDITORS => ['cn' => 'Editors', 'description' => '', 'members' => [self::JAN, self::READER]],
            ];
            return Plan::build($people, $groups, $acls, $referenced, 336);
        }

        /** @return list<array{type: string, id: string, perms: array<string, string>}> */
        private function acls(): array
        {
            return [
                ['type' => 'aa', 'id' => self::AA, 'perms' => [self::ADMINS => '4']],
                ['type' => 'slice', 'id' => self::S1, 'perms' => [self::KALEN => '3', self::EDITORS => '2', self::READER => '1']],
            ];
        }

        /** @return list<array{type: string, id: string}> */
        private function objects(): array
        {
            return [['type' => 'aa', 'id' => self::AA], ['type' => 'slice', 'id' => self::S1], ['type' => 'slice', 'id' => self::S2]];
        }

        private function ldap(): FakePerm
        {
            $kalen = ['aa' => [self::AA => '4'], 'slice' => [self::S1 => '3']];
            return new FakePerm(
                [self::KALEN => $kalen, self::VARIANT => $kalen, self::JAN => ['slice' => [self::S1 => '2']]],
                [
                    self::KALEN   => [[self::ADMINS], [self::ADMINS]],
                    self::VARIANT => [[self::ADMINS], [self::ADMINS]],
                    self::JAN     => [[self::EDITORS], [self::EDITORS]],
                ],
                [
                    self::KALEN   => $this->info('User', 'kalen01', 'k@example.org', 'kalen01'),
                    self::VARIANT => $this->info('User', 'kalen01', 'k@example.org', 'kalen01'),
                    self::JAN     => $this->info('User', 'jan', 'j@example.org', 'jan'),
                    self::ADMINS  => $this->info('Group', '', '', 'AA Admins'),
                    self::EDITORS => $this->info('Group', '', '', 'Editors'),
                ],
                // LDAP lists only members it can describe: no placeholder, no reader
                [self::ADMINS => [self::KALEN => []], self::EDITORS => [self::JAN => []]],
                [
                    'aa ' . self::AA    => [self::ADMINS => '4'],
                    'slice ' . self::S1 => [self::KALEN => '3', self::EDITORS => '2', self::READER => '1'],
                ],
            );
        }

        private function sql(): FakePerm
        {
            return new FakePerm(
                ['337' => ['aa' => [self::AA => '4'], 'slice' => [self::S1 => '3']], '336' => ['slice' => [self::S1 => '2']]],
                ['337' => [['338'], ['338']], '336' => [['339'], ['339']]],
                [
                    '337' => $this->info('User', 'kalen01', 'k@example.org', 'kalen01'),
                    '336' => $this->info('User', 'jan', 'j@example.org', 'jan'),
                    '338' => $this->info('Group', '', '', 'AA Admins'),
                    '339' => $this->info('Group', '', '', 'Editors'),
                ],
                ['338' => ['337' => []], '339' => ['336' => [], self::READER => false]],
                [
                    'aa ' . self::AA    => ['338' => '4'],
                    'slice ' . self::S1 => ['337' => '3', '339' => '2', self::READER => '1'],
                ],
            );
        }

        /** @return array<string, string> */
        private function info(string $type, string $login, string $mail, string $name): array
        {
            return ['type' => $type, 'login' => $login, 'mail' => $mail, 'name' => $name];
        }

        /**
         * @param list<array{blocking: bool, text: string}> $findings
         * @return list<string>
         */
        private function blocking(array $findings): array
        {
            return array_values(array_column(array_filter($findings, static fn(array $f): bool => $f['blocking']), 'text'));
        }

        /** @param list<array{blocking: bool, text: string}> $findings */
        private function has(array $findings, bool $blocking, string $needle): bool
        {
            foreach ($findings as $finding) {
                if ($finding['blocking'] === $blocking && str_contains($finding['text'], $needle)) {
                    return true;
                }
            }
            return false;
        }
    }
}
