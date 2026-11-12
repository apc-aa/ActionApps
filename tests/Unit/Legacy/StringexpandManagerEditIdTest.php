<?php

declare(strict_types=1);

/**
 * {manager:<slices>:…:<edit_code>} edits the item whose id the URL gives - only an item of the managed slices;
 * any other id leaves the ordinary listing.
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

namespace Tests\Unit\Legacy {

    use AA_Stringexpand_Manager;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class StringexpandManagerEditIdTest extends TestCase
    {
        private const MANAGED = '0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a';
        private const OWN     = '1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c';
        private const FOREIGN = '2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d';
        private const CMDVAR  = 'aamng_test';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            // the item table knows OWN in the managed slice only
            RecordingDb::$answer = static fn(string $sql): array => (stripos($sql, self::MANAGED) !== false && stripos($sql, self::OWN) !== false)
                ? [['id' => self::OWN]]
                : [];
        }

        protected function tearDown(): void
        {
            unset($_GET[self::CMDVAR]);
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        private static function editId(mixed $get, array $slices = [self::MANAGED]): string
        {
            $_GET[self::CMDVAR] = $get;
            return (new \ReflectionMethod(AA_Stringexpand_Manager::class, 'editId'))->invoke(null, self::CMDVAR, $slices);
        }

        public function test_editId_itemOfManagedSlice(): void
        {
            self::assertSame(self::OWN, self::editId(self::OWN));
        }

        public function test_editId_itemOfOtherSlice_isEmpty(): void
        {
            self::assertSame('', self::editId(self::FOREIGN));
        }

        public function test_editId_noManagedSlice_isEmpty(): void
        {
            self::assertSame('', self::editId(self::OWN, []));
        }

        public function test_editId_notAnId_asksNothing(): void
        {
            self::assertSame('', self::editId('x'));
            self::assertSame('', self::editId([self::OWN]));
            self::assertSame(0, RecordingDb::count('SELECT'));
        }

        public function test_editId_missing_isEmpty(): void
        {
            self::assertSame('', (new \ReflectionMethod(AA_Stringexpand_Manager::class, 'editId'))->invoke(null, self::CMDVAR, [self::MANAGED]));
        }
    }
}
