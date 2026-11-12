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

    use AA_Router_Seo;
    use AA_Scroller_Easy;
    use AA_Scroller_Router;
    use AA_Scroller_View;
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
            require_once __DIR__ . '/../../../modules/site/router.class.php';
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

        public function test_update_goesToPageFromUrl(): void
        {
            $_GET = ['scr_scr_Go' => '3'];
            try {
                $scr = new AA_Scroller_Easy('scr', 20, 57, 'https://example.org/');
                $scr->update();
                self::assertSame(3, $scr->getCurrent());
            } finally {
                $_GET = [];
            }
        }

        /** the JavaScript the browser runs from the first onclick of $html */
        private static function onclickJs(string $html): string
        {
            self::assertSame(1, preg_match('/onclick="([^"]*)"/', $html, $m), $html);
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        }

        private static function viewPager(string $request_uri): string
        {
            $_SERVER['REQUEST_URI'] = $request_uri;
            $GLOBALS['apc_state']   = null;
            try {
                return (new AA_Scroller_View('5', 10, 25))->get('', '', '', '', 'res');
            } finally {
                unset($_SERVER['REQUEST_URI'], $GLOBALS['apc_state']);
            }
        }

        public function test_get_ajaxTarget_ordinaryUrl_keepsMarkup(): void
        {
            $html = self::viewPager('/cz/news?x=1');

            self::assertStringContainsString("onclick=\"AA_Ajax('res','/cz/news?set[5]=page-2&x=1');return false;\"", $html);
        }

        public function test_get_ajaxTarget_apostropheInRequestUri_staysInString(): void
        {
            $js = self::onclickJs(self::viewPager("/view.php/'-alert(1)-'?vid=5"));

            self::assertSame("AA_Ajax('res','/view.php/\\'-alert(1)-\\'?set[5]=page-2&vid=5');return false;", $js);
        }

        /** the attribute is written without HTML escaping - neither a quote nor a character reference may leave the string */
        public function test_get_ajaxTarget_quoteAndReferenceInRequestUri_stayInString(): void
        {
            $html = self::viewPager('/cz/x?a="&apos;&#39;&#x27;');

            self::assertStringNotContainsString('a="', $html);
            self::assertStringNotContainsString("'", str_replace(["AA_Ajax('res','", "');return false;"], '', self::onclickJs($html)));
        }

        public function test_routerLink_ajaxTarget_escapesApostrophe(): void
        {
            $router = new class ([], '/cz/') extends AA_Router_Seo {
                public function go2url($query_string): string
                {
                    return "/cz/x'-alert(1)-'?" . $query_string;
                }
            };
            $html = (new AA_Scroller_Router('5', 10, 25))->setRouter($router)->scroller('res');

            self::assertSame("AA_Ajax('res','/cz/x\\'-alert(1)-\\'?xpage=2');return false;", self::onclickJs($html));
        }

        public function test_routerLink_ajaxTarget_ordinaryUrl_keepsMarkup(): void
        {
            $router = new class ([], '/cz/') extends AA_Router_Seo {
                public function go2url($query_string): string
                {
                    return '/cz/news?a=1&' . $query_string;
                }
            };
            $html = (new AA_Scroller_Router('5', 10, 25))->setRouter($router)->scroller('res');

            self::assertStringContainsString('onclick="AA_Ajax(&apos;res&apos;,&apos;/cz/news?a=1&amp;xpage=2&apos;);return false;"', $html);
        }

        public function test_update_withoutUrlParameters_keepsPageSilently(): void
        {
            $_GET = [];
            $scr  = (new AA_Scroller_Easy('scr', 20, 57, 'https://example.org/'))->go2page(2);
            set_error_handler(static function (int $no, string $msg): never {
                throw new \ErrorException($msg, 0, $no);
            });
            try {
                $scr->update();
            } finally {
                restore_error_handler();
            }
            self::assertSame(2, $scr->getCurrent());
        }
    }
}
