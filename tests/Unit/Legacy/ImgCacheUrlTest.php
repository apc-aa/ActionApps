<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\Cache\ImgCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AA_Stringexpand_Img::getUrl() picking between the MissCache url and img.php.
 *
 * A resized image is served as a static file whenever this install can do it, and falls
 * back to img.php whenever it cannot. Getting the fallback wrong is invisible in
 * development and shows up as a broken image on every page, so both directions are
 * pinned here - including the img.php shapes, which must stay byte-identical to what AA
 * emitted before (characterization).
 *
 * include/stringexpand.php cannot be loaded on its own (it pulls in perm_core, formutil,
 * a session and a database), so the class is extracted from the file and evaluated
 * without its parent. The test therefore runs the real, current source.
 *
 * The constants it reads directly are renamed to test-only ones on the way in: other test
 * files define AA_INSTAL_URL & co. at file scope, so whichever of them loads first would
 * otherwise decide the values here. The image cache settings it gets from ImgCache, which
 * reads config.php once per request - the test hands them in there, the opt-out included.
 *
 * The eval() below is the technique, not a defect: its input is this repository's own
 * source plus a strtr() of constant names, never anything external, and tests/ is not
 * part of the dist tree servers run (dev/release/dist-exclude.txt).
 */
final class ImgCacheUrlTest extends TestCase
{
    /** the class under test */
    private const CLS = 'AA_Test_Stringexpand_Img';

    /** config.php constants the class reads -> the test-only ones defined below */
    private const CONSTANTS = [
        'AA_HTTP_DOMAIN' => 'AA_T_HTTP_DOMAIN',
        'AA_INSTAL_PATH' => 'AA_T_INSTAL_PATH',
        'AA_INSTAL_URL'  => 'AA_T_INSTAL_URL',
    ];

    /** what config.php defines for the image cache, as ImgCache holds it */
    private const CACHE_CONFIG = [
        'IMG_UPLOAD_URL'           => 'https://example.org/img_upload/',
        'IMG_UPLOAD_PATH'          => '/nonexistent/aa-img-cache-test/img_upload/',   // never touched: the render reads no file, which is the point
        'IMG_UPLOAD_DIR_MODE'      => 0o775,
        'AA_INSTAL_URL'            => 'https://example.org/apc-aa/',
        'AA_MISSCACHE_IMG_DISABLE' => false,
    ];

    public static function setUpBeforeClass(): void
    {
        // include/util.php cannot be loaded here either, so stub what the class calls
        if (!\function_exists('safe')) {
            eval('function safe($var = "") { return htmlspecialchars((string) ($var ?? ""), ENT_QUOTES | ENT_HTML5, "ISO-8859-1"); }');
        }
        if (!\function_exists('warn')) {
            eval('function warn($msg) {}');
        }

        \define('AA_T_HTTP_DOMAIN', 'https://example.org/');
        \define('AA_T_INSTAL_PATH', '/apc-aa/');
        \define('AA_T_INSTAL_URL', 'https://example.org/apc-aa/');

        $source = file_get_contents(__DIR__ . '/../../../include/stringexpand.php');
        self::assertIsString($source);
        $class = preg_replace('~^class \w+ extends \w+~', 'class ' . self::CLS, self::extractClass($source, 'AA_Stringexpand_Img'), 1);
        eval(strtr($class, self::CONSTANTS));
    }

    public static function tearDownAfterClass(): void
    {
        ImgCache::useConfig(null);   // ImgCache reads the real config again
    }

    protected function setUp(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.org';
        ImgCache::useConfig(self::CACHE_CONFIG);
        $this->resetMemos();
    }

