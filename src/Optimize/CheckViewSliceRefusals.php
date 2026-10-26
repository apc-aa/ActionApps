<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Security\RequestCharset;
use AA\Security\ViewSlices;
use AA\Util\LogOnce;
use AA_Log;
use AA_Views;

/** Slices that views and slice.php left out in the last 30 days, as the page did not know their reading
 *  password: a view called from the URL over such a slice, or parameters that named such a slice, or items
 *  of it, or a condition on a field of related items in it (AA\Security\ViewSlices) - logged once a day
 *  per view (or condition) and slice; also conditions and sorts on fields AA never shows, once a day per
 *  slice and field. And the charsets a request named
 *  for converting a view, discussion, poll or form that were ignored (AA\Security\RequestCharset) - logged
 *  once a day per script and parameter. Index-only.
 */
class CheckViewSliceRefusals extends AbstractOptimize
{
    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    /** log rows of ignored charsets read for their latest names - a row a day per script and parameter */
    private const CHARSET_LOG_MAX = 300;

    /** distinct names a finding shows */
    private const CHARSET_NAMES = 3;

    public static function name(): string
    {
        return _m('Slices left out of views, charsets ignored');
    }

    public static function description(): string
    {
        return _m('Lists the views that left out a slice in the last 30 days because the page did not know the slice\'s reading password: a view called from the URL over that slice, or with parameters naming the slice or items of it.')
            . ' ' . _m('And the conditions on fields of related items in such a slice, which matched nothing.')
            . ' ' . _m('And the conditions and sorts on fields whose values AA never shows, such as passwords, which were left out.')
            . ' ' . _m('Also lists the charsets that links and forms named in convertfrom or convertto and that were ignored.');
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
        $views  = [];
        foreach ($rows as $row) {
            [$lister, $slice] = array_pad(ParamExplode((string) $row['selector']), 2, '');
            if (is_long_id($slice)) {
                $slices[$slice] = $slice;
            }
            if (ctype_digit($lister)) {
                $views[$lister] = (int) $lister;
            }
        }
        $slice_names = $slices ? DB_AA::select(['unpackid' => 'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module`', [['id', array_values($slices), 'l']]) : [];
        $view_urls   = [];
        foreach ($views as $vid) {
            $view = AA_Views::getViewNumeric($vid);
            if ($view->load()) {            // a deleted view has no settings to open
                $view_urls[$vid] = $view->jumpUrl();
            }
        }
        $since        = now() - self::WINDOW_SECONDS;
        $charset_rows = LogOnce::recent(RequestCharset::EVENT, $since, self::GROUPS);
        $charset_log  = $charset_rows ? DB_AA::select([], 'SELECT `selector`, `params` FROM `log`', [['type', RequestCharset::EVENT], ['time', $since, '>=']], ['time-'], null, self::CHARSET_LOG_MAX) : [];
        return self::findingsFor($rows, $slice_names, $view_urls, self::withLatestNames($charset_rows, $charset_log));
    }

    /**
     * The rows of ignored charsets with the page of the newest log row and up to three latest distinct names
     * ('names') - a row a day keeps only the first name of that day, and LogOnce::recent() the greatest params.
     * @param list<array<string, ?string>> $charset_rows LogOnce::recent() rows
     * @param list<array<string, ?string>> $log_rows     their log rows (selector, params), newest first
     * @return list<array<string, mixed>>
     */
    public static function withLatestNames(array $charset_rows, array $log_rows): array
    {
        $latest = [];
        foreach ($log_rows as $log_row) {
            $selector = (string) $log_row['selector'];
            $params   = (string) $log_row['params'];
            $latest[$selector]['params'] ??= $params;
            $name = (string) (ParamExplode($params)[0] ?? '');
            if (count($latest[$selector]['names'] ?? []) < self::CHARSET_NAMES) {
                $latest[$selector]['names'][$name] = $name;
            }
        }
        foreach ($charset_rows as $i => $row) {
            if (isset($latest[(string) $row['selector']])) {
                $charset_rows[$i]['params'] = $latest[(string) $row['selector']]['params'];
                $charset_rows[$i]['names']  = array_values($latest[(string) $row['selector']]['names']);
            }
        }
        return $charset_rows;
    }

    /**
     * @param list<array<string, ?string>> $rows         LogOnce::recent() rows
     * @param array<string, string>        $slice_names  slice id => name
     * @param array<int|string, string>    $view_urls    view id => url of its settings
     * @param list<array<string, mixed>>   $charset_rows withLatestNames() rows of the ignored charsets
     * @return Finding[]
     */
    public static function findingsFor(array $rows, array $slice_names, array $view_urls, array $charset_rows): array
    {
        $findings = [];
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            [$lister, $slice_id, $field_id] = array_pad(ParamExplode($selector), 3, '');
            $links = [];
            if (isset($view_urls[$lister])) {
                $links[_m('View settings')] = $view_urls[$lister];
            }
            if (is_long_id($slice_id)) {
                $links[_m('Slice settings (reading password)')] = get_admin_url('slicedit.php', ['change_id' => $slice_id]);
            }
            $slice_name = ($slice_names[$slice_id] ?? '') ?: $slice_id;
            if ($lister === ViewSlices::PROTECTED_FIELD) {
                $title    = _m('A condition or sort on field %1 of slice %2 was left out: AA never shows its values', [$field_id, $slice_name]);
                $listed   = [_m('Field') => safe($field_id)];
                $advice   = _m('A view, {ids} or a search on the page had a condition or sort on a password, its copy, a two-factor secret or another field whose values AA never shows (also cmd[]=d-..., sort[] or set[]=sort-... in the URL). The condition matched nothing and the sort was dropped. Whether the field is filled can still be asked (ISNULL, NOTNULL); remove the condition or sort from the view or template otherwise. If you do not know the page, the request did not come from your pages and nothing needs to change.');
                $kind     = _m('Protected field refused');
            } elseif ($lister === ViewSlices::CONDITION) {
                $title    = _m('A condition on related items matched nothing in slice %1: its reading password was not known', [$slice_name]);
                $listed   = [];
                $advice   = _m('A view, {ids} or a search on the page had a condition on a field of related items in that slice (<field>@<relation> or <field>@<slice>/<relation>, also cmd[]=d-... in the URL), and the page did not know the slice\'s reading password, so the condition matched nothing. If your page needs it, give the password in the template of the page ({credentials:...}) before the view, never in a link, AJAX or jsview.php. If you do not know the page, the request did not come from your pages and nothing needs to change.');
                $kind     = _m('Condition refused');
            } else {
                $lister   = ctype_digit($lister) ? _m('View %1', [$lister]) : $lister;   // or slice.php
                $title    = _m('%1 left out slice %2: its reading password was not known', [$lister, $slice_name]);
                $listed   = [_m('Listed by') => safe($lister)];
                $advice   = _m('The view was called from the URL (view.php, jsview.php, discussion.php), also as a banner of such a view or of slice.php (set[]=banner-..., slice.php?banner=...), over that slice, or its URL parameters (set[]=slice_id-.../slices-..., the item ids of cmd[]=x-...) or slices[] of slice.php named it, and the page did not know the slice\'s reading password. If your page needs the slice there, call the view from the template of the page ({view...}), where views are trusted; give its password in the URL (slice_pwd=...) only in a server-side include, never in a link, AJAX or jsview.php, where it would end up in the page source and logs. If you do not know the page, the request did not come from your pages and nothing needs to change.');
                $kind     = _m('Slice left out');
            }
            $findings[] = new Finding(
                Finding::WARNING,
                $title,
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [$listed + [
                    _m('Slice')          => safe($slice_name),
                    _m('Page asked for') => safe((string) $row['params']),
                    _m('Days seen')      => (string) (int) $row['days'],
                    _m('Last')           => date('Y-m-d', (int) $row['last']),
                ]],
                advice: $advice,
                links: $links + [_m('Log rows') => AA_Log::getAdminUrl(ViewSlices::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
                kind: $kind,
            );
        }
        foreach ($charset_rows as $row) {
            $findings[] = self::charsetFinding($row);
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No slice was left out of a view or a condition, no protected field was refused and no charset was ignored in the last 30 days'))];
    }

    /** @param array<string, mixed> $row */
    private static function charsetFinding(array $row): Finding
    {
        $selector = (string) $row['selector'];
        [$script, $param]           = array_pad(ParamExplode($selector), 2, '');
        [$charset, $page, $referer] = array_pad(ParamExplode((string) $row['params']), 3, '');
        return new Finding(
            Finding::WARNING,
            _m('%1 ignored a charset in %2', [$script, $param]),
            count: (int) $row['days'],
            since: (int) $row['first'],
            evidence: [[
                _m('Parameter')        => safe($param),
                _m('Latest charsets')  => safe(implode(', ', (array) ($row['names'] ?? [$charset]))),
                _m('Page asked for')   => safe($page),
                _m('Link on page')     => safe($referer),
                _m('Days seen')        => (string) (int) $row['days'],
                _m('Last')             => date('Y-m-d', (int) $row['last']),
            ]],
            advice: _m('A link or form named a charset for converting a view, discussion, poll or form (convertfrom or convertto of view.php, jsview.php, discussion.php, poll.php and filler.php, also set[...]=convertfrom-... of a view) that AA does not take from the URL; it was ignored as if it were not there. Allowed are %1, in any case. Correct the charset in the link or form of your page, or leave convertfrom out - a view then converts from the charset of its slice. If you do not know the page, the request did not come from your pages and nothing needs to change.', [implode(', ', RequestCharset::ALLOWED)]),
            links: [_m('Log rows') => AA_Log::getAdminUrl(RequestCharset::EVENT, $selector)],
            key: 'charset:' . $selector,
            last: (int) $row['last'],
            kind: _m('Charset ignored'),
        );
    }
}
