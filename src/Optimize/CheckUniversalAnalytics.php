<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\Adminlink;
use AA_Module;
use AA_Object;

/** Templates and site settings still carrying a Universal Analytics id (UA-...). Google stopped
 *  collecting its data on 1 July 2023, so such a site measures no visits - unless a GA4 id (G-...)
 *  or a Tag Manager container (GTM-...) in the same module does it instead. One finding per module.
 *  A LIKE scan over every code column (CodePoints), hence extended.
 */
class CheckUniversalAnalytics extends AbstractOptimize
{
    /** case-sensitive, so x-ua-compatible is no match */
    private const UA_ID = '/\bUA-\d{4,10}-\d{1,4}\b/';
    /** standalone, so IMG-ABCDEF1 is no match */
    private const NEW_ID = '/(?<![\w-])(?:G-[A-Z0-9]{6,12}|GTM-[A-Z0-9]{4,10})(?![\w-])/';

    public static function name(): string
    {
        return _m('Old Google Analytics (Universal Analytics)');
    }

    public static function description(): string
    {
        return _m('Lists the sites and slices whose templates or settings still use a Universal Analytics id (UA-...). Google stopped collecting its data on 1 July 2023, so such code measures no visits.');
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
        $rows = self::matching('UA-', static fn(string $txt): bool => (bool) self::uaIds($txt));
        if (!$rows) {
            return self::findingsFor([], []);
        }
        // the new tag may sit in another template of the module (gtag.js and Tag Manager both load from
        // googletagmanager.com); a value with a UA id is in $rows already - the LIKE above matched it
        array_push($rows, ...self::matching('googletagmanager', static fn(string $txt): bool => self::newIds($txt) && !self::uaIds($txt)));

        $objects = array_column(array_filter($rows, static fn(array $row): bool => $row['table'] === 'object_text'), 'iid');
        $owners  = $objects ? AA_Object::loadProperties(array_values(array_unique($objects)), 'aa_owner') : [];
        $with_ua = [];
        foreach ($rows as $key => $row) {
            $module_id = self::moduleOf($row, $owners);
            $rows[$key]['module'] = $module_id;
            if (is_long_id($module_id) && self::uaIds($row['txt'])) {
                $with_ua[$module_id] = $module_id;
            }
        }
        // deleted modules are not served
        $names = $with_ua ? DB_AA::select(['unpackid' => 'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module`', [['id', array_values($with_ua), 'l'], ['deleted', 0, 'i']]) : [];

        $found = [];
        foreach ($rows as $row) {
            if (isset($names[$row['module']])) {
                $found[] = ['module' => $row['module'], 'where' => Adminlink::linkByTable($row['table'], $row['column'], $row) ?: safe("$row[table].$row[column] $row[iid]"), 'txt' => $row['txt']];
            }
        }
        // a G-... id in the site settings: {generate:HEAD} prints gtag.js with it
        foreach (array_keys($names) as $module_id) {
            $ga_id = (string) AA_Module::getModuleProperty($module_id, 'ga_id');
            if (self::newIds($ga_id)) {
                $found[] = ['module' => $module_id, 'where' => a_href(AA_Module::getModuleUrl($module_id, 'modules/site/modedit.php'), _m('Site settings')), 'txt' => $ga_id];
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
        $counts = [];   // module id => places with a UA id
        $new    = [];   // module id => GA4 and Tag Manager ids
        foreach ($rows as $row) {
            $module_id = $row['module'];
            $old_ids   = self::uaIds($row['txt']);
            $new_ids   = self::newIds($row['txt']);
            if (!isset($names[$module_id]) || (!$old_ids && !$new_ids)) {
                continue;
            }
            $counts[$module_id]   = ($counts[$module_id] ?? 0) + ($old_ids ? 1 : 0);
            $new[$module_id]      = array_merge($new[$module_id] ?? [], $new_ids);
            $places[$module_id][] = [
                _m('Where')                     => $row['where'],
                _m('Old id (UA)')               => safe(implode(', ', $old_ids)),
                _m('New id (GA4, Tag Manager)') => safe(implode(', ', $new_ids)),
            ];
        }

        $findings = [];
        foreach (array_filter($counts) as $module_id => $count) {
            $new_ids = implode(', ', array_unique($new[$module_id]));
            $findings[] = ($new_ids === '')
                ? new Finding(
                    Finding::WARNING,
                    _m('%1 still uses the old Google Analytics (UA-...) - no visits are measured', [$names[$module_id]]),
                    count: $count,
                    evidence: $places[$module_id],
                    advice: _m("Google stopped collecting Universal Analytics data on 1 July 2023. 1. In Google Analytics open Admin > the GA4 property of the site > Data streams > the web stream and copy its Measurement ID (G-...); if the site has no GA4 property, create one. 2. In the places listed: in gtag code write the G-... id instead of the UA-... one (in gtag/js?id= and in gtag('config', ...)) and keep the consent settings; delete the _gaq, ga.js, analytics.js and _gat blocks; where no gtag code is left, paste in the standard GA4 snippet. Replace a UA-... id in the site settings (Google Analytics tracking id) by the G-... id. 3. The next day check GA4 > Reports > Realtime that visits come in."),
                    key: $module_id,
                )
                : new Finding(
                    Finding::INFO,
                    _m('%1 still has the old Google Analytics code (UA-...) next to the new one - it can be removed', [$names[$module_id]]),
                    count: $count,
                    evidence: $places[$module_id],
                    advice: _m("The new code (%1) is there already; the UA-... lines only load a script that measures nothing. Delete the _gaq, ga.js, analytics.js and _gat blocks and gtag('config', 'UA-...'); where the gtag/js?id= address carries the UA-... id, write the G-... id there instead of deleting the line. Clear a UA-... id in the site settings (Google Analytics tracking id) or replace it by the G-... id. A GTM-... container measures only with a GA4 tag inside - check that in Tag Manager. Then check GA4 > Reports > Realtime that visits still come in.", [$new_ids]),
                    key: $module_id,
                );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No template uses the old Google Analytics (Universal Analytics)'))];
    }

    /** The module a CodePoints row belongs to: a view, field, spot or Code item names it in sid, a row of
     *  slice / module / site / polls is the module, an object belongs to its owner.
     *  @param array{table: string, iid: string, sid?: string} $row
     *  @param array<string, list<string>> $owners  object id => [aa_owner] (AA_Object::loadProperties())
     */
    public static function moduleOf(array $row, array $owners): string
    {
        return (string) ($row['sid'] ?? ($row['table'] === 'object_text' ? ($owners[$row['iid']][0] ?? '') : $row['iid']));
    }

    /** @return list<string> the UA ids in the code, AA comments left out */
    public static function uaIds(string $code): array
    {
        return self::ids(self::UA_ID, $code);
    }

    /** @return list<string> the GA4 and Tag Manager ids in the code, AA comments left out */
    public static function newIds(string $code): array
    {
        return self::ids(self::NEW_ID, $code);
    }

    /** @return list<string> */
    private static function ids(string $pattern, string $code): array
    {
        preg_match_all($pattern, self::withoutComments($code), $match);
        return array_values(array_unique($match[0]));
    }

    /** $code without the {#comments} AA removes, nested braces included. One pass over the braces: a regex
     *  for nested braces backtracks quadratically on unclosed {#, which any template author can write.
     *  An unclosed comment is printed, so what it holds counts. */
    private static function withoutComments(string $code): string
    {
        if (!str_contains($code, '{#')) {
            return $code;
        }
        $open = [];   // positions of the unclosed {
        $cut  = [];   // comment start => its }
        $len  = strlen($code);
        for ($i = strcspn($code, '{}'); $i < $len; $i += 1 + strcspn($code, '{}', $i + 1)) {
            if ($code[$i] === '{') {
                $open[] = $i;
            } elseif ($open) {
                $start = array_pop($open);
                if ($i === $start + 1) {
                    $open = [];   // AA never expands an empty {}, so no brace around it closes
                } elseif ($code[$start + 1] === '#') {
                    $cut[$start] = $i;
                }
            }
        }
        ksort($cut);   // inner comments close first
        $out = '';
        $pos = 0;
        foreach ($cut as $start => $end) {
            if ($start >= $pos) {   // not inside a comment cut already
                $out .= substr($code, $pos, $start - $pos);
                $pos  = $end + 1;
            }
        }
        return $out . substr($code, $pos);
    }

    /** the code values containing $needle which $keep accepts
     *  @param callable(string): bool $keep
     *  @return list<array{table: string, column: string, iid: string, sid?: string, txt: string}> */
    private static function matching(string $needle, callable $keep): array
    {
        $rows = [];
        foreach (CodePoints::containing($needle) as $row) {
            if ($keep($row['txt'])) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
