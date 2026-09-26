<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\LogOnce;
use AA_Log;

/** Pages that loaded CKEditor 4 by {require:ckeditor} in the last 30 days - logged once a day per
 *  module and page. AA edits with TinyMCE now; CKEditor 4 stays only for the templates that still
 *  ask for it, and goes once nobody does. Index-only. */
class CheckCkeditorUse extends AbstractOptimize
{
    /** log.type of the rows - selector module id:host/path */
    public const EVENT = 'CKEDITOR4';

    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    /** Called when a page requires ckeditor.
     *  @param array<string, mixed> $server $_SERVER
     *  @return bool a row was written */
    public static function record(array $server, string $module_id): bool
    {
        return LogOnce::daily(self::EVENT, self::selector($server, $module_id));
    }

    /** the module and the page, without the query - it may carry tokens, and one page is one template
     *  @param array<string, mixed> $server $_SERVER */
    public static function selector(array $server, string $module_id): string
    {
        $path = parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return ParamImplode([$module_id, (string) ($server['HTTP_HOST'] ?? '') . (is_string($path) ? $path : '')]);
    }

    public static function name(): string
    {
        return _m('CKEditor 4 use');
    }

    public static function description(): string
    {
        return _m('Lists the pages that loaded the old CKEditor 4 by {require:ckeditor} in the last 30 days. AA edits text with TinyMCE now; CKEditor 4 will be removed once no template asks for it.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'content';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $rows    = LogOnce::recent(self::EVENT, now() - self::WINDOW_SECONDS, self::GROUPS);
        $modules = [];
        foreach ($rows as $row) {
            $module = (string) ParamExplode((string) $row['selector'])[0];
            if (is_long_id($module)) {
                $modules[$module] = $module;
            }
        }
        $names = $modules ? DB_AA::select(['unpackid' => 'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module`', [['id', array_values($modules), 'l']]) : [];
        return self::findingsFor($rows, $names);
    }

    /**
     * @param list<array<string, ?string>> $rows  LogOnce::recent() rows
     * @param array<string, string>        $names module id => name
     * @return Finding[]
     */
    public static function findingsFor(array $rows, array $names): array
    {
        $findings = [];
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            [$module_id, $page] = array_pad(ParamExplode($selector), 2, '');
            $findings[] = new Finding(
                Finding::WARNING,
                _m('CKEditor 4 loaded on %1', [$page]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Page')      => safe($page),
                    _m('Module')    => safe((string) ($names[$module_id] ?? '') ?: $module_id),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('A template of the page has {require:ckeditor} (and CKEDITOR.replace()). Wrap the textarea in <aa-richtext mode="rich"> with {require:aa-richtext@1} instead - the textarea keeps its name and value, so the form and scripts reading textarea.value work as they are; CKEDITOR.instances[...].getData() becomes the textarea value.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(self::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No page loaded CKEditor 4 in the last 30 days'))];
    }
}
