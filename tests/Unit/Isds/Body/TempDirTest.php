<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Body;

use AA\Isds\Body\TempDir;
use PHPUnit\Framework\TestCase;

final class TempDirTest extends TestCase
{
    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->made) as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    private function dir(): string
    {
        $dir = sys_get_temp_dir() . '/aa-tempdir-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700);
        $this->made[] = $dir;
        return $dir;
    }

    /**
     * every warning, silenced too, that $run raises
     * @return list<string>
     */
    private static function warnings(callable $run): array
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $text) use (&$warnings): bool {
            $warnings[] = $text;
            return true;
        });
        try {
            $run();
        } finally {
            restore_error_handler();
        }
        return $warnings;
    }

    public function test_remove_filesAndTheDirectory(): void
    {
        $dir = $this->dir();
        file_put_contents("$dir/in", 'a');
        file_put_contents("$dir/installed-fonts.json", '{}');

        self::assertSame([], self::warnings(static fn() => TempDir::remove($dir)));
        self::assertDirectoryDoesNotExist($dir);
    }

    // the call at shutdown after finally removed it already
    public function test_remove_goneDirectoryNoWarning(): void
    {
        $dir = $this->dir();
        TempDir::remove($dir);

        self::assertSame([], self::warnings(static fn() => TempDir::remove($dir)));
    }

    public function test_remove_linkNotFollowed(): void
    {
        $target = $this->dir();
        file_put_contents("$target/kept", 'a');
        $this->made[] = "$target/kept";
        $link         = sys_get_temp_dir() . '/aa-tempdir-link-' . bin2hex(random_bytes(6));
        symlink($target, $link);
        $this->made[] = $link;

        TempDir::remove($link);

        self::assertFileExists("$target/kept");
    }
}