    /** Source of one class, from "class X" to its closing brace (comments/strings safe). */
    private static function extractClass(string $source, string $name): string
    {
        $tokens = token_get_all($source);
        $start  = null;
        $depth  = 0;
        $out    = '';
        foreach ($tokens as $i => $token) {
            if ($start === null) {
                if (\is_array($token) && $token[0] === T_CLASS
                    && ($tokens[$i + 2][1] ?? '') === $name) {
                    $start = $i;
                } else {
                    continue;
                }
            }
            $text = \is_array($token) ? $token[1] : $token;
            $out .= $text;
            if ($text === '{') {
                $depth++;
            } elseif ($text === '}' && --$depth === 0) {
                return $out;
            }
        }
        self::fail("class $name not found in include/stringexpand.php");
    }

    /** The class computes whether urls are relative once per request - drop it between cases. */
    private function resetMemos(): void
    {
        (new \ReflectionProperty(self::CLS, 'cache_url_relative'))->setValue(null, null);
    }

    private function url(string $image, string $params, string $type = 'rel_escaped'): string
    {
        return \call_user_func([self::CLS, 'getUrl'], $image, $params, $type);
    }

    /**
     * An install whose config.php3 predates one of the constants must fall back, not die:
     * dereferencing a missing constant is a fatal error on every page carrying an image.
     */
    public function testConfigWithoutTheCacheConstantsFallsBackInsteadOfDying(): void
    {
        ImgCache::useConfig(false);

        self::assertSame('/apc-aa/img.php?src=/img_upload/a/p.jpg&amp;w=150', $this->url('img_upload/a/p.jpg', 'w=150'));
    }

    /** The img.php fallback lands in <img src="...">, so the source must not break out. */
    public function testImgPhpFallbackEscapesTheSource(): void
    {
        self::assertSame(
            '/apc-aa/img.php?src=/apc-aa-files/x.jpg&quot;onerror=alert(1)&amp;w=150',
            $this->url('apc-aa-files/x.jpg"onerror=alert(1)', 'w=150'),
        );
    }

    /** The parameters land in the same attribute - a template may build them from item content. */
    public function testImgPhpFallbackEscapesTheParameters(): void
    {
        self::assertSame(
            '/apc-aa/img.php?src=/apc-aa-files/x.jpg&amp;w=150&amp;fltr[]=wmt|a&quot; onerror=&quot;alert(1)',
            $this->url('apc-aa-files/x.jpg', 'w=150&fltr[]=wmt|a" onerror="alert(1)'),
        );
        self::assertSame(
            '/apc-aa/img.php?src=/apc-aa-files/x.jpg&amp;w=150&amp;h=99&amp;q=80',
            $this->url('apc-aa-files/x.jpg', 'w=150&amp;h=99&q=80'),
        );
    }

    /** An install which set AA_MISSCACHE_IMG_DISABLE keeps every image on img.php. */
    public function testOptOutConstantKeepsEverythingOnImgPhp(): void
    {
        ImgCache::useConfig(['AA_MISSCACHE_IMG_DISABLE' => true] + self::CACHE_CONFIG);

        self::assertSame('/apc-aa/img.php?src=/img_upload/a/p.jpg&amp;w=150', $this->url('img_upload/a/p.jpg', 'w=150'));
    }

    /** With the uploads at the domain root no url prefix tells an uploaded image from any other file. */
    public function testUploadsAtTheDomainRootKeepEverythingOnImgPhp(): void
    {
        ImgCache::useConfig(['IMG_UPLOAD_URL' => 'https://example.org/'] + self::CACHE_CONFIG);

        self::assertSame('/apc-aa/img.php?src=/a/p.jpg&amp;w=150', $this->url('a/p.jpg', 'w=150'));
    }

    /** Sources under IMG_UPLOAD, in every spelling a template may hand over. */
    public static function cachedProvider(): array
    {
        return [
            'relative path'      => ['img_upload/abc/photo.jpg'],
            'leading slash'      => ['/img_upload/abc/photo.jpg'],
            'own absolute url'   => ['https://example.org/img_upload/abc/photo.jpg'],
            'directly in root'   => ['img_upload/photo.jpg'],
        ];
    }

    #[DataProvider('cachedProvider')]
    public function testSourceUnderImgUploadUsesTheCache(string $image): void
    {
        self::assertStringStartsWith('/img_upload/mC/pT/', $this->url($image, 'w=150&h=150'));
    }

