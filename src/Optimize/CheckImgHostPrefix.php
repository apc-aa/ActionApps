<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Cache\ImgCache;

/** Templates which write an address in front of {img:...}. With the image cache on, {img} returns the
 *  whole address of the thumbnail whenever the page is served from another host than the upload host
 *  (and always in cron and e-mails), so "https://site.cz{img:...}" becomes
 *  "https://site.czhttps://upload.host/..." - a broken image. Old templates did this because {img}
 *  used to return a path (/aaa/img.php?...). One finding per module. A LIKE scan over every code
 *  column (CodePoints), hence extended.
 */
class CheckImgHostPrefix extends AbstractOptimize
{
    /** "https://host", "https#://host/path", "//host" or "https://{server:HTTP_HOST}" at the end of the
     *  text before {img: - "#:" is the escaped colon of AA templates. Any other brace in between is another
     *  command, and "?", "=" or "&" a query whose value is the image address (a share link) - both fine. */
    private const PREFIX = '~(?:https?#?:)?//(?:[^\s"\'<>{}()?=&]|\{server:[^{}]*+\})*+$~iD';

    /** how far before {img: (and after it, for its arguments) to look - keeps a long run of address
     *  characters from making the scan quadratic; real addresses and {img} calls are far shorter */
    private const WINDOW = 300;
    private const CALL_MAX = 500;

    /** the <info> of {img} for which it prints a bare url - the other ones print a number or a whole tag */
    private const URL_INFOS = ['' => true, 'url' => true, 'cache' => true, 'rawurl' => true];

    public static function name(): string
    {
        return _m('Address written in front of {img}');
    }

    public static function description(): string
    {
        return _m('Lists the sites and slices whose templates write a web address in front of {img:...}. {img} gives the whole address of the image, so the address doubles and the image does not show.');
    }

    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
    }

    public static function category(): string
    {
        return 'content';
    }

    /** rewriting a template is its author's call - no repair */
    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        if (!ImgCache::enabled()) {
            // {img} gives the relative img.php path then, and the address in front of it is needed
            return [new Finding(Finding::OK, _m('The image cache is off - {img} gives a path, an address in front of it is right'))];
        }
        // e-mails too: a mail client cannot load a doubled address either; the regex runs once, in findingsFor()
        $rows  = CodePoints::withModules(CodePoints::matching('{img:', static fn(string $txt): bool => str_contains($txt, '//'), true));
        $names = CodePoints::liveModules(array_column($rows, 'module'));
        $found = [];
        foreach ($rows as $row) {
            if (isset($names[$row['module']])) {
                $found[] = ['module' => $row['module'], 'where' => CodePoints::where($row), 'txt' => $row['txt']];
            }
        }
        return self::findingsFor($found, $names);
    }

    /**
     * @param list<array{module: string, where: string, txt: string}> $rows  code points; where = HTML
     * @param array<string, string> $names  module id => name of the live modules - rows of others are left out
     * @return Finding[]
     */
    public static function findingsFor(array $rows, array $names): array
    {
        $places = [];   // module id => evidence rows
        foreach ($rows as $row) {
            $prefixes = self::prefixes($row['txt']);
            if (!isset($names[$row['module']]) || !$prefixes) {
                continue;
            }
            $places[$row['module']][] = [
                _m('Where')                => $row['where'],
                _m('Written before {img}') => safe(implode(', ', $prefixes)),
            ];
        }

        $findings = [];
        foreach ($places as $module_id => $evidence) {
            $findings[] = new Finding(
                Finding::WARNING,
                _m('%1 writes an address in front of {img} - the images do not show', [$names[$module_id]]),
                count: count($evidence),
                evidence: $evidence,
                advice: _m('Since September 2026 {img:...} gives the whole address of the image (with the domain where the image is stored), so the address written in front of it doubles and the image does not load. In the places listed, delete the address in front of {img:...}, e.g. <img src="https://example.org{img:{img_upload......}:w=330}"> becomes <img src="{img:{img_upload......}:w=330}">. The ":cache" option of {img} is the default now and can go too. In an RSS feed whose image is not an uploaded file, write {img:...:...:rawurl} instead - it always gives the whole address (unescaped, which is fine in RSS).'),
                key: $module_id,
                place: $names[$module_id],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No template writes an address in front of {img}'))];
    }

    /** @return list<string> each distinct address written straight in front of an {img:...} printing a url,
     *          AA comments left out */
    public static function prefixes(string $code): array
    {
        $code  = CodePoints::withoutComments($code);
        $found = [];
        for ($pos = stripos($code, '{img:'); $pos !== false; $pos = stripos($code, '{img:', $pos + 5)) {
            $start = max(0, $pos - self::WINDOW);
            if (!preg_match(self::PREFIX, substr($code, $start, $pos - $start), $match)) {
                continue;
            }
            // the first call listed is the one at $pos; an unclosed one is printed as text
            $call = ImgMeasuring::calls(substr($code, $pos, self::CALL_MAX), true)[0] ?? null;
            if ($call && isset(self::URL_INFOS[trim($call[1][2] ?? '')])) {
                $found[] = $match[0];
            }
        }
        return array_values(array_unique($found));
    }
}
