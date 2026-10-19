<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Security\ViewSlices;
use AA\Util\LogOnce;
use AA_Log;

/** Slices that views and slice.php left out in the last 30 days, as the page did not know their reading
 *  password: a view called from the URL over such a slice, or parameters that named such a slice, or items
 *  of it (AA\Security\ViewSlices) - logged once a day per view and slice. Index-only.
 */
class CheckViewSliceRefusals extends AbstractOptimize
{
    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Slices left out of views');
    }

    public static function description(): string
    {
        return _m('Lists the views that left out a slice in the last 30 days because the page did not know the slice\'s reading password: a view called from the URL over that slice, or with parameters naming the slice or items of it.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'security';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $rows   = LogOnce::recent(ViewSlices::EVENT, now() - self::WINDOW_SECONDS, self::GROUPS);
        $slices = [];
        foreach ($rows as $row) {
            $slice = (string) (ParamExplode((string) $row['selector'])[1] ?? '');
            if (is_long_id($slice)) {
                $slices[$slice] = $slice;
            }
        }
        $slice_names = $slices ? DB_AA::select(['unpackid' => 'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module`', [['id', array_values($slices), 'l']]) : [];
        return self::findingsFor($rows, $slice_names);
    }

    /**
     * @param list<array<string, ?string>> $rows         LogOnce::recent() rows
     * @param array<string, string>        $slice_names  slice id => name
     * @return Finding[]
     */
    public static function findingsFor(array $rows, array $slice_names): array
    {
        $findings = [];
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            [$lister, $slice_id] = array_pad(ParamExplode($selector), 2, '');
            $lister     = ctype_digit($lister) ? _m('View %1', [$lister]) : $lister;   // or slice.php
            $slice_name = ($slice_names[$slice_id] ?? '') ?: $slice_id;
            $findings[] = new Finding(
                Finding::WARNING,
                _m('%1 left out slice %2: its reading password was not known', [$lister, $slice_name]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Listed by')      => safe($lister),
                    _m('Slice')          => safe($slice_name),
                    _m('Page asked for') => safe((string) $row['params']),
                    _m('Days seen')      => (string) (int) $row['days'],
                    _m('Last')           => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('The view was called from the URL (view.php, jsview.php, discussion.php), also as a banner of such a view or of slice.php (set[]=banner-..., slice.php?banner=...), over that slice, or its URL parameters (set[]=slice_id-.../slices-..., the item ids of cmd[]=x-...) or slices[] of slice.php named it, and the page did not know the slice\'s reading password. If your page needs the slice there, call the view from the template of the page ({view...}), where views are trusted; give its password in the URL (slice_pwd=...) only in a server-side include, never in a link, AJAX or jsview.php, where it would end up in the page source and logs. If you do not know the page, the request did not come from your pages and nothing needs to change.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(ViewSlices::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No slice was left out of a view in the last 30 days'))];
    }
}
