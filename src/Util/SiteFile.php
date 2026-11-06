<?php

declare(strict_types=1);

namespace AA\Util;

use AA\Upload\UploadDir;

/**
 * A file of the site that a template prints ({include:<name>:readfile|fileman|site}) or a mail attaches.
 * A template reads it only when it lies, links resolved, below the directory the name is relative to or
 * below another directory of the site's files (the upload directories). Never from an AA tree - the
 * running one or another on the same disk (staging, the copy an update leaves) - which keeps the
 * configuration and the keys and often lies in the document root itself, and never a file the server
 * runs, a dotfile or a file of settings or keys, whatever directory it is in. A mail reads any upload
 * (readAttachment()). A refused read gives '' and a log row (EVENT), so a site that loses a part of its
 * page can be found on State Check.
 */
final class SiteFile
{
    /** log.type of a refused read - selector "<purpose>:<name>", params the reason (a REASON_* code) and the slice */
    public const EVENT = 'FILE_DENY';

    /** log.type of a mail file read from outside the upload directories - still sent, selector "mail:<its directory>" */
    public const EVENT_OUTER = 'FILE_OUTER';

    /** why a read was refused, as logged */
    public const REASON_OUTSIDE = 'outside';
    public const REASON_AA_TREE = 'aa';
    public const REASON_DOT     = 'dot';
    public const REASON_SECRET  = 'secret';
    public const REASON_TYPE    = 'type';
    public const REASON_CHANGED = 'changed';

    /** the largest file printed - a page part, not a download */
    public const MAX_BYTES = 16 * 1024 * 1024;

    /** the largest local file a mail carries, as for a web address */
    public const ATTACHMENT_MAX_BYTES = 25 * 1024 * 1024;

    /** settings, keys, dumps and backups a server or a tool keeps next to the site - the scripts are Files::isExecutableName() */
    private const SECRET_EXTENSIONS = [
        'ini', 'env', 'key', 'pem', 'crt', 'cer', 'der', 'p12', 'pfx', 'jks', 'keystore', 'asc', 'gpg', 'kdbx',
        'conf', 'cnf', 'cfg', 'inc', 'neon', 'yml', 'yaml', 'sql', 'sqlite', 'db', 'dump', 'log',
        'bak', 'old', 'orig', 'save', 'swp', 'tmp', 'htaccess', 'htpasswd', 'passwd', 'sh', 'py', 'pl', 'rb',
    ];

