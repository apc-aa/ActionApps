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

    /** fetches of all mails of the process */
    public static int $calls = 0;

    public static string $image = 'image bytes';

    /** the message body handed to mail() */
    public string $sent = '';

    public function getFile($filename): string
    {
        $this->fetched[] = $filename;
        ++self::$calls;
        return str_starts_with($filename, 'http') ? self::$image : '';
    }

    protected function nativeMail(string $to, string $subject, string $body, string $headers, string $params = ''): bool
    {
        $this->sent = $body;
        return true;
    }
}

/**
 * The built message embeds the images of the html and points their src at the attachment - a mail
 * client has no page url to resolve a relative src against. Separate processes: the test defines
 * AA_INSTAL_URL, and fetched images are remembered for the whole process.
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

    /** @return array{0: FetchRecordingMail, 1: string, 2: int} the mail, its built html with each cid: replaced by the image name, the number of embedded images */
    private static function mail(string $src, ?string $body = null): array
    {
        $mail = new FetchRecordingMail();
        $mail->setHtml($body ?? "<p><img src=\"$src\"></p>");
        $mail->buildMessage();
        [$html, $images] = self::parts((new \ReflectionProperty(\htmlMimeMail::class, 'output'))->getValue($mail));
        return [$mail, $html, \count($images)];
    }

    /**
     * The html part of a built body, each cid: in it replaced by the name of its image, and the images.
     * @return array{0: string, 1: array<string, string>} html, cid => base64 body
     */
    private static function parts(string $output): array
    {
        $html   = $output;   // a message without images is the html part alone
        $images = [];
        $names  = [];
        foreach (preg_split('~^--=_[0-9a-f]+(?:--)?\r?$~m', $output) as $part) {
            [$head, $body] = array_pad(preg_split('~\r?\n\r?\n~', ltrim($part, "\r\n"), 2), 2, '');
            if (str_contains($head, 'Content-Type: text/html')) {
                $html = $body;
            } elseif (preg_match('~Content-ID: <([0-9a-f]+)>~', $head, $cid) && preg_match('~filename="([^"]*)"~', $head, $name)) {
                $images[$cid[1]] = trim($body);
                $names[$cid[1]]  = $name[1];
            }
        }
        $html = rtrim(quoted_printable_decode($html), "\r\n");
        foreach ($names as $cid => $name) {
            $html = str_replace("cid:$cid", $name, $html);
        }
        return [$html, $images];
    }

    /** two images go out as two inline parts, each referenced by its cid */
    public function test_send_embedsImagesAsCids(): void
    {
        $mail = new FetchRecordingMail();
        $mail->setHtml('<img src="https://www.example.org/a.png"><img src="https://www.example.org/b.jpg">');
        $mail->send(['to@example.org']);

        self::assertSame(2, preg_match_all('~src="cid:[0-9a-f]+"~', quoted_printable_decode($mail->sent)));
        [$html, $images] = self::parts($mail->sent);
        self::assertSame('<img src="1a.png"><img src="2b.jpg">', $html);
        self::assertSame([base64_encode('image bytes'), base64_encode('image bytes')], array_values($images));
    }

    public function test_build_absoluteUrl_isEmbedded(): void
    {
        [$mail, $html, $embedded] = self::mail('https://www.example.org/img/logo.png');

        self::assertSame(['https://www.example.org/img/logo.png'], $mail->fetched);
        self::assertSame(1, $embedded);
        self::assertSame('<p><img src="1logo.png"></p>', $html);
    }

    public function test_build_rootRelativeImgPhp_isFetchedFromTheAaInstall(): void
    {
        [$mail, , $embedded] = self::mail('/actionapps/img.php?src=/files/up/a/p.jpg&amp;w=150');

        self::assertSame(['https://aa.example.org/actionapps/img.php?src=/files/up/a/p.jpg&w=150'], $mail->fetched);
        self::assertSame(1, $embedded);
    }

    /** {img} links the image cache root-relative on a page served by the host of IMG_UPLOAD_URL */
    public function test_build_rootRelativeUpload_isFetchedFromTheUploadHost(): void
    {
        [$mail, $html, $embedded] = self::mail('/files/up/mC/pT/abc/p.jpg!w=150.jpg');

        self::assertSame(['https://img.example.org/files/up/mC/pT/abc/p.jpg!w=150.jpg'], $mail->fetched);
        self::assertSame(1, $embedded);
        self::assertSame('<p><img src="1p.jpg!w=150.jpg"></p>', $html);
    }

    /** the path stands inside the img.php url too, which must stay whole */
    public function test_build_uploadPathInsideAnotherUrl_replacesOnlyItsOwnSrc(): void
    {
        [, $html, $embedded] = self::mail('', '<img src="/files/up/a/p.jpg"><img src="/actionapps/img.php?src=/files/up/a/p.jpg&amp;w=150">');

        self::assertSame(2, $embedded);
        self::assertSame('<img src="1p.jpg"><img src="2p.jpg">', $html);
    }

    public function test_build_oneImageInTwoSpellings_isOneAttachment(): void
    {
        [$mail, $html, $embedded] = self::mail('', '<img src="https://img.example.org/files/up/a/p.jpg"><img src="/files/up/a/p.jpg">');

        self::assertSame(['https://img.example.org/files/up/a/p.jpg'], $mail->fetched);
        self::assertSame(1, $embedded);
        self::assertSame('<img src="1p.jpg"><img src="1p.jpg">', $html);
    }

    /** only the src is embedded - Apple Mail would show a srcset or <source> candidate instead, a link at best */
    public function test_build_responsiveCandidates_areRenamed(): void
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
    public function test_build_renamedSrcset_isNotEmbedded(): void
    {
        [$mail, $html] = self::mail('', '<picture><source srcset="/files/up/a/x.jpg"><img src="/files/up/a/p.jpg" srcset=\'/files/up/a/y.jpg\'></picture>');

        self::assertSame(['https://img.example.org/files/up/a/p.jpg'], $mail->fetched);
        self::assertSame('<picture><source data-srcset="/files/up/a/x.jpg"><img src="1p.jpg" data-srcset=\'/files/up/a/y.jpg\'></picture>', $html);
    }

    public function test_setHtml_fetchesNothing(): void
    {
        $mail = new FetchRecordingMail();
        $mail->setHtml('<img src="https://www.example.org/a.png">');

        self::assertSame([], $mail->fetched);
    }

    /** a bulk mail builds one message per reader - the image is fetched once for all of them */
    public function test_twoMailsSameImage_fetchedOnce(): void
    {
        [$first, $html1] = self::mail('https://www.example.org/a.png');
        [$second, $html2] = self::mail('https://www.example.org/a.png');

        self::assertSame(1, FetchRecordingMail::$calls);
        self::assertSame(['https://www.example.org/a.png'], $first->fetched);
        self::assertSame([], $second->fetched);
        self::assertSame('<p><img src="1a.png"></p>', $html2);
        self::assertSame($html1, $html2);
    }

    public function test_failedFetch_notRepeatedInProcess(): void
    {
        FetchRecordingMail::$image = '';
        self::mail('https://www.example.org/a.png');
        [, $html, $embedded] = self::mail('https://www.example.org/a.png');

        self::assertSame(1, FetchRecordingMail::$calls);
        self::assertSame(0, $embedded);
        self::assertSame('<p><img src="https://www.example.org/a.png"></p>', $html);
    }

    /** a queued mail is a serialized object - it carries the html, not the images, and builds again when sent */
    public function test_serialize_carriesNoImageBodies(): void
    {
        FetchRecordingMail::$image = random_bytes(100_000);
        $mail = new FetchRecordingMail();
        $mail->setHtml('<p><img src="https://www.example.org/a.png"></p>');
        $mail->send(['to@example.org']);
        self::assertStringContainsString(substr(base64_encode(FetchRecordingMail::$image), 0, 76), $mail->sent);
        $mail->sent = '';   // the capture is the test's own, not a part of the mail

        $serialized = serialize($mail);
        self::assertLessThan(\strlen(FetchRecordingMail::$image), \strlen($serialized));

        $queued = unserialize($serialized);
        $queued->send(['to@example.org']);
        [$html, $images] = self::parts($queued->sent);
        self::assertSame('<p><img src="1a.png"></p>', $html);
        self::assertSame([FetchRecordingMail::$image], array_map(base64_decode(...), array_values($images)));
    }

    /** a file of the sites may change between two mails, and reading it costs no network */
    public function test_localPath_notCached(): void
    {
        foreach ([1, 2] as $i) {
            $mail = new FetchRecordingMail();
            $mail->setHtml('<img src="a.png">', null, '/srv/site/files/up/');
            $mail->buildMessage();
        }

        self::assertSame(2, FetchRecordingMail::$calls);
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
    public function test_build_rootRelative_isLeftAlone(array|false $config, string $src): void
    {
        ImgCache::useConfig($config);

        [$mail, $html, $embedded] = self::mail($src);

        self::assertSame([$src], $mail->fetched);
        self::assertSame(0, $embedded);
        self::assertSame("<p><img src=\"$src\"></p>", $html);
    }
}
