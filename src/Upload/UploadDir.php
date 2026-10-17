<?php

declare(strict_types=1);

namespace AA\Upload;

use AA\Util\SafeFile;

/**
 * The slice directory - where a slice keeps its uploaded files - read by the URLs AA stores for them:
 * the one way to turn such a URL back into the bytes on the disk. Nothing is ever fetched from the
 * web: a URL must name a file below the directory's URL, on its host and port, and the opened file
 * must lie below the slice directory, links included - as the kernel's own path of the handle
 * (/proc/self/fd) says; where PHP cannot list /proc/self/fd, nothing is read. The slice directory
 * itself must be no link: it is an entry of the upload directory (IMG_UPLOAD_PATH), which other users
 * of the server may write. That parent is trusted and may be a link, to another volume say; for a
 * File Manager slice (FILEMAN_BASE_DIR/<fileman_dir>/items) it is <fileman_dir>, itself an entry of
 * FILEMAN_BASE_DIR - trusted only where no other user writes there. Exception messages quote the
 * URL - escape them when shown.
 */
final readonly class UploadDir
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /** the directory's URL, for messages */
    private string $url;
    private string $host;
    private ?int $port;
    /** the URL path of the directory, ending with a slash */
    private string $path;
    private string $dir;

    /**
     * @param string $url the URL the directory is published at - http(s) with a host
     * @param string $dir the directory on the disk
     * @throws \InvalidArgumentException
     */
    public function __construct(string $url, string $dir)
    {
        $parts  = parse_url(trim($url));
        $scheme = strtolower(is_array($parts) ? ($parts['scheme'] ?? '') : '');
        if (!is_array($parts) || !isset(self::DEFAULT_PORTS[$scheme]) || (($parts['host'] ?? '') === '') || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException("The upload URL \"$url\" is not like https://example.org/upload");
        }
        $this->url  = rtrim(trim($url), '/');
        $this->host = self::host($parts['host']);
        $this->port = self::port($scheme, $parts['port'] ?? null);
        $this->path = rtrim($parts['path'] ?? '', '/') . '/';
        $this->dir  = rtrim(trim($dir), '/');
        if ($this->dir === '') {
            throw new \InvalidArgumentException('The upload directory is not set');
        }
        // the check resolves the parent and keeps the last name as written: "." or ".." would refuse every file
        if (in_array(basename($this->dir), ['.', '..'], true)) {
            throw new \InvalidArgumentException('The upload directory must not end with "." or ".."');
        }
    }

    /**
     * Where the file fields and the rich-text editor of $slice store files
     * @throws \InvalidArgumentException the upload URL or directory is not set right
     */
    public static function forSlice(\AA_Slice $slice): self
    {
        return self::forUploadBase($slice->getUploadBase());
    }

    /**
     * @param array<string, mixed> $base what AA_Slice::getUploadBase() gives
     * @throws \InvalidArgumentException the upload URL or directory is not set right
     */
    public static function forUploadBase(array $base): self
    {
        try {
            return new self((string) ($base['url'] ?? ''), (string) ($base['path'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            // read by the editor of a slice, who cannot fix it: say where it is set
            throw new \InvalidArgumentException($e->getMessage() . ' - set IMG_UPLOAD_URL (an absolute http(s) URL) and IMG_UPLOAD_PATH in include/config.php, or the upload URL in the settings of the slice', 0, $e);
        }
    }

    /** $url names a file below the directory - the disk is not looked at */
    public function contains(string $url): bool
    {
        return $this->relative($url) !== null;
    }

    /**
     * The name of the file $url names, decoded
     * @throws \InvalidArgumentException not a URL of this directory
     */
    public function name(string $url): string
    {
        $relative = $this->relative($url) ?? throw new \InvalidArgumentException("$url is not a file uploaded to $this->url");
        return substr((string) strrchr("/$relative", '/'), 1);
    }

    /**
     * The bytes of the file $url names. Opened first, then checked where the opened file lies: by
     * the kernel's own path of the handle (/proc/self/fd), which a link swapped in at any moment
     * cannot fake. Without /proc/self/fd (no /proc, or open_basedir without it - see kernelPaths())
     * nothing is read: a path resolved again may lead elsewhere than the open did. A hard link to a
     * file elsewhere counts as a file of the directory.
     * @throws \InvalidArgumentException not a file of this directory, or larger than $maxBytes
     * @throws \RuntimeException the file does not exist or cannot be read, the slice directory is a
     *                           link, or PHP cannot list /proc/self/fd
     */
    public function read(string $url, int $maxBytes): string
    {
        $relative = $this->relative($url) ?? throw new \InvalidArgumentException("$url is not a file uploaded to $this->url");
        if (!self::kernelPaths()) {
            throw new \RuntimeException('AA cannot check attachment files on this server. Ask the server administrator to add /proc/self/fd to open_basedir.');
        }
        $path = "$this->dir/$relative";
        // realpath() answers from a per-process cache of resolved paths (120 s), lstat() from its last
        // call: only the parent is resolved, and clearing its entry drops the last lstat too, which
        // isFile() relies on
        clearstatcache(true, dirname($this->dir));
        try {
            $fh = SafeFile::open($path);
        } catch (\RuntimeException) {
            // also a FIFO, a device or a directory; a message of our own, SafeFile's name disk paths
            throw new \RuntimeException("The file $url does not exist or cannot be read");
        }
        try {
            $opened = fstat($fh) ?: throw new \RuntimeException("The file $url cannot be read");
            $root   = $this->root() ?? throw new \RuntimeException("The file $url cannot be read");
            // listed when probed, not now (open_basedir tightened since): refused, not left unchecked
            $real   = self::openedPath($opened) ?? false;
            if (($real === false) || !str_starts_with($real, $root) || !self::isFile($real, $opened)) {
                // deleted since it was opened: no path is left, /proc names it "... (deleted)"
                if ((fstat($fh)['nlink'] ?? 1) === 0) {
                    throw new \RuntimeException("The file $url cannot be read");
                }
                // a slice directory moved away behind a link refuses every file: say why, for the administrator
                if (is_link($this->dir)) {
                    throw new \RuntimeException('The upload directory of this slice is a link; AA reads attachments only from a real directory. Ask the administrator to replace the link with the directory.');
                }
                throw new \InvalidArgumentException("$url is not a file uploaded to $this->url");
            }
            if ($opened['size'] > $maxBytes) {
                throw new \InvalidArgumentException("The file $url is larger than $maxBytes bytes");
            }
            // one byte more than fstat() said shows a file that has grown since
            $content = stream_get_contents($fh, $opened['size'] + 1);
            if (!is_string($content) || (strlen($content) !== $opened['size'])) {
                throw new \RuntimeException("The file $url changed while it was read");
            }
            return $content;
        } finally {
            fclose($fh);
        }
    }

    /**
     * $url as a path below the directory, null when it is not one. A URL without a host - an editor
     * may shorten one so - can only mean this server.
     */
    private function relative(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (isset($parts['host'])) {
            if (!in_array($scheme, ['', 'http', 'https'], true) || (self::host($parts['host']) !== $this->host) || (self::port($scheme, $parts['port'] ?? null) !== $this->port)) {
                return null;
            }
        } elseif ($scheme !== '') {
            return null;
        }
        $path = $parts['path'] ?? '';
        if (!str_starts_with($path, $this->path)) {
            return null;
        }
        $relative = rawurldecode(substr($path, strlen($this->path)));
        if (str_contains($relative, "\0") || (array_intersect(explode('/', $relative), ['', '.', '..']) !== [])) {
            return null;
        }
        return $relative;
    }

    /**
     * PHP may list /proc/self/fd here: only the kernel's own path of a handle tells read() where an
     * opened file lies, so it reads nothing otherwise. open_basedir hides it unless it names
     * /proc/self/fd. Decided once per request.
     */
    public static function kernelPaths(): bool
    {
        static $listed = null;
        return $listed ??= (@scandir('/proc/self/fd') !== false);
    }

    /**
     * Where the files of the slice directory lie, with a slash at the end; null when the upload
     * directory cannot be resolved. Only that parent is resolved: a link put in place of the slice
     * directory would carry the check anywhere.
     */
    private function root(): ?string
    {
        $real = @realpath(dirname($this->dir));
        return ($real === false) ? null : rtrim($real, '/') . '/' . basename($this->dir) . '/';
    }

    /**
     * The kernel's path of the open file fstat() said $opened of
     * @param array<int|string, int> $opened
     * @param string $fds /proc/self/fd, another directory only in tests
     * @return string|false|null false when the handle is not found there or its path cannot be read
     *                           (open_basedir refuses a path outside it), null when $fds cannot be listed
     */
    private static function openedPath(array $opened, string $fds = '/proc/self/fd'): string|false|null
    {
        $list = @scandir($fds);
        if ($list === false) {
            return null;
        }
        foreach ($list as $fd) {
            if (self::isFile("$fds/$fd", $opened, false)) {
                return @readlink("$fds/$fd");
            }
        }
        return false;
    }

    /**
     * $path is the file fstat() said $opened of
     * @param array<int|string, int> $opened
     * @param bool $link lstat(): a resolved path, so a link appearing on it since does not match
     */
    private static function isFile(string $path, array $opened, bool $link = true): bool
    {
        $stat = $link ? @lstat($path) : @stat($path);
        return is_array($stat) && ($stat['dev'] === $opened['dev']) && ($stat['ino'] === $opened['ino']);
    }

    /** a host as DNS compares it: in any case, with or without the final dot of the root */
    private static function host(string $host): string
    {
        $host = strtolower($host);
        return str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
    }

    /** null for the default port of the scheme, so https://h/ and https://h:443/ are one place */
    private static function port(string $scheme, ?int $port): ?int
    {
        return (($port === null) || ($port === (self::DEFAULT_PORTS[$scheme] ?? null))) ? null : $port;
    }
}
