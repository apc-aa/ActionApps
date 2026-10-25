<?php

declare(strict_types=1);

namespace AA\Isds\Body;

/** the temporary directory of one letter: its images, the fonts and images of dompdf */
final class TempDir
{
    /**
     * $dir and the files left in it, never what a link leads to. Called from finally and again at shutdown,
     * as a fatal error (memory, time) skips finally.
     */
    public static function remove(string $dir): void
    {
        clearstatcache(true, $dir);
        // gone already at shutdown after a normal run; even a silenced warning would reach an error handler
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if (($name !== '.') && ($name !== '..')) {
                @unlink("$dir/$name");
            }
        }
        @rmdir($dir);
    }
}
