<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Cache\ImgCache;

/** A resized image is served from IMG_UPLOAD/mC/ by the web server; a request for one which
 *  is not generated yet has to reach the dispatcher in modules/site/site.php. AA puts its own
 *  .htaccess with that rule into the cache root: service/sql_update.php and the task queue
 *  (ScheduleAaTasks) write it when it is missing - the page render checks none of this.
 *
 *  The State Check asks the web server whether a miss really gets through (the file is
 *  honoured only where AllowOverride permits it, nginx ignores it), and whether the server
 *  reaches img.php, which generates the image - the two things no look at the disk tells.
 */
class MissCacheRouting extends AbstractOptimize
{
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
        if (!ImgCache::enabled() || self::hasRule()) {
            return true;
        }
        $this->message(_m('%1 is missing', [safe(self::ruleFile())]));
        return false;
    }

    /** Writes the rule of this AA tree - over an existing one, too: State Check offers Repair
     *  when a miss does not get through, e.g. because the rule routes to another tree. */
    public function repair(): bool
    {
        if (!ImgCache::enabled()) {
            return true;
        }
        if (($rule = self::thisRule()) === null) {
            $this->message(_m('AA_INSTAL_PATH %1 cannot go into a rewrite rule', [safe(constant('AA_INSTAL_PATH'))]));
            return false;
        }
        if (!self::write(ImgCache::root(), $rule, ImgCache::dirMode())) {
            $this->message(_m('Cannot write %1', [safe(self::ruleFile())]));
            return false;
        }
        return true;
    }

    public function findings(): array
    {
        if (!ImgCache::enabled()) {
            return [new Finding(Finding::INFO, _m('The image cache is switched off or not configured - {img} uses img.php'))];
        }
        if (!function_exists('curl_init')) {
            return [new Finding(Finding::INFO, _m('The image cache routing cannot be checked without the PHP curl extension'))];
        }
        $has_rule = self::hasRule();
        $linked   = is_link(ImgCache::root());
        $this_rule  = self::thisRule();
        $can_repair = !$linked && ($this_rule !== null) && ImgCacheWritable::canStore(ImgCache::root());   // Repair renames over the file, as the cache stores
        // a source which is never there: the dispatcher answers its blank and stores nothing
        $url             = ImgCache::get()->getCachedUrl('pT', 'aa-routing-probe/none.jpg?w=1');
        [$status, $type] = self::probe($url, true);
        $evidence        = [[
            _m('Probe')     => safe($url),
            _m('Answer')    => $status ? safe("$status $type") : _m('none'),
            _m('.htaccess') => ($has_rule ? _m('present') : _m('missing')) . ($linked ? ' - ' . _m('the cache directory is a symbolic link, AA does not write there') : ''),
        ]];
        $title = _m('A request for an image which is not generated yet does not reach AA');

        $findings = [match (self::verdict($status, $type, $has_rule)) {
            'ok'          => new Finding(Finding::OK, _m('A request for an image which is not generated yet reaches AA'), key: 'routing'),
            'missing'     => new Finding(Finding::ERROR, $title, evidence: $evidence, advice: _m('Every image which is not generated yet shows broken. Repair writes the .htaccess into the cache directory (service/sql_update.php does the same); on nginx add the rule from config.php.default to the server configuration.'), repairable: $can_repair, key: 'routing'),
            'unreachable' => new Finding(Finding::ERROR, _m('The server cannot load its own image url'), evidence: $evidence, advice: _m('Check that the server resolves and reaches the host of IMG_UPLOAD_URL.'), key: 'routing'),
            'ignored'     => new Finding(Finding::ERROR, $title, evidence: $evidence, advice: _m('The .htaccess in the cache directory has no effect: AllowOverride does not allow it, the server runs nginx, or it routes to an AA tree which does not answer on this host - Repair then points it at this one. Otherwise add the rule from config.php.default to the server configuration.'), repairable: $can_repair && (@file_get_contents(self::ruleFile()) !== $this_rule), key: 'routing'),   // this tree's rule again would change nothing
        }];
        if (($img_php = self::imgPhpFinding()) !== null) {
            $findings[] = $img_php;
        }
        return $findings;
    }

    /** The dispatcher generates a miss by a request from the server to img.php, which follows no
     *  redirect. Asked without a source, img.php answers its redirect to images/blank.gif - which
     *  says it runs and the server reaches it under AA_INSTAL_URL as written.
     *  @return ?Finding null when img.php answers so */
    private static function imgPhpFinding(): ?Finding
    {
        $url                        = constant('AA_INSTAL_URL') . 'img.php';
        [$status, $type, $location] = self::probe($url, false);
        if (self::imgPhpRuns($status, $location, constant('AA_INSTAL_URL') . 'images/blank.gif')) {
            return null;
        }
        return new Finding(
            Finding::ERROR,
            _m('img.php does not answer the server itself'),
            evidence: [[_m('Url') => safe($url), _m('Answer') => $status ? safe(trim("$status $type $location")) : _m('none')]],
            advice: _m('Images which are not in the cache yet cannot be generated. No answer: check that the server resolves and reaches the host of AA_INSTAL_URL. A redirect: set AA_INSTAL_URL to the address it redirects to (https, www) - the generator does not follow redirects.'),
            key: 'img.php',
        );
    }

    /** Writes the rule unless there is one - an /aaa/ and an /aa-dev/ tree on one server share
     *  the upload directory, and either one's dispatcher does.
     *  @return bool whether the routing file is in place */
    public static function ensure(): bool
    {
        return !ImgCache::enabled() || self::hasRule() || (new self())->repair();
    }

    /** Writes $rule as $dir/.htaccess, creating $dir - aside and renamed, so no request ever sees
     *  half of it (a truncated rule answers 500 for every image in the cache). */
    public static function write(string $dir, string $rule, int $dir_mode): bool
    {
        // a symlinked cache root is none of ours (MissCache does not purge there either) - and one
        // planted by another user of the uploads would take the rule over someone else's .htaccess
        if (is_link($dir)) {
            return false;
        }
        if (!is_dir($dir) && !@\Files::createFolder($dir, $dir_mode) && !is_dir($dir)) {
            return false;
        }
        $tmp = "$dir/.htaccess." . bin2hex(random_bytes(4));
        if ((@file_put_contents($tmp, $rule) === strlen($rule)) && @chmod($tmp, 0o644) && @rename($tmp, "$dir/.htaccess")) {
            return true;
        }
        @unlink($tmp);
        return false;
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

    /** Whether img.php answered a request without a source the way it does when it runs: a
     *  redirect to $error_image. No answer, or a redirect anywhere else (http -> https), breaks
     *  the generator, which follows no redirect. */
    public static function imgPhpRuns(int $status, string $location, string $error_image): bool
    {
        return ($status >= 300) && ($status < 400) && ($location === $error_image);
    }

    /** What the probe answer means. A missing .htaccess comes first - whatever else is wrong,
     *  writing it is the one thing Repair can do.
     *  @return 'ok'|'missing'|'unreachable'|'ignored' */
    public static function verdict(int $status, string $content_type, bool $has_rule): string
    {
        return match (true) {
            ($status >= 200) && ($status < 300) && str_starts_with(strtolower($content_type), 'image/') => 'ok',
            !$has_rule                                                                                => 'missing',
            $status === 0                                                                             => 'unreachable',
            default                                                                                   => 'ignored',   // 404, 403, or 200 text/html of a catch-all page
        };
    }

    /** one stat - an empty file is a leftover and routes nothing */
    private static function hasRule(): bool
    {
        $file = self::ruleFile();
        clearstatcache(true, $file);
        return is_file($file) && (filesize($file) > 0);
    }

    private static function ruleFile(): string
    {
        return ImgCache::root() . '/.htaccess';
    }

    private static function thisRule(): ?string
    {
        return self::rule(constant('AA_INSTAL_PATH'));
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
