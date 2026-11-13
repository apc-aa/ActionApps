<?php

declare(strict_types=1);

// store_fixtures loads the real locsess.php, which needs the constants include/config.php normally provides.

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

    use AA;
    use AA_Aliases;
    use AA_Item;
    use PHPUnit\Framework\TestCase;

    /** _#DISCEDIT (AA_Item::f_e() disc) - admin/discedit.php works only on items of the module in its URL */
    final class ItemDiscEditUrlTest extends TestCase
    {
        private const CURRENT = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const OTHER   = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
        private const ITEM    = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';

        private ?string $module_id = null;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            $this->module_id = AA::$module_id;
            AA::$module_id   = self::CURRENT;
        }

        protected function tearDown(): void
        {
            AA::$module_id = $this->module_id;
        }

        private static function item(string $slice_id): AA_Item
        {
            return new AA_Item(['id..............' => [['value' => hex2bin(self::ITEM)]], 'u_slice_id......' => [['value' => $slice_id]]], new AA_Aliases());
        }

        public function test_item_of_the_current_slice_keeps_its_link(): void
        {
            $this->assertSame('discedit.php?item_id=' . self::ITEM . '&amp;module_id=' . self::CURRENT, self::item(self::CURRENT)->f_e('', 'disc'));
        }

        public function test_item_of_another_slice_links_to_its_own_slice(): void
        {
            $this->assertSame('discedit.php?item_id=' . self::ITEM . '&amp;module_id=' . self::OTHER, self::item(self::OTHER)->f_e('', 'disc'));
        }

        public function test_item_without_its_slice_links_to_the_current_one(): void
        {
            $item = new AA_Item(['id..............' => [['value' => hex2bin(self::ITEM)]]], new AA_Aliases());
            $this->assertSame('discedit.php?item_id=' . self::ITEM . '&amp;module_id=' . self::CURRENT, $item->f_e('', 'disc'));
        }
    }
}