    public function testCacheUrlCarriesNameParametersAndOutputExtension(): void
    {
        self::assertSame(
            '/img_upload/mC/pT/abc/photo.jpg!w=150!h=150!zc=1!aoe=1.jpg',
            $this->url('img_upload/abc/photo.jpg', 'w=150&h=150&zc=1'),   // aoe=1 added by addAoe4ZoomCrop()
        );
    }

    /** CKEditor stores "&amp;" in templates; MissCache splits the query on "&". */
    public function testHtmlEscapedSeparatorDoesNotLeakIntoTheCacheName(): void
    {
        self::assertSame(
            $this->url('img_upload/abc/photo.jpg', 'w=150&h=150'),
            $this->url('img_upload/abc/photo.jpg', 'w=150&amp;h=150'),
        );
    }

    public function testOutputFormatDrivesTheExtension(): void
    {
        self::assertStringEndsWith('!w=150!f=webp.webp', $this->url('img_upload/a/p.jpg', 'w=150&f=webp'));
    }

    /**
     * Sources MissCache cannot serve: the dispatcher rebuilds the phpThumb src under
     * IMG_UPLOAD, so anything else would only ever get the blank fallback.
     */
    public static function uncachedProvider(): array
    {
        return [
            'file manager'      => ['apc-aa-files/2024/photo.jpg', 'w=150'],
            'install images'    => ['apc-aa/images/logo.png', 'w=150'],
            'foreign domain'    => ['https://other.example.com/photo.jpg', 'w=150'],
            'generated image'   => ['img_upload/abc/photo.jpg', 'new=FFFFFF|0&w=20&h=20'],
            'phpThumb debug'    => ['img_upload/abc/photo.jpg', 'w=150&phpThumbDebug=9'],
            'explicit nocache'  => ['img_upload/abc/photo.jpg', 'w=150&nocache=1'],
            'forced download'   => ['img_upload/abc/photo.jpg', 'w=150&down=photo.jpg'],
            'second source'     => ['img_upload/abc/photo.jpg', 'w=150&src=/img_upload/abc/other.jpg'],
            'hash of the source' => ['img_upload/abc/photo.jpg', 'w=150&md5s='],
            // the library mirrors directories as they are, and only a file on disk under its
            // literal name can be forged - img.php decodes its query and copes with these
            'quote in directory'      => ['img_upload/x"onerror="alert(1)/a.jpg', 'w=150'],
            'space in directory'      => ['img_upload/my photos/a.jpg', 'w=150'],
            'percent-encoded name'    => ['img_upload/abc/a%20b.jpg', 'w=150'],
            'percent in directory'    => ['img_upload/a%20b/c.jpg', 'w=150'],
            'query in the source'     => ['img_upload/abc/a.jpg?v=2', 'w=150'],
            'fragment in the source'  => ['img_upload/abc/a.jpg#top', 'w=150'],
            'parent directory'        => ['img_upload/../etc/a.jpg', 'w=150'],
            'empty directory segment' => ['img_upload//a.jpg', 'w=150'],
            'no file name'            => ['img_upload/abc/', 'w=150'],
            'newline after directory' => ["img_upload/abc\n/a.jpg", 'w=150'],
            'thumbnail as the source' => ['img_upload/mC/pT/abc/a.jpg!w=300.jpg', 'w=150'],
            // names the dispatcher refuses, or the request to img.php cannot carry
            'dots in the name'        => ['img_upload/abc/foto...jpg', 'w=150'],
            'backslash in the name'   => ['img_upload/abc/a\\b.jpg', 'w=150'],
            'space in the name'       => ['img_upload/abc/my photo.jpg', 'w=150'],
            'control byte in name'    => ["img_upload/abc/a\tb.jpg", 'w=150'],
        ];
    }

