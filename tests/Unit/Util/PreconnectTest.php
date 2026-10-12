<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Cache\Cacheentry;
use AA\Cache\ImgCache;
use AA\Util\Preconnect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreconnectTest extends TestCase
{
    /** values no install uses by default - a hard-coded upload path or host would not pass */
    private const CONFIG = [
        'IMG_UPLOAD_URL'           => 'https://up.example.org/files/up/',
        'IMG_UPLOAD_PATH'          => '/srv/example/files/up/',
        'IMG_UPLOAD_DIR_MODE'      => 0o775,
        'AA_INSTAL_URL'            => 'https://up.example.org/actionapps/',
        'AA_MISSCACHE_IMG_DISABLE' => false,
    ];

    private const HTML = ['type' => 'Content-Type: text/html; charset=utf-8'];

    private const UPLOAD = '<https://up.example.org>; rel=preconnect';

    private const FONTS = '<https://fonts.gstatic.com>; rel=preconnect; crossorigin';

    protected function setUp(): void
    {
        ImgCache::useConfig(self::CONFIG);
    }

    protected function tearDown(): void
    {
        ImgCache::useConfig(null);
    }

    /** @return array<string, array{0:string, 1:string}> page, expected header */
    public static function pages(): array
    {
        $thumb = '<img src="https://up.example.org/files/up/mC/pT/1/a.jpg!w=150.jpg">';
        $fonts = '<link href="https://fonts.googleapis.com/css2?family=Lato" rel="stylesheet">';
        return [
            'an upload linked absolutely'         => [$thumb, 'Link: ' . self::UPLOAD],
            'protocol-relative link'              => ['<img src="//up.example.org/files/up/1/a.jpg">', 'Link: ' . self::UPLOAD],
            'the upload host links relatively'    => ['<img src="/files/up/mC/pT/1/a.jpg!w=150.jpg">', ''],
            'the host linked outside the uploads' => ['<a href="https://up.example.org/actionapps/">admin</a>', ''],
            'the usual path is nothing special'   => ['<img src="https://up.example.org/img_upload/1/a.jpg">', ''],
            'Google Fonts'                        => [$fonts, 'Link: ' . self::FONTS],
            'both, upload host first'             => [$fonts . $thumb, 'Link: ' . self::UPLOAD . ', ' . self::FONTS],
            'neither'                             => ['<p>text</p>', ''],
        ];
    }

    #[DataProvider('pages')]
    public function test_header_names_the_origins_the_page_needs(string $page, string $expected): void
    {
        $this->assertSame($expected, Preconnect::header(self::HTML, $page));
    }

    /** a feed, a script or a download loads no images or fonts in a browser */
    public function test_header_only_for_an_html_page(): void
    {
        $page = '<img src="https://up.example.org/files/up/1/a.jpg">';

        $this->assertSame('', Preconnect::header(['type' => 'Content-Type: application/javascript; charset=utf-8'], $page));
        $this->assertSame('', Preconnect::header(self::HTML + ['disposition' => 'Content-Disposition: attachment; filename="a.html"'], $page));
    }

    public function test_header_keeps_the_port_of_the_upload_host(): void
    {
        ImgCache::useConfig(['IMG_UPLOAD_URL' => 'https://up.example.org:8443/files/up/'] + self::CONFIG);

        $this->assertSame('Link: <https://up.example.org:8443>; rel=preconnect', Preconnect::header(self::HTML, '<img src="https://up.example.org:8443/files/up/1/a.jpg">'));
        $this->assertSame('', Preconnect::header(self::HTML, '<img src="https://up.example.org/files/up/1/a.jpg">'), 'another origin');
    }

    /** an upload directory at the domain root: any link to the host is a link to an upload */
    public function test_header_with_the_uploads_at_the_domain_root(): void
    {
        ImgCache::useConfig(['IMG_UPLOAD_URL' => 'https://up.example.org/'] + self::CONFIG);

        $this->assertSame('Link: ' . self::UPLOAD, Preconnect::header(self::HTML, '<img src="https://up.example.org/1/a.jpg">'));
    }

    /** IMG_UPLOAD_URL without a host links the uploads on the host of the page; no config.php at all leaves Google Fonts only */
    public function test_header_without_an_upload_host(): void
    {
        $page = '<img src="https://up.example.org/files/up/1/a.jpg"><link href="https://fonts.googleapis.com/css2?family=Lato" rel="stylesheet">';

        ImgCache::useConfig(['IMG_UPLOAD_URL' => '/files/up/'] + self::CONFIG);
        $this->assertSame('Link: ' . self::FONTS, Preconnect::header(self::HTML, $page));

        ImgCache::useConfig(false);
        $this->assertSame('Link: ' . self::FONTS, Preconnect::header(self::HTML, $page));
    }

    /** the page cache stores the entry serialized and sends its headers on every hit */
    public function test_header_goes_into_the_cache_entry(): void
    {
        $entry = new Cacheentry('<img src="https://up.example.org/files/up/1/a.jpg">', self::HTML);

        $this->assertStringContainsString('Link: ' . self::UPLOAD, serialize($entry));
    }
}
