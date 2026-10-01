<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * cdn/ is the local mirror of jsDelivr files which AA serves itself (AA_Requires::mirror()).
 * dev/cdn/SHA256SUMS pins every file. A path the code links but nobody fetched, or a file
 * changed by hand, would only show up as a 404 or a broken page on the sites - so CI checks
 * that the code, the list and the files agree.
 */
final class CdnMirrorTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /** @return array<string, string> path below cdn/ => sha256 */
    private static function sums(): array
    {
        $sums = [];
        foreach (file(self::root() . '/dev/cdn/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            [$sum, $path] = preg_split('/\s+/', $line, 2);
            $sums[$path] = $sum;
        }
        return $sums;
    }

    /** @return list<string> paths below cdn/ the PHP code links - mirror('...') and 'cdn/...' literals */
    private static function linkedPaths(): array
    {
        $files = glob(self::root() . '/*.php');
        foreach (['include', 'src', 'modules', 'admin', 'central', 'misc'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . "/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        $paths = [];
        foreach ($files as $file) {
            preg_match_all("~(?:mirror\\(['\"]|['\"]cdn/)((?:npm|gh)/[^'\"]+)~", file_get_contents($file), $m);
            $paths = array_merge($paths, $m[1]);
        }
        return array_values(array_unique($paths));
    }

    public function test_every_linked_path_is_recorded(): void
    {
        $paths = self::linkedPaths();
        self::assertNotEmpty($paths);   // the scan itself still finds them
        self::assertSame([], array_values(array_diff($paths, array_keys(self::sums()))));
    }

    public function test_recorded_files_are_unchanged(): void
    {
        foreach (self::sums() as $path => $sum) {
            $file = self::root() . "/cdn/$path";
            self::assertFileExists($file);
            self::assertSame($sum, hash_file('sha256', $file), $path);
        }
    }

    public function test_every_file_is_recorded(): void
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/cdn', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[] = substr($file->getPathname(), \strlen(self::root() . '/cdn/'));
        }
        self::assertSame([], array_values(array_diff($files, array_keys(self::sums()))));
    }
}
