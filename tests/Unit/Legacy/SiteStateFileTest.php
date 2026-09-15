<?php

declare(strict_types=1);

/**
 * The one derivation of the legacy control-file path, shared by the public dispatcher
 * (modules/site/site.php) and CheckSiteStateFiles. state_file is a site setting typed in
 * the admin UI, so the name must stay inside modules/site/sites/ whatever is saved there.
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

    final class SiteStateFileTest extends TestCase
    {
        /** @return array<string, array{?string, string}> */
        public static function values(): array
        {
            return [
                'plain name'       => ['example.php3', 'site_example.php3'],   // shape of every live flag=0 value
                'stray whitespace' => ["  nszm.php3\n", 'site_nszm.php3'],
                'dots only'        => ['..', 'site_..'],                       // basename() leaves it, no such file
                // a slash means the column holds a router home path, not a control file - reshaping it
                // into site_home would invent a file and made the stock Site Template a false ERROR
                'router home path' => ['/en/home', ''],
                'router cz path'   => ['/cz/uvod', ''],
                'traversal'        => ['../../include/config.php', ''],
                'subdirectory'     => ['custom/foo.php', ''],
                'absolute path'    => ['/etc/passwd', ''],
                'zero'             => ['0', ''],                               // falsy for the caller before the helper
                'empty'            => ['', ''],
                'only whitespace'  => ['   ', ''],
                'unset'            => [null, ''],
            ];
        }

        #[DataProvider('values')]
        public function testStateFileNameStaysAFileName(?string $state_file, string $expected): void
        {
            self::assertSame($expected, AA_Module_Site::stateFileName($state_file));
        }

        public function testPathStaysInTheSitesDirectory(): void
        {
            $path = AA_Module_Site::stateFilePath(AA_Module_Site::stateFileName('..'));
            self::assertSame(AA_BASE_PATH . 'modules/site/sites/site_..', $path);
            self::assertStringNotContainsString('/../', $path);
        }
    }
}
