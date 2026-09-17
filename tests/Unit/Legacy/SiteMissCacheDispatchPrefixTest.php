<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use MissCache\MissCache;
use MissCache\Plugins\PhpThumbPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the MissCache dispatch guard in modules/site/site.php.
 *
 * site.php routes a cache miss to MissCache only when REQUEST_URI starts with the
 * cache prefix, computed inline as $mc_dir. The rendered URLs come from
 * MissCache::getCachedUrl() (via AA_Stringexpand_Img::getUrl()), whose
 * constructor rtrim()s the base URL — so a base without a trailing slash still
 * yields "/upload/mC/...", while the original guard built "/uploadmC/". The two
 * then disagree, the guard never matches, and every thumbnail 404s with no error
 * anywhere: the URLs are emitted correctly and the dispatcher is simply never
 * entered (biom.cz, 2026-09-03).
 *
 * The invariant these tests pin: for ANY IMG_UPLOAD_URL shape, $mc_dir must be a
 * prefix of the path of the URL the library actually emits.
 */
final class SiteMissCacheDispatchPrefixTest extends TestCase
{
    /** absolute path to the file under test */
    private const SOURCE = __DIR__ . '/../../../modules/site/site.php';

    /** cache sub-directory, as passed in site.php */
    private const CACHE_SEGMENT = 'mC';

    /**
     * The dispatch guard of site.php, in both variants.
     *
     * @param string $variant 'old' (naive concatenation) or 'new' (the fix)
     */
    private function mcDir(string $variant, string $imgUploadUrl): string
    {
        return match ($variant) {
            'old' => parse_url($imgUploadUrl, PHP_URL_PATH) . self::CACHE_SEGMENT . '/',
            'new' => rtrim((string) parse_url($imgUploadUrl, PHP_URL_PATH), '/') . '/' . self::CACHE_SEGMENT . '/',
        };
    }

    /** Path part of the URL the library emits for a thumbnail under $imgUploadUrl. */
    private function renderedPath(string $imgUploadUrl, string $srcWithQuery): string
    {
        $mc = new MissCache(
            $imgUploadUrl,
            '/srv/site/upload',
            self::CACHE_SEGMENT,
            0775,
            [new PhpThumbPlugin('https://example.org/aaa/img.php')],
        );

        return (string) parse_url($mc->getCachedUrl('pT', $srcWithQuery), PHP_URL_PATH);
    }

    /**
     * IMG_UPLOAD_URL shapes an install may legitimately carry.
     *
     * @return array<string, array{0: string, 1: bool}> url => [url, matched by the OLD guard?]
     */
    public static function imgUploadUrlProvider(): array
    {
        return [
            'absolute, trailing slash'      => ['https://biom.cz/upload/', true],
            'absolute, no trailing slash'   => ['https://biom.cz/upload', false],   // the bug
            'relative, trailing slash'      => ['/upload/', true],
            'relative, no trailing slash'   => ['/upload', false],                  // the bug
            'sub-directory install'         => ['https://example.org/site/img_upload/', true],
            'sub-directory, no slash'       => ['https://example.org/site/img_upload', false],
            'cache at docroot'              => ['https://example.org/', true],
            'no path component at all'      => ['https://example.org', false],      // parse_url() -> null
        ];
    }

    #[DataProvider('imgUploadUrlProvider')]
    public function testFixedGuardMatchesTheRenderedUrlForEveryBaseShape(string $imgUploadUrl, bool $oldMatches): void
    {
        $path = $this->renderedPath($imgUploadUrl, '123/photo.jpg?w=150&h=150&zc=1');

        $this->assertStringStartsWith(
            $this->mcDir('new', $imgUploadUrl),
            $path,
            "dispatch guard must match the URL the library emits for base '$imgUploadUrl'",
        );
    }

