<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Files::uploadFile() refuses a name the web server could run instead of serving it. Apache's
 * mod_mime takes every extension of the name, so "x.php.jpg" runs as PHP wherever .php has a
 * handler - an inner extension counts as well. The names come already escaped (Files::escape():
 * lower case, no leading dot), as uploadFile() checks them.
 */
final class FilesExecutableNameTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\defined('DEFAULT_LANG_INCLUDE')) {
            \define('DEFAULT_LANG_INCLUDE', 'en_utf8.php3');
        }
        require_once __DIR__ . '/../../../include/files.class.php';
    }

    #[DataProvider('executableNames')]
    public function test_isExecutableName_refusesWhatTheServerRuns(string $name): void
    {
        self::assertTrue(\Files::isExecutableName($name));
    }

    public static function executableNames(): array
    {
        return [
            'php'             => ['shell.php'],
            'old php'         => ['x.php5'],
            'phtml'           => ['x.phtml'],
            'phar'            => ['x.phar'],
            'ssi'             => ['page.shtml'],
            'cgi'             => ['run.cgi'],
            'inner php'       => ['photo.php.jpg'],
            'inner ssi'       => ['a.shtml.pdf'],
            'in a path'       => ['/var/www/img_upload/abc/x.php'],
        ];
    }

    /** the check sees the stored name: escape() has already lowercased it and trimmed the dots */
    #[DataProvider('uploadedNames')]
    public function test_uploadFile_refusesTheEscapedName(string $name): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m()
        self::assertFalse(\Files::uploadFile(['name' => $name, 'tmp_name' => '/nonexistent'], sys_get_temp_dir()));
        self::assertSame(FILE_ERROR_TYPE_NOT_ALLOWED, \Files::lastErr());
    }

    public static function uploadedNames(): array
    {
        return [
            'upper case'    => ['X.PHP'],
            'trailing dot'  => ['x.php.'],
            'inner, spaced' => ['x.php .jpg'],
            'entity'        => ['x.ph&#112;'],
        ];
    }

    #[DataProvider('servedNames')]
    public function test_isExecutableName_letsFilesThroughThatAreServed(string $name): void
    {
        self::assertFalse(\Files::isExecutableName($name));
    }

    public static function servedNames(): array
    {
        return [
            'jpg'             => ['photo.jpg'],
            'pdf'             => ['vyrocni-zprava-2025.pdf'],
            'php in the name' => ['php-kurz.pdf'],
            'no extension'    => ['readme'],
            'language suffix' => ['cenik.pl.pdf'],
            'html'            => ['newsletter.html'],
            'php without dot' => ['photophp.jpg'],
        ];
    }
}
