<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Cache\ImgCache;

/** A resized image is served from IMG_UPLOAD/mC/ by the web server; a request for one which
 *  is not generated yet has to reach the dispatcher in modules/site/site.php. A catch-all
 *  rewrite with "!-f" (AA_Router_Seo) routes it there already, a rewrite of the language
 *  prefixes only answers 404 - so AA puts its own .htaccess into the cache root.
 *
 *  The page render checks none of this - it reads no file, see AA_Stringexpand_Img::useCache().
 *  service/sql_update.php and the task queue (ScheduleAaTasks) write the .htaccess when it is
 *  missing, and the State Check asks the web server whether a miss really gets through: the
 *  file is honoured only where AllowOverride permits it, and nginx ignores it.
 */
class MissCacheRouting extends AbstractOptimize
{
    /** the image img.php is asked to resize by findings(), put there by ensure() - in the cache root, which no purge touches */
    private const PROBE_IMAGE = 'aa-probe.gif';

    /** what may go into the RewriteRule target, "/" for an AA at the domain root - Apache parses
     *  the file, and a space or a newline there answers 500 for every image in the cache */
    private const INSTALL_PATH = '/(?:(?!\.\.?/)[A-Za-z0-9._~-]+/)*';

    public static function name(): string
    {
        return _m('Image cache routing');
    }

    public static function description(): string
    {
        return _m('Resized images are served as static files; a request for one which is not generated yet has to reach AA. Repair writes the .htaccess which routes it there, the extended State Check asks the web server whether such a request really gets through.');
    }

    public static function types(): array
    {
        return ['sql_update', self::CHECK_EXTENDED];
    }

    public static function category(): string
    {
        return 'cache';
    }

    /** Only whether the .htaccess is there - service/sql_update.php runs this for every
     *  class, so the HTTP probes are left to findings(). */
    public function test(): bool
    {
        if (!ImgCache::enabled()) {
            return true;
        }
        clearstatcache(true, self::ruleFile());
        if (!is_link(self::ruleFile()) && (@filesize(self::ruleFile()) > 0)) {
            return true;
        }
        $this->message(_m('%1 is missing', [safe(self::ruleFile())]));
        return false;
    }

    /** Writes the .htaccess when it is missing. Called by hand (State Check offers it once the
     *  probe failed) it also replaces a rule which is not this tree's - which ensure() never does. */
    public function repair(): bool
    {
        if (!ImgCache::enabled()) {
            return true;
        }
        if (($rule = self::thisRule()) === null) {
            $this->message(_m('AA_INSTAL_PATH %1 cannot go into a rewrite rule', [safe(constant('AA_INSTAL_PATH'))]));
            return false;
        }
        if (is_link(ImgCache::root())) {
            $this->message(_m('%1 is a symbolic link - AA does not write there', [safe(ImgCache::root())]));
            return false;
        }
        $file = self::ruleFile();
        $done = (is_link($file) || (is_file($file) && (@file_get_contents($file) !== $rule)))
            ? self::replace($file, $rule)
            : self::writeIfMissing(ImgCache::root(), $rule, (int) constant('IMG_UPLOAD_DIR_MODE'));
        if (!$done) {
            $this->message(_m('Cannot write %1', [safe($file)]));
        }
        self::placeProbeImage();
        return $done;
    }

