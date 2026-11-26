<?php

declare(strict_types=1);

/**
 * "Redirect to the main URL" of a site: a visit to one of its Alternate URLs moves to the same
 * page on the main URL; every other host (the admin host with site_id, a proxy, a copy) renders.
 */

namespace {
    // AA_Module_Site pulls include/constants.php through the autoloader (see ModuleNameClassMismatchTest)
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA_Module_Site;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class SiteAlternateRedirectTest extends TestCase
    {
        private const MAIN = 'https://reiki-kurzy.cz/';
        private const ALTERNATES = ['https://reiki-moravcova.cz/'];

        /** @return array<string, array{string, string, string, list<string>, ?string}> */
        public static function visits(): array
        {
            $m = self::MAIN;
            $a = self::ALTERNATES;
            return [
                'alternate host'            => ['reiki-moravcova.cz', '/cz/terminy', $m, $a, 'https://reiki-kurzy.cz/cz/terminy'],
                'www of the alternate'      => ['www.reiki-moravcova.cz', '/cz/home', $m, $a, 'https://reiki-kurzy.cz/cz/home'],
                'query string kept'         => ['reiki-moravcova.cz', '/cz/home?x=1&y=2', $m, $a, 'https://reiki-kurzy.cz/cz/home?x=1&y=2'],
                'root'                      => ['reiki-moravcova.cz', '/', $m, $a, 'https://reiki-kurzy.cz/'],
                'empty uri'                 => ['reiki-moravcova.cz', '', $m, $a, 'https://reiki-kurzy.cz/'],
                'upper-case host'           => ['Reiki-Moravcova.CZ', '/cz/home', $m, $a, 'https://reiki-kurzy.cz/cz/home'],
                'host with port'            => ['reiki-moravcova.cz:8080', '/cz/home', $m, $a, 'https://reiki-kurzy.cz/cz/home'],
                // still a path on the main host, not a protocol-relative address of another one
                'path starting with //'     => ['reiki-moravcova.cz', '//evil.example/x', $m, $a, 'https://reiki-kurzy.cz//evil.example/x'],
                'alternate without scheme'  => ['reiki-moravcova.cz', '/cz/home', $m, ['reiki-moravcova.cz'], 'https://reiki-kurzy.cz/cz/home'],
                'alternate stored as www'   => ['reiki-moravcova.cz', '/cz/home', $m, ['http://www.reiki-moravcova.cz'], 'https://reiki-kurzy.cz/cz/home'],
                'main with www and port'    => ['reiki-moravcova.cz', '/a', 'http://www.reiki-kurzy.cz:8080/', $a, 'http://www.reiki-kurzy.cz:8080/a'],
                'one of more alternates'    => ['old.example', '/a', $m, ['https://reiki-moravcova.cz/', 'https://old.example'], 'https://reiki-kurzy.cz/a'],

                // the visits that stay
                'main host'                 => ['reiki-kurzy.cz', '/cz/home', $m, $a, null],
                // www. of the main URL shares its page-cache entry, so it is the server's job
                'www of the main host'      => ['www.reiki-kurzy.cz', '/cz/home', $m, $a, null],
                'admin host with site_id'   => ['toolkit.ecn.cz', '/aaa/modules/site/site.php?site_id=a', $m, $a, null],
                'no alternates'             => ['reiki-moravcova.cz', '/cz/home', $m, [], null],
                'empty alternate'           => ['reiki-moravcova.cz', '/cz/home', $m, [''], null],
                'empty main url'            => ['reiki-moravcova.cz', '/cz/home', '', $a, null],
                'main url without scheme'   => ['reiki-moravcova.cz', '/cz/home', 'reiki-kurzy.cz', $a, null],
                'main url not http'         => ['reiki-moravcova.cz', '/cz/home', 'ftp://reiki-kurzy.cz/', $a, null],
                'alternate equal to main'   => ['reiki-kurzy.cz', '/cz/home', $m, [$m], null],
                'empty host'                => ['', '/cz/home', $m, $a, null],
                'suffix is not the host'    => ['xreiki-moravcova.cz', '/cz/home', $m, $a, null],
            ];
        }

        /** @param list<string> $alternates */
        #[DataProvider('visits')]
        public function test_alternateRedirectUrl(string $host, string $uri, string $main, array $alternates, ?string $expected): void
        {
            self::assertSame($expected, AA_Module_Site::alternateRedirectUrl('GET', $host, $uri, $main, $alternates));
        }

        /** a 301 turns a POST into a GET - the form would be lost */
        public function test_alternateRedirectUrl_moves_only_reads(): void
        {
            foreach (['GET' => true, 'HEAD' => true, 'head' => true, 'POST' => false, 'PUT' => false, '' => false] as $method => $moved) {
                $url = AA_Module_Site::alternateRedirectUrl($method, 'reiki-moravcova.cz', '/cz/home', self::MAIN, self::ALTERNATES);
                self::assertSame($moved ? 'https://reiki-kurzy.cz/cz/home' : null, $url, "method '$method'");
            }
        }
    }
}
