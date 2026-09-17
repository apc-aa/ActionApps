<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\ImgCacheWritable;
use PHPUnit\Framework\TestCase;

final class ImgCacheWritableTest extends TestCase
{
    private string $tmp;

    /** stands for IMG_UPLOAD_PATH */
    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/aa-img-cache-writable-' . bin2hex(random_bytes(8));
        mkdir($this->tmp, 0o775);
    }

    protected function tearDown(): void
    {
        @chmod($this->tmp . '/mC', 0o775);
        @chmod($this->tmp, 0o775);
        @rmdir($this->tmp . '/mC');
        @rmdir($this->tmp);
    }

    public function test_cache_root_not_created_yet_is_judged_by_the_upload_directory(): void
    {
        $this->assertTrue(ImgCacheWritable::canStore($this->tmp . '/mC'));
    }

    public function test_writable_cache_root_can_store(): void
    {
        mkdir($this->tmp . '/mC', 0o775);
        $this->assertTrue(ImgCacheWritable::canStore($this->tmp . '/mC'));
    }

    public function test_unwritable_cache_root_cannot_store(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes anywhere');
        }
        mkdir($this->tmp . '/mC', 0o555);
        $this->assertFalse(ImgCacheWritable::canStore($this->tmp . '/mC'), 'the upload directory being writable does not help');

        rmdir($this->tmp . '/mC');
        chmod($this->tmp, 0o555);
        $this->assertFalse(ImgCacheWritable::canStore($this->tmp . '/mC'), 'nor can the cache root be created');
    }
}