    public function findings(): array
    {
        if (!ImgCache::enabled()) {
            return [new Finding(Finding::INFO, _m('The image cache is switched off or not configured - {img} uses img.php'))];
        }
        if (!function_exists('curl_init')) {
            return [new Finding(Finding::INFO, _m('The image cache routing cannot be checked without the PHP curl extension'))];
        }
        // img.php first: the web server answering the probes below looks the upload directory up, and
        // a filesystem caching lookups (GlusterFS entry-timeout) would then let PHP through as well
        $img_php = self::imgPhpFinding();

        $root         = ImgCache::root();
        $root_is_link = is_link($root);   // none of ours - see MissCache::purge()
        $file         = self::ruleFile();
        $is_link      = is_link($file);   // never read through one: it could point at config.php
        $rule         = (!$root_is_link && !$is_link && is_file($file)) ? (string) @file_get_contents($file) : '';
        $rule         = ($rule === '') ? null : $rule;   // an empty file routes nothing - test() and ensure() count it missing too
        // Repair renames over the file, which takes a writable directory whoever owns the rule -
        // or creates the cache root first, in the upload directory
        $can_repair = !$root_is_link && (is_dir($root) ? is_writable($root) : is_writable(constant('IMG_UPLOAD_PATH')));
        // a fresh name every time: a probe some dispatcher stored would be answered statically ever after
        $url             = ImgCache::get()->getCachedUrl('pT', 'aa-routing-probe/' . bin2hex(random_bytes(6)) . '.jpg?w=1');
        [$status, $type] = self::probe($url, true);
        $evidence        = [[
            _m('Probe')     => safe($url),
            _m('Answer')    => $status ? safe("$status $type") : _m('none'),
            _m('.htaccess') => $is_link ? _m('symbolic link') : (($rule === null) ? _m('missing') : '<pre>' . safe($rule) . '</pre>'),
        ]];
        $title = _m('A request for an image which is not generated yet does not reach AA');

        $findings = [match (self::verdict($status, $type, $rule !== null)) {
            'ok'          => new Finding(Finding::OK, _m('A request for an image which is not generated yet reaches AA'), key: 'routing'),
            'missing'     => new Finding(Finding::ERROR, $title, evidence: $evidence, advice: _m('Every image which is not generated yet shows broken. Repair writes the .htaccess into the cache directory (service/sql_update.php does the same); on nginx add the rule from config.php.default to the server configuration.'), repairable: $can_repair, key: 'routing'),
            'unreachable' => new Finding(Finding::ERROR, _m('The server cannot load its own image url'), evidence: $evidence, advice: _m('Check that the server resolves and reaches the host of IMG_UPLOAD_URL.'), key: 'routing'),
            'ignored'     => new Finding(Finding::ERROR, $title, evidence: $evidence, advice: _m('The .htaccess in the cache directory has no effect: AllowOverride does not allow it, the server runs nginx, or it routes to an AA tree which does not answer on this host - Repair then points it at this one. Otherwise add the rule from config.php.default to the server configuration.'), repairable: $can_repair && ($rule !== self::thisRule()), key: 'routing'),
        }];

        if ($img_php !== null) {
            $findings[] = $img_php;
        }
        if ($root_is_link) {
            $findings[] = new Finding(Finding::WARNING, _m('The image cache directory is a symbolic link'), evidence: [[_m('Directory') => safe($root)]], advice: _m('AA neither writes the routing rule nor purges old thumbnails there. If it is not your own layout, somebody with access to the upload directory placed it - remove it.'), key: 'root');
        }
        // write-if-missing leaves any rule alone, a hand edited or planted one too
        if ($is_link) {
            $findings[] = new Finding(Finding::WARNING, _m('The .htaccess in the image cache is a symbolic link'), advice: _m('AA never writes through it. Somebody with access to the upload directory placed it there; Repair replaces the link with the rule of this AA tree.'), repairable: $can_repair, key: 'rule');
        } elseif (($rule !== null) && !self::isAaRule($rule)) {
            $findings[] = new Finding(Finding::WARNING, _m('The .htaccess in the image cache is not the one AA writes'), evidence: $evidence, advice: _m('Somebody changed it, or it does not come from AA. Repair replaces it with the rule of this AA tree.'), repairable: $can_repair, key: 'rule');
        }
        return $findings;
    }

