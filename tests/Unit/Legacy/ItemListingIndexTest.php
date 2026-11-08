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

    use AA_Aliases;
    use AA_Item;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /** _#ITEMINDX and its siblings (AA_Item::f_e() itemindex, …) read the stacks a listing in progress fills (itemview) */
    final class ItemListingIndexTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        /** @return array<string, array{string, string}> f_e parameter, the stack it reads */
        public static function stacks(): array
        {
            return [
                'itemindex'      => ['itemindex', 'QueryIDsIndex'],
                'pageindex'      => ['pageindex', 'QueryIDsPageIndex'],
                'groupindex'     => ['groupindex', 'QueryIDsGroupIndex'],
                'itemgroupindex' => ['itemgroupindex', 'QueryIDsItemGroupIndex'],
            ];
        }

        #[DataProvider('stacks')]
        public function test_f_e_insideListing_givesTheInnermostIndex(string $param, string $stack): void
        {
            $GLOBALS[$stack] = [4, 0];
            try {
                self::assertSame('0', self::item()->f_e('', $param), 'the first item is "0", not ""');
            } finally {
                unset($GLOBALS[$stack]);
            }
        }

        /** the slice export expands item content outside any listing - a literal _#ITEMINDX there ended in a TypeError */
        #[DataProvider('stacks')]
        public function test_f_e_outsideListing_isEmpty(string $param, string $stack): void
        {
            unset($GLOBALS[$stack]);
            self::assertSame('', self::item()->f_e('', $param));
        }

        /** _#ID_COUNT (f_e:itemcount) outside a listing - no count, and no warning */
        public function test_f_e_itemcountOutsideListing_isEmpty(): void
        {
            unset($GLOBALS['QueryIDsCount']);
            set_error_handler(static function (int $no, string $msg): never {
                throw new \ErrorException($msg, 0, $no);
            });
            try {
                self::assertSame('', self::item()->f_e('', 'itemcount'));
            } finally {
                restore_error_handler();
            }
        }

        private static function item(): AA_Item
        {
            return new AA_Item(['headline........' => [['value' => 'x']]], new AA_Aliases());
        }
    }
}
