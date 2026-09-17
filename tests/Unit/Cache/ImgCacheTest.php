<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use AA\Cache\ImgCache;
use PHPUnit\Framework\TestCase;

/** No test defines the IMG_UPLOAD_* constants, so config.php is "missing" here unless a case hands the values in. */
final class ImgCacheTest extends TestCase
{
    private const CONFIG = [
        'IMG_UPLOAD_URL'           => 'https://example.org/img_upload/',
        'IMG_UPLOAD_PATH'          => '/srv/example/img_upload/',
        'IMG_UPLOAD_DIR_MODE'      => 0o775,
        'AA_INSTAL_URL'            => 'https://example.org/aa/',
        'AA_MISSCACHE_IMG_DISABLE' => false,
    ];

    protected function tearDown(): void
    {
        self::config(null);
    }

    /** the values as read from config.php - null to read the real constants again */
    private static function config(array|false|null $config): void
    {
        (new \ReflectionProperty(ImgCache::class, 'config'))->setValue(null, $config);
        (new \ReflectionProperty(ImgCache::class, 'instances'))->setValue(null, []);
    }

    /** a config.php3 older than the constants: off, and nothing dereferences them */
    public function test_configured_is_false_without_the_constants(): void
    {
        self::config(null);

        $this->assertFalse(ImgCache::configured());
        $this->assertFalse(ImgCache::enabled());
    }

    public function test_get_needs_a_configured_cache(): void
    {
        self::config(false);

        $this->expectException(\LogicException::class);
        ImgCache::get();
    }

    public function test_enabled_unless_switched_off_or_at_the_domain_root(): void
    {
        self::config(self::CONFIG);
        $this->assertTrue(ImgCache::enabled());

        self::config(['AA_MISSCACHE_IMG_DISABLE' => true] + self::CONFIG);
        $this->assertFalse(ImgCache::enabled());
        $this->assertTrue(ImgCache::configured(), 'switched off, what is cached is still served and purged');

        self::config(['IMG_UPLOAD_URL' => 'https://example.org/'] + self::CONFIG);
        $this->assertFalse(ImgCache::enabled(), 'no url prefix tells an upload from any other file');
    }

    public function test_paths_follow_the_config(): void
    {
        self::config(self::CONFIG);

        $this->assertSame('img_upload/', ImgCache::srcBase());
        $this->assertSame('/srv/example/img_upload/mC', ImgCache::root());
    }

    public function test_get_builds_absolute_or_relative_urls_once(): void
    {
        self::config(self::CONFIG);

        $this->assertSame('https://example.org/img_upload/mC/pT/1/a.jpg!w=1.jpg', ImgCache::get()->getCachedUrl('pT', 'img_upload/1/a.jpg?w=1'));
        $this->assertSame('/img_upload/mC/pT/1/a.jpg!w=1.jpg', ImgCache::get(true)->getCachedUrl('pT', 'img_upload/1/a.jpg?w=1'));
        $this->assertSame(ImgCache::get(true), ImgCache::get(true), '{img} asks for every image on the page');
    }
}
