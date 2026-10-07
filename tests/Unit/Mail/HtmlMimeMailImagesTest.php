<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use AA\Cache\ImgCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/include/htmlMimeMail/htmlMimeMail.php';

/** Answers every http(s) url with image bytes and nothing else - a root-relative path read as a
 *  file from the root of the filesystem finds nothing on a real server either. */
final class FetchRecordingMail extends \htmlMimeMail
{
    /** @var list<string> */
    public array $fetched = [];

    public function getFile($filename): string
    {
        $this->fetched[] = $filename;
        return str_starts_with($filename, 'http') ? 'image bytes' : '';
    }
}

/**
 * setHtml() embeds the images of the html into the mail and points their src at the attachment -
 * a mail client has no page url to resolve a relative src against. Separate processes: the test
 * defines AA_INSTAL_URL.
 */
#[RunTestsInSeparateProcesses]
final class HtmlMimeMailImagesTest extends TestCase
{
    private const CACHE_CONFIG = [
        'IMG_UPLOAD_URL'           => 'https://img.example.org/files/up/',
        'IMG_UPLOAD_PATH'          => '/srv/site/files/up/',
        'IMG_UPLOAD_DIR_MODE'      => 0o775,
        'AA_INSTAL_URL'            => 'https://aa.example.org/actionapps/',
        'AA_MISSCACHE_IMG_DISABLE' => false,
    ];

    protected function setUp(): void
    {
        if (!defined('AA_INSTAL_URL')) {
            define('AA_INSTAL_URL', 'https://aa.example.org/actionapps/');
        }
        ImgCache::useConfig(self::CACHE_CONFIG);
    }

    /** @return array{0: FetchRecordingMail, 1: string, 2: int} the mail, its html after setHtml(), the number of embedded images */
    private static function mail(string $src, ?string $body = null): array
    {
        $mail = new FetchRecordingMail();
        $mail->setHtml($body ?? "<p><img src=\"$src\"></p>");
        $html   = (new \ReflectionProperty(\htmlMimeMail::class, 'html'))->getValue($mail);
        $images = (new \ReflectionProperty(\htmlMimeMail::class, 'html_images'))->getValue($mail);
        return [$mail, $html, \count($images)];
    }

    public function test_setHtml_absoluteUrl_isEmbedded(): void
    {
        [$mail, $html, $embedded] = self::mail('https://www.example.org/img/logo.png');

        self::assertSame(['https://www.example.org/img/logo.png'], $mail->fetched);
        self::assertSame(1, $embedded);
        self::assertSame('<p><img src="1logo.png"></p>', $html);
    }

    public function test_setHtml_rootRelativeImgPhp_isFetchedFromTheAaInstall(): void
    {
        [$mail, , $embedded] = self::mail('/actionapps/img.php?src=/files/up/a/p.jpg&amp;w=150');

        self::assertSame(['https://aa.example.org/actionapps/img.php?src=/files/up/a/p.jpg&w=150'], $mail->fetched);
        self::assertSame(1, $embedded);
    }

    /** {img} links the image cache root-relative on a page served by the host of IMG_UPLOAD_URL */
    public function test_setHtml_rootRelativeUpload_isFetchedFromTheUploadHost(): void
    {
        [$mail, $html, $embedded] = self::mail('/files/up/mC/pT/abc/p.jpg!w=150.jpg');

        self::assertSame(['https://img.example.org/files/up/mC/pT/abc/p.jpg!w=150.jpg'], $mail->fetched);
        self::assertSame(1, $embedded);
        self::assertSame('<p><img src="1p.jpg!w=150.jpg"></p>', $html);
    }

    /** the path stands inside the img.php url too, which must stay whole */
    public function test_setHtml_uploadPathInsideAnotherUrl_replacesOnlyItsOwnSrc(): void
    {
        [, $html, $embedded] = self::mail('', '<img src="/files/up/a/p.jpg"><img src="/actionapps/img.php?src=/files/up/a/p.jpg&amp;w=150">');

        self::assertSame(2, $embedded);
        self::assertSame('<img src="1p.jpg"><img src="2p.jpg">', $html);
    }

    public function test_setHtml_oneImageInTwoSpellings_isOneAttachment(): void
    {
        [$mail, $html, $embedded] = self::mail('', '<img src="https://img.example.org/files/up/a/p.jpg"><img src="/files/up/a/p.jpg">');

        self::assertSame(['https://img.example.org/files/up/a/p.jpg'], $mail->fetched);
        self::assertSame(1, $embedded);
        self::assertSame('<img src="1p.jpg"><img src="1p.jpg">', $html);
    }

    /** only the src is embedded - Apple Mail would show a srcset or <source> candidate instead, a link at best */
    public function test_setHtml_responsiveCandidates_areDropped(): void
    {
        [$mail, $html, $embedded] = self::mail('', '<picture class=aa-picture>'
            . '<source type="image/avif" srcset="/files/up/mC/pT/a/p.jpg!w=343!f=avif.avif 343w, /files/up/mC/pT/a/p.jpg!w=686!f=avif.avif 686w" sizes="auto, 343px">'
            . '<img src="/files/up/a/p.jpg" srcset="/files/up/a/p.jpg 343w, /files/up/a/q.jpg 686w" SIZES=\'343px\' width=343 alt="srcset=x > y">'
            . '</picture><video><source src="https://www.example.org/v.mp4"></video>');

        self::assertSame(['https://img.example.org/files/up/a/p.jpg'], $mail->fetched);
        self::assertSame(1, $embedded);
        self::assertSame('<picture class=aa-picture><img src="1p.jpg" width=343 alt="srcset=x > y"></picture>'
            . '<video><source src="https://www.example.org/v.mp4"></video>', $html);
    }

    /** @return array<string, array{0: array<string, mixed>|false, 1: string}> */
    public static function leftAloneProvider(): array
    {
        return [
            'outside the uploads'        => [self::CACHE_CONFIG, '/img/logo.png'],
            // the upload directory is what config.php says, not the usual name
            'the usual name, elsewhere'  => [self::CACHE_CONFIG, '/img_upload/a/p.jpg'],
            // no url prefix tells an uploaded file from any other one
            'uploads at the domain root' => [['IMG_UPLOAD_URL' => 'https://img.example.org/'] + self::CACHE_CONFIG, '/a/p.jpg'],
            'no upload config'           => [false, '/files/up/a/p.jpg'],
        ];
    }

    /** a root-relative path names no host AA knows of
     *  @param array<string, mixed>|false $config */
    #[DataProvider('leftAloneProvider')]
    public function test_setHtml_rootRelative_isLeftAlone(array|false $config, string $src): void
    {
        ImgCache::useConfig($config);

        [$mail, $html, $embedded] = self::mail($src);

        self::assertSame([$src], $mail->fetched);
        self::assertSame(0, $embedded);
        self::assertSame("<p><img src=\"$src\"></p>", $html);
    }
}
