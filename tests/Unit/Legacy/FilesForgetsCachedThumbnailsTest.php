<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\Cache\ImgCache;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * A thumbnail url carries no version of its source, so when AA replaces or deletes an
 * uploaded file, the cached thumbnails of the old content have to go with it.
 * Files::uploadFile() ('overwrite', 'backup') cannot run outside a real upload
 * (move_uploaded_file); Files::delFile() and ImgCache::forget() below it can.
 *
 * Each test runs in its own process: it defines the config constants of the image
 * cache, which other tests must not inherit.
 */
final class FilesForgetsCachedThumbnailsTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root === '') {
            return;
        }
        $tree = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($tree as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->root);
    }

    private function install(): string
    {
        $this->root = sys_get_temp_dir() . '/aa-forget-' . bin2hex(random_bytes(8));
        $upload     = $this->root . '/img_upload/';
        mkdir($upload . '123', 0o775, true);
        \define('IMG_UPLOAD_URL', 'https://example.org/img_upload/');
        \define('IMG_UPLOAD_PATH', $upload);
        \define('IMG_UPLOAD_DIR_MODE', 0o775);
        \define('AA_INSTAL_URL', 'https://example.org/aa/');
        return $upload;
    }

    /** the file the cache would forge for the url {img} emits */
    private function thumbnail(string $source, string $params): string
    {
        $cache = ImgCache::get();
        $file  = $cache->parseRequest((string) parse_url($cache->getCachedUrl('pT', "img_upload/$source?$params"), PHP_URL_PATH))->filesystemPath;
        @mkdir(\dirname($file), 0o775, true);
        file_put_contents($file, 'thumbnail');
        return $file;
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_deleting_a_file_drops_its_thumbnails_and_nothing_else(): void
    {
        $upload = $this->install();
        file_put_contents($upload . '123/photo.jpg', 'photo');
        $gone = [$this->thumbnail('123/photo.jpg', 'w=150'), $this->thumbnail('123/photo.jpg', 'w=300&h=200&zc=1&aoe=1')];
        $kept = $this->thumbnail('123/other.jpg', 'w=150');

        $this->assertTrue(\Files::delFile($upload . '123/photo.jpg'));

        foreach ($gone as $file) {
            $this->assertFileDoesNotExist($file);
        }
        $this->assertFileExists($kept);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_file_which_is_not_there_is_not_deleted_and_nothing_is_forgotten(): void
    {
        $upload = $this->install();
        $kept   = $this->thumbnail('123/photo.jpg', 'w=150');

        $this->assertFalse(\Files::delFile($upload . '123/photo.jpg'));
        $this->assertFileExists($kept, 'the source may just be unreadable for a moment - its thumbnail is still right');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_forget_ignores_a_file_outside_the_uploads(): void
    {
        $this->install();
        $kept = $this->thumbnail('123/photo.jpg', 'w=150');

        $this->assertSame(0, ImgCache::forget('/etc/123/photo.jpg'));
        $this->assertFileExists($kept);
    }
}