    #[DataProvider('imgUploadUrlProvider')]
    public function testOriginalGuardMissesWheneverTheBaseHasNoTrailingSlash(string $imgUploadUrl, bool $oldMatches): void
    {
        $path = $this->renderedPath($imgUploadUrl, '123/photo.jpg?w=150&h=150&zc=1');

        $this->assertSame(
            $oldMatches,
            str_starts_with($path, $this->mcDir('old', $imgUploadUrl)),
            "documents the original guard's behaviour for base '$imgUploadUrl'",
        );
    }

    /** The real biom.cz URL that 404'd, end to end: render it, then dispatch on it. */
    public function testBiomProductionUrlIsDispatchedWithBothBaseShapes(): void
    {
        $src      = 'upload/c7b858a933eb8b04267cfb6c891ce453/heralec_ondrej-malinovsky-2.jpg?w=100&h=100&zc=1';
        $resolved = [];

        foreach (['https://biom.cz/upload/', 'https://biom.cz/upload'] as $base) {
            $mc  = new MissCache($base, '/srv/biom/upload', self::CACHE_SEGMENT, 0775, [new PhpThumbPlugin('https://biom.cz/aaa/img.php')]);
            $url = $mc->getCachedUrl('pT', $src);

            $this->assertSame(
                'https://biom.cz/upload/mC/pT/c7b858a933eb8b04267cfb6c891ce453/heralec_ondrej-malinovsky-2.jpg!w=100!h=100!zc=1.jpg',
                $url,
                "both base shapes render the same URL (base '$base')",
            );

            $uri = (string) parse_url($url, PHP_URL_PATH);
            $this->assertStringStartsWith($this->mcDir('new', $base), $uri, "guard matches (base '$base')");

            $req = $mc->parseRequest($uri);
            $this->assertNotNull($req, "dispatcher parses the URI (base '$base')");
            $resolved[] = [$req->filesystemPath, $req->sourceFsPath, $req->params, $req->outExt];
        }

        // ... and both resolve to the very same cache file and the very same source.
        $this->assertSame($resolved[0], $resolved[1], 'the trailing slash must not move the cache file or the source');
    }

    /** The added slash must not make the guard swallow a sibling path. */
    public function testGuardDoesNotMatchASiblingDirectory(): void
    {
        $mc_dir = $this->mcDir('new', 'https://example.org/upload');

        $this->assertFalse(str_starts_with('/uploadmC/pT/x.jpg', $mc_dir));
        $this->assertFalse(str_starts_with('/upload-mC/pT/x.jpg', $mc_dir));
        $this->assertFalse(str_starts_with('/uploads/mC/pT/x.jpg', $mc_dir));
        $this->assertTrue(str_starts_with('/upload/mC/pT/x.jpg', $mc_dir));
    }

    public function testProductionGuardRtrimsTheBasePath(): void
    {
        $src = file_get_contents(self::SOURCE);
        $this->assertNotFalse($src, 'cannot read ' . self::SOURCE);

        $this->assertMatchesRegularExpression(
            '/\$mc_dir\s*=\s*rtrim\(\(string\)\s*parse_url\(IMG_UPLOAD_URL,\s*PHP_URL_PATH\),\s*[\'"]\/[\'"]\)\s*\.\s*[\'"]\/[\'"]\s*\.\s*ImgCache::SEGMENT\s*\.\s*[\'"]\/[\'"]/',
            $src,
            'site.php must rtrim the base path before appending "/" . ImgCache::SEGMENT . "/"',
        );
        $this->assertSame(self::CACHE_SEGMENT, \AA\Cache\ImgCache::SEGMENT, 'the segment the render and the purge use');
        $this->assertDoesNotMatchRegularExpression(
            '/parse_url\(IMG_UPLOAD_URL,\s*PHP_URL_PATH\)\s*\.\s*[\'"]mC\/[\'"]/',
            $src,
            'the naive concatenation (silently dead dispatcher on a slashless base) must not return',
        );
    }
}