    /** a parameter list broken over lines gives the same thumbnail as the one-line list */
    public function testLineBreaksInTheParametersAreDropped(): void
    {
        self::assertSame($this->url('img_upload/abc/photo.jpg', 'w=300&h=200&zc=1'), $this->url('img_upload/abc/photo.jpg', "w=300&h=200\n\t&zc=1"));
    }

    /** The base comes from config.php and is not mirrored - odd characters there must not cost the cache */
    public function testImageDirectlyInTheUploadDirectoryUsesTheCache(): void
    {
        self::assertSame('/img_upload/mC/pT/photo.jpg!w=150.jpg', $this->url('img_upload/photo.jpg', 'w=150'));
    }

    /** A value stored through the anonymous filler.php reaches <img src="{img:...}"> - it must not open the attribute. */
    public function testQuoteInTheSourceNeverReachesThePageRaw(): void
    {
        foreach (['img_upload/x"onerror="alert(1)/a.jpg', 'img_upload/abc/a"onerror="alert(1).jpg'] as $image) {
            self::assertStringNotContainsString('"', $this->url($image, 'w=150'), $image);
        }
    }

    /** The file name may hold anything - the library encodes it - so an odd but real upload name keeps the cache. */
    public function testOddFileNameStillUsesTheCache(): void
    {
        self::assertSame('/img_upload/mC/pT/abc/k~C5~AF~C5~88~2B1.jpg!w=150.jpg', $this->url('img_upload/abc/kůň+1.jpg', 'w=150'));
    }

    #[DataProvider('uncachedProvider')]
    public function testUnsupportedSourceOrParameterFallsBackToImgPhp(string $image, string $params): void
    {
        self::assertStringContainsString('img.php?src=', $this->url($image, $params));
    }

    /**
     * A url on ANOTHER server must never reach the cache. getUrl() strips the host from
     * every domain, so after the normalisation an image fed in from a second AA looks
     * exactly like a local one - and the cache url would point at a file this server does
     * not have, so the image would only ever get the blank fallback.
     *
     * @return array<string, array{0: string}>
     */
    public static function foreignHostProvider(): array
    {
        return [
            'another AA, same layout' => ['https://other-aa.example.org/img_upload/abc/photo.jpg'],
            'protocol relative'       => ['//other-aa.example.org/img_upload/abc/photo.jpg'],
            'plain foreign image'     => ['https://other.example.com/photo.jpg'],
        ];
    }

    #[DataProvider('foreignHostProvider')]
    public function testForeignHostNeverReachesTheCache(string $image): void
    {
        self::assertStringContainsString('img.php?src=', $this->url($image, 'w=150&h=150&zc=1'));
    }

    /** The host is the only reason: the same path without it does reach the cache. */
    public function testItIsTheHostThatKeepsTheFedImageOutOfTheCache(): void
    {
        self::assertStringContainsString('img.php?src=', $this->url('https://other-aa.example.org/img_upload/abc/photo.jpg', 'w=150'));
        self::assertStringStartsWith('/img_upload/mC/pT/', $this->url('img_upload/abc/photo.jpg', 'w=150'));
    }

    /**
     * The render decides from the url alone - IMG_UPLOAD_PATH above does not even exist.
     * A source which is missing (or unreadable for a moment) is the dispatcher's business
     * at request time: it answers a short-lived blank and stores nothing.
     */
    public function testSourceIsNeverLookedUpOnDisk(): void
    {
        self::assertSame(
            '/img_upload/mC/pT/abc/not-uploaded-yet.jpg!w=150.jpg',
            $this->url('img_upload/abc/not-uploaded-yet.jpg', 'w=150'),
        );
    }

    /** Characterization: the img.php fallback is exactly what AA emitted before. */
    public function testImgPhpFallbackShapesAreUnchanged(): void
    {
        self::assertSame(
            '/apc-aa/img.php?src=/apc-aa-files/a/p.jpg&amp;w=150&amp;h=99',
            $this->url('apc-aa-files/a/p.jpg', 'w=150&h=99'),
        );
        self::assertSame(
            'https://example.org/apc-aa/img.php?src=/apc-aa-files/a/p.jpg&w=150&h=99',
            $this->url('apc-aa-files/a/p.jpg', 'w=150&h=99', 'abs_raw'),
        );
    }

