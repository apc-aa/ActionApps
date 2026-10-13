<?php

declare(strict_types=1);

namespace AA\Optimize;

/** A cached site page varies only on the cookies its render read through
 *  \AA\Cache\PageCache::cookie(). Installation PHP that reads cookies directly makes the
 *  first visitor's variant the page of everyone - AA's own code is held to the rule by
 *  tests/Unit/Cache/PageCacheVaryTest, these files are invisible to it.
 */
class CheckCookieReads extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Cookies read past the page cache');
    }

    public static function description(): string
    {
        return _m('Lists installation PHP files (include/usr_aliasfnc.php, include/custom/, modules/site/sites/) that read cookies directly - cached site pages do not vary on such a cookie.');
    }

    public static function types(): array
    {
        return [self::CHECK_EXTENDED];   // reads and tokenizes files
    }

    public static function category(): string
    {
        return 'errors';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        return self::findingsFor(defined('AA_BASE_PATH') ? (string) constant('AA_BASE_PATH') : '');
    }

    /** @param string $base the AA base directory, with the trailing slash
     *  @return Finding[] */
    public static function findingsFor(string $base): array
    {
        $findings = [];
        foreach (self::installationFiles($base) as $file) {
            if ($lines = self::cookieReads((string) file_get_contents($base . $file))) {
                $findings[] = new Finding(
                    Finding::WARNING,
                    _m('%1 reads cookies directly', [$file]),
                    count: count($lines),
                    evidence: [[_m('File') => safe($file), _m('Lines') => safe(implode(', ', $lines))]],
                    advice: _m('A cached site page does not vary on such a cookie - the variant of the first visitor is served to everyone. Read the cookie with \AA\Cache\PageCache::cookie(\'name\'), in templates {cookie:name}; the page is then cached once per value of that cookie.'),
                    key: $file,
                );
            }
        }
        return $findings ?: [new Finding(Finding::OK, _m('No installation file reads cookies directly'))];
    }

    /** include/usr_aliasfnc.php(3), the PHP under include/custom/ and modules/site/sites/, relative to $base
     *  @return list<string> */
    public static function installationFiles(string $base): array
    {
        $files = [];
        foreach (['include/usr_aliasfnc.php', 'include/usr_aliasfnc.php3'] as $file) {
            if (is_readable($base . $file)) {
                $files[] = $file;
            }
        }
        foreach (['include/custom', 'modules/site/sites'] as $dir) {
            if (!is_dir($base . $dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $path) {
                if (in_array($path->getExtension(), ['php', 'php3'], true) && $path->isReadable()) {
                    $files[] = substr($path->getPathname(), strlen($base));
                }
            }
        }
        sort($files);
        return $files;
    }

    /** Lines of $code that get at cookies without PageCache::cookie(): $_COOKIE, $GLOBALS['_COOKIE'],
     *  the raw header ($_SERVER['HTTP_COOKIE'], getallheaders(), apache_request_headers()), INPUT_COOKIE
     *  @return list<int> */
    public static function cookieReads(string $code): array
    {
        $lines = [];
        foreach (token_get_all($code) as $token) {
            if (!is_array($token)) {
                continue;
            }
            [$id, $text, $line] = $token;
            if (($id === T_VARIABLE && $text === '$_COOKIE')
                || ($id === T_CONSTANT_ENCAPSED_STRING && preg_match('/HTTP_COOKIE|^.?_COOKIE.?$/', $text))
                // \getallheaders() is one T_NAME_FULLY_QUALIFIED token, "$_SERVER[HTTP_COOKIE]" has a T_STRING key
                || (($id === T_STRING || $id === T_NAME_FULLY_QUALIFIED)
                    && in_array(strtolower(ltrim($text, '\\')), ['input_cookie', 'getallheaders', 'apache_request_headers', 'http_cookie'], true))) {
                $lines[] = $line;
            }
        }
        return array_values(array_unique($lines));
    }
}
