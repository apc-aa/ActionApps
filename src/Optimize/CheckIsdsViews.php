<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Isds\Accounts;
use AA\Isds\DatovkaConf;
use AA\Isds\Gate;
use AA\Isds\SliceRules;
use AA\Util\LogOnce;
use AA_Log;

/**
 * The names of the views of a page of a mass sending - isdscampainer and isds_* - where they can be
 * mistaken: in the pair of slices of an ISDS account of this database, where {datovkabulk:view} finds
 * them and shows none of a name two have; and isdscampainer among the slices of each of its sites, where
 * {view:isdscampainer} takes the view of the highest id (AA_Module_Site::findViewId()), which need not be
 * the page. A name two pairs, or a pair and another slice of the site, have breaks nothing. And the names
 * a page asked for that no view of its pair has (Gate::VIEW_EVENT, logged once a day). Index of
 * view.slice_id and log.type only.
 */
class CheckIsdsViews extends AbstractOptimize
{
    /** the entry view of the page; the views it shows are isds_<part> */
    public const ENTRY = Gate::ENTRY_VIEW;

    /** a name logged that long ago is likely fixed - it is logged once a day while it is not */
    private const LOG_WINDOW_SECONDS = 7 * 86400;
    private const LOG_ROWS           = 50;

    public static function name(): string
    {
        return _m('Views of mass sendings');
    }

    public static function description(): string
    {
        return _m('Checks the names of the views of a page of a mass sending to data boxes: each view isds_... once in the slices of campaigns and messages of an ISDS account - of two the page shows none -, and the entry view isdscampainer once among the slices of the site.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'mail';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    /** @return Finding[] */
    public function findings(): array
    {
        $bindings = Accounts::bindings($this->conf());
        $rules    = $this->sliceRules();
        $here     = $this->modulesHere(Accounts::moduleIds($bindings));
        $findings = [];
        // ids of the entry views an error names already: their warning of the site would tell the same
        $reported = [];
        foreach (Accounts::here($bindings, $here) as $binding) {
            $pair = array_values(array_filter([$binding->campaignSlice, $binding->messageSlice]));
            $key  = 'pair:' . strtolower(implode(':', $pair));
            if ($pair && !isset($findings[$key])) {
                $findings[$key] = [];
                foreach (self::byName($this->views($pair)) as $name => $views) {
                    if ((count($views) > 1) && Gate::pageView($name)) {
                        if ($name === self::ENTRY) {
                            array_push($reported, ...array_column($views, 'id'));
                        }
                        $findings[$key][] = new Finding(
                            Finding::ERROR,
                            _m('Views of the slices of ISDS account %1 share the name %2', [Accounts::label($binding->environment, $binding->name), $name]),
                            count: count($views),
                            evidence: self::evidence($views, $rules),
                            advice: _m('The page of the mass sending shows none of them. Keep the name on one view of the slice of campaigns or of messages, and rename or delete the others.'),
                            key: "view:$key:$name",
                            place: $rules->name($binding->campaignSlice),
                        );
                    }
                }
            }
            // a site of another database is that one's to check
            foreach (array_intersect($binding->sites, $here) as $site) {
                $entries = self::byName($this->views($rules->relatedModules($site)))[self::ENTRY] ?? [];
                if ((count($entries) > 1) && array_diff(array_column($entries, 'id'), $reported)) {
                    $findings["site:$site"] = [new Finding(
                        Finding::WARNING,
                        _m('Site %1 has the entry view isdscampainer more than once', [$rules->name($site)]),
                        count: count($entries),
                        evidence: self::evidence($entries, $rules),
                        advice: _m('{view:isdscampainer} takes the view of the highest number, which need not be the page of the mass sending. Call the entry view by its number in the spot of the page, and rename the views of that name that are not it.'),
                        key: "entry:$site",
                        place: $rules->name($site),
                    )];
                }
            }
        }

        return [...array_merge(...array_values($findings)), ...$this->missingFindings($rules)] ?: [new Finding(Finding::OK, _m('%1: nothing found', [static::name()]))];
    }

    /**
     * Names of views pages asked for that no view of their pair has - renamed or deleted: that part of the
     * page stayed empty
     * @return list<Finding>
     */
    private function missingFindings(SliceRules $rules): array
    {
        $findings = [];
        foreach ($this->missingViews() as $row) {
            $selector      = (string) $row['selector'];
            [$site, $name] = array_pad(explode(':', $selector, 2), 2, '');
            $siteName      = is_long_id($site) ? $rules->name($site) : $site;
            $findings[]    = new Finding(
                Finding::WARNING,
                _m('A page of a mass sending on site %1 shows a view no view of its slices is named: %2', [$siteName, $name]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('View')      => safe($name),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('That part of the page stays empty. Give the name back to the view of the slice of campaigns or of messages that was renamed, or correct the name in the template.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(Gate::VIEW_EVENT, $selector)],
                key: "missing:$selector",
                last: (int) $row['last'],
                place: $siteName,
            );
        }

        return $findings;
    }

    /**
     * @param list<array<string, string>> $rows views()
     * @return array<string, list<array<string, string>>> name => its views, the lowest id first
     */
    private static function byName(array $rows): array
    {
        usort($rows, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $names = [];
        foreach ($rows as $row) {
            $names[(string) $row['name']][] = $row;
        }

        return $names;
    }

    /**
     * @param list<array<string, string>> $views
     * @return list<array<string, string>>
     */
    private static function evidence(array $views, SliceRules $rules): array
    {
        return array_map(static fn(array $v): array => [_m('View') => safe((string) $v['id']), _m('Slice') => safe($rules->name($v['slice']))], $views);
    }

    // ------------------------------------------------------------ what tests replace

    protected function conf(): DatovkaConf
    {
        return DatovkaConf::load();
    }

    /**
     * @param list<string> $ids of sites or slices
     * @return list<string> those that are modules of this database
     */
    protected function modulesHere(array $ids): array
    {
        return Accounts::modulesHere($ids);
    }

    protected function sliceRules(): SliceRules
    {
        return new SliceRules();
    }

    protected function now(): int
    {
        return time();
    }

    /** @return list<array<string, ?string>> LogOnce::recent() rows of Gate::VIEW_EVENT */
    protected function missingViews(): array
    {
        return LogOnce::recent(Gate::VIEW_EVENT, $this->now() - self::LOG_WINDOW_SECONDS, self::LOG_ROWS);
    }

    /**
     * @param list<string> $slices
     * @return list<array<string, string>> id, slice, name of the views of the slices whose name begins with
     *                                     isds - of the names Gate::pageView() allows; the page logs other names it
     *                                     asked for in vain, missingViews() reads them
     */
    protected function views(array $slices): array
    {
        return $slices ? array_values(DB_AA::select([], 'SELECT id, LOWER(HEX(`slice_id`)) AS slice, field3 AS name FROM view', [['slice_id', $slices, 'l'], ['field3', 'isds', 'BEGIN']])) : [];
    }
}