    /** Without parameters the source goes to the page as it is, so it is escaped the same way. */
    public function testNoParametersStillEscapesTheSourceForThePage(): void
    {
        self::assertSame('x.jpg&quot; onerror=&quot;alert(1)', $this->url('x.jpg" onerror="alert(1)', ''));
        self::assertSame('x.jpg" onerror="alert(1)', $this->url('x.jpg" onerror="alert(1)', '', 'abs_raw'));
    }

    /** No parameters means no resizing at all - the source url is returned untouched. */
    public function testNoParametersReturnsTheSourceUnchanged(): void
    {
        self::assertSame('https://example.org/img_upload/a/p.jpg', $this->url('https://example.org/img_upload/a/p.jpg', ''));
    }

    /**
     * 'measure' is what @getimagesize() is pointed at. It must stay on img.php: through
     * the cache the miss would be forged synchronously inside the page render, while
     * this way it warms phpThumb's own cache for the browser's request.
     */
    public function testMeasuringNeverGoesThroughTheCache(): void
    {
        self::assertSame(
            'https://example.org/apc-aa/img.php?src=/img_upload/a/p.jpg&w=150',
            $this->url('img_upload/a/p.jpg', 'w=150', 'measure'),
        );
    }

    /**
     * The url stays document-root relative while IMG_UPLOAD is on the host being served -
     * that is what the img.php link did. An install serving several domains from one
     * IMG_UPLOAD has to keep the absolute url, or the browser follows the canonical
     * redirect on every image and the cache ends up split over two trees.
     *
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function hostProvider(): array
    {
        return [
            'same host'          => ['example.org',       '/img_upload/mC/'],
            'www of same host'   => ['www.example.org',   '/img_upload/mC/'],
            'another domain'     => ['other.example.com', 'https://example.org/img_upload/mC/'],
            'cli (cron, mails)'  => [null,                'https://example.org/img_upload/mC/'],
        ];
    }

    #[DataProvider('hostProvider')]
    public function testUrlIsRelativeOnlyOnTheHostServingImgUpload(?string $host, string $expected_prefix): void
    {
        if ($host === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $host;
        }
        $this->resetMemos();

        self::assertStringStartsWith($expected_prefix, $this->url('img_upload/a/p.jpg', 'w=150'));
    }

    /**
     * 'im' is lazy unless the template's attributes set loading themselves - loading=eager
     * for the hero image, which lazy would paint late. Without loading= the tag is the one
     * AA always printed (characterization).
     */
    #[DataProvider('quickImgProvider')]
    public function testQuickImgIsLazyUnlessTheAttributesSetLoading(string $attrs, string $expected): void
    {
        $cls = self::CLS;
        $src = $this->url('img_upload/a/p.jpg', 'w=150');

        self::assertSame(sprintf($expected, $src), (new $cls())->expand('img_upload/a/p.jpg', 'w=150', 'im', 'Logo', $attrs));
    }

    /** @return array<string, array{string, string}> */
    public static function quickImgProvider(): array
    {
        return [
            'no attributes'    => ['', '<img src="%s"  loading=lazy title="Logo" alt="Logo" />'],
            'other attributes' => ['class="big"', '<img src="%s" class="big" loading=lazy title="Logo" alt="Logo" />'],
            'hero'             => ['loading=eager', '<img src="%s" loading=eager  title="Logo" alt="Logo" />'],
        ];
    }

    /** 'rawurl' is meant to be used outside the page (feeds, JSON), so it stays absolute. */
    public function testRawUrlIsAbsoluteEvenOnTheOwnHost(): void
    {
        self::assertStringStartsWith('https://example.org/img_upload/mC/', $this->url('img_upload/a/p.jpg', 'w=150', 'abs_raw'));
    }
}
