<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Cache\PageCache;

/** Templates reading by {server:...} a value which may be the visitor's own (REMOTE_ADDR,
 *  HTTP_USER_AGENT, HTTP_REFERER, ...). Such a page is nobody else's, so the page cache does not
 *  store it and every visit renders it again - fine for a redirect or a click counter, costly in a
 *  site header. A LIKE scan over every code column (CodePoints), hence extended.
 */
class CheckPerVisitorReads extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Pages left out of the page cache by {server:...}');
    }

    public static function description(): string
    {
        return _m('Lists the sites and slices whose templates read by {server:...} a value which may be the visitor\'s own (address, browser, referer) - the page cache does not store such pages.');
    }

    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
    }

    public static function category(): string
    {
        return 'cache';
    }

    /** rewriting a template is its author's call - no repair */
    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $rows  = [];
        $reads = [];   // the same keys as $rows
        foreach (CodePoints::containing('{server:') as $row) {
            if ($found = self::reads($row['txt'])) {
                $rows[]  = $row;
                $reads[] = $found;
            }
        }
        $rows  = CodePoints::withModules($rows);
        $names = CodePoints::liveModules(array_column($rows, 'module'));
        $live  = [];
        foreach ($rows as $i => $row) {
            if (isset($names[$row['module']])) {
                $live[] = ['module' => $row['module'], 'where' => CodePoints::where($row), 'reads' => $reads[$i]];
            }
        }
        return self::findingsFor($live, $names);
    }

    /**
     * @param list<array{module: string, where: string, reads: list<string>}> $rows  code points of live modules; where = HTML
     * @param array<string, string> $names  module id => name
     * @return Finding[]
     */
    public static function findingsFor(array $rows, array $names): array
    {
        $places = [];   // module id => evidence rows
        foreach ($rows as $row) {
            $places[$row['module']][] = [_m('Where') => $row['where'], _m('Reads') => safe(implode(', ', $row['reads']))];
        }
        $findings = [];
        foreach ($places as $module_id => $evidence) {
            $findings[] = new Finding(
                Finding::INFO,
                _m('%1 has pages the page cache does not store - a template reads a value which may be the visitor\'s own', [$names[$module_id]]),
                count: count($evidence),
                evidence: $evidence,
                advice: _m('Each visit renders such a page again. A redirect, a click counter or a form may stay as it is. Elsewhere, above all in a template of every page (header, footer): delete a value printed only for debugging and check the spelling of the name ({server:HTTP_REFERER}); to tell visitors coming from a campaign use {qs:utm_source}, the device {detect}. The host, scheme and URI ({server:HTTP_HOST}, {server:REQUEST_URI}) keep the page cached.'),
                key: $module_id,
                place: $names[$module_id],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No template reads a value of the visitor\'s own by {server:...}'))];
    }

    /** @return list<string> the $_SERVER names the code reads by {server:...} which keep a page out of the cache, AA comments left out */
    public static function reads(string $code): array
    {
        preg_match_all('/\{server:([A-Za-z0-9_]+)\}/', CodePoints::withoutComments($code), $match);
        return array_values(array_unique(array_filter($match[1], static fn(string $name): bool => !PageCache::variesOnServer($name))));
    }
}
