<?php

declare(strict_types=1);

// include/perm_sql.php is reached through include/locsess.php, which needs the constants
// include/config.php normally provides. Safe test values, defined before the autoloader
// resolves the first legacy class (same block as ItemStoreFieldsTest).

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
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

namespace Tests\Unit\Legacy {

    use AA_Permsystem_Sql;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * Characterization of AA_Permsystem_Sql::getMembership() (include/perm_sql.php): the
     * groups an id belongs to, collected level by level with one statement per level.
     *
     * Written against the code that asks the database on every call; kept as the contract
     * for the per-request memo (T6): the same id is answered from memory until the
     * membership table is written through the perm_sql.php functions.
     */
    final class MembershipCacheTest extends TestCase
    {
        /** memberid => groupids - the membership table of every test */
        private const GROUPS = [
            'u1' => ['g1', 'g2'],
            'g1' => ['g3'],
            'g2' => [],
            'g3' => [],
            'u2' => ['g2'],
            'u0' => ['0', 'g1'],     // a 0 group id sits in some databases - ignored
            'c1' => ['c2'],          // a cycle
            'c2' => ['c1'],
        ];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            AA_Permsystem_Sql::forgetMembership();   // the memo is static - every test starts with an empty one
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            RecordingDb::$answer = static function (string $sql): array {
                if (!preg_match('/memberid (?:= (\'[^\']*\')|IN \(([^)]*)\))/', $sql, $m)) {
                    return [];
                }
                $members = array_map(static fn($q) => trim($q, "'"), explode(',', $m[2] ?? $m[1]));
                $rows    = [];
                foreach ($members as $member) {
                    foreach (self::GROUPS[$member] ?? [] as $group) {
                        $rows[] = ['groupid' => $group];
                    }
                }
                return $rows;
            };
        }

        private static function selects(): int
        {
            return RecordingDb::count('SELECT');
        }

        public function testCollectsNestedGroupsOneStatementPerLevel(): void
        {
            $groups = (new AA_Permsystem_Sql())->getMembership('u1');

            self::assertSame(['g1', 'g2', 'g3'], $groups);
            self::assertSame(3, self::selects(), 'u1 -> (g1, g2) -> (g3) -> nothing');
        }

        public function testTheSameIdIsAnsweredFromMemoryWithinTheRequest(): void
        {
            $perm = new AA_Permsystem_Sql();
            $perm->getMembership('u1');
            $before = self::selects();

            self::assertSame(['g1', 'g2', 'g3'], $perm->getMembership('u1'));
            self::assertSame(['g1', 'g2', 'g3'], (new AA_Permsystem_Sql())->getMembership('u1'), 'memo is per request, not per object');
            self::assertSame($before, self::selects(), 'no further statement for an id already resolved');
        }

        public function testEachIdHasItsOwnEntry(): void
        {
            $perm = new AA_Permsystem_Sql();

            self::assertSame(['g1', 'g2', 'g3'], $perm->getMembership('u1'));
            self::assertSame(['g2'], $perm->getMembership('u2'));
            self::assertSame([], $perm->getMembership('nobody'));
            self::assertSame(['g2'], $perm->getMembership('u2'));
        }

        public function testZeroGroupIdIsIgnored(): void
        {
            self::assertSame(['g1', 'g3'], (new AA_Permsystem_Sql())->getMembership('u0'));
        }

        public function testCycleEndsWhenNothingNewTurnsUp(): void
        {
            self::assertSame(['c2', 'c1'], (new AA_Permsystem_Sql())->getMembership('c1'));
            self::assertSame(3, self::selects(), 'c1 -> (c2) -> (c1, already seen) -> stop');
        }

        #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]   // the array id reaches the SQL builder, which warns while stringifying it
        public function testANonScalarIdIsNotMemoizedAndDoesNotFail(): void
        {
            $perm = new AA_Permsystem_Sql();

            self::assertSame([], $perm->getMembership(['u1']));
            self::assertSame([], $perm->getMembership(['u1']));
            self::assertSame(2, self::selects(), 'asked twice - nothing to key a memo on');
        }

        /** @return array<string, callable(): void> */
        public static function membershipWriters(): array
        {
            return [
                'AddGroupMember' => [static fn() => \AddGroupMember('g9', 'u1')],
                'DelGroupMember' => [static fn() => \DelGroupMember('g1', 'u1')],
                'DelUser'        => [static fn() => \DelUser('u1')],
                'DelGroup'       => [static fn() => \DelGroup('g1')],
            ];
        }

        #[DataProvider('membershipWriters')]
        public function testAChangeOfTheMembershipTableForgetsWhatWasResolved(callable $write): void
        {
            $perm = new AA_Permsystem_Sql();
            $perm->getMembership('u1');
            $before = self::selects();

            $write();
            $perm->getMembership('u1');

            self::assertGreaterThan($before, self::selects(), 'resolved again after the write');
        }
    }
}
