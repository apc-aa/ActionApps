<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Util\Adminlink;
use AA\IO\DB\DB_AA;
use AA\Util\LogOnce;
use AA_Log;

/** Item forms (aa[...] input - filler.php, {editable} and {ajax} on site pages) which tried to change
 *  the flags or the author of an existing item, or the password, 2FA secret, login or e-mail of a reader, or to
 *  move the item to another slice, in the last 30 days with no user logged in (AA\IO\Grabber\Form) -
 *  the field was left as stored. Logged once a day per slice and field. Index-only.
 */
class CheckAnonymousEditRefusals extends AbstractOptimize
{
    /** log.type of the rows - selector slice_id:field_id, params u|i|r:script */
    public const EVENT = 'ANON_DENY';

    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Forms without login changing accounts or authors');
    }

    public static function description(): string
    {
        return _m('Lists the fields of existing items a form tried to change in the last 30 days with no user logged in, although they hold the flags or the author of the item, the password, 2FA secret or login of a reader, or the slice of the item. The fields were left as stored.');
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
        $modes    = ['u' => _m('replace'), 'i' => _m('add'), 'r' => _m('remove')];
        $findings = [];
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            [$slice_id, $field_id] = array_pad(ParamExplode($selector), 2, '');
            [$mode, $script]       = array_pad(ParamExplode((string) $row['params']), 2, '');
            $slice_name = (string) ($slice_names[$slice_id] ?? '') ?: $slice_id;
            $last       = date('Y-m-d', (int) $row['last']);
            $findings[] = new Finding(
                Finding::WARNING,
                _m('A form without login tried to change the field %1 of an item in slice %2 (last %3)', [$field_id, $slice_name, $last]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Slice')     => safe($slice_name),
                    _m('Field')     => safe($field_id),
                    // the params of one of the rows (LogOnce::recent() takes the MAX)
                    _m('Item mode') => safe($modes[$mode] ?? $mode),
                    _m('Script')    => safe($script),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => $last,
                ]],
                advice: _m('The field was left as stored, the rest of the item was saved. Either a form of the site sends this field without a login - then the site must let the reader log in before the form is sent - or the request was forged to take over a reader\'s account or the item.'),
                links: array_filter([_m('Field settings') => Adminlink::fieldUrl($slice_id, $field_id), _m('Log rows') => AA_Log::getAdminUrl(self::EVENT, $selector)]),
                key: $selector,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No form without login tried to change an account or author field in the last 30 days'))];
    }
}
