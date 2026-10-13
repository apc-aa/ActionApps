<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\LogOnce;
use AA_Log;

/** Existing items changed through the aa[...] form input (filler.php, {live} / {ajax} / {editable} on
 *  site pages, itemedit.php) by someone who could not edit them in the admin - anonymous visitors,
 *  readers editing their own record, other logged-in users. Such edits are still allowed; the list
 *  shows which sites rely on them before the permission is enforced. Logged once a day per slice, kind
 *  of editor and field (AA\IO\Grabber\Form). Index-only.
 */
class CheckItemEditsWithoutPermission extends AbstractOptimize
{
    /** log.type of the rows - selector slice_id:anonymous|own|user:field_id, params u|i|r:script */
    public const EVENT = 'EDIT_NOPRM';

    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 200;

    public static function name(): string
    {
        return _m('Item edits without the edit permission');
    }

    public static function description(): string
    {
        return _m('Lists the slices whose existing items were changed in the last 30 days through a form of the site by visitors without login, by readers or by users without the permission to edit the items. Such edits are allowed for now; the list shows which forms rely on them.');
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
        $rows   = LogOnce::recent(self::EVENT, now() - self::WINDOW_SECONDS, self::GROUPS);
        $slices = [];
        foreach ($rows as $row) {
            $slice = (string) ParamExplode((string) $row['selector'])[0];
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
        $editors  = ['anonymous' => _m('visitor without login'), 'own' => _m('reader or author of the item'), 'user' => _m('user without permission')];
        $modes    = ['u' => _m('replace'), 'i' => _m('add'), 'r' => _m('remove')];
        $findings = [];
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            [$slice_id, $editor, $field_id] = array_pad(ParamExplode($selector), 3, '');
            [$mode, $script]              = array_pad(ParamExplode((string) $row['params']), 2, '');
            $slice_name = (string) ($slice_names[$slice_id] ?? '') ?: $slice_id;
            $last       = date('Y-m-d', (int) $row['last']);
            $findings[] = new Finding(
                Finding::INFO,
                _m('Field %1 of items in slice %2 changed by: %3 (last %4)', [$field_id, $slice_name, $editors[$editor] ?? $editor, $last]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Slice')     => safe($slice_name),
                    _m('Editor')    => safe($editors[$editor] ?? $editor),
                    _m('Field')     => safe($field_id),
                    // the params of one of the rows (LogOnce::recent() takes the MAX)
                    _m('Item mode') => safe($modes[$mode] ?? $mode),
                    _m('Script')    => safe($script),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => $last,
                ]],
                advice: _m('A future version will let only users with the permission to edit the item change it. If this is a form of the site, note it - it will need a login or a signed edit link.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(self::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No item was changed without the edit permission in the last 30 days'))];
    }
}
