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
    /** what may go into the RewriteRule target - Apache parses the file, and a space or a
     *  newline there answers 500 for every image in the cache, the cached ones included */
    private const INSTALL_PATH = '#^/[A-Za-z0-9._~/-]*/$#D';

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
        if (!ImgCache::enabled() || is_file(self::ruleFile())) {
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
        $file = self::ruleFile();
        $done = (is_file($file) && (@file_get_contents($file) !== $rule))
            ? self::replace($file, $rule)
            : self::writeIfMissing(ImgCache::root(), $rule, (int) constant('IMG_UPLOAD_DIR_MODE'));
        if (!$done) {
            $this->message(_m('Cannot write %1', [safe($file)]));
        }
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
        $file = self::ruleFile();
        $rule = is_file($file) ? (string) @file_get_contents($file) : null;
        // a fresh name every time: a probe some dispatcher stored would be answered statically ever after
        $url             = ImgCache::get()->getCachedUrl('pT', 'aa-routing-probe/' . bin2hex(random_bytes(6)) . '.jpg?w=1');
        [$status, $type] = self::probe($url);
        $evidence        = [[
            _m('Probe')     => safe($url),
            _m('Answer')    => $status ? safe("$status $type") : _m('none'),
            _m('.htaccess') => ($rule === null) ? _m('missing') : '<pre>' . safe($rule) . '</pre>',
        ]];
        $title = _m('A request for an image which is not generated yet does not reach AA');

        $findings = [match (self::verdict($status, $type, $rule !== null)) {
            'ok'          => new Finding(Finding::OK, _m('A request for an image which is not generated yet reaches AA'), key: 'routing'),
            'missing'     => new Finding(Finding::ERROR, $title, evidence: $evidence, advice: _m('Every image which is not generated yet shows broken. Repair writes the .htaccess into the cache directory (service/sql_update.php does the same); on nginx add the rule from config.php.default to the server configuration.'), repairable: true, key: 'routing'),
            'unreachable' => new Finding(Finding::ERROR, _m('The server cannot load its own image url'), evidence: $evidence, advice: _m('Check that the server resolves and reaches the host of IMG_UPLOAD_URL.'), key: 'routing'),
            'ignored'     => new Finding(Finding::ERROR, $title, evidence: $evidence, advice: _m('The .htaccess in the cache directory has no effect: AllowOverride does not allow it, the server runs nginx, or it routes to an AA tree which does not answer on this host - Repair then points it at this one. Otherwise add the rule from config.php.default to the server configuration.'), repairable: $rule !== self::thisRule(), key: 'routing'),
        }];

        // a miss is generated by a request from the server to img.php, possibly on another host
        $img_php = constant('AA_INSTAL_URL') . 'img.php';
        if (self::probe($img_php)[0] === 0) {
            $findings[] = new Finding(Finding::ERROR, _m('The server cannot reach its own img.php'), evidence: [[_m('Url') => safe($img_php)]], advice: _m('Images which are not in the cache yet cannot be generated. Check that the server resolves and reaches the host of AA_INSTAL_URL.'), key: 'img.php');
        }
        // write-if-missing leaves any rule alone, a hand edited or planted one too
        if (($rule !== null) && !self::isAaRule($rule)) {
            $findings[] = new Finding(Finding::WARNING, _m('The .htaccess in the image cache is not the one AA writes'), evidence: $evidence, advice: _m('Somebody changed it, or it does not come from AA. Repair replaces it with the rule of this AA tree.'), repairable: true, key: 'rule');
        }
        return $findings;
    }

    /** Writes the .htaccess unless there is one - never replaces it: an /aaa/ and an /aa-dev/
     *  tree on one server share the upload directory, and either one's dispatcher does.
     *  @return bool whether the routing file is in place */
    public static function ensure(): bool
    {
        if (!ImgCache::enabled()) {
            return true;
        }
        $rule = self::thisRule();
        return ($rule !== null) && self::writeIfMissing(ImgCache::root(), $rule, (int) constant('IMG_UPLOAD_DIR_MODE'));
    }

    public static function writeIfMissing(string $dir, string $rule, int $dir_mode): bool
    {
        $file = "$dir/.htaccess";
        if (is_file($file)) {
            return true;
        }
        // sticky: the directory is group-writable, and only the owner may then rename or delete the rule
        if (!is_dir($dir) && !@\Files::createFolder($dir, $dir_mode | 0o1000) && !is_dir($dir)) {
            return false;
        }
        // 'x' creates or fails - a rule another tree wrote in the meantime stays
        if (!($handle = @fopen($file, 'x'))) {
            return is_file($file);
        }
        $written = @fwrite($handle, $rule) === strlen($rule);
        fclose($handle);
        if (!$written) {
            @unlink($file);   // a truncated rule answers 500 for every image in the cache
            return false;
        }
        @chmod($file, 0o644);
        return true;
    }

    /** the rule routing a miss to the dispatcher of the AA installed at $install_path, null for a path which cannot go into it */
    public static function rule(string $install_path): ?string
    {
        if (!preg_match(self::INSTALL_PATH, $install_path)) {
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

    /** Whether $content is AA's rule for some AA tree - comments aside, which older AA wrote differently */
    public static function isAaRule(string $content): bool
    {
        $directives = static fn(string $text): string => trim((string) preg_replace('~^\s*(#.*)?\R~m', '', $text . "\n"));
        $pattern    = str_replace(preg_quote('/INSTALL/', '#'), '/[A-Za-z0-9._~/-]*/', preg_quote($directives((string) self::rule('/INSTALL/')), '#'));
        return (bool) preg_match("#^$pattern\$#D", $directives($content));
    }

    /** What the probe answer means. A missing .htaccess comes first - whatever else is wrong,
     *  writing it is the one thing Repair can do.
     *  @return 'ok'|'missing'|'unreachable'|'ignored' */
    public static function verdict(int $status, string $content_type, bool $has_rule): string
    {
        return match (true) {
            ($status === 200) && str_starts_with(strtolower($content_type), 'image/') => 'ok',
            !$has_rule                                                                => 'missing',
            $status === 0                                                             => 'unreachable',
            default                                                                   => 'ignored',   // 404, 403, or 200 text/html of a catch-all page
        };
    }

    private static function ruleFile(): string
    {
        return ImgCache::root() . '/.htaccess';
    }

    private static function thisRule(): ?string
    {
        return self::rule(constant('AA_INSTAL_PATH'));
    }

    /** replace an existing rule - written aside and renamed, so no request ever sees half of it */
    private static function replace(string $file, string $rule): bool
    {
        $tmp = "$file." . bin2hex(random_bytes(4));
        if ((@file_put_contents($tmp, $rule) === false) || !@chmod($tmp, 0o644) || !@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** @return array{0:int,1:string} HTTP status (0 = no answer at all) and content type */
    private static function probe(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION  => true,   // http -> https, www -> bare: the browser follows them too
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_TIMEOUT         => 10,
            CURLOPT_USERAGENT       => 'APC-AA State Check',
            CURLOPT_WRITEFUNCTION   => static fn($handle, string $data): int => 0,   // the headers tell all - stop at the first byte of a body
        ]);
        curl_exec($ch);
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
    }
}
