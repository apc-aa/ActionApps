<?php

declare(strict_types=1);

/**
 * ViewListingColumns widens view.cond1field - cond3field for the name of a field of related items and adds
 * the columns of the newer view settings; the view form asks it whether it may offer them.
 */

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

namespace Tests\Unit\Optimize {

    use AA\Optimize\AbstractOptimize;
    use AA\Optimize\Finding;
    use AA\Optimize\ViewListingColumns;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class ViewListingColumnsTest extends TestCase
    {
        private const WIDE = ['cond1field' => 'varbinary(100)', 'cond2field' => 'varbinary(100)', 'cond3field' => 'varbinary(100)', 'group_limit' => 'smallint(5) unsigned', 'conds' => 'text', 'show_if' => 'text', 'show_else' => 'longtext'];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            AbstractOptimize::justPrint(false);
            RecordingDb::reset();
        }

        /** @param array<string, string> $columns the live view table: column => type (with the columns nobody asks about); [] = no table */
        private function liveView(array $columns): void
        {
            RecordingDb::$answer = static function (string $sql) use ($columns): array {
                if (!str_contains($sql, 'information_schema.COLUMNS')) {
                    return [];
                }
                $rows = $columns ? [['COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'int(10) unsigned']] : [];
                foreach ($columns as $name => $type) {
                    $rows[] = ['COLUMN_NAME' => $name, 'COLUMN_TYPE' => $type];
                }
                return $rows;
            };
        }

        /** @return string[] */
        private static function alters(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $s): bool => str_starts_with($s, 'ALTER')));
        }

        /** @return array<string, array{array<string, string>, string}> */
        public static function oldTables(): array
        {
            $short = ['cond1field' => 'varbinary(32)', 'cond2field' => 'varbinary(32)', 'cond3field' => 'varbinary(32)'];
            return [
                'before the change' => [
                    $short,
                    'ALTER TABLE `view` MODIFY `cond1field` varbinary(100), MODIFY `cond2field` varbinary(100), MODIFY `cond3field` varbinary(100), ADD `group_limit` smallint(5) unsigned, ADD `conds` text, ADD `show_if` text, ADD `show_else` longtext',
                ],
                'with the first round of columns' => [
                    array_diff_key(self::WIDE, ['conds' => 1, 'show_if' => 1, 'show_else' => 1]),
                    'ALTER TABLE `view` ADD `conds` text, ADD `show_if` text, ADD `show_else` longtext',
                ],
                'only the limit missing' => [
                    array_diff_key(self::WIDE, ['group_limit' => 1]),
                    'ALTER TABLE `view` ADD `group_limit` smallint(5) unsigned',
                ],
                'one column short' => [
                    ['cond2field' => 'varbinary(32)'] + self::WIDE,
                    'ALTER TABLE `view` MODIFY `cond2field` varbinary(100)',
                ],
                'the limit too small' => [
                    ['group_limit' => 'tinyint(3) unsigned'] + self::WIDE,
                    'ALTER TABLE `view` MODIFY `group_limit` smallint(5) unsigned',
                ],
            ];
        }

        /** @param array<string, string> $columns */
        #[DataProvider('oldTables')]
        public function test_repair_oldTable_changesItAtOnce(array $columns, string $alter): void
        {
            $this->liveView($columns);

            self::assertFalse((new ViewListingColumns())->test());
            $finding = (new ViewListingColumns())->findings()[0];
            self::assertSame(Finding::WARNING, $finding->severity);
            self::assertTrue($finding->repairable);
            self::assertTrue((new ViewListingColumns())->repair());
            self::assertSame([$alter], self::alters());
        }

        /** Each setting of the form waits only for its own column: a database with the first round of columns
         *  keeps the group limit and the fields of related items while the newer three are missing. */
        public function test_absent_condsWide_perColumn(): void
        {
            $this->liveView(array_diff_key(self::WIDE, ['conds' => 1, 'show_if' => 1, 'show_else' => 1]));
            self::assertSame(['conds', 'show_if', 'show_else'], ViewListingColumns::absent());
            self::assertTrue(ViewListingColumns::condsWide());

            $this->liveView(['cond2field' => 'varbinary(32)'] + array_diff_key(self::WIDE, ['group_limit' => 1]));
            self::assertSame(['group_limit'], ViewListingColumns::absent());
            self::assertFalse(ViewListingColumns::condsWide());
        }

        /** @return array<string, array{array<string, string>}> */
        public static function goodTables(): array
        {
            return [
                'the template'      => [self::WIDE],
                'wider'             => [['cond1field' => 'varbinary(255)', 'group_limit' => 'int(10) unsigned'] + self::WIDE],
                'a text type'       => [['cond1field' => 'blob', 'cond3field' => 'tinyblob'] + self::WIDE],
            ];
        }

        /** @param array<string, string> $columns */
        #[DataProvider('goodTables')]
        public function test_repair_goodTable_altersNothing(array $columns): void
        {
            $this->liveView($columns);

            self::assertSame([], ViewListingColumns::absent());
            self::assertTrue(ViewListingColumns::condsWide());
            self::assertTrue((new ViewListingColumns())->test());
            self::assertSame(Finding::OK, (new ViewListingColumns())->findings()[0]->severity);
            self::assertTrue((new ViewListingColumns())->repair());
            self::assertSame([], self::alters());
        }

        public function test_repair_noTable_altersNothing(): void
        {
            // a new install: UpdateDbStructure creates the table whole
            $this->liveView([]);

            self::assertTrue((new ViewListingColumns())->test());
            self::assertTrue((new ViewListingColumns())->repair());
            self::assertSame([], self::alters());
        }

        public function test_repair_justPrint_altersNothing(): void
        {
            // sql_update.php without "Write to database" only lists the SQL
            $this->liveView(['cond1field' => 'varbinary(32)'] + self::WIDE);
            AbstractOptimize::justPrint(true);

            self::assertTrue((new ViewListingColumns())->repair());
            self::assertSame([], self::alters());
        }

        public function test_types(): void
        {
            self::assertSame(['sql_update', AbstractOptimize::CHECK_BASIC], ViewListingColumns::types());
        }
    }
}
