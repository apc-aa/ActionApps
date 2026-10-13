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
        'SCROLLER_LENGTH' => 20,
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA_Scroller_Easy;
    use PHPUnit\Framework\TestCase;

    /**
     * AA_Scroller (include/scroller.php) lives in the session of slice.php between requests. Read back
     * without its page size it lists no item - the SSI listing was empty on every visit but the first.
     */
    final class ScrollerTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/scroller.php';
        }

        public function test_unserialize_keepsPageSizeItemCountAndPage(): void
        {
            $scr = (new AA_Scroller_Easy('scr', 20, 57, 'https://example.org/'))->go2page(2);

            $back = unserialize(serialize($scr));

            self::assertSame(20, $back->getListlen());
            self::assertSame(57, $back->getItemCount());
            self::assertSame(2, $back->getCurrent());
            self::assertSame(3, $back->pageCount());
        }

        /** a scroller stored by an older AA has no itmcnt and nulls instead of numbers */
        public function test_unserialize_olderObject_getsDefaults(): void
        {
            $old = 'O:16:"AA_Scroller_Easy":3:{s:10:"' . "\0*\0" . 'current";N;s:5:"' . "\0*\0" . 'id";s:3:"scr";s:10:"' . "\0*\0" . 'listlen";N;}';

            $back = unserialize($old);

            self::assertSame(0, $back->getListlen());
            self::assertSame(0, $back->getItemCount());
            self::assertSame(1, $back->getCurrent());
        }
    }
}
