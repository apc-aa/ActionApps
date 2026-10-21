<?php

declare(strict_types=1);

// References reads through include/locsess.php (getDB()/freeDB()), which needs the
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

    use AA\IO\DB\DB_AA;
    use AA\Perm\LdapToSql\References;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class ReferencesTest extends TestCase
    {
        private const MAP = ['uid=Kalen01,ou=People,ou=AA' => '338', 'uid=kalen01 ,ou=People,ou=AA' => '338'];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
        }

        public function test_firstFreeId_ignoresImporterAndStartsAboveForeignNumbers(): void
        {
            $this->answer(fn(string $sql) => match (true) {
                str_contains($sql, 'FROM ' . DB_AA::quoteIdentifier('users')) => [['m' => '1']],
                str_contains($sql, 'FROM ' . DB_AA::quoteIdentifier('item')) && str_contains($sql, 'posted_by') => [['m' => '335']],
                default => [['m' => null]],
            });

            self::assertSame(336, References::firstFreeId());

            $maxima = $this->queries('SELECT MAX(');
            self::assertCount(14, $maxima, '13 reference columns and users.id');
            foreach ($maxima as $sql) {
                if (str_contains($sql, DB_AA::quoteIdentifier('users'))) {
                    continue;
                }
                self::assertStringContainsString("<> '9999999999'", $sql, 'the importer is left out by the query itself');
                self::assertStringContainsString("REGEXP '^[0-9]+\$'", $sql, 'only all-digit values are ids');
            }
            $objects = array_values(array_filter($maxima, static fn($s) => str_contains($s, 'object_text')));
            self::assertCount(1, $objects);
            self::assertStringContainsString(
                DB_AA::quoteIdentifier('property') . " IN ('author','saved_by','user')",
                $objects[0],
                'other object properties hold timestamps and counters, not user ids',
            );
        }

        public function test_firstFreeId_countsUserIdsOfObjects(): void
        {
            $this->answer(fn(string $sql) => [['m' => str_contains($sql, 'object_text') ? '400' : '3']]);

            self::assertSame(401, References::firstFreeId());
        }

        public function test_maxId_isTheUsersIdColumnLimit(): void
        {
            self::assertSame(2147483647, References::MAX_ID);
        }

        public function test_firstFreeId_unreadMaximumThrows(): void
        {
            $this->answer(fn(string $sql) => str_contains($sql, 'change') ? [] : [['m' => '5']]);

            $this->expectException(\RuntimeException::class);
            References::firstFreeId();
        }

        public function test_firstFreeId_beyondUsersIdRangeThrows(): void
        {
            $this->answer(fn(string $sql) => str_contains($sql, 'log') ? [['m' => '2147483647']] : [['m' => null]]);

            $this->expectException(\RuntimeException::class);
            References::firstFreeId();
        }

        public function test_dnValues_dropsNonDn(): void
        {
            $this->answer(fn(string $sql) => str_contains($sql, 'posted_by')
                ? [['v' => 'uid=a,ou=AA'], ['v' => 'cn=x'], ['v' => 'uid=b,ou=People,ou=AA']]
                : []);

            self::assertSame(['uid=a,ou=AA', 'uid=b,ou=People,ou=AA'], References::dnValues());
        }

        public function test_dnValues_binaryDistinctSpellingsFromEveryColumn(): void
        {
            $this->answer(fn(string $sql) => match (true) {
                str_contains($sql, 'posted_by') => [['v' => 'uid=Kalen01,ou=People,ou=AA'], ['v' => 'uid=kalen01\20,ou=People,ou=AA']],
                str_contains($sql, DB_AA::quoteIdentifier('change')) => [['v' => 'UID=kalen01,ou=People,ou=AA'], ['v' => 'uid=Kalen01,ou=People,ou=AA']],
                str_contains($sql, DB_AA::quoteIdentifier('log')) => [['v' => ' uid =kalen01,ou=People,ou=AA']],
                default => [],
            });

            self::assertSame([
                'uid=Kalen01,ou=People,ou=AA',
                'uid=kalen01\20,ou=People,ou=AA',
                'UID=kalen01,ou=People,ou=AA',
                ' uid =kalen01,ou=People,ou=AA',
            ], References::dnValues(), 'each exact spelling once');

            $selects = $this->queries('SELECT DISTINCT');
            self::assertCount(13, $selects);
            $c = DB_AA::quoteIdentifier('posted_by');
            self::assertSame(
                "SELECT DISTINCT CONVERT($c USING utf8mb4) COLLATE utf8mb4_bin AS v FROM " . DB_AA::quoteIdentifier('item') . " WHERE $c LIKE '%=%,%'",
                $selects[0],
                'compared as remap() compares, so a *_ci DISTINCT cannot merge spellings; not only uid=% matches',
            );
        }

        public function test_dnValues_skipsMissingColumns(): void
        {
            $this->answer(fn() => [], ['links_links', 'toexecute', 'object_text']);

            References::dnValues();

            self::assertCount(8, $this->queries('SELECT DISTINCT'));
            self::assertSame([], array_filter(RecordingDb::$log, static fn($s) => str_contains($s, 'links_links') && !str_contains($s, 'information_schema')));
        }

        public function test_dnValues_unlistedCoreColumnThrows(): void
        {
            $this->answer(fn() => [], ['item']);

            $this->expectException(\RuntimeException::class);
            References::dnValues();
        }

        public function test_rewrite_oneStatementPerColumn(): void
        {
            $this->answer(fn() => []);
            RecordingDb::$affected = static fn(string $sql) => str_starts_with($sql, 'UPDATE ' . DB_AA::quoteIdentifier('item')) ? 7 : 0;

            $changed = DB_AA::transaction(fn() => References::rewrite(self::MAP));

            self::assertSame(13, RecordingDb::count('UPDATE'));
            self::assertCount(13, $changed);
            self::assertSame(7, $changed['item.posted_by']);
            self::assertSame(0, $changed['object_text.value']);
            self::assertSame('COMMIT', end(RecordingDb::$log));
        }

        public function test_rewrite_emptyMapSendsNothing(): void
        {
            self::assertSame([], DB_AA::transaction(fn() => References::rewrite([])));
            self::assertSame(0, RecordingDb::count('UPDATE'));
            self::assertSame(0, RecordingDb::count('SELECT'));
        }

        public function test_rewrite_refusedStatementThrowsAndRollsBack(): void
        {
            $this->answer(fn() => []);
            RecordingDb::$refuse_on = 'UPDATE ' . DB_AA::quoteIdentifier('log');

            try {
                DB_AA::transaction(fn() => References::rewrite(self::MAP));
                self::fail('a refused UPDATE must not pass silently');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('log.user', $e->getMessage());
            }
            self::assertSame('ROLLBACK', end(RecordingDb::$log));
        }

        public function test_rewrite_refusesOutsideTransaction(): void
        {
            $this->expectException(\RuntimeException::class);
            References::rewrite(self::MAP);
        }

        public function test_count_rowsAndDurationPerColumn(): void
        {
            $this->answer(fn(string $sql) => [['cnt' => str_contains($sql, 'edited_by') ? '4' : '0']]);

            $counts = References::count(self::MAP);

            self::assertCount(13, $counts);
            self::assertSame(4, $counts['item.edited_by']['rows']);
            self::assertSame(0, $counts['change.user']['rows']);
            self::assertIsFloat($counts['item.edited_by']['seconds']);
            $c = DB_AA::quoteIdentifier('edited_by');
            self::assertContains(
                'SELECT count(*) as cnt FROM ' . DB_AA::quoteIdentifier('item')
                . " WHERE CONVERT($c USING utf8mb4) COLLATE utf8mb4_bin IN ('uid=Kalen01,ou=People,ou=AA','uid=kalen01 ,ou=People,ou=AA') LIMIT  1",
                RecordingDb::$log,
                'the rows remap() would change',
            );
            self::assertSame([], References::count([]));
        }

        public function test_mentions_countsOtherTextColumns(): void
        {
            $this->answer(fn(string $sql) => match (true) {
                str_contains($sql, 'information_schema.TABLES') => [['t' => 'slice'], ['t' => 'view'], ['t' => 'users'], ['t' => 'bad`name']],
                str_contains($sql, 'information_schema.COLUMNS') => [
                    ['t' => 'slice', 'c' => 'name'], ['t' => 'slice', 'c' => 'created_by'], ['t' => 'slice', 'c' => 'fulltext_format'],
                    ['t' => 'slice', 'c' => 'x`y'], ['t' => 'view', 'c' => 'odd'], ['t' => 'view', 'c' => 'even'],
                ],
                str_contains($sql, DB_AA::quoteIdentifier('view')) => [['cnt' => '0']],
                str_contains($sql, ' OR ') => [['cnt' => '2']],
                str_contains($sql, 'fulltext_format') => [['cnt' => '2']],
                default => [['cnt' => '0']],
            });

            self::assertSame(['slice.fulltext_format' => 2], References::mentions());

            self::assertStringContainsString('TABLE_ROWS < 200000', RecordingDb::$log[0]);
            self::assertStringNotContainsString('users', RecordingDb::$log[1], 'own tables are not searched');
            self::assertStringNotContainsString('bad', RecordingDb::$log[1], 'a name that would need escaping is skipped');
            $counts = $this->queries('SELECT count(*)');
            self::assertCount(4, $counts, 'one scan per table, a count per column only where it found something');
            foreach ($counts as $sql) {
                self::assertStringNotContainsString('created_by', $sql, 'reference columns are rewritten, not reported');
                self::assertStringNotContainsString('x`y', $sql);
            }
            $f = DB_AA::quoteIdentifier('fulltext_format');
            self::assertContains(
                'SELECT count(*) as cnt FROM ' . DB_AA::quoteIdentifier('slice') . " WHERE CONVERT($f USING utf8mb4) COLLATE utf8mb4_general_ci LIKE '%ou=AA%' LIMIT  1",
                $counts,
                'case-insensitive also in varbinary columns',
            );
        }

        /**
         * Scripts the answers; the information_schema listing of the reference columns lists
         * all of them except those of the $missing tables.
         * @param \Closure(string): array $rows
         * @param list<string> $missing
         */
        private function answer(\Closure $rows, array $missing = []): void
        {
            RecordingDb::$answer = static function (string $sql) use ($rows, $missing): array {
                if (str_contains($sql, 'information_schema.COLUMNS') && str_contains($sql, "'posted_by'")) {
                    $present = array_filter(References::COLUMNS, static fn($tc) => !in_array($tc[0], $missing, true));
                    return array_map(static fn($tc) => ['t' => $tc[0], 'c' => $tc[1]], array_values($present));
                }
                return $rows($sql);
            };
        }

        /** @return list<string> */
        private function queries(string $prefix): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, $prefix)));
        }
    }
}
