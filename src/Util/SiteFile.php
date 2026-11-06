<?php

declare(strict_types=1);

namespace AA\Util;

/**
 * A file of the site that a template prints ({include:<name>:readfile|fileman}). Read only when it lies,
 * links resolved, below the directory the name is relative to or below another directory of the site's
 * files (the upload directories); never from the AA tree, which keeps the configuration and the keys
 * and often lies in the document root itself, and never a file the server runs, a dotfile or a file of
 * settings or keys - whatever directory it is in. A refused read gives '' and a log row (EVENT), so a
 * site that loses a part of its page can be found on State Check.
 */
final class SiteFile
{
    /** log.type of a refused read - selector "<purpose>:<name>", params the reason and the slice */
    public const EVENT = 'FILE_DENY';

    /** the largest file printed - a page part, not a download */
    public const MAX_BYTES = 16 * 1024 * 1024;

    /** settings, keys and data dumps a server or a tool keeps next to the site - the scripts are Files::isExecutableName() */
    private const SECRET_EXTENSIONS = [
        'ini', 'env', 'key', 'pem', 'crt', 'cer', 'der', 'p12', 'pfx', 'jks', 'keystore', 'asc', 'gpg', 'kdbx',
        'conf', 'cnf', 'cfg', 'inc', 'neon', 'yml', 'yaml', 'sql', 'sqlite', 'db', 'dump', 'log',
        'bak', 'old', 'orig', 'save', 'swp', 'tmp', 'htaccess', 'htpasswd', 'passwd', 'sh', 'py', 'pl', 'rb',
    ];

    /**
     * The contents of $name, '' when it is missing or refused (refusals are logged)
     * @param string       $base    the directory $name is relative to - the document root, the File Manager directory
     * @param list<string> $roots   other directories of the site's files the name may lead to through a link
     * @param string       $purpose the kind of read, for the log: readfile, fileman, ...
     * @param string       $where   what else identifies the place, for the log - a slice id
     */
    public static function read(string $base, string $name, array $roots, string $purpose, string $where = ''): string
    {
        try {
            return self::contents($base, $name, $roots, defined('AA_BASE_PATH') ? (string) constant('AA_BASE_PATH') : '');
        } catch (\DomainException $e) {
            LogOnce::daily(self::EVENT, "$purpose:$name", [$e->getMessage(), $where]);
        } catch (\RuntimeException) {
            // a missing or unreadable file printed nothing before either
        }
        return '';
    }

    /**
     * @param list<string> $roots
     * @param string       $aaTree the directory AA is installed in; nothing below it is read
     * @throws \DomainException  the file is refused - the message says why
     * @throws \RuntimeException the file is missing, cannot be read or is too large
     */
    public static function contents(string $base, string $name, array $roots, string $aaTree, int $maxBytes = self::MAX_BYTES): string
    {
        if (trim($base) === '') {
            // a name relative to nothing would be relative to the root of the disk
            throw new \DomainException('no base directory');
        }
        if (str_contains($name, "\0")) {
            throw new \DomainException('a NUL byte in the name');
        }
        $segments = array_values(array_filter(explode('/', $name), static fn(string $segment): bool => $segment !== ''));
        if (!$segments) {
            throw new \RuntimeException('no file named');
        }
        // also "." and "..": a name never leaves its directory, nor names a dotfile (.htaccess, .git, .env)
        if (self::hasDotSegment($segments)) {
            throw new \DomainException('a name starting with a dot');
        }
        if (self::isSecretName(end($segments))) {
            throw new \DomainException('a script, settings or key file');
        }
        $path = rtrim($base, '/') . '/' . implode('/', $segments);
        $real = self::resolved($path);
        if ($real === false) {
            throw new \RuntimeException('not found');
        }
        $root = self::rootOf($real, [$base, ...$roots]) ?? throw new \DomainException('outside the files of the site');
        if (($aaTree !== '') && (self::rootOf($real, [$aaTree]) !== null)) {
            throw new \DomainException('in the AA tree');
        }
        // a link inside the site may name a dotfile or a key under another name
        if (self::hasDotSegment(explode('/', substr($real, strlen($root)))) || self::isSecretName(basename($real))) {
            throw new \DomainException('a link to a dotfile, script, settings or key file');
        }

        try {
            $fh = SafeFile::open($real);
        } catch (\UnexpectedValueException) {
            throw new \DomainException('not a regular file');
        }
        try {
            $opened = fstat($fh);
            $stat   = @stat($real);
            // the path checked above must still lead to the file opened - a link swapped in between leads elsewhere
            if (($opened === false) || ($stat === false) || ($stat['dev'] !== $opened['dev']) || ($stat['ino'] !== $opened['ino']) || (self::resolved($path) !== $real)) {
                throw new \DomainException('changed while it was read');
            }
            if ($opened['size'] > $maxBytes) {
                throw new \RuntimeException("larger than $maxBytes bytes");
            }
            return (string) stream_get_contents($fh, $maxBytes);
        } finally {
            fclose($fh);
        }
    }

    /**
     * The directories of the install the sites' files are uploaded to: the slices' upload directory and
     * the File Manager's - a link of the document root may lead there (another volume)
     * @return list<string>
     */
    public static function uploadRoots(): array
    {
        $roots = [];
        foreach (['IMG_UPLOAD_PATH', 'FILEMAN_BASE_DIR'] as $name) {
            if (defined($name) && (trim((string) constant($name)) !== '')) {
                $roots[] = (string) constant($name);
            }
        }
        return $roots;
    }

    /** a file of settings or keys, a dump, or one the server runs ("x.php.bak" too) */
    public static function isSecretName(string $name): bool
    {
        $extensions = array_map('trim', array_slice(explode('.', strtolower($name)), 1));
        return \Files::isExecutableName($name) || (bool) array_intersect($extensions, self::SECRET_EXTENSIONS);
    }

    /** @param list<string> $segments */
    private static function hasDotSegment(array $segments): bool
    {
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '.')) {
                return true;
            }
        }
        return false;
    }

    /**
     * The first of $dirs, resolved and ending with a slash, that $real lies below; null when none.
     * The directories come from the configuration and may be links themselves (an upload directory on
     * another volume), so they are resolved.
     * @param list<string> $dirs
     */
    private static function rootOf(string $real, array $dirs): ?string
    {
        foreach ($dirs as $dir) {
            if ((trim($dir) === '') || (($resolved = self::resolved($dir)) === false)) {
                continue;
            }
            $resolved = rtrim($resolved, '/') . '/';
            if (str_starts_with($real, $resolved)) {
                return $resolved;
            }
        }
        return null;
    }

    private static function resolved(string $path): string|false
    {
        // realpath() answers from a cache of resolved paths kept for 120 s
        clearstatcache(true);
        return @realpath($path);
    }
}
