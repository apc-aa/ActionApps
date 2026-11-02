<?php

declare(strict_types=1);

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

    use AA;
    use AA_Router_Seo;
    use AA_Scroller_Router;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /** The infinite listing loads the next page when its hidden page links scroll into view. */
    #[RunTestsInSeparateProcesses]
    final class ScrollerInfiniteTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            set_error_handler(static fn(): bool => true);   // locsess.php warns about the missing web request
            try {
                require_once __DIR__ . '/../fixtures/util_shims.php';
                require_once __DIR__ . '/fixtures/store_fixtures.php';
                require_once __DIR__ . '/../../../include/scroller.php';
                require_once __DIR__ . '/../../../modules/site/router.class.php';
            } finally {
                restore_error_handler();
            }
        }

        private static function foot(): string
        {
            set_error_handler(static fn(): bool => true);   // the requires read the unset keys of every library they do not use
            try {
                return AA::Stringexpander()->getRequireFoot();
            } finally {
                restore_error_handler();
            }
        }

        /** with no next page the nav is empty - the script found no current page in it and threw */
        public function test_infinite_singlePage_addsNoLoader(): void
        {
            $html = (new AA_Scroller_Router('inf', 10, 5))->setRouter(new AA_Router_Seo([], '/cz/'))->infinite('<p>items</p>');

            self::assertStringContainsString('<p>items</p>', $html);
            self::assertStringNotContainsString('IntersectionObserver', self::foot());
        }

        public function test_infinite_morePages_addsLoader(): void
        {
            $GLOBALS['apc_state'] = ['xlang' => 'cz', 'xpage' => '', 'xflag' => '', 'xcat' => '', 'xqs' => ''];
            $html = (new AA_Scroller_Router('inf', 10, 25))->setRouter(new AA_Router_Seo([], '/cz/'))->infinite('<p>items</p>');

            self::assertStringContainsString('class=active', $html);
            self::assertStringContainsString('IntersectionObserver', self::foot());
        }
    }
}
