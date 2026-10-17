<?php

declare(strict_types=1);

namespace AA\Util;

/**
 * Files in a directory other users of the server may write into, such as the upload directory: a file
 * is read only when it is a regular one, and content goes only into a file the call itself created.
 * The rename that puts it in place acts by name, so another user may put something else there
 * afterwards - check what lies at a path by its content. PHP has no fchmod(), so the mode of a new
 * file is the one the umask gives it.
 */
final class SafeFile
{
    /** stat() mode: the type bits, and the type of a regular file */
    private const S_IFMT  = 0o170000;
    private const S_IFREG = 0o100000;

    /** seconds after which a temporary file was left by a request that died */
    private const STRAY = 3600;

    /**
     * $path opened for reading when it is a regular file of at most $maxBytes at the open - a FIFO put
     * in its place would block the open, a device would never end. It may grow later: read() reads at
     * most $maxBytes.
     * @return resource
     * @throws \RuntimeException
     */
    public static function open(string $path, int $maxBytes)
    {
        error_clear_last();
        // 'n': a FIFO without a writer returns at once instead of blocking the open
        $file = @fopen($path, 'rbn');
        if ($file === false) {
            throw new \RuntimeException("Cannot read $path" . self::lastError());
        }
        $stat = fstat($file);
        if (($stat === false) || (($stat['mode'] & self::S_IFMT) !== self::S_IFREG) || ($stat['size'] > $maxBytes)) {
            fclose($file);
            throw new \RuntimeException("$path is not a regular file of at most $maxBytes bytes");
        }
        stream_set_blocking($file, true);
        return $file;
    }

    /**
     * The whole of $path, read once through one handle: it returns at most $maxBytes, reading at most a
     * chunk more to tell a longer file
     * @throws \RuntimeException
     */
    public static function read(string $path, int $maxBytes): string
    {
        $file = self::open($path, $maxBytes);
        try {
            stream_set_chunk_size($file, 1 << 20);  // a read of 8 KB is a network round trip on GlusterFS
            $bytes = stream_get_contents($file, $maxBytes + 1);
        } finally {
            fclose($file);
        }
        // it may have grown since fstat()
        if (($bytes === false) || (strlen($bytes) > $maxBytes)) {
            throw new \RuntimeException("Cannot read $path whole, at most $maxBytes bytes");
        }
        return $bytes;
    }

    /**
     * Puts what $content() returns at $path: written into a new file in the same directory, then renamed
     * to $path, so no reader sees a part of it. What lies at $path later may still be another user's -
     * see the class. The new file is made before $content() runs, so a directory PHP cannot write into
     * fails before the work.
     * @param \Closure(): string $content
     * @throws \RuntimeException also what $content() throws - nothing is replaced then
     */
    public static function replace(string $path, \Closure $content): void
    {
        $dir    = dirname($path);
        $prefix = '.' . basename($path) . '.';
        self::removeStrays($dir, $prefix);
        try {
            $temp = $dir . '/' . $prefix . bin2hex(random_bytes(8));
        } catch (\Random\RandomException $e) {
            throw new \RuntimeException("No random name for a file in $dir: " . $e->getMessage(), 0, $e);
        }
        // 'x' (O_CREAT|O_EXCL) makes a new regular file or refuses a planted file or link; tempnam()
        // would fall back to the system temp directory, from where the rename is no longer atomic
        error_clear_last();
        $file = @fopen($temp, 'x');
        if ($file === false) {
            throw new \RuntimeException("Cannot create a file in $dir - it is missing or not writable" . self::lastError());
        }
        $renamed = false;
        try {
            $bytes = $content();
            error_clear_last();
            $written = (@fwrite($file, $bytes) === strlen($bytes)) && @fflush($file) && @fsync($file);
            fclose($file);
            $file = null;
            if (!$written || !@rename($temp, $path)) {
                throw new \RuntimeException("Cannot replace $path" . self::lastError());
            }
            $renamed = true;
        } finally {
            if ($file !== null) {
                fclose($file);
            }
            if (!$renamed) {
                @unlink($temp);
            }
        }
    }

    /** temporary files of requests that died while writing */
    private static function removeStrays(string $dir, string $prefix): void
    {
        $own = '/\A' . preg_quote($prefix, '/') . '[0-9a-f]{16}\z/';
        foreach (glob($dir . '/' . $prefix . '*', GLOB_NOSORT) ?: [] as $stray) {
            $mtime = @filemtime($stray);
            if ((preg_match($own, basename($stray)) === 1) && ($mtime !== false) && ((time() - $mtime) > self::STRAY)) {
                @unlink($stray);
            }
        }
    }

    private static function lastError(): string
    {
        $error = error_get_last()['message'] ?? '';
        return ($error === '') ? '' : " - $error";
    }
}