    /** A miss is generated by a request from the server to img.php, which follows no redirect, for a
     *  file under IMG_UPLOAD - so img.php is asked for the probe image ensure() keeps there. Whether
     *  that image is there, the web server tells - PHP's own look would fail just when PHP cannot read.
     *  @return ?Finding null when img.php makes the image */
    private static function imgPhpFinding(): ?Finding
    {
        $img_php                    = constant('AA_INSTAL_URL') . 'img.php?src=/' . ImgCache::srcBase()
 . ImgCache::SEGMENT . '/' . self::PROBE_IMAGE . '&w=1';
        [$status, $type, $location] = self::probe($img_php, false);
        if (self::isImage($status, $type)) {
            return null;
        }
        $probe_image                 = rtrim(constant('IMG_UPLOAD_URL'), '/') . '/' . ImgCache::SEGMENT . '/' . self::PROBE_IMAGE;
        [$image_status, $image_type] = self::probe($probe_image, false);
        $evidence                    = [[
            _m('Url')         => safe($img_php),
            _m('Answer')      => $status ? safe(trim("$status $type $location")) : _m('none'),
            _m('Probe image') => safe($probe_image) . ' - ' . ($image_status ? safe("$image_status $image_type") : _m('none')),
        ]];
        return match (self::imgPhpVerdict($status, $type, $location, constant('AA_INSTAL_URL') . 'images/blank.gif', self::isImage($image_status, $image_type))) {
            'ok'         => null,
            'unplaced'   => new Finding(Finding::INFO, _m('img.php runs; whether it can read the upload directory is checked once the task queue puts the probe image there'), evidence: $evidence, key: 'img.php'),
            'unreadable' => new Finding(Finding::ERROR, _m('img.php cannot read the upload directory'), evidence: $evidence, advice: _m('The web server serves the probe image, img.php does not get to read it - every image which is not in the cache yet shows blank. Check that PHP may read the upload directory (permissions, ACL, open_basedir) and resolves the same document root. A file of that name which is not a GIF answers so too: delete it, the task queue writes it again.'), key: 'img.php'),
            'broken'     => new Finding(Finding::ERROR, _m('img.php does not answer the server itself'), evidence: $evidence, advice: _m('Images which are not in the cache yet cannot be generated. No answer: check that the server resolves and reaches the host of AA_INSTAL_URL. A redirect: set AA_INSTAL_URL to the address it redirects to (https, www) - the generator does not follow redirects.'), key: 'img.php'),
        };
    }

    /** Writes the .htaccess unless there is one - never replaces it: an /aaa/ and an /aa-dev/
     *  tree on one server share the upload directory, and either one's dispatcher does.
     *  @return bool whether the routing file is in place */
    public static function ensure(): bool
    {
        if (!ImgCache::enabled()) {
            return true;
        }
        $rule   = self::thisRule();
        $routed = ($rule !== null) && self::writeIfMissing(ImgCache::root(), $rule, (int) constant('IMG_UPLOAD_DIR_MODE'));
        self::placeProbeImage();   // best effort - without it the State Check probes img.php less strictly
        return $routed;
    }

    public static function writeIfMissing(string $dir, string $rule, int $dir_mode): bool
    {
        return self::place($dir, '.htaccess', $rule, $dir_mode);
    }

    /** Writes $content as $dir/$name unless the file is there - never through a symlink, never half
     *  of it (a truncated rule answers 500 for every image in the cache). An empty one counts as
     *  missing: every writer here is atomic, so it is a leftover of something else.
     *  @return bool whether the file is in place */
    private static function place(string $dir, string $name, string $content, int $dir_mode): bool
    {
        $file = "$dir/$name";
        clearstatcache(true, $file);
        if (is_link($dir) || is_link($file) || ($content === '')) {
            return false;   // a symlinked cache root is none of ours, a planted link is never written through
        }
        if (is_file($file)) {
            return (@filesize($file) > 0) || self::replace($file, $content);
        }
        if (!is_dir($dir) && !@\Files::createFolder($dir, $dir_mode) && !is_dir($dir)) {
            return false;
        }
        $tmp = "$file." . bin2hex(random_bytes(4));
        try {
            if ((@file_put_contents($tmp, $content) !== strlen($content)) || !self::sizeIs($tmp, strlen($content)) || !@chmod($tmp, 0o644)) {
                return false;
            }
            // link() fails when the name exists - a rule another tree wrote meanwhile stays - and
            // never follows a symlink. A filesystem without hard links, or a PHP with link()
            // disabled, gets rename() right after checking nothing is there; rename() replaces
            // a name, it never writes through one either.
            if (function_exists('link') && @link($tmp, $file)) {
                return true;
            }
            return is_file($file) || @rename($tmp, $file);
        } finally {
            @unlink($tmp);
        }
    }

    /** the rule routing a miss to the dispatcher of the AA installed at $install_path, null for a path which cannot go into it */
    public static function rule(string $install_path): ?string
    {
        if (!preg_match('#^' . self::INSTALL_PATH . '$#D', $install_path)) {
            return null;
        }
        return "# Generated by APC-AA - routes a request for a not yet generated file to the\n"
            . "# dispatcher; files already in the cache are served by the web server and never\n"
            . "# reach PHP. Delete this file and AA writes it again (e.g. after moving AA).\n"
            . "<IfModule mod_rewrite.c>\n"
            . "RewriteEngine On\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "RewriteRule ^ " . $install_path . "modules/site/site.php [L,QSA]\n"
            . "</IfModule>\n";
    }