    /** a file every AA tree has - its directory is the root of a tree */
    private const AA_TREE_MARK = 'include/locsess.php';

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
            return self::contents($base, $name, $roots, self::aaTree());
        } catch (\DomainException $e) {
            LogOnce::daily(self::EVENT, "$purpose:$name", [$e->getMessage(), $where]);
        } catch (\RuntimeException) {
            // a missing file, or no directory to read from (cron has no document root), is no refusal
        }
        return '';
    }

    /**
     * Only an existing file is refused, so a template asking for invented names writes no log rows.
     * @param list<string> $roots
     * @param string       $aaTree the directory AA is installed in; nothing below it is read
     * @throws \DomainException  the file is refused - the message is a REASON_* code
     * @throws \RuntimeException the file is missing, cannot be read or is too large, or there is no $base
     */
    public static function contents(string $base, string $name, array $roots, string $aaTree, int $maxBytes = self::MAX_BYTES): string
    {
        if (trim($base) === '') {
            // a name relative to nothing would be relative to the root of the disk
            throw new \RuntimeException('no base directory');
        }
        // "." changes nothing; ".." would leave the directory
        $segments = array_values(array_filter(explode('/', $name), static fn(string $segment): bool => ($segment !== '') && ($segment !== '.')));
        if (!$segments) {
            throw new \RuntimeException('no file named');
        }
        $path = rtrim($base, '/') . '/' . implode('/', $segments);
        $real = self::resolved($path);
        if ($real === false) {
            throw new \RuntimeException('not found');
        }
        // the name itself: no "..", no dotfile (.htaccess, .git, .env); the resolved path below: no link around it
        if (self::hasDotSegment($segments)) {
            throw new \DomainException(self::REASON_DOT);
        }
        if (self::isSecretName(end($segments))) {
            throw new \DomainException(self::REASON_SECRET);
        }
        $root = self::rootOf($real, [$base, ...$roots]) ?? throw new \DomainException(self::REASON_OUTSIDE);
        if (self::inAaTree($real, $aaTree, $roots)) {
            throw new \DomainException(self::REASON_AA_TREE);
        }
        if (self::hasDotSegment(explode('/', substr($real, strlen($root))))) {
            throw new \DomainException(self::REASON_DOT);
        }
        if (self::isSecretName(basename($real))) {
            throw new \DomainException(self::REASON_SECRET);
        }
        return self::readResolved($path, $real, $maxBytes);
    }

    /**
     * A local file a mail attaches or embeds, '' when it is missing or refused (logged). Never from an AA
     * tree, never a script. From an upload directory - a slice's or the File Manager's - any other file
     * but a dotfile, as attachments are pdf, doc, xlsx... A file elsewhere is refused also as a dotfile,
     * a settings or key file or a file without an extension (an attachment has one, /etc/passwd or a key
     * does not); any other is still sent and logged (EVENT_OUTER) until the mails that use one are known.
     * @param list<string> $bases  the upload directories (uploadRoots())
     * @param ?string      $aaTree the directory AA is installed in - for tests
     */
    public static function readAttachment(string $path, array $bases, ?string $aaTree = null): string
    {
        $aaTree ??= self::aaTree();
        $real = self::resolved($path);
        if ($real === false) {
            return '';
        }
        // also an upload directory set to the document root, which holds the AA tree
        $inTree = self::inAaTree($real, $aaTree, $bases);
        if ($inTree || \Files::isExecutableName($real)) {
            LogOnce::daily(self::EVENT, "mail:$path", [$inTree ? self::REASON_AA_TREE : self::REASON_SECRET, '']);
            return '';
        }
        $dir = self::attachmentDir($real, $bases);
        if ($dir !== null) {
            try {
                return $dir->readPath($real, self::ATTACHMENT_MAX_BYTES);
            } catch (\InvalidArgumentException|\RuntimeException) {
                // a dotfile, a file grown too large or changed, a slice directory that is a link: the rules for other files decide
            }
        }
        try {
            if (!str_contains(ltrim(basename($real), '.'), '.')) {
                throw new \DomainException(self::REASON_TYPE);
            }
            $content = self::contents('/', $real, $bases, $aaTree, self::ATTACHMENT_MAX_BYTES);
        } catch (\DomainException $e) {
            LogOnce::daily(self::EVENT, "mail:$path", [$e->getMessage(), '']);
            return '';
        } catch (\RuntimeException) {
            return '';
        }
        // by the directory: what the mails need is where they keep their files
        LogOnce::daily(self::EVENT_OUTER, 'mail:' . dirname($real));
        return $content;
    }

    /**
     * The upload directory of a slice (or a File Manager directory) the resolved path $real lies in, null
     * when none. The bases are resolved too: they may be links (another volume).
     * @param list<string> $bases
     */
    public static function attachmentDir(string $real, array $bases): ?UploadDir
    {
        return UploadDir::forPath($real, array_values(array_filter(array_map(static fn(string $base): string|false => (trim($base) === '') ? false : self::resolved($base), $bases))));
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

    /**
     * A file of settings or keys, a dump or backup ("config.ini~" too), or one the server runs - a script
     * extension anywhere in the name counts ("x.php.bak", "config.php-old"), as mod_mime runs "x.php.jpg"
     */
    public static function isSecretName(string $name): bool
    {
        $dot = strpos($name, '.');
        if ($dot === false) {
            return false;
        }
        $extensions = preg_split('~[^a-z0-9]+~', strtolower(substr($name, $dot + 1)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (array_intersect($extensions, \Files::EXECUTABLE_EXTENSIONS)) {
            return true;
        }
        return in_array(end($extensions), self::SECRET_EXTENSIONS, true);
    }

    /**
     * The bytes of $real, which $path resolved to - read through one handle, which must still be the file
     * at that path (a link swapped in between leads elsewhere)
     * @throws \DomainException  not a regular file, or changed under the read
     * @throws \RuntimeException cannot be read, or larger than $maxBytes
     */
    private static function readResolved(string $path, string $real, int $maxBytes): string
    {
        try {
            $fh = SafeFile::open($real);
        } catch (\UnexpectedValueException) {
            throw new \DomainException(self::REASON_TYPE);
        }
        try {
            $opened = fstat($fh);
            $stat   = @stat($real);
            if (($opened === false) || ($stat === false) || ($stat['dev'] !== $opened['dev']) || ($stat['ino'] !== $opened['ino']) || (self::resolved($path) !== $real)) {
                throw new \DomainException(self::REASON_CHANGED);
            }
            if ($opened['size'] > $maxBytes) {
                throw new \RuntimeException("larger than $maxBytes bytes");
            }
            // asked for by the size, not the limit: PHP 8.2 allocates the whole length asked for
            $content = (string) stream_get_contents($fh, $opened['size'] + 1);
            if (strlen($content) !== $opened['size']) {
                throw new \DomainException(self::REASON_CHANGED);
            }
            return $content;
        } finally {
            fclose($fh);
        }
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
     * $real lies in the running AA tree, or in any other (a directory above it holds the file every tree
     * has) - unless in one of $uploads that an install keeps inside its tree, away from its settings
     * @param list<string> $uploads the upload directories
     */
    private static function inAaTree(string $real, string $aaTree, array $uploads = []): bool
    {
        $tree = ($aaTree !== '') ? self::rootOf($real, [$aaTree]) : null;
        for ($dir = dirname($real); ($tree === null) && !in_array($dir, ['/', '.', ''], true); $dir = dirname($dir)) {
            if (@is_file("$dir/" . self::AA_TREE_MARK)) {
                $tree = "$dir/";
            }
        }
        if ($tree === null) {
            return false;
        }
        foreach ($uploads as $upload) {
            $upload = self::rootOf($real, [$upload]);
            if (($upload !== null) && ($upload !== $tree) && str_starts_with($upload, $tree) && !str_starts_with($upload, "{$tree}include/") && !str_starts_with($upload, "{$tree}config/")) {
                return false;
            }
        }
        return true;
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

    /** where AA is installed - this file is in its src/Util */
    private static function aaTree(): string
    {
        return dirname(__DIR__, 2);
    }

    /** $path with every link resolved, false when it cannot be (also a NUL byte, which realpath() throws on) */
    private static function resolved(string $path): string|false
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        // realpath() answers from a cache of resolved paths kept for 120 s
        clearstatcache(true);
        return @realpath($path);
    }
}
