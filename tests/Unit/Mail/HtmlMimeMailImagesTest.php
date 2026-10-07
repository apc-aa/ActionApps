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
    public function test_setHtml_responsiveCandidates_areRenamed(): void
    {
        [$mail, $html, $embedded] = self::mail('', '<picture class=aa-picture>'
            . '<source type="image/avif" srcset="/files/up/mC/pT/a/p.jpg!w=343!f=avif.avif 343w, /files/up/mC/pT/a/p.jpg!w=686!f=avif.avif 686w" sizes="auto, 343px">'
            . '<img src="/files/up/a/p.jpg" srcset="/files/up/a/p.jpg 343w, /files/up/a/q.jpg 686w" SIZES=\'343px\' width=343 alt="srcset=x > y">'
            . '</picture><video><source src="https://www.example.org/v.mp4"></video>');

        self::assertSame(['https://img.example.org/files/up/a/p.jpg'], $mail->fetched);
        self::assertSame(1, $embedded);
        self::assertSame('<picture class=aa-picture>'
            . '<source type="image/avif" data-srcset="/files/up/mC/pT/a/p.jpg!w=343!f=avif.avif 343w, /files/up/mC/pT/a/p.jpg!w=686!f=avif.avif 686w" data-sizes="auto, 343px">'
            . '<img src="1p.jpg" data-srcset="/files/up/a/p.jpg 343w, /files/up/a/q.jpg 686w" data-SIZES=\'343px\' width=343 alt="srcset=x > y">'
            . '</picture><video><source src="https://www.example.org/v.mp4"></video>', $html);
    }

    /** a renamed srcset of one url looks like any quoted image path - it must not become an attachment */
    public function test_setHtml_renamedSrcset_isNotEmbedded(): void
    {
        [$mail, $html] = self::mail('', '<picture><source srcset="/files/up/a/x.jpg"><img src="/files/up/a/p.jpg" srcset=\'/files/up/a/y.jpg\'></picture>');

        self::assertSame(['https://img.example.org/files/up/a/p.jpg'], $mail->fetched);
        self::assertSame('<picture><source data-srcset="/files/up/a/x.jpg"><img src="1p.jpg" data-srcset=\'/files/up/a/y.jpg\'></picture>', $html);
    }

    private static function srcOnly(string $html): string
    {
        return (new \ReflectionMethod(\htmlMimeMail::class, 'srcOnly'))->invoke(null, $html);
    }

    /** @return array<string, array{string, string}> */
    public static function srcOnlyProvider(): array
    {
        return [
            // a quote opens a value only right after '=' - as browsers read it
            'apostrophe in an unquoted value' => ["<img alt=Tom's src=a.jpg srcset=\"b.jpg 2x\">", "<img alt=Tom's src=a.jpg data-srcset=\"b.jpg 2x\">"],
            'text after the tag stays text'   => ["<img src=a srcset=b> it's <p sizes='x'>", "<img src=a data-srcset=b> it's <p sizes='x'>"],
            'no space between attributes'     => ['<img src="a"srcset="b 2x">', '<img src="a"data-srcset="b 2x">'],
            'right after the name'            => ['<img/srcset=b src=a>', '<img/data-srcset=b src=a>'],
            'srcset= inside a value'          => ['<img alt="srcset=x > y" src=a>', '<img alt="srcset=x > y" src=a>'],
            'vertical tab is no space'        => ["<img alt=a\x0Bsrcset=b src=c>", "<img alt=a\x0Bsrcset=b src=c>"],
            'source of a picture'             => ['<picture><source srcset=a.avif sizes=auto type=image/avif><img src=a.jpg>', '<picture><source data-srcset=a.avif data-sizes=auto type=image/avif><img src=a.jpg>'],
            'source of an audio'              => ['<audio><source src=a.mp3></audio>', '<audio><source src=a.mp3></audio>'],
            // 2 MB in one <picture> once exhausted the backtrack limit and the mail went out empty
            'data uri in a picture'           => ['<picture><img src="data:image/png;base64,' . str_repeat('A', 2_000_000) . '"></picture><img src=b srcset=c>', '<picture><img src="data:image/png;base64,' . str_repeat('A', 2_000_000) . '"></picture><img src=b data-srcset=c>'],
        ];
    }

    #[DataProvider('srcOnlyProvider')]
    public function test_setHtml_responsiveCandidates_areRenamedAsBrowsersReadTags(string $html, string $expected): void
    {
        self::assertSame($expected, self::srcOnly($html));
    }

    /** @return array<string, array{string}> */
    public static function misreadTagsProvider(): array
    {
        return [
            'vertical tab before a name'  => ["<img alt=a\x0Bsrcset=\" title=\"x onerror=f() y\">"],
            'vertical tab after the tag'  => ["<img\x0Bsrcset=' title='x><img src=x onerror=f()>'>"],
            '= starting a name'           => ['<img ="x>srcset=<b title=" >  <img src=x onerror=f()>   ">'],
            '= starting a name in source' => ['<picture><source ="x><img alt=" >  <img src=x onerror=f()> ">'],
            '<img inside a value'         => ['<p title=\'<img srcset="\'> <b title=">\'> <img src=x onerror=f()> ">'],
        ];
    }

    /** where a tag is read otherwise than a browser reads it, renaming still only inserts 'data-' -
     *  deleting the attribute took its quotes along, which turned text into an onerror handler */
    #[DataProvider('misreadTagsProvider')]
    public function test_setHtml_misreadTag_onlyGainsDataPrefixes(string $html): void
    {
        self::assertSame($html, str_replace('data-', '', self::srcOnly($html)));
    }

    /** nothing is scanned twice, so hostile markup costs linear time even without the PCRE JIT - and is never lost */
    public function test_setHtml_hostileMarkup_staysLinear(): void
    {
        $jit = ini_set('pcre.jit', '0');
        try {
            foreach ([
                str_repeat('<picture', 20000),
                '<picture>' . str_repeat('<source a="', 10000) . str_repeat("<source b='", 10000),
                str_repeat('<img a="', 10000) . str_repeat("<img b='", 10000),
                '<img ' . str_repeat(' ', 100000) . '/',
                str_repeat('<img/x=', 20000),
            ] as $html) {
                $start = hrtime(true);
                self::assertSame($html, self::srcOnly($html));
                self::assertLessThan(1.0, (hrtime(true) - $start) / 1e9);   // ~10 ms linear, seconds quadratic
            }
        } finally {
            ini_set('pcre.jit', (string) $jit);
        }
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
