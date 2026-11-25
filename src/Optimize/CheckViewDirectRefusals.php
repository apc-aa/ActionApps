<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Security\ViewDirect;
use AA\Util\LogOnce;
use AA_Log;
use AA_Views;

/** Views asked for by their own address (view.php, jsview.php) in the last 30 days that showed nothing,
 *  because the view, its slice or a site using the slice lets views work only inside pages
 *  (AA\Security\ViewDirect) - logged once a day per view. A page that loads such a view by its address
 *  only has an empty place, which nobody reports. Index-only.
 */
class CheckViewDirectRefusals extends AbstractOptimize
{
    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Views asked for by their address, set to work only inside pages');
    }

    public static function description(): string
    {
        return _m('Lists the views that view.php or jsview.php was asked for in the last 30 days and did not show, because the view, its slice or a site using the slice lets views work only inside pages - with the page that asked.');
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
        $rows    = LogOnce::recent(ViewDirect::EVENT, now() - self::WINDOW_SECONDS, self::GROUPS);
        $modules = [];
        $urls    = [];
        foreach ($rows as $row) {
            [$vid, , $module] = array_pad(ParamExplode((string) $row['selector']), 3, '');
            if (is_long_id($module)) {
                $modules[$module] = $module;
            }
            if (ctype_digit($vid) && !isset($urls[$vid]) && ($view = AA_Views::getViewNumeric((int) $vid))->load()) {   // a deleted view has no settings to open
                $urls[$vid] = $view->jumpUrl();
            }
        }
        $names = $modules ? DB_AA::select(['unpackid' => 'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module`', [['id', array_values($modules), 'l']]) : [];
        return self::findingsFor($rows, $names, $urls);
    }

    /**
     * @param list<array<string, ?string>> $rows      LogOnce::recent() rows
     * @param array<string, string>        $names     slice or site id => name
     * @param array<int|string, string>    $view_urls view id => url of its settings
     * @return Finding[]
     */
    public static function findingsFor(array $rows, array $names, array $view_urls): array
    {
        $findings = [];
        foreach ($rows as $row) {
            $selector            = (string) $row['selector'];
            [$vid, $by, $module] = array_pad(ParamExplode($selector), 3, '');
            [$asked, $referer]   = array_pad(ParamExplode((string) $row['params']), 2, '');
            $name                = ($names[$module] ?? '') ?: $module;
            $set_by = match ($by) {
                ViewDirect::BY_SLICE => _m('the slice %1 (list of its views)', [$name]),
                ViewDirect::BY_SITE  => _m('the site %1 (settings of the site)', [$name]),
                default              => _m('the view ("Only inside pages")'),
            };
            $links = isset($view_urls[$vid]) ? [_m('View settings') => $view_urls[$vid]] : [];
            if (($by === ViewDirect::BY_SLICE) && is_long_id($module)) {
                $links[_m('Views of the slice')] = get_admin_url('se_views.php', ['module_id' => $module]);
            }
            $findings[] = new Finding(
                Finding::WARNING,
                _m('View %1 was asked for by its address and showed nothing: it works only inside pages', [$vid]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Set by')            => safe($set_by),
                    _m('Address asked for') => safe($asked),
                    _m('Link on page')      => safe($referer),
                    _m('Days seen')         => (string) (int) $row['days'],
                    _m('Last')              => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('Something asked view.php or jsview.php for the view: a page that loads it by its address (AJAX, lazy loading - see "Link on page"), a link, a feed reader or a robot with an old address. If one of your pages needs it, put the view into the template of the page ({view:...}), or let this view be shown by its address again, where "Set by" says. If you do not know the page, nothing needs to change.'),
                links: $links + [_m('Log rows') => AA_Log::getAdminUrl(ViewDirect::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
                kind: _m('View not shown'),
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No view set to work only inside pages was asked for by its address in the last 30 days'))];
    }
}
