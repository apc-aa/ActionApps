<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA_Module;

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
        $rows = CodePoints::matching('UA-', static fn(string $txt): bool => (bool) self::uaIds($txt));
        if (!$rows) {
            return self::findingsFor([], []);
        }
        // the new tag may sit in another template of the module (gtag.js and Tag Manager both load from
        // googletagmanager.com); a value with a UA id is in $rows already - the LIKE above matched it
        array_push($rows, ...CodePoints::matching('googletagmanager', static fn(string $txt): bool => self::newIds($txt) && !self::uaIds($txt)));

        $rows    = CodePoints::withModules($rows);
        $with_ua = array_column(array_filter($rows, static fn(array $row): bool => (bool) self::uaIds($row['txt'])), 'module');
        $names   = CodePoints::liveModules($with_ua);

        $found = [];
        foreach ($rows as $row) {
            if (isset($names[$row['module']])) {
                $found[] = ['module' => $row['module'], 'where' => CodePoints::where($row), 'txt' => $row['txt']];
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
        preg_match_all($pattern, CodePoints::withoutComments($code), $match);
        return array_values(array_unique($match[0]));
    }
}