    /** Whether $content is AA's rule for some AA tree - comments, blank lines, indentation, line
     *  ends and a BOM aside: older AA wrote other comments, and an editor may add the rest */
    public static function isAaRule(string $content): bool
    {
        $directives = static function (string $text): string {
            $lines = array_map('trim', preg_split('~\R~', (string) preg_replace('~^\xEF\xBB\xBF~', '', $text)) ?: []);
            return implode("\n", array_filter($lines, static fn(string $line): bool => ($line !== '') && !str_starts_with($line, '#')));
        };
        $pattern = str_replace(preg_quote('/INSTALL/', '#'), self::INSTALL_PATH, preg_quote($directives((string) self::rule('/INSTALL/')), '#'));
        return (bool) preg_match("#^$pattern\$#D", $directives($content));
    }

    /** What img.php's answer to the probe image means. Its redirect to $error_image says it runs but
     *  could not read the file - which the web server serving that file ($probe_served) turns from
     *  "not written yet" into the failure the generator hits (vona 2026-09-16: an ACL of the
     *  GlusterFS mount let the web server in, not PHP). No answer, or a redirect anywhere else
     *  (http -> https), breaks the generator whatever the file.
     *  @return 'ok'|'unplaced'|'unreadable'|'broken' */
    public static function imgPhpVerdict(int $status, string $content_type, string $location, string $error_image, bool $probe_served): string
    {
        return match (true) {
            self::isImage($status, $content_type)                                 => 'ok',
            !(($status >= 300) && ($status < 400) && ($location === $error_image)) => 'broken',
            $probe_served                                                         => 'unreadable',
            default                                                               => 'unplaced',
        };
    }

    /** What the probe answer means. A missing .htaccess comes first - whatever else is wrong,
     *  writing it is the one thing Repair can do.
     *  @return 'ok'|'missing'|'unreachable'|'ignored' */
    public static function verdict(int $status, string $content_type, bool $has_rule): string
    {
        return match (true) {
            self::isImage($status, $content_type) => 'ok',
            !$has_rule                            => 'missing',
            $status === 0                         => 'unreachable',
            default                               => 'ignored',   // 404, 403, or 200 text/html of a catch-all page
        };
    }

    private static function isImage(int $status, string $content_type): bool
    {
        return ($status >= 200) && ($status < 300) && str_starts_with(strtolower($content_type), 'image/');
    }

    private static function ruleFile(): string
    {
        return ImgCache::root() . '/.htaccess';
    }

    private static function thisRule(): ?string
    {
        return self::rule(constant('AA_INSTAL_PATH'));
    }

    /** a copy of AA's blank.gif for the img.php probe of findings() */
    private static function placeProbeImage(): bool
    {
        return self::place(ImgCache::root(), self::PROBE_IMAGE, (string) @file_get_contents(\dirname(__DIR__, 2) . '/images/blank.gif'), (int) constant('IMG_UPLOAD_DIR_MODE'));
    }

    /** replace an existing rule - written aside and renamed, so no request ever sees half of it */
    private static function replace(string $file, string $rule): bool
    {
        $tmp = "$file." . bin2hex(random_bytes(4));
        if ((@file_put_contents($tmp, $rule) !== strlen($rule)) || !self::sizeIs($tmp, strlen($rule)) || !@chmod($tmp, 0o644) || !@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** NFS reports a full disk only when the file is closed, which file_put_contents() does not tell */
    private static function sizeIs(string $file, int $size): bool
    {
        clearstatcache(true, $file);
        return @filesize($file) === $size;
    }

    /** @return array{0:int,1:string,2:string} HTTP status (0 = no answer at all), content type and the redirect target of a 3xx */
    private static function probe(string $url, bool $follow): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION  => $follow,   // a browser follows http -> https or www -> bare; the generator does not
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_TIMEOUT         => 10,
            CURLOPT_USERAGENT       => 'APC-AA State Check',
            CURLOPT_WRITEFUNCTION   => static fn($handle, string $data): int => 0,   // the headers tell all - stop at the first byte of a body
        ]);
        curl_exec($ch);
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE), (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
    }
}
