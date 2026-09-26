<?php

declare(strict_types=1);

// RecordingDb (store_fixtures) loads the real locsess.php, which needs the constants
// include/config.php normally provides.

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

    use AA_Views;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * AA_Views::storeDigestFilters() - the Alerts selections (alerts_filter rows) of a digest view,
     * posted by admin/se_view.php as filters[rowid][description|conds]. The row ids are keys from the
     * request: only a positive integer (a row of this view) or new<n> may touch a row, and never
     * a row of another view or a view of another slice.
     */
    final class DigestFiltersStoreTest extends TestCase
    {
        private const SLICE = '0123456789abcdef0123456789abcdef';
        private const OTHER = 'fedcba9876543210fedcba9876543210';

        /** view 7 is a view of SLICE, any other id is not */
        private const OWNER_SQL = "SELECT id FROM \"view\" WHERE id = 7 AND slice_id = 0x0123456789abcdef0123456789abcdef LIMIT  1";

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            RecordingDb::$answer = static fn(string $sql) => ($sql === self::OWNER_SQL) ? [['id' => 7]] : [];
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        /** @return string[] the statements on alerts_filter */
        private static function filterStatements(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $sql) => str_contains($sql, 'alerts_filter')));
        }

        public function test_storesTheGroupingAndSortOfTheView(): void
        {
            $this->assertTrue(AA_Views::storeDigestFilters(7, self::SLICE, 1, "headline........'", [], false));

            $this->assertSame([
                self::OWNER_SQL,
                "UPDATE `view` SET  aditional = 1 , aditional3 = 'headline........\\'' WHERE id = 7 AND slice_id = 0x0123456789abcdef0123456789abcdef",
            ], RecordingDb::$log);
        }

        public function test_updatesDeletesAndInsertsTheRowsOfTheView(): void
        {
            $ok = AA_Views::storeDigestFilters(7, self::SLICE, 0, '', [
                3      => ['description' => "Nové 'články'", 'conds' => 'conds[0][category........]=x'],
                4      => ['description' => '', 'conds' => 'ignored'],
                'new0' => ['description' => 'Events', 'conds' => 'conds[0][x]=1'],
                'new1' => ['description' => '', 'conds' => ''],       // the empty box of the form
            ], false);

            $this->assertTrue($ok);
            $this->assertSame([
                "UPDATE `alerts_filter` SET  description = 'Nové \\'články\\'' , conds = 'conds[0][category........]=x' WHERE id = 3 AND vid = 7",
                "DELETE FROM `alerts_filter`  WHERE id = 4 AND vid = 7",
                "INSERT INTO `alerts_filter` (`vid`,`conds`,`description`) VALUES (7,'conds[0][x]=1','Events')",
            ], self::filterStatements());
            $this->assertSame([['vid' => 7, 'conds' => 'conds[0][x]=1', 'description' => 'Events']], RecordingDb::inserts('alerts_filter'));
        }

        /** "Save as new" posts the selections of the source view under their ids - they become rows of the new view */
        public function test_createdViewGetsThePostedSelectionsAsNewRows(): void
        {
            $ok = AA_Views::storeDigestFilters(7, self::SLICE, 0, '', [
                3      => ['description' => 'News', 'conds' => 'conds[0][x]=1'],
                4      => ['description' => '', 'conds' => 'dropped'],
                'new5' => ['description' => 'Events', 'conds' => 'conds[0][x]=2'],
                'x 1'  => ['description' => 'bad key', 'conds' => ''],
            ], true);

            $this->assertTrue($ok);
            $this->assertSame([
                ['vid' => 7, 'conds' => 'conds[0][x]=1', 'description' => 'News'],
                ['vid' => 7, 'conds' => 'conds[0][x]=2', 'description' => 'Events'],
            ], RecordingDb::inserts('alerts_filter'));
            $this->assertCount(2, self::filterStatements(), 'no update or delete - the source view keeps its rows');
        }

        /** only an empty description deletes - "0" is a description */
        public function test_descriptionZeroIsKept(): void
        {
            AA_Views::storeDigestFilters(7, self::SLICE, 0, '', [
                3      => ['description' => '0', 'conds' => 'a'],
                4      => ['description' => '', 'conds' => 'b'],
                'new0' => ['description' => '0', 'conds' => 'c'],
            ], false);

            $this->assertSame([
                "UPDATE `alerts_filter` SET  description = '0' , conds = 'a' WHERE id = 3 AND vid = 7",
                "DELETE FROM `alerts_filter`  WHERE id = 4 AND vid = 7",
                "INSERT INTO `alerts_filter` (`vid`,`conds`,`description`) VALUES (7,'c','0')",
            ], self::filterStatements());
        }

        /** a selection of another view keeps its row - the vid is always in the WHERE */
        public function test_rowOfAnotherViewIsMatchedOnlyWithinThisView(): void
        {
            AA_Views::storeDigestFilters(7, self::SLICE, 0, '', [
                12 => ['description' => 'taken over', 'conds' => ''],
                13 => ['description' => '', 'conds' => ''],
            ], false);

            $this->assertSame([
                "UPDATE `alerts_filter` SET  description = 'taken over' , conds = '' WHERE id = 12 AND vid = 7",
                "DELETE FROM `alerts_filter`  WHERE id = 13 AND vid = 7",
            ], self::filterStatements());
        }

        public static function badKeys(): array
        {
            return [
                'or 1=1'          => ['1 OR 1=1'],
                'quoted or'       => ["1' OR '1'='1"],
                'comment'         => ['1 #'],
                'subquery'        => ['(SELECT 1)'],
                'zero'            => ['0'],
                'negative'        => ['-3'],
                'leading zero'    => ['07'],
                'float'           => ['1.5'],
                'new alone'       => ['new'],
                'new with tail'   => ['new0 OR 1=1'],
                'new, newline'    => ["new0\n"],
                'number, newline' => ["5\n"],
                'hex'             => ['0x1f'],
            ];
        }

        /** keys neither of a stored row (positive integer) nor of a new one (new<n>) are no row of the form */
        #[\PHPUnit\Framework\Attributes\DataProvider('badKeys')]
        public function test_injectedKeyIsIgnored(string $key): void
        {
            AA_Views::storeDigestFilters(7, self::SLICE, 0, '', [
                $key => ['description' => '', 'conds' => ''],
            ], false);
            AA_Views::storeDigestFilters(7, self::SLICE, 0, '', [
                $key => ['description' => 'x', 'conds' => 'y'],
            ], false);
            AA_Views::storeDigestFilters(7, self::SLICE, 0, '', [
                $key => ['description' => 'x', 'conds' => 'y'],
            ], true);

            $this->assertSame([], self::filterStatements());
        }

        public function test_malformedRowIsIgnored(): void
        {
            AA_Views::storeDigestFilters(7, self::SLICE, 0, '', [
                5      => 'not a row',
                'new0' => ['description' => ['x'], 'conds' => 'y'],
            ], false);

            $this->assertSame([], self::filterStatements());
        }

        /** the id of a view of another slice (a changed view_id) changes neither the view nor its selections */
        public function test_viewOfAnotherSliceIsNotTouched(): void
        {
            $ok = AA_Views::storeDigestFilters(8, self::SLICE, 1, 'x', [
                12     => ['description' => 'taken over', 'conds' => ''],
                13     => ['description' => '', 'conds' => ''],
                'new0' => ['description' => 'planted', 'conds' => ''],
            ], false);

            $this->assertFalse($ok);
            $this->assertSame(["SELECT id FROM \"view\" WHERE id = 8 AND slice_id = 0x0123456789abcdef0123456789abcdef LIMIT  1"], RecordingDb::$log);
        }

        public function test_view7InAnotherSliceIsNotTouched(): void
        {
            $this->assertFalse(AA_Views::storeDigestFilters(7, self::OTHER, 1, 'x', ['new0' => ['description' => 'planted', 'conds' => '']], false));
            $this->assertCount(1, RecordingDb::$log, 'only the look-up of the view');
        }
    }
}
